<?php
/**
 * What an AI client can actually do to this site.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * The tool surface offered over MCP.
 *
 * Deliberately several small tools rather than one write_post. A single coarse
 * tool would have the model work blind and hand back a finished article to
 * accept or reject; small ones let it draft, measure, read the failures and
 * revise — which is the entire reason the checks exist. The scorecard is the
 * most valuable thing here and it runs no model at all.
 *
 * Every handler returns text. A model reads prose better than it reads a
 * nested object, and a check that says "Length: 240, wanted 700–900" is
 * something it can act on directly.
 */
class Blogcraft_Mcp_Tools {

	/**
	 * Every tool, in the shape tools/list returns.
	 *
	 * @return array
	 */
	public static function definitions() {
		return array(
			array(
				'name'        => 'get_writing_rules',
				'title'       => __( 'Get the writing rules', 'dicecodes-ai-blog-writer' ),
				'description' => 'The standing brief every post on this site is written to: length, sections, sentence and paragraph limits, reading ease, the subject, the voice, banned phrasing, and which blocks a post should contain. Read this before drafting anything.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => new stdClass(),
				),
			),
			array(
				'name'        => 'check_draft',
				'title'       => __( 'Score a draft', 'dicecodes-ai-blog-writer' ),
				'description' => 'Measure a draft against this site\'s writing rules. Returns a score out of 100 and every check that was run, with what it found against what was wanted, and an instruction for each failure. Call this before creating a post, then revise against what it says and call it again.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'html'             => array(
							'type'        => 'string',
							'description' => 'The article body as HTML.',
						),
						'title'            => array(
							'type'        => 'string',
							'description' => 'The post title.',
						),
						'slug'             => array(
							'type'        => 'string',
							'description' => 'The intended address, in words separated by hyphens.',
						),
						'meta_description' => array(
							'type'        => 'string',
							'description' => 'The search-result line, around 155 characters.',
						),
						'evidence'         => array(
							'type'        => 'string',
							'description' => 'Anything the author supplied that only they know: figures they measured, prices they paid, what went wrong when they tried it. Checked for on the page.',
						),
					),
					'required'   => array( 'html' ),
				),
			),
			array(
				'name'        => 'suggest_internal_links',
				'title'       => __( 'Find posts to link to', 'dicecodes-ai-blog-writer' ),
				'description' => 'Existing posts on this site worth linking to from an article on a given topic. Weave these into sentences rather than listing them at the end.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'topic' => array(
							'type'        => 'string',
							'description' => 'What the new article is about.',
						),
						'limit' => array(
							'type'        => 'integer',
							'description' => 'How many to return. Defaults to five.',
						),
					),
					'required'   => array( 'topic' ),
				),
			),
			array(
				'name'        => 'find_duplicate',
				'title'       => __( 'Check for an existing post', 'dicecodes-ai-blog-writer' ),
				'description' => 'Whether this site already covers a topic. Two posts competing for the same subject hurt both, so check before writing.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'topic' => array(
							'type'        => 'string',
							'description' => 'The subject you are about to write about.',
						),
					),
					'required'   => array( 'topic' ),
				),
			),
			array(
				'name'        => 'create_draft',
				'title'       => __( 'Create a draft', 'dicecodes-ai-blog-writer' ),
				'description' => 'Save an article as a WordPress draft. The HTML is converted into real Gutenberg blocks, so every paragraph and heading stays editable. Nothing is published by this call.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'title'            => array(
							'type'        => 'string',
							'description' => 'The post title.',
						),
						'html'             => array(
							'type'        => 'string',
							'description' => 'The article body as HTML.',
						),
						'slug'             => array(
							'type'        => 'string',
							'description' => 'The intended address. Optional; WordPress derives one from the title otherwise.',
						),
						'meta_description' => array(
							'type'        => 'string',
							'description' => 'The search-result line.',
						),
					),
					'required'   => array( 'title', 'html' ),
				),
			),
			array(
				'name'        => 'update_draft',
				'title'       => __( 'Revise a draft', 'dicecodes-ai-blog-writer' ),
				'description' => 'Replace the contents of a draft this tool created. Use after check_draft has reported failures.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'          => array(
							'type'        => 'integer',
							'description' => 'The draft to revise.',
						),
						'title'            => array( 'type' => 'string' ),
						'html'             => array( 'type' => 'string' ),
						'meta_description' => array( 'type' => 'string' ),
					),
					'required'   => array( 'post_id' ),
				),
			),
			array(
				'name'        => 'publish_draft',
				'title'       => __( 'Publish a draft', 'dicecodes-ai-blog-writer' ),
				'description' => 'Publish a draft this tool created. Refused when the draft scores below the site\'s quality threshold — score it with check_draft and fix what it reports first.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'The draft to publish.',
						),
					),
					'required'   => array( 'post_id' ),
				),
			),
		);
	}

	/**
	 * Whether a name is one of ours.
	 *
	 * @param string $name Tool name.
	 * @return bool
	 */
	public static function exists( $name ) {
		foreach ( self::definitions() as $tool ) {
			if ( $tool['name'] === $name ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Run a tool.
	 *
	 * @param string $name Tool name.
	 * @param array  $args Arguments as the client sent them.
	 * @return array Keys: text, error.
	 */
	public static function call( $name, $args ) {
		// Every write re-checks the capability. The token already mapped to a
		// user with it, but a tool that publishes should say so itself rather
		// than inherit the assurance from three files away.
		$writes = array( 'create_draft', 'update_draft', 'publish_draft' );

		if ( in_array( $name, $writes, true ) && ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			return self::fail( 'This connection is not allowed to change posts on this site.' );
		}

		switch ( $name ) {
			case 'get_writing_rules':
				return self::writing_rules();

			case 'check_draft':
				return self::check_draft( $args );

			case 'suggest_internal_links':
				return self::internal_links( $args );

			case 'find_duplicate':
				return self::find_duplicate( $args );

			case 'create_draft':
				return self::create_draft( $args );

			case 'update_draft':
				return self::update_draft( $args );

			case 'publish_draft':
				return self::publish_draft( $args );
		}

		return self::fail( 'No such tool.' );
	}

	// ------------------------------------------------------------- reading.

	/**
	 * The active blueprint, in the words the pipeline already uses.
	 *
	 * Both renderers below are what mode A puts in front of
	 * a model at every stage. Re-describing the same blueprint here in
	 * different words would mean two sets of instructions drifting apart,
	 * and the one a reader tuned on the Blueprint screen would be the one
	 * that stopped being obeyed.
	 *
	 * @return array
	 */
	private static function writing_rules() {
		$bp    = Blogcraft_Blueprint::get();
		$lines = array( 'The writing rules for this site. Follow them.', '' );

		$structure = trim( (string) Blogcraft_Blueprint::structure_rules( $bp ) );
		$voice     = trim( (string) Blogcraft_Blueprint::voice_rules( $bp ) );

		if ( '' !== $structure ) {
			$lines[] = $structure;
			$lines[] = '';
		}

		if ( '' !== $voice ) {
			$lines[] = $voice;
			$lines[] = '';
		}

		$lines[] = 'Write plain HTML: h2, h3, p, ul, ol, table, strong, em, a. No markdown.';
		$lines[] = 'Score the result with check_draft before creating a post, then fix what it reports.';

		return self::ok(
			implode(
				'
',
				$lines
			)
		);
	}
	/**
	 * Measure a draft.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	private static function check_draft( $args ) {
		$html = isset( $args['html'] ) ? (string) $args['html'] : '';

		if ( '' === trim( $html ) ) {
			return self::fail( 'No draft was supplied to check.' );
		}

		$blueprint = Blogcraft_Blueprint::get();

		$context = array(
			'title'            => isset( $args['title'] ) ? (string) $args['title'] : '',
			'slug'             => isset( $args['slug'] ) ? (string) $args['slug'] : '',
			'meta_description' => isset( $args['meta_description'] ) ? (string) $args['meta_description'] : '',
			'evidence'         => isset( $args['evidence'] ) ? (string) $args['evidence'] : '',
		);

		$result = Blogcraft_Scorecard::evaluate( $html, $blueprint, $context );
		$bar    = (int) Blogcraft_Settings::get( 'quality_threshold' );
		$score  = (int) $result['score'];

		$lines = array(
			sprintf( 'Score: %1$d out of 100. This site publishes at %2$d or above.', $score, $bar ),
			'',
		);

		$failed = array();

		foreach ( (array) $result['checks'] as $check ) {
			$mark = $check['pass'] ? 'pass' : 'FAIL';

			$lines[] = sprintf(
				'%1$-4s  %2$s: %3$s (wanted %4$s)',
				$mark,
				$check['label'],
				$check['actual'],
				$check['target']
			);

			if ( ! $check['pass'] ) {
				$failed[] = $check;
			}
		}

		if ( ! empty( $failed ) ) {
			$lines[] = '';
			$lines[] = 'Fix these:';

			foreach ( $failed as $check ) {
				$repair = trim( (string) $check['repair'] );

				// A failure with no instruction is one a rewrite cannot fix —
				// too few internal links means the site has nothing else on
				// the subject, and no amount of rewriting invents three
				// related posts. Saying so is more useful than a blank.
				$lines[] = '- ' . ( '' !== $repair
					? $repair
					: $check['label'] . ': ' . $check['actual'] . ', wanted ' . $check['target']
						. '. This is about the site rather than the writing, so rewriting will not change it.' );
			}
		}

		return self::ok( implode( "\n", $lines ) );
	}

	/**
	 * Posts worth linking to.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	private static function internal_links( $args ) {
		$topic = isset( $args['topic'] ) ? (string) $args['topic'] : '';

		if ( '' === trim( $topic ) ) {
			return self::fail( 'No topic was given.' );
		}

		$limit = isset( $args['limit'] ) ? max( 1, min( 20, (int) $args['limit'] ) ) : 5;
		$want  = Blogcraft_Backlinks::fingerprint( $topic );

		$posts = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'numberposts'      => 60,
				'suppress_filters' => false,
			)
		);

		$scored = array();

		foreach ( $posts as $post ) {
			$score = Blogcraft_Backlinks::similarity(
				$want,
				Blogcraft_Backlinks::fingerprint( $post->post_title )
			);

			if ( $score > 0 ) {
				$scored[] = array(
					'score' => $score,
					'title' => $post->post_title,
					'url'   => get_permalink( $post ),
				);
			}
		}

		if ( empty( $scored ) ) {
			return self::ok( 'This site has no published posts related to that topic yet, so there is nothing to link to. Do not invent internal links.' );
		}

		usort(
			$scored,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		$lines = array( 'Posts on this site worth linking to. Weave them into sentences, not a list at the end.', '' );

		foreach ( array_slice( $scored, 0, $limit ) as $hit ) {
			$lines[] = sprintf( '- %1$s — %2$s', $hit['title'], $hit['url'] );
		}

		return self::ok( implode( "\n", $lines ) );
	}

	/**
	 * Whether the site already covers this.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	private static function find_duplicate( $args ) {
		$topic = isset( $args['topic'] ) ? (string) $args['topic'] : '';

		if ( '' === trim( $topic ) ) {
			return self::fail( 'No topic was given.' );
		}

		$want  = Blogcraft_Backlinks::fingerprint( $topic );
		$posts = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => array( 'publish', 'draft', 'pending' ),
				'numberposts'      => 60,
				'suppress_filters' => false,
			)
		);

		$close = array();

		foreach ( $posts as $post ) {
			$score = Blogcraft_Backlinks::similarity(
				$want,
				Blogcraft_Backlinks::fingerprint( $post->post_title )
			);

			if ( $score >= 0.5 ) {
				$close[] = sprintf( '- %1$s (%2$s) — %3$s', $post->post_title, $post->post_status, get_permalink( $post ) );
			}
		}

		if ( empty( $close ) ) {
			return self::ok( 'Nothing on this site covers that subject closely. Safe to write it.' );
		}

		return self::ok(
			"This site already has something close. Two posts competing for one subject hurt both.\n\n"
			. implode( "\n", $close )
			. "\n\nEither write about a different angle, or refresh the existing post instead."
		);
	}

	// ------------------------------------------------------------ writing.

	/**
	 * Save an article as a draft.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	private static function create_draft( $args ) {
		$title = isset( $args['title'] ) ? trim( (string) $args['title'] ) : '';
		$html  = isset( $args['html'] ) ? (string) $args['html'] : '';

		if ( '' === $title || '' === trim( $html ) ) {
			return self::fail( 'A draft needs both a title and a body.' );
		}

		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => Blogcraft_Blocks::from_html( $html ),
				'post_status'  => 'draft',
				'post_type'    => 'post',
				'post_author'  => get_current_user_id(),
				'post_name'    => isset( $args['slug'] ) ? sanitize_title( (string) $args['slug'] ) : '',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return self::fail( 'WordPress refused to save the draft: ' . $post_id->get_error_message() );
		}

		self::remember( (int) $post_id, $args );

		return self::ok(
			sprintf(
				"Saved as draft %1\$d.\n%2\$s\n\nIt is not published. Score it with check_draft, revise with update_draft, then publish_draft when it clears the threshold.",
				(int) $post_id,
				get_edit_post_link( (int) $post_id, 'raw' )
			)
		);
	}

	/**
	 * Replace the contents of a draft.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	private static function update_draft( $args ) {
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$post    = self::ours( $post_id );

		if ( ! $post ) {
			return self::fail( 'That is not a draft this connection created.' );
		}

		$update = array( 'ID' => $post_id );

		if ( isset( $args['title'] ) && '' !== trim( (string) $args['title'] ) ) {
			$update['post_title'] = trim( (string) $args['title'] );
		}

		if ( isset( $args['html'] ) && '' !== trim( (string) $args['html'] ) ) {
			$update['post_content'] = Blogcraft_Blocks::from_html( (string) $args['html'] );
		}

		$done = wp_update_post( $update, true );

		if ( is_wp_error( $done ) ) {
			return self::fail( 'WordPress refused the change: ' . $done->get_error_message() );
		}

		self::remember( $post_id, $args );

		return self::ok( sprintf( 'Draft %d updated. Score it again before publishing.', $post_id ) );
	}

	/**
	 * Publish a draft, if it is good enough.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	private static function publish_draft( $args ) {
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$post    = self::ours( $post_id );

		if ( ! $post ) {
			return self::fail( 'That is not a draft this connection created.' );
		}

		$blueprint = Blogcraft_Blueprint::get();
		$result    = Blogcraft_Scorecard::evaluate(
			$post->post_content,
			$blueprint,
			array(
				'title'            => $post->post_title,
				'slug'             => $post->post_name,
				'meta_description' => (string) $post->post_excerpt,
				'evidence'         => (string) get_post_meta( $post_id, '_blogcraft_evidence', true ),
			)
		);

		$score = (int) $result['score'];
		$bar   = (int) Blogcraft_Settings::get( 'quality_threshold' );

		// The same gate mode A uses. A post that would be held for review
		// when the plugin wrote it is held when a client writes it, or the
		// threshold means nothing.
		if ( $score < $bar ) {
			return self::fail(
				sprintf(
					'Not published. It scores %1$d and this site publishes at %2$d. Call check_draft on it to see what is failing.',
					$score,
					$bar
				)
			);
		}

		$done = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $done ) ) {
			return self::fail( 'WordPress refused to publish it: ' . $done->get_error_message() );
		}

		return self::ok(
			sprintf( 'Published, scoring %1$d. %2$s', $score, get_permalink( $post_id ) )
		);
	}

	// ------------------------------------------------------------ helpers.

	/**
	 * A post this connection is allowed to touch.
	 *
	 * Only posts created through MCP, and only ones not already published.
	 * A tool that can rewrite anything on the site is a much larger promise
	 * than the one being made here.
	 *
	 * @param int $post_id Candidate.
	 * @return WP_Post|null
	 */
	private static function ours( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			return null;
		}

		if ( ! get_post_meta( $post_id, '_blogcraft_mcp', true ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * Mark a post as ours and keep what the checks will need later.
	 *
	 * @param int   $post_id Post.
	 * @param array $args    Tool arguments.
	 * @return void
	 */
	private static function remember( $post_id, $args ) {
		update_post_meta( $post_id, '_blogcraft_mcp', 1 );
		update_post_meta( $post_id, '_blogcraft_generated', 1 );

		// post_excerpt, not a key of our own: it is where mode A already
		// puts the search description and where the SEO code reads it.
		if ( isset( $args['meta_description'] ) ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_excerpt' => sanitize_text_field( (string) $args['meta_description'] ),
				)
			);
		}

		if ( isset( $args['evidence'] ) ) {
			update_post_meta( $post_id, '_blogcraft_evidence', sanitize_textarea_field( (string) $args['evidence'] ) );
		}
	}

	/**
	 * A successful tool result.
	 *
	 * @param string $text What to tell the model.
	 * @return array
	 */
	private static function ok( $text ) {
		return array(
			'text'  => $text,
			'error' => false,
		);
	}

	/**
	 * A tool result the model should read and act on.
	 *
	 * @param string $text What went wrong.
	 * @return array
	 */
	private static function fail( $text ) {
		return array(
			'text'  => $text,
			'error' => true,
		);
	}
}
