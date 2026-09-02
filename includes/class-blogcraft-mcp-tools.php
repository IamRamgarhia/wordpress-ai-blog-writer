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
	 * Where a publishing time asked for on the brief is kept.
	 *
	 * Publishing happens in a later call than the one that knew about it,
	 * so the draft carries it until publish_draft comes asking.
	 */
	const WHEN_META = '_blogcraft_publish_at';

	/**
	 * What was done to a post when it went live, so it is not done twice.
	 *
	 * Publishing fires the status transition, which finishes the post, and
	 * the call that asked for the publish then wants to report what happened.
	 * Holding the answer here lets the second ask be told without the work
	 * running again.
	 */
	const DONE_META = '_blogcraft_finished';

	/**
	 * Every tool, in the shape tools/list returns.
	 *
	 * @return array
	 */
	public static function definitions() {
		return array(
			array(
				'name'        => 'get_brief',
				'title'       => __( 'Get the brief', 'dicecodes-ai-blog-writer' ),
				'description' => 'The post the site owner has asked for: the topic, the angle, anything only they know, and any per-post choices that differ from the standing rules. Call this first whenever you are asked to write, and follow it. Returns nothing when no brief is waiting, in which case ask what to write about.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => new stdClass(),
				),
			),
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
				'description' => 'Measure a draft against this site\'s writing rules. Returns a score out of 100 and every check that was run, with what it found against what was wanted, and an instruction for each failure. Pass post_id to score a draft already saved here, or html to score something before saving it. Repeat this after every revision: the first score is a starting point, not a verdict.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'          => array(
							'type'        => 'integer',
							'description' => 'A draft saved here, to score exactly as it stands. Use this after add_pictures, because the saved post is no longer the html you sent.',
						),
						'html'             => array(
							'type'        => 'string',
							'description' => 'The article body as HTML, for scoring something not yet saved.',
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
					'required'   => array(),
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
							'description' => 'The search-result line, around 155 characters.',
						),
						'seo_title'        => array(
							'type'        => 'string',
							'description' => 'The search-result title, which is not the page heading. It has to earn the click and is cut off near sixty characters. Optional; the title is used otherwise.',
						),
						'topic'            => array(
							'type'        => 'string',
							'description' => 'What the post is about, in a few words. Used to pick pictures and to decide which older posts should link to this one.',
						),
						'category'         => array(
							'type'        => 'string',
							'description' => 'The category name. Created if it does not exist. Without one the post lands in Uncategorised.',
						),
						'tags'             => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Tag names. Created if they do not exist.',
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
						'slug'             => array( 'type' => 'string' ),
						'meta_description' => array( 'type' => 'string' ),
						'seo_title'        => array(
							'type'        => 'string',
							'description' => 'The search-result title, which is not the page heading. It has to earn the click and is cut off near sixty characters. Optional; the title is used otherwise.',
						),
						'topic'            => array(
							'type'        => 'string',
							'description' => 'What the post is about, in a few words. Used to pick pictures and to decide which older posts should link to this one.',
						),
						'category'         => array(
							'type'        => 'string',
							'description' => 'The category name. Created if it does not exist. Without one the post lands in Uncategorised.',
						),
						'tags'             => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Tag names. Created if they do not exist.',
						),
					),
					'required'   => array( 'post_id' ),
				),
			),
			array(
				'name'        => 'publish_draft',
				'title'       => __( 'Publish a draft', 'dicecodes-ai-blog-writer' ),
				'description' => 'Publish a draft this tool created, and finish it the way this site finishes every post: search title and description, a featured image, links added to older posts pointing at this one, and a submission to the search engines that accept one. Refused when the draft scores below the site\'s quality threshold — score it with check_draft and fix what it reports first.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array(
							'type'        => 'integer',
							'description' => 'The draft to publish.',
						),
						'publish_at' => array(
							'type'        => 'string',
							'description' => 'Optional. A date and time in the future, in the site\'s own timezone, to schedule it for instead of publishing now.',
						),
					),
					'required'   => array( 'post_id' ),
				),
			),
			array(
				'name'        => 'list_drafts',
				'title'       => __( 'List your drafts', 'dicecodes-ai-blog-writer' ),
				'description' => 'The drafts written through this connection that are still unpublished, newest first. Use this to pick up work from an earlier conversation instead of starting the post again.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array(
							'type'        => 'integer',
							'description' => 'How many to return. Defaults to ten.',
						),
					),
				),
			),
			array(
				'name'        => 'read_draft',
				'title'       => __( 'Read a draft back', 'dicecodes-ai-blog-writer' ),
				'description' => 'The current contents of a draft this connection created, as HTML, with its title, description and score. Read it before revising so you are editing what is actually saved.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'The draft to read.',
						),
					),
					'required'   => array( 'post_id' ),
				),
			),
			array(
				'name'        => 'add_pictures',
				'title'       => __( 'Add pictures', 'dicecodes-ai-blog-writer' ),
				'description' => 'Give a draft a featured image, and a picture under each of its first few headings, using whichever picture service this site is set up with. Does nothing if the owner has not switched pictures on. Publishing does this too, so call it only when you want to see them before publishing.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'The draft to illustrate.',
						),
						'topic'   => array(
							'type'        => 'string',
							'description' => 'What the post is about, so the pictures suit it. The title is used otherwise.',
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
		$writes = array( 'create_draft', 'update_draft', 'publish_draft', 'add_pictures' );

		if ( in_array( $name, $writes, true ) && ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			return self::fail( 'This connection is not allowed to change posts on this site.' );
		}

		switch ( $name ) {
			case 'get_brief':
				return self::brief();

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

			case 'list_drafts':
				return self::list_drafts( $args );

			case 'read_draft':
				return self::read_draft( $args );

			case 'add_pictures':
				return self::add_pictures( $args );
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
		// Scoring a saved draft rather than a string means the score is
		// of the post as it stands, pictures and all, instead of the
		// html somebody last happened to send.
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;

		if ( $post_id > 0 ) {
			$post = self::ours( $post_id );

			if ( ! $post ) {
				return self::fail( 'That is not a draft this connection created.' );
			}

			$args = array_merge(
				array(
					'html'             => $post->post_content,
					'title'            => $post->post_title,
					'slug'             => $post->post_name,
					'meta_description' => (string) $post->post_excerpt,
					'evidence'         => (string) get_post_meta( $post_id, '_blogcraft_evidence', true ),
				),
				$args
			);
		}

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

		// Scoring something saved here is worth keeping. Without this the
		// library lists every post a connected app wrote as "not scored",
		// however many times it was measured, because the only code that
		// ever wrote the score down was the pipeline this path replaces.
		if ( $post_id > 0 ) {
			self::record_score( $post_id, $result );
		}

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

		// The activity log knew about the pipeline and nothing else, so on a
		// site where an app does the writing the screen that answers "what
		// has this been doing" could not answer it at all.
		Blogcraft_Logger::info(
			'A connected app created a draft.',
			array(
				'post_id' => (int) $post_id,
				'title'   => $title,
			)
		);

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

		// Before the gate, so a draft that is refused still carries the score
		// it was refused for. Somebody looking at the library then sees why
		// it is still sitting there.
		self::record_score( $post_id, $result );

		// The same gate mode A uses. A post that would be held for review
		// when the plugin wrote it is held when a client writes it, or the
		// threshold means nothing.
		if ( $score < $bar ) {
			// The one somebody goes looking for: a draft they were told was
			// finished, still sitting there.
			Blogcraft_Logger::info(
				'A post was held back: it scored below the threshold.',
				array(
					'post_id' => $post_id,
					'score'   => $score,
					'wanted'  => $bar,
				)
			);

			return self::fail(
				sprintf(
					'Not published. It scores %1$d and this site publishes at %2$d. Call check_draft on it to see what is failing.',
					$score,
					$bar
				)
			);
		}

		$update = array(
			'ID'          => $post_id,
			'post_status' => 'publish',
		);

		// A date in the future turns publishing into scheduling, which
		// is WordPress's own behaviour for a future post_date. A date
		// in the past is not an error worth refusing over; it just
		// publishes now, which is what was asked for.
		$when = isset( $args['publish_at'] ) ? trim( (string) $args['publish_at'] ) : '';

		// Nothing in the call, so the time the brief asked for, which the
		// draft has been carrying since it was created.
		if ( '' === $when ) {
			$when = trim( (string) get_post_meta( $post_id, self::WHEN_META, true ) );
		}

		$stamp = ( '' === $when ) ? 0 : strtotime( $when );

		if ( $stamp && $stamp > time() ) {
			// GMT first and the local column derived from it, rather than
			// arithmetic on the offset. Doing the sum by hand put the date
			// in the past on any site east of Greenwich, and a post dated
			// in the past is one WordPress publishes immediately — the
			// exact opposite of what was asked for.
			$update['post_status']   = 'future';
			$update['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $stamp );
			$update['post_date']     = get_date_from_gmt( $update['post_date_gmt'] );

			// Without this wp_update_post silently drops both dates and
			// publishes now. The call reports success either way, so the
			// only sign is a post that went out a week early.
			$update['edit_date'] = true;
		}

		$done = wp_update_post( $update, true );

		if ( is_wp_error( $done ) ) {
			return self::fail( 'WordPress refused to publish it: ' . $done->get_error_message() );
		}

		$finished = self::finish( $post_id );

		Blogcraft_Logger::info(
			( 'future' === $update['post_status'] )
				? 'A connected app scheduled a post.'
				: 'A connected app published a post.',
			array(
				'post_id' => $post_id,
				'score'   => $score,
			)
		);

		if ( 'future' === $update['post_status'] ) {
			return self::ok(
				sprintf(
					'Scheduled for %1$s, scoring %2$d. %3$s%4$s',
					get_the_date( '', $post_id ) . ' ' . get_the_time( '', $post_id ),
					$score,
					get_permalink( $post_id ),
					$finished
				)
			);
		}

		return self::ok(
			sprintf( 'Published, scoring %1$d. %2$s%3$s', $score, get_permalink( $post_id ), $finished )
		);
	}

	// ------------------------------------------------------------ helpers.

	/**
	 * The brief waiting on the site, if there is one.
	 *
	 * The Write a post screen still has its whole form on this path.
	 * Reducing it to a sentence to paste threw away the topic, the
	 * angle, the evidence box and every per-post override — which are
	 * the things that make a post specific rather than generic. They
	 * are filled in there and collected here.
	 *
	 * @return array
	 */
	private static function brief() {
		$text = Blogcraft_Brief::as_text();

		if ( '' === $text ) {
			return self::ok(
				'No brief is waiting. Ask what the post should be about, and what the author knows about it that nobody else does.'
			);
		}

		return self::ok( $text );
	}

	/**
	 * Put the post where it belongs: category, tags, search title.
	 *
	 * Without this every post written over MCP landed in Uncategorised
	 * with no tags, which is not a finished post on any site that uses
	 * either.
	 *
	 * @param int   $post_id The draft.
	 * @param array $args    Tool arguments.
	 * @return void
	 */
	private static function place( $post_id, $args ) {
		$category = isset( $args['category'] ) ? trim( (string) $args['category'] ) : '';

		if ( '' !== $category ) {
			$term = term_exists( $category, 'category' );

			if ( ! $term ) {
				$term = wp_insert_term( $category, 'category' );
			}

			if ( ! is_wp_error( $term ) && isset( $term['term_id'] ) ) {
				wp_set_post_terms( $post_id, array( (int) $term['term_id'] ), 'category' );
			}
		}

		$tags = isset( $args['tags'] ) ? (array) $args['tags'] : array();
		$tags = array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $tags ) ) );

		if ( ! empty( $tags ) ) {
			wp_set_post_terms( $post_id, $tags, 'post_tag' );
		}
	}

	/**
	 * Apply the placement choices only this site can make.
	 *
	 * The byline and the publishing time sit on the brief form and were
	 * being saved with the brief, but the text handed to the app never
	 * mentioned either and the app has no parameter for either. So both were
	 * controls that took an answer and threw it away.
	 *
	 * The time is kept rather than used, because the draft is not published
	 * in the call that creates it.
	 *
	 * @param int $post_id The draft just created.
	 * @return void
	 */
	private static function place_from_brief( $post_id ) {
		$brief = Blogcraft_Brief::get();

		if ( empty( $brief['placement'] ) || ! is_array( $brief['placement'] ) ) {
			return;
		}

		$placement = $brief['placement'];
		$author    = isset( $placement['author'] ) ? (int) $placement['author'] : 0;

		// Checked against what exists now, the way the pipeline checks it: a
		// user can be deleted between a brief being written and acted on.
		if ( $author > 0 && get_userdata( $author ) ) {
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_author' => $author,
				)
			);
		}

		$when = isset( $placement['publish_at'] ) ? trim( (string) $placement['publish_at'] ) : '';

		if ( '' !== $when ) {
			update_post_meta( $post_id, self::WHEN_META, $when );
		}
	}

	/**
	 * Keep a score where this site's own screens look for it.
	 *
	 * Mode A writes these when the pipeline finishes a post, and the
	 * library, the overview and the activity screen all read them back.
	 * Nothing on this path wrote them, so a post a connected app wrote,
	 * checked and published read "not scored" for ever.
	 *
	 * @param int   $post_id The draft.
	 * @param array $result  What the scorecard returned.
	 * @return void
	 */
	private static function record_score( $post_id, $result ) {
		update_post_meta( $post_id, '_blogcraft_quality', (int) $result['score'] );

		// Written so the review screen describes the post as it now stands,
		// rather than as it was when somebody last happened to check it.
		if ( ! empty( $result['checks'] ) ) {
			update_post_meta( $post_id, '_blogcraft_checks', (array) $result['checks'] );
		}

		if ( ! empty( $result['metrics'] ) ) {
			update_post_meta( $post_id, '_blogcraft_metrics', (array) $result['metrics'] );
		}
	}

	/**
	 * Everything this site does to a post once it goes live.
	 *
	 * Mode A does all of this in its finishing stages. A post written
	 * over MCP went out with no featured image, no search title, nothing
	 * linking to it and no submission to anybody — a draft that happened
	 * to be public rather than a finished post.
	 *
	 * Nothing here may fail the publish. The post is already live; a
	 * picture service being down is not a reason to report failure for
	 * something that succeeded.
	 *
	 * @param int $post_id The post, now published.
	 * @return string What was done, for the client to report.
	 */
	public static function finish( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		// Done already, by whichever route got here first.
		$already = (string) get_post_meta( $post_id, self::DONE_META, true );

		if ( '' !== $already ) {
			return ( '-' === $already ) ? '' : $already;
		}

		$topic = (string) get_post_meta( $post_id, '_blogcraft_topic', true );
		$topic = ( '' === $topic ) ? $post->post_title : $topic;
		$did   = array();

		// The search-result title is not the heading on the page: one is
		// read by somebody who has arrived, the other has to earn the
		// click.
		$seo_title = (string) get_post_meta( $post_id, '_blogcraft_seo_title', true );

		Blogcraft_Seo::write_seo_meta(
			$post_id,
			( '' === $seo_title ) ? $post->post_title : $seo_title,
			(string) $post->post_excerpt
		);

		try {
			if ( Blogcraft_Images::attach_featured( $post_id, $post->post_title, $topic ) ) {
				$did[] = 'a featured image';
			}
		} catch ( Throwable $e ) {
			Blogcraft_Logger::error( 'The featured image could not be added.', array( 'reason' => $e->getMessage() ) );
		}

		try {
			$added = Blogcraft_Images::add_section_images( $post_id, self::article_shape( $post ), 3 );

			if ( $added ) {
				$did[] = sprintf( '%d section pictures', (int) $added );
			}
		} catch ( Throwable $e ) {
			Blogcraft_Logger::error( 'Section pictures could not be added.', array( 'reason' => $e->getMessage() ) );
		}

		try {
			// Every competing tool links only forward, leaving existing
			// posts unaware the new one exists.
			if ( Blogcraft_Backlinks::link_back( $post_id, $topic, 3 ) ) {
				$did[] = 'links from older posts';
			}
		} catch ( Throwable $e ) {
			Blogcraft_Logger::error( 'Older posts could not be pointed at this one.', array( 'reason' => $e->getMessage() ) );
		}

		try {
			// Does nothing unless the owner switched it on.
			if ( Blogcraft_Indexnow::submit( $post_id ) ) {
				$did[] = 'submitted to search engines';
			}
		} catch ( Throwable $e ) {
			Blogcraft_Logger::error( 'The search engines could not be told.', array( 'reason' => $e->getMessage() ) );
		}

		$summary = empty( $did ) ? '' : ' Added: ' . implode( ', ', $did ) . '.';

		// A dash for "nothing to add", because an empty string would read as
		// never having run and the work would be attempted again on the next
		// save of the same post.
		update_post_meta( $post_id, self::DONE_META, ( '' === $summary ) ? '-' : $summary );

		return $summary;
	}

	/**
	 * Finish a post that went live without the tool being asked to publish it.
	 *
	 * The plugin tells people to read the draft before anything goes out, and
	 * the obvious way to act on that is to open it in WordPress and press
	 * Publish. Nothing was hooked to that, so following the advice meant a
	 * post published with no featured image, no section pictures, nothing
	 * linking to it, no search title or description written, and no search
	 * engine told — while asking the app to publish did all five.
	 *
	 * Only posts this connection wrote. Mode A finishes its own inside the
	 * pipeline, and a post somebody wrote by hand is not ours to touch.
	 *
	 * @param string  $status Status being moved to.
	 * @param string  $was    Status being moved from.
	 * @param WP_Post $post   The post.
	 * @return void
	 */
	public static function on_publish( $status, $was, $post ) {
		if ( 'publish' !== $status || 'publish' === $was ) {
			return;
		}

		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			return;
		}

		if ( ! get_post_meta( $post->ID, '_blogcraft_mcp', true ) ) {
			return;
		}

		self::finish( $post->ID );
	}

	/**
	 * A published post described the way the image helper expects.
	 *
	 * It wants the article structure mode A carried through its pipeline,
	 * and all it reads from a section is the heading. Those are in the
	 * saved post, so they are read back out rather than invented.
	 *
	 * @param WP_Post $post The post.
	 * @return array
	 */
	private static function article_shape( $post ) {
		$found = array();
		preg_match_all( '#<h2[^>]*>(.*?)</h2>#is', (string) $post->post_content, $found );

		$sections = array();

		foreach ( $found[1] as $heading ) {
			$text = trim( wp_strip_all_tags( $heading ) );

			if ( '' !== $text ) {
				$sections[] = array( 'heading' => $text );
			}
		}

		return array( 'sections' => $sections );
	}

	/**
	 * The unpublished drafts this connection made.
	 *
	 * A conversation that ends mid-draft used to lose the draft: nothing
	 * could list it, so the next conversation started the post again.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	private static function list_drafts( $args ) {
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 10;
		$limit = max( 1, min( 50, $limit ) );

		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'draft', 'pending', 'future' ),
				'posts_per_page' => $limit,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_key'       => '_blogcraft_mcp', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the point of the query is to find exactly these.
			)
		);

		if ( empty( $posts ) ) {
			return self::ok( 'No unpublished drafts from this connection.' );
		}

		$lines = array();

		foreach ( $posts as $post ) {
			$lines[] = sprintf(
				'%1$d  %2$s  (%3$s, last changed %4$s)',
				(int) $post->ID,
				$post->post_title,
				$post->post_status,
				$post->post_modified
			);
		}

		return self::ok( implode( "\n", $lines ) );
	}

	/**
	 * One draft, as it is actually saved.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	private static function read_draft( $args ) {
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$post    = self::ours( $post_id );

		if ( ! $post ) {
			return self::fail( 'That is not a draft this connection created.' );
		}

		return self::ok(
			sprintf(
				"Title: %1\$s\nStatus: %2\$s\nDescription: %3\$s\n\n%4\$s",
				$post->post_title,
				$post->post_status,
				(string) $post->post_excerpt,
				(string) $post->post_content
			)
		);
	}

	/**
	 * Illustrate a draft before it goes out.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	private static function add_pictures( $args ) {
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$post    = self::ours( $post_id );

		if ( ! $post ) {
			return self::fail( 'That is not a draft this connection created.' );
		}

		if ( ! Blogcraft_Settings::get( 'images_enabled' ) ) {
			return self::fail(
				'Pictures are switched off for this site. The owner turns them on under Settings, Connect a picture service.'
			);
		}

		$topic = isset( $args['topic'] ) ? trim( (string) $args['topic'] ) : '';
		$topic = ( '' === $topic ) ? (string) get_post_meta( $post_id, '_blogcraft_topic', true ) : $topic;
		$topic = ( '' === $topic ) ? $post->post_title : $topic;
		$did   = 0;

		try {
			$did += (int) Blogcraft_Images::attach_featured( $post_id, $post->post_title, $topic );
			$did += (int) Blogcraft_Images::add_section_images( $post_id, self::article_shape( $post ), 3 );
		} catch ( Throwable $e ) {
			return self::fail( 'The picture service could not be reached: ' . $e->getMessage() );
		}

		if ( $did < 1 ) {
			return self::ok( 'Nothing to add — it already has the pictures it is getting.' );
		}

		return self::ok( sprintf( 'Added %d pictures. Read the draft in WordPress to see them.', $did ) );
	}
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

		// Nothing was spent here: the model ran in somebody else's
		// application on their subscription. Saying so is more use
		// than an empty panel that reads as a bug.
		Blogcraft_Usage::by_client( $post_id );

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

		// The topic decides which pictures suit the post and which older
		// posts should point at it, and publishing happens in a later
		// call than the one that knew it.
		if ( isset( $args['topic'] ) && '' !== trim( (string) $args['topic'] ) ) {
			update_post_meta( $post_id, '_blogcraft_topic', sanitize_text_field( (string) $args['topic'] ) );
		}

		if ( isset( $args['seo_title'] ) && '' !== trim( (string) $args['seo_title'] ) ) {
			update_post_meta( $post_id, '_blogcraft_seo_title', sanitize_text_field( (string) $args['seo_title'] ) );
		}

		self::place( $post_id, $args );

		// The two placement choices the app has no parameter for and no
		// business making: whose byline goes on the post, and when it should
		// go out. Both are on the brief form, both were being saved with it,
		// and until now both were read by nothing at all.
		self::place_from_brief( $post_id );

		// The brief has been acted on. Leaving it would hand the next
		// conversation a topic this site has just covered, which is the
		// duplicate find_duplicate exists to prevent.
		Blogcraft_Brief::clear();
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
