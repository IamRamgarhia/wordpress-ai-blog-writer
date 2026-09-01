<?php
/**
 * What a post actually cost to write.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records which model wrote a post, and how much of it there was.
 *
 * The numbers were already being handed over and thrown away: every provider
 * response carries the model it used and the tokens it spent, and the only
 * thing kept was a running monthly total. So the screen could say the month
 * had cost thirteen thousand tokens and nothing could say which post did it,
 * or which model wrote any given piece — which is the question anybody
 * comparing two models actually has.
 *
 * A post is written across several requests, so the running total cannot live
 * in a static. It is kept against the job until there is a post to attach it
 * to, and moved onto the post when the job finishes.
 */
class Blogcraft_Usage {

	/**
	 * The post meta the finished figures end up in.
	 */
	const META = '_blogcraft_usage';

	/**
	 * How long a running total survives without being claimed.
	 *
	 * A job takes minutes. A day is the point past which one is never
	 * coming back, and letting it expire on its own is why nothing has
	 * to sweep these up later.
	 */
	const HOLD = DAY_IN_SECONDS;

	/**
	 * The job being run, set once per request by the worker.
	 *
	 * @var int
	 */
	private static $job = 0;

	/**
	 * Hook the display up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
	}

	/**
	 * Note which job the calls from here on belong to.
	 *
	 * @param int $job_id The job.
	 * @return void
	 */
	public static function watch( $job_id ) {
		self::$job = (int) $job_id;
	}

	/**
	 * Add one provider response to the running total.
	 *
	 * @param string $provider   Which service.
	 * @param string $model      Which model it answered as.
	 * @param int    $prompt     Tokens sent.
	 * @param int    $completion Tokens returned.
	 * @return void
	 */
	public static function add( $provider, $model, $prompt, $completion ) {
		if ( self::$job <= 0 ) {
			return;
		}

		$so_far = self::running( self::$job );

		$so_far['provider']    = (string) $provider;
		$so_far['prompt']     += max( 0, (int) $prompt );
		$so_far['completion'] += max( 0, (int) $completion );
		$so_far['requests']   += 1;

		// Every stage may answer as a different model — a cheap one for the
		// outline and a better one for the prose is a normal arrangement — so
		// all of them are kept rather than the last one winning.
		$model = trim( (string) $model );

		if ( '' !== $model && ! in_array( $model, $so_far['models'], true ) ) {
			$so_far['models'][] = $model;
		}

		set_transient( self::key( self::$job ), $so_far, self::HOLD );
	}

	/**
	 * Move a job's total onto the post it produced.
	 *
	 * @param int $job_id  The job.
	 * @param int $post_id The post.
	 * @return void
	 */
	public static function claim( $job_id, $post_id ) {
		$job_id = (int) $job_id;
		$total  = self::running( $job_id );

		if ( $total['requests'] < 1 ) {
			return;
		}

		update_post_meta( (int) $post_id, self::META, $total );
		delete_transient( self::key( $job_id ) );
	}

	/**
	 * Record that an AI client wrote this one instead.
	 *
	 * Nothing was spent here — the model ran inside somebody else's
	 * application on their subscription — and saying so is more useful than
	 * an empty box that looks like a bug.
	 *
	 * @param int    $post_id The post.
	 * @param string $client  What connected, if it said.
	 * @return void
	 */
	public static function by_client( $post_id, $client = '' ) {
		update_post_meta(
			(int) $post_id,
			self::META,
			array(
				'provider'   => 'client',
				'client'     => sanitize_text_field( $client ),
				'models'     => array(),
				'prompt'     => 0,
				'completion' => 0,
				'requests'   => 0,
			)
		);
	}

	/**
	 * What is known about one post.
	 *
	 * @param int $post_id The post.
	 * @return array Empty when nothing was recorded.
	 */
	public static function of( $post_id ) {
		$stored = get_post_meta( (int) $post_id, self::META, true );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Put the box on the editor for posts this plugin had a hand in.
	 *
	 * @return void
	 */
	public static function add_box() {
		add_meta_box(
			'blogcraft-usage',
			__( 'Written by AI', 'dicecodes-ai-blog-writer' ),
			array( __CLASS__, 'render_box' ),
			'post',
			'side',
			'default'
		);
	}

	/**
	 * The box itself.
	 *
	 * @param WP_Post $post The post being edited.
	 * @return void
	 */
	public static function render_box( $post ) {
		$usage = self::of( $post->ID );

		if ( empty( $usage ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Not written by this plugin.', 'dicecodes-ai-blog-writer' )
			);

			return;
		}

		echo '<ul class="bc-usage">';

		if ( 'client' === $usage['provider'] ) {
			printf(
				'<li><strong>%1$s</strong> %2$s</li>',
				esc_html__( 'Written by:', 'dicecodes-ai-blog-writer' ),
				esc_html( '' === $usage['client'] ? __( 'a connected AI client', 'dicecodes-ai-blog-writer' ) : $usage['client'] )
			);
			printf(
				'<li class="description">%s</li>',
				esc_html__( 'On their subscription. Nothing was billed to you here.', 'dicecodes-ai-blog-writer' )
			);
		} else {
			printf(
				'<li><strong>%1$s</strong> %2$s</li>',
				esc_html__( 'Provider:', 'dicecodes-ai-blog-writer' ),
				esc_html( $usage['provider'] )
			);

			if ( ! empty( $usage['models'] ) ) {
				printf(
					'<li><strong>%1$s</strong> %2$s</li>',
					esc_html__( 'Model:', 'dicecodes-ai-blog-writer' ),
					esc_html( implode( ', ', (array) $usage['models'] ) )
				);
			}

			printf(
				'<li><strong>%1$s</strong> %2$s</li>',
				esc_html__( 'Tokens:', 'dicecodes-ai-blog-writer' ),
				esc_html(
					sprintf(
						/* translators: 1: tokens sent, 2: tokens returned, 3: number of requests. */
						__( '%1$s in, %2$s out, over %3$s requests', 'dicecodes-ai-blog-writer' ),
						number_format_i18n( (int) $usage['prompt'] ),
						number_format_i18n( (int) $usage['completion'] ),
						number_format_i18n( (int) $usage['requests'] )
					)
				)
			);
		}

		$score = get_post_meta( $post->ID, '_blogcraft_quality', true );

		if ( '' !== $score ) {
			printf(
				'<li><strong>%1$s</strong> %2$s</li>',
				esc_html__( 'Score:', 'dicecodes-ai-blog-writer' ),
				esc_html( sprintf( '%d / 100', (int) $score ) )
			);
		}

		echo '</ul>';
	}

	/**
	 * The running total for a job.
	 *
	 * @param int $job_id The job.
	 * @return array
	 */
	private static function running( $job_id ) {
		$stored = get_transient( self::key( $job_id ) );

		return wp_parse_args(
			is_array( $stored ) ? $stored : array(),
			array(
				'provider'   => '',
				'client'     => '',
				'models'     => array(),
				'prompt'     => 0,
				'completion' => 0,
				'requests'   => 0,
			)
		);
	}

	/**
	 * Where a job's running total is kept.
	 *
	 * A transient rather than an option: it expires on its own, so an
	 * abandoned job leaves nothing to clean up, and uninstall does not
	 * need a direct query to reach a set of names by prefix.
	 *
	 * @param int $job_id The job.
	 * @return string
	 */
	private static function key( $job_id ) {
		return 'blogcraft_usage_job_' . (int) $job_id;
	}
}
