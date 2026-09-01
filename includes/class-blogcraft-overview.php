<?php
/**
 * The overview screen.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * The first screen anyone sees, and the one that has to answer three questions.
 *
 * What do I still need to set up, what has this thing actually done, and what
 * needs me right now. The previous version answered none of them: it showed
 * four queue counters and a token total, which tells a new user nothing and a
 * returning user less.
 *
 * Everything here links somewhere. A dashboard that reports a problem without
 * offering the screen that fixes it is just a slower way of worrying.
 */
class Blogcraft_Overview {

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'dicecodes-ai-blog-writer' ) );
		}

		echo '<div class="wrap blogcraft-page">';

		Blogcraft_Nav::render();

		echo '<div class="blogcraft-head">';
		echo '<h1>' . esc_html__( 'Dicecodes AI Blog Writer', 'dicecodes-ai-blog-writer' ) . '</h1>';
		echo '<p>' . esc_html__( 'What is set up, what it has written, and what needs you.', 'dicecodes-ai-blog-writer' ) . '</p>';
		echo '</div>';

		self::render_mode();
		self::render_setup();
		self::render_how();
		self::render_attention();
		self::render_numbers();
		self::render_recent();

		echo '</div>';
	}

	/**
	 * The steps still standing between here and a written post.
	 *
	 * Shown only while something is genuinely outstanding. A permanent
	 * checklist of ticks is decoration.
	 *
	 * @return void
	 */
	private static function render_setup() {
		$steps = self::setup_steps();
		$done  = 0;

		foreach ( $steps as $step ) {
			if ( $step['done'] ) {
				++$done;
			}
		}

		if ( count( $steps ) === $done ) {
			return;
		}

		echo '<section class="blogcraft-card"><header>';
		echo '<h2>' . esc_html__( 'Finish setting up', 'dicecodes-ai-blog-writer' ) . '</h2>';
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: steps completed. 2: total steps. */
					__( '%1$d of %2$d done. Nothing gets written until the first one is.', 'dicecodes-ai-blog-writer' ),
					$done,
					count( $steps )
				)
			)
		);
		echo '</header>';

		echo '<ol class="blogcraft-steps">';

		foreach ( $steps as $step ) {
			printf(
				'<li class="%1$s"><span class="blogcraft-step-mark" aria-hidden="true"></span><span class="blogcraft-step-text"><strong>%2$s</strong><span>%3$s</span></span>%4$s<span class="screen-reader-text">%5$s</span></li>',
				$step['done'] ? 'is-done' : 'is-todo',
				esc_html( $step['title'] ),
				esc_html( $step['detail'] ),
				$step['done'] ? '' : sprintf(
					'<a class="button button-small" href="%1$s">%2$s</a>',
					esc_url( $step['url'] ),
					esc_html( $step['action'] )
				),
				esc_html( $step['done'] ? __( 'Done', 'dicecodes-ai-blog-writer' ) : __( 'Still to do', 'dicecodes-ai-blog-writer' ) )
			);
		}

		echo '</ol>';
		echo '</section>';
	}

	/**
	 * The setup steps, in the order they actually have to happen.
	 *
	 * @return array
	 */
	private static function setup_steps() {
		$blueprint = Blogcraft_Blueprint::get();

		$described = ( '' !== trim( (string) $blueprint['audience_custom'] ) )
			|| ( '' !== trim( (string) $blueprint['niche'] ) );

		$written = self::written_count() > 0;

		// Either answer counts. Research is off until asked for, which is the
		// only honest default when switching it on is what tells Blogcraft it
		// may contact somebody — but that also means a first post is written
		// from memory unless the reader knows the setting exists. So the
		// checklist raises it once, and a deliberate no clears the step just
		// as a yes does.
		$decided = '' !== trim( (string) Blogcraft_Settings::get( 'research_provider' ) );

		foreach ( array_keys( Blogcraft_Research::free_sources() ) as $source ) {
			if ( Blogcraft_Settings::was_chosen( $source ) ) {
				$decided = true;
				break;
			}
		}

		// The first two steps are the whole of the difference between
		// the two ways of working. Telling somebody who chose an AI
		// client to go and add an API key is telling them to undo the
		// choice they just made.
		if ( Blogcraft_Mode::is_client() ) {
			$first = array(
				array(
					'title'  => __( 'Connect an AI client', 'dicecodes-ai-blog-writer' ),
					'detail' => __( 'Paste the address into Claude or ChatGPT and approve it here.', 'dicecodes-ai-blog-writer' ),
					'done'   => ! empty( Blogcraft_Mcp_Auth::all() ) || ! empty( Blogcraft_Mcp_Oauth::clients() ),
					'url'    => admin_url( 'admin.php?page=blogcraft-settings#bc-card-clients' ),
					'action' => __( 'Connect', 'dicecodes-ai-blog-writer' ),
				),
			);
		} else {
			$first = array(
				array(
					'title'  => __( 'Connect a provider', 'dicecodes-ai-blog-writer' ),
					'detail' => __( 'Your key, your account, your bill.', 'dicecodes-ai-blog-writer' ),
					'done'   => Blogcraft_Provider_Registry::is_configured(),
					'url'    => admin_url( 'admin.php?page=blogcraft-settings' ),
					'action' => __( 'Set it up', 'dicecodes-ai-blog-writer' ),
				),
				array(
					'title'  => __( 'Choose what it may read', 'dicecodes-ai-blog-writer' ),
					'detail' => __( 'Nothing is contacted until you say so. Wikipedia and Hacker News need no key.', 'dicecodes-ai-blog-writer' ),
					'done'   => $decided,
					'url'    => admin_url( 'admin.php?page=blogcraft-settings#bc-card-research' ),
					'action' => __( 'Choose', 'dicecodes-ai-blog-writer' ),
				),
			);
		}

		return array_merge(
			$first,
			array(
				array(
					'title'  => __( 'Say who you write for', 'dicecodes-ai-blog-writer' ),
					'detail' => __( 'Without this, posts read like every other tool\'s.', 'dicecodes-ai-blog-writer' ),
					'done'   => $described,
					'url'    => admin_url( 'admin.php?page=blogcraft-blueprint' ),
					'action' => __( 'Describe it', 'dicecodes-ai-blog-writer' ),
				),
				array(
					'title'  => __( 'Write one post', 'dicecodes-ai-blog-writer' ),
					'detail' => Blogcraft_Mode::is_client()
						? __( 'Ask your app: "read my writing rules and write a post about X".', 'dicecodes-ai-blog-writer' )
						: __( 'Read it before you turn anything on a schedule.', 'dicecodes-ai-blog-writer' ),
					'done'   => $written,
					'url'    => Blogcraft_Mode::is_client()
						? admin_url( 'admin.php?page=blogcraft-help' )
						: admin_url( 'admin.php?page=blogcraft-write' ),
					'action' => Blogcraft_Mode::is_client()
						? __( 'How', 'dicecodes-ai-blog-writer' )
						: __( 'Write one', 'dicecodes-ai-blog-writer' ),
				),
			)
		);
	}

	/**
	 * How this site writes, said once, at the top.
	 *
	 * There are two ways of working and nothing outside the settings
	 * screen said which one was in force, so the overview described a
	 * provider setup to sites that had deliberately chosen the other.
	 *
	 * @return void
	 */
	private static function render_mode() {
		if ( ! Blogcraft_Mode::chosen() ) {
			return;
		}

		printf(
			'<div class="bc-mode-now"><span class="bc-mode-tag">%1$s</span><span class="bc-mode-what">%2$s</span><a href="%3$s">%4$s</a></div>',
			esc_html( Blogcraft_Mode::label() ),
			esc_html( Blogcraft_Mode::summary() ),
			esc_url( admin_url( 'admin.php?page=blogcraft-settings' ) ),
			esc_html__( 'Change', 'dicecodes-ai-blog-writer' )
		);
	}

	/**
	 * What using this actually looks like.
	 *
	 * Four steps, always present, folded shut once the setup checklist has
	 * gone. The checklist answers "what have I not done yet" and stops being
	 * useful the moment it is complete; this answers "how do I use this", which
	 * stays useful and is what somebody returning after a fortnight wants.
	 *
	 * @return void
	 */
	private static function render_how() {
		// The first and third steps are the two that differ. Describing a
		// provider setup to a site that deliberately chose the other way,
		// and pointing it at a screen that no longer exists there, is how
		// the explanation ends up contradicting the checklist above it.
		$client = Blogcraft_Mode::is_client();

		$steps = array(
			array(
				$client
					? __( 'Connect an app you already pay for', 'dicecodes-ai-blog-writer' )
					: __( 'Connect a provider, and a picture service', 'dicecodes-ai-blog-writer' ),
				$client
					? __( 'Paste this site\'s address into Claude or ChatGPT and approve the connection here. No key, and nothing billed to you beyond the subscription you have.', 'dicecodes-ai-blog-writer' )
					: __( 'The writing needs a key from an AI provider — yours, billed to you. Pictures come from a separate service, and the one that runs by default needs no key at all.', 'dicecodes-ai-blog-writer' ),
				admin_url( 'admin.php?page=blogcraft-settings' ),
				__( 'Settings', 'dicecodes-ai-blog-writer' ),
			),
			array(
				__( 'Tell it how you write', 'dicecodes-ai-blog-writer' ),
				__( 'Start from a shape — a guide, a listicle, a review — or paste an article you admire and it will measure how that one is built. If you already have posts here, it can read them and describe your voice for you.', 'dicecodes-ai-blog-writer' ),
				admin_url( 'admin.php?page=blogcraft-blueprint' ),
				__( 'How it writes', 'dicecodes-ai-blog-writer' ),
			),
			array(
				$client
					? __( 'Ask it to write, and tell it what only you know', 'dicecodes-ai-blog-writer' )
					: __( 'Give it a topic, and anything only you know', 'dicecodes-ai-blog-writer' ),
				$client
					? __( 'Say: read my writing rules and write a post about X. Give it your own figures and results too — they are used as fact and checked against the finished draft.', 'dicecodes-ai-blog-writer' )
					: __( 'The topic is the only field you have to fill in. The one worth filling in anyway is what you know that nobody else does: your own figures and results are used as fact and checked against the finished draft.', 'dicecodes-ai-blog-writer' ),
				$client
					? admin_url( 'admin.php?page=blogcraft-help' )
					: admin_url( 'admin.php?page=blogcraft-write' ),
				$client
					? __( 'How', 'dicecodes-ai-blog-writer' )
					: __( 'Write a post', 'dicecodes-ai-blog-writer' ),
			),
			array(
				__( 'Read it before anything goes out', 'dicecodes-ai-blog-writer' ),
				__( 'Posts are saved as drafts. Every draft is measured first and anything below your threshold is held for review, so nothing is published that has not been looked at.', 'dicecodes-ai-blog-writer' ),
				admin_url( 'edit.php?post_status=draft&post_type=post' ),
				__( 'Your drafts', 'dicecodes-ai-blog-writer' ),
			),
		);

		// Open while there is still setup to do, shut afterwards.
		$open = ! self::setup_done();

		echo '<section class="blogcraft-card"><header>';
		echo '<h2>' . esc_html__( 'How this works', 'dicecodes-ai-blog-writer' ) . '</h2>';
		echo '<p>' . esc_html__( 'Four steps, once. After that it is a topic and a look at the draft.', 'dicecodes-ai-blog-writer' ) . '</p>';

		printf(
			'<button type="button" class="bc-help-toggle" aria-expanded="%1$s" aria-controls="bc-how"><span aria-hidden="true">?</span>%2$s</button>',
			$open ? 'true' : 'false',
			esc_html__( 'Show the steps', 'dicecodes-ai-blog-writer' )
		);

		echo '</header>';

		printf( '<ol class="blogcraft-steps bc-how" id="bc-how"%s>', $open ? '' : ' hidden' );

		foreach ( $steps as $step ) {
			printf(
				'<li><span class="blogcraft-step-text"><strong>%1$s</strong><span>%2$s</span></span><a class="button" href="%3$s">%4$s</a></li>',
				esc_html( $step[0] ),
				esc_html( $step[1] ),
				esc_url( $step[2] ),
				esc_html( $step[3] )
			);
		}

		echo '</ol>';
		printf(
			'<p class="blogcraft-hint"><a href="%1$s">%2$s</a> <span aria-hidden="true">&middot;</span> <a href="%3$s" target="_blank" rel="noopener noreferrer">%4$s</a></p>',
			esc_url( Blogcraft_Docs::url() ),
			esc_html__( 'Everything else, in detail', 'dicecodes-ai-blog-writer' ),
			esc_url( Blogcraft_Docs::site_url() ),
			esc_html__( 'Guides and walkthroughs', 'dicecodes-ai-blog-writer' )
		);
		echo '</section>';
	}

	/**
	 * Whether every setup step is finished.
	 *
	 * @return bool
	 */
	private static function setup_done() {
		foreach ( self::setup_steps() as $step ) {
			if ( empty( $step['done'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Anything actively wrong or waiting on a person.
	 *
	 * @return void
	 */
	private static function render_attention() {
		$items   = array();
		$waiting = count( Blogcraft_Review::pending_posts() );
		$failed  = (int) Blogcraft_Queue::count_by_status( 'failed' );

		if ( $waiting > 0 ) {
			$items[] = array(
				'text' => sprintf(
					/* translators: %d: number of posts held for review. */
					_n( '%d post is waiting for you to read it.', '%d posts are waiting for you to read them.', $waiting, 'dicecodes-ai-blog-writer' ),
					$waiting
				),
				'url'  => admin_url( 'admin.php?page=blogcraft-review' ),
				'link' => __( 'Review them', 'dicecodes-ai-blog-writer' ),
				'kind' => 'wait',
			);
		}

		if ( $failed > 0 ) {
			$items[] = array(
				'text' => sprintf(
					/* translators: %d: number of failed jobs. */
					_n( '%d post could not be written.', '%d posts could not be written.', $failed, 'dicecodes-ai-blog-writer' ),
					$failed
				),
				'url'  => admin_url( 'admin.php?page=blogcraft-activity' ),
				'link' => __( 'See why', 'dicecodes-ai-blog-writer' ),
				'kind' => 'bad',
			);
		}

		if ( Blogcraft_Cost::over_cap() ) {
			$items[] = array(
				'text' => __( 'The monthly token cap has been reached, so nothing new is being written.', 'dicecodes-ai-blog-writer' ),
				'url'  => admin_url( 'admin.php?page=blogcraft-settings#bc-card-provider' ),
				'link' => __( 'Raise it', 'dicecodes-ai-blog-writer' ),
				'kind' => 'bad',
			);
		}

		if ( Blogcraft_Settings::get( 'autopilot_enabled' ) && array() === Blogcraft_Autopilot::days() ) {
			$items[] = array(
				'text' => __( 'Automatic writing is on, but no days are chosen, so nothing will ever run.', 'dicecodes-ai-blog-writer' ),
				'url'  => admin_url( 'admin.php?page=blogcraft-settings#bc-card-automation' ),
				'link' => __( 'Choose days', 'dicecodes-ai-blog-writer' ),
				'kind' => 'bad',
			);
		}

		// Half of the content search engines cite is under three months old, so
		// a shelf of ageing posts is a standing loss rather than a tidy-up job.
		// Refreshing one keeps the URL and everything it has earned; writing a
		// new post starts from nothing.
		$stale = count( Blogcraft_Refresh::find_stale( null, 20 ) );

		if ( $stale > 0 && ! Blogcraft_Settings::get( 'refresh_enabled' ) ) {
			$items[] = array(
				'text' => sprintf(
					/* translators: %d: number of posts that have not been updated recently. */
					_n(
						'%d post has not been updated in a long time. Refreshing it is usually worth more than writing a new one.',
						'%d posts have not been updated in a long time. Refreshing them is usually worth more than writing new ones.',
						$stale,
						'dicecodes-ai-blog-writer'
					),
					$stale
				),
				'url'  => admin_url( 'admin.php?page=blogcraft-settings#bc-card-automation' ),
				'link' => __( 'Turn refreshing on', 'dicecodes-ai-blog-writer' ),
				'kind' => 'wait',
			);
		}

		// A half-configured picture service is silent: the chain falls through to
		// a free one and the post still gets an image, so nothing looks wrong and
		// the model that was chosen is never used.
		$image_provider = (string) Blogcraft_Settings::get( 'image_provider' );

		if ( array_key_exists( $image_provider, Blogcraft_Image_Models::providers() ) && ! Blogcraft_Image_Models::is_configured() ) {
			$items[] = array(
				'text' => __( 'The picture service you chose is missing a key or a model name, so free images are being used instead.', 'dicecodes-ai-blog-writer' ),
				'url'  => admin_url( 'admin.php?page=blogcraft-settings#bc-card-pictures' ),
				'link' => __( 'Finish it', 'dicecodes-ai-blog-writer' ),
				'kind' => 'wait',
			);
		}

		// Where the crafted title and description end up belongs on this
		// screen, because it happens at publish, on another screen, into
		// fields somebody has to go and look at. It does not belong in
		// this list: a card headed "Needs you" is for things that need
		// doing, and an amber bar beside a sentence saying everything is
		// fine teaches people to stop reading the amber bars.

		if ( empty( $items ) ) {
			return;
		}

		echo '<section class="blogcraft-card"><header>';
		echo '<h2>' . esc_html__( 'Needs you', 'dicecodes-ai-blog-writer' ) . '</h2>';
		echo '</header>';
		echo '<ul class="blogcraft-attention">';

		foreach ( $items as $item ) {
			printf(
				'<li class="is-%1$s">%2$s <a href="%3$s">%4$s</a></li>',
				esc_attr( $item['kind'] ),
				esc_html( $item['text'] ),
				esc_url( $item['url'] ),
				esc_html( $item['link'] )
			);
		}

		echo '</ul>';
		echo '</section>';
	}

	/**
	 * The figures worth glancing at, and what happens next.
	 *
	 * @return void
	 */
	private static function render_numbers() {
		$totals = Blogcraft_Cost::month_totals();

		echo '<section class="blogcraft-card"><header>';
		echo '<h2>' . esc_html__( 'This month', 'dicecodes-ai-blog-writer' ) . '</h2>';
		echo '<p>' . esc_html__( 'Tokens are billed by your provider, not by us.', 'dicecodes-ai-blog-writer' ) . '</p>';
		echo '</header>';

		echo '<ul class="blogcraft-stats">';

		self::tile( (string) number_format_i18n( self::written_count() ), __( 'Posts written', 'dicecodes-ai-blog-writer' ) );
		self::tile( (string) number_format_i18n( (int) Blogcraft_Queue::count_by_status( 'pending' ) ), __( 'Waiting', 'dicecodes-ai-blog-writer' ) );
		self::tile( (string) number_format_i18n( (int) $totals['requests'] ), __( 'Requests', 'dicecodes-ai-blog-writer' ) );
		self::tile(
			self::compact( (int) $totals['prompt'] + (int) $totals['completion'] ),
			__( 'Tokens', 'dicecodes-ai-blog-writer' )
		);

		// Only shown once something has actually been billed for. A permanent
		// zero would be a tile about a feature nobody here is using.
		if ( (int) $totals['images'] > 0 ) {
			self::tile( (string) number_format_i18n( (int) $totals['images'] ), __( 'Paid images', 'dicecodes-ai-blog-writer' ) );
		}

		echo '</ul>';

		$next = self::next_run();

		if ( '' !== $next ) {
			printf( '<p class="blogcraft-hint">%s</p>', esc_html( $next ) );
		}

		// Where the crafted title and description end up. It happens at
		// publish, on another screen, into fields somebody has to go and look
		// at — so without a line saying so, nothing on any screen tells you
		// it happened at all.
		printf( '<p class="blogcraft-hint">%s</p>', esc_html( self::search_line() ) );

		echo '<div class="blogcraft-actions">';

		// A button to a screen this setup does not have is worse than no
		// button: it looks like the way forward and is a dead end.
		if ( Blogcraft_Mode::allows( 'blogcraft-write' ) ) {
			printf(
				'<a class="button button-primary" href="%1$s">%2$s</a>',
				esc_url( admin_url( 'admin.php?page=blogcraft-write' ) ),
				esc_html__( 'Write a post', 'dicecodes-ai-blog-writer' )
			);
		} else {
			printf(
				'<a class="button button-primary" href="%1$s">%2$s</a>',
				esc_url( admin_url( 'admin.php?page=blogcraft-settings' ) ),
				esc_html__( 'Connect an app', 'dicecodes-ai-blog-writer' )
			);
		}
		printf(
			'<a class="button" href="%1$s">%2$s</a>',
			esc_url( admin_url( 'admin.php?page=blogcraft-blueprint' ) ),
			esc_html__( 'How it writes', 'dicecodes-ai-blog-writer' )
		);
		echo '</div>';
		echo '</section>';
	}

	/**
	 * Which SEO plugin the title and description are handed to.
	 *
	 * @return string
	 */
	private static function search_line() {
		$plugin = Blogcraft_Seo::active_seo_plugin();

		if ( '' === $plugin ) {
			return __( 'No SEO plugin found, so the description and the sharing tags are written into the page itself.', 'dicecodes-ai-blog-writer' );
		}

		return sprintf(
			/* translators: %s: name of the SEO plugin that is active. */
			__( 'Search is handled by %s, so each post fills in its title and description fields.', 'dicecodes-ai-blog-writer' ),
			Blogcraft_Seo::seo_plugin_name( $plugin )
		);
	}
	/**
	 * When the next scheduled post is due, in plain words.
	 *
	 * @return string Empty when nothing is scheduled.
	 */
	private static function next_run() {
		if ( ! Blogcraft_Settings::get( 'autopilot_enabled' ) ) {
			return __( 'Automatic writing is off. Posts are written only when you ask for one.', 'dicecodes-ai-blog-writer' );
		}

		$plan = Blogcraft_Autopilot::plan();

		if ( empty( $plan ) ) {
			return __( 'Automatic writing is on, but there are no topics queued for it.', 'dicecodes-ai-blog-writer' );
		}

		$format = get_option( 'date_format', 'M j' ) . ' ' . get_option( 'time_format', 'H:i' );

		return sprintf(
			/* translators: 1: topic. 2: date and time. */
			__( 'Next up: "%1$s", due %2$s.', 'dicecodes-ai-blog-writer' ),
			(string) $plan[0]['topic'],
			wp_date( $format, (int) $plan[0]['when'] )
		);
	}

	/**
	 * The last few posts this plugin wrote, with how they scored.
	 *
	 * @return void
	 */
	private static function render_recent() {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => 5,
				'no_found_rows'  => true,
				'meta_key'       => '_blogcraft_generated', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		echo '<section class="blogcraft-card"><header>';
		echo '<h2>' . esc_html__( 'Recently written', 'dicecodes-ai-blog-writer' ) . '</h2>';
		echo '</header>';

		if ( empty( $posts ) ) {
			echo '<p>' . esc_html__( 'Nothing yet. The first post you queue will appear here.', 'dicecodes-ai-blog-writer' ) . '</p>';
			echo '</section>';

			return;
		}

		echo '<table class="widefat striped blogcraft-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Post', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Score', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Written', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '</tr></thead><tbody>';

		$format = get_option( 'date_format', 'M j, Y' );

		foreach ( $posts as $post ) {
			$score = (int) get_post_meta( $post->ID, '_blogcraft_quality', true );

			echo '<tr>';
			printf(
				'<td><a href="%1$s">%2$s</a></td>',
				esc_url( (string) get_edit_post_link( $post->ID ) ),
				esc_html( get_the_title( $post ) )
			);
			printf(
				'<td><span class="blogcraft-badge is-%1$s">%2$s</span></td>',
				esc_attr( $post->post_status ),
				esc_html( self::status_word( $post->post_status ) )
			);
			printf(
				'<td>%s</td>',
				$score > 0 ? esc_html( sprintf( '%d / 100', $score ) ) : '&mdash;'
			);
			printf( '<td>%s</td>', esc_html( wp_date( $format, get_post_timestamp( $post ) ) ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</section>';
	}

	/**
	 * A post status in the words a person would use.
	 *
	 * @param string $status WordPress status.
	 * @return string
	 */
	private static function status_word( $status ) {
		if ( 'publish' === $status ) {
			return __( 'Live', 'dicecodes-ai-blog-writer' );
		}

		if ( 'pending' === $status ) {
			return __( 'Held for review', 'dicecodes-ai-blog-writer' );
		}

		return __( 'Draft', 'dicecodes-ai-blog-writer' );
	}

	/**
	 * How many posts this plugin has written.
	 *
	 * @return int
	 */
	private static function written_count() {
		$found = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_blogcraft_generated', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		return count( $found );
	}

	/**
	 * Render one stat tile.
	 *
	 * @param string $value Figure.
	 * @param string $label What it counts.
	 * @return void
	 */
	private static function tile( $value, $label ) {
		printf(
			'<li><span class="blogcraft-stat-value">%1$s</span><span class="blogcraft-stat-label">%2$s</span></li>',
			esc_html( $value ),
			esc_html( $label )
		);
	}

	/**
	 * Shorten a large number for display.
	 *
	 * @param int $value Number.
	 * @return string
	 */
	private static function compact( $value ) {
		$value = (int) $value;

		if ( $value < 1000 ) {
			return (string) $value;
		}

		return number_format_i18n( $value / 1000, 1 ) . 'k';
	}
}
