<?php
/**
 * The post-writing pipeline.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the write_post pipeline's stages with the worker.
 *
 * Each stage makes exactly one provider call and hands its result to the next
 * through the job payload. Because the worker runs one stage per claim, a
 * generation that takes minutes never needs a single request to outlive PHP's
 * execution limit.
 */
class Blogcraft_Pipeline {

	/**
	 * Pipeline name, as stored on the job row.
	 */
	const NAME = 'write_post';

	/**
	 * Register every stage handler.
	 *
	 * @return void
	 */
	public static function register() {
		Blogcraft_Worker::register_stage( self::NAME, 'outline', array( __CLASS__, 'stage_outline' ) );
		Blogcraft_Worker::register_stage( self::NAME, 'draft', array( __CLASS__, 'stage_draft' ) );
		Blogcraft_Worker::register_stage( self::NAME, 'critique', array( __CLASS__, 'stage_critique' ) );
		Blogcraft_Worker::register_stage( self::NAME, 'revise', array( __CLASS__, 'stage_revise' ) );
		Blogcraft_Worker::register_stage( self::NAME, 'publish', array( __CLASS__, 'stage_publish' ) );
	}

	/**
	 * Queue a new post for generation.
	 *
	 * @param string $topic  Topic to write about.
	 * @param string $status Post status to create: draft or publish.
	 * @return int Job id, or 0 on failure.
	 */
	public static function enqueue_topic( $topic, $status = 'draft' ) {
		return Blogcraft_Queue::enqueue(
			self::NAME,
			'outline',
			array(
				'topic'  => (string) $topic,
				'status' => ( 'publish' === $status ) ? 'publish' : 'draft',
			)
		);
	}

	/**
	 * Run one provider call and return the parsed JSON.
	 *
	 * @param array $messages Chat messages.
	 * @param array $options  Provider options.
	 * @return array Parsed payload.
	 * @throws RuntimeException When no provider is configured, the cap is hit, or the call fails.
	 */
	private static function ask( $messages, $options = array() ) {
		if ( Blogcraft_Cost::over_cap() ) {
			throw new RuntimeException( 'Monthly token cap reached; generation paused.' );
		}

		$provider = Blogcraft_Provider_Registry::from_settings();

		if ( null === $provider ) {
			throw new RuntimeException( 'No AI provider is configured.' );
		}

		$options  = array_merge( array( 'json_mode' => true ), $options );
		$response = $provider->complete( $messages, $options );

		if ( $response->is_error() ) {
			throw new RuntimeException( esc_html( $response->error ) );
		}

		Blogcraft_Cost::record(
			$provider->id(),
			$response->model,
			$response->prompt_tokens,
			$response->completion_tokens
		);

		$parsed = Blogcraft_Prompts::extract_json( $response->text );

		if ( empty( $parsed ) ) {
			throw new RuntimeException( 'The model did not return usable JSON.' );
		}

		return $parsed;
	}

	/**
	 * Plan the post.
	 *
	 * @param Blogcraft_Job $job Current job.
	 * @return array
	 */
	public static function stage_outline( $job ) {
		$topic   = isset( $job->payload['topic'] ) ? $job->payload['topic'] : '';
		$outline = self::ask( Blogcraft_Prompts::outline( $topic ) );

		$payload            = $job->payload;
		$payload['outline'] = $outline;

		return array(
			'next'    => 'draft',
			'payload' => $payload,
		);
	}

	/**
	 * Write the first draft.
	 *
	 * @param Blogcraft_Job $job Current job.
	 * @return array
	 */
	public static function stage_draft( $job ) {
		$topic   = isset( $job->payload['topic'] ) ? $job->payload['topic'] : '';
		$outline = isset( $job->payload['outline'] ) ? $job->payload['outline'] : array();
		$article = self::ask( Blogcraft_Prompts::draft( $topic, $outline ), array( 'max_tokens' => 4096 ) );

		$payload            = $job->payload;
		$payload['article'] = $article;

		return array(
			'next'    => 'critique',
			'payload' => $payload,
		);
	}

	/**
	 * Critique the draft.
	 *
	 * @param Blogcraft_Job $job Current job.
	 * @return array
	 */
	public static function stage_critique( $job ) {
		$article = isset( $job->payload['article'] ) ? $job->payload['article'] : array();
		$result  = self::ask( Blogcraft_Prompts::critique( $article ) );

		$problems = isset( $result['problems'] ) && is_array( $result['problems'] ) ? $result['problems'] : array();

		$payload             = $job->payload;
		$payload['problems'] = $problems;

		// Nothing to fix means the revise pass would only burn tokens.
		return array(
			'next'    => empty( $problems ) ? 'publish' : 'revise',
			'payload' => $payload,
		);
	}

	/**
	 * Apply the critique.
	 *
	 * @param Blogcraft_Job $job Current job.
	 * @return array
	 */
	public static function stage_revise( $job ) {
		$article  = isset( $job->payload['article'] ) ? $job->payload['article'] : array();
		$problems = isset( $job->payload['problems'] ) ? $job->payload['problems'] : array();
		$revised  = self::ask( Blogcraft_Prompts::revise( $article, $problems ), array( 'max_tokens' => 4096 ) );

		$payload            = $job->payload;
		$payload['article'] = $revised;

		return array(
			'next'    => 'publish',
			'payload' => $payload,
		);
	}

	/**
	 * Create the WordPress post.
	 *
	 * @param Blogcraft_Job $job Current job.
	 * @return array
	 * @throws RuntimeException When the post cannot be created.
	 */
	public static function stage_publish( $job ) {
		$payload = $job->payload;
		$article = isset( $payload['article'] ) ? $payload['article'] : array();
		$outline = isset( $payload['outline'] ) ? $payload['outline'] : array();

		$title = '';

		if ( ! empty( $outline['title'] ) ) {
			$title = sanitize_text_field( (string) $outline['title'] );
		}

		if ( '' === $title ) {
			$title = sanitize_text_field( isset( $payload['topic'] ) ? (string) $payload['topic'] : __( 'Untitled', 'blogcraft' ) );
		}

		$content = Blogcraft_Blocks::render( $article );

		if ( '' === $content ) {
			throw new RuntimeException( 'The generated article was empty.' );
		}

		$postarr = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => ( isset( $payload['status'] ) && 'publish' === $payload['status'] ) ? 'publish' : 'draft',
			'post_type'    => 'post',
		);

		if ( ! empty( $outline['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( (string) $outline['slug'] );
		}

		if ( ! empty( $outline['meta_description'] ) ) {
			$postarr['post_excerpt'] = sanitize_text_field( (string) $outline['meta_description'] );
		}

		$post_id = wp_insert_post( $postarr, true );

		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( esc_html( $post_id->get_error_message() ) );
		}

		update_post_meta( $post_id, '_blogcraft_generated', 1 );
		update_post_meta( $post_id, '_blogcraft_topic', isset( $payload['topic'] ) ? (string) $payload['topic'] : '' );

		Blogcraft_Logger::info(
			'Generated post created.',
			array( 'post_id' => (int) $post_id ),
			(int) $job->id
		);

		$payload['post_id'] = (int) $post_id;

		return array(
			'next'    => null,
			'payload' => $payload,
		);
	}
}
