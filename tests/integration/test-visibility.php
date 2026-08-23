<?php
/**
 * Making the work visible: to readers, to crawlers, and to the outline.
 *
 * Four things that were either invisible or judged by the wrong ruler. The
 * expertise markup spoke only to parsers; the outline was planned without
 * looking at what already ranks; nothing told the crawlers that take being
 * told; and a rewrite of an existing post was scored by a different, older
 * system than the one that had published it.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Visibility extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		delete_option( 'blogcraft_settings' );
		delete_option( Blogcraft_Blueprint::OPTION );
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'blogcraft_show_author_box' );
		delete_option( 'blogcraft_settings' );
		delete_option( Blogcraft_Blueprint::OPTION );
		parent::tear_down();
	}

	/**
	 * A generated post by an author with a filled-in profile.
	 *
	 * @return array array( post_id, author_id )
	 */
	private function post_with_author() {
		$author_id = self::factory()->user->create(
			array(
				'role'        => 'author',
				'display_name' => 'Ada Fielding',
				'description'  => 'Fifteen years pulling espresso, six of them running a roastery.',
				'user_url'     => 'https://example.com/ada',
			)
		);

		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => $author_id,
				'post_title'  => 'How Cold Brew Works',
			)
		);

		update_post_meta( $post_id, '_blogcraft_generated', 1 );

		return array( $post_id, $author_id );
	}

	// ------------------------------------------------------- the byline box.

	public function test_the_byline_names_the_author_and_shows_their_bio() {
		list( $post_id ) = $this->post_with_author();

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$html = Blogcraft_Seo::append_author_box( 'The article.' );

		$this->assertStringContainsString( 'Ada Fielding', $html );
		$this->assertStringContainsString( 'running a roastery', $html );
		$this->assertStringContainsString( 'rel="author"', $html );
	}

	public function test_the_byline_links_to_profiles_elsewhere() {
		// sameAs in the markup and a visible link are the same claim made
		// twice, which is the point: one is for parsers, one is for people.
		list( $post_id, $author_id ) = $this->post_with_author();

		$this->assertContains( 'https://example.com/ada', Blogcraft_Seo::author_profiles( $author_id ) );

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$this->assertStringContainsString( 'example.com/ada', Blogcraft_Seo::append_author_box( 'The article.' ) );
	}

	public function test_the_byline_is_left_off_posts_blogcraft_did_not_write() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$this->assertSame( 'The article.', Blogcraft_Seo::append_author_box( 'The article.' ) );
	}

	public function test_a_theme_can_turn_the_byline_off() {
		list( $post_id ) = $this->post_with_author();

		add_filter( 'blogcraft_show_author_box', '__return_false' );

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$this->assertSame( 'The article.', Blogcraft_Seo::append_author_box( 'The article.' ) );
	}

	public function test_profile_links_that_are_not_addresses_are_left_out() {
		// Contact-method fields hold handles as often as URLs, and a bare
		// handle is not something sameAs can point at.
		$author_id = self::factory()->user->create( array( 'user_url' => '' ) );
		update_user_meta( $author_id, 'twitter', '@somebody' );

		$this->assertSame( array(), Blogcraft_Seo::author_profiles( $author_id ) );
	}

	// --------------------------------------------------- reading the rivals.

	public function test_competitor_headings_are_read_from_the_ranking_pages() {
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => '<html><body>'
						. '<nav><h2>Site navigation menu</h2></nav>'
						. '<h2>How cold brew extraction actually works</h2>'
						. '<h2>Short</h2>'
						. '<h2>Choosing a grind size for cold brew</h2>'
						. '<footer><h2>Related posts you might like</h2></footer>'
						. '</body></html>',
				);
			},
			10,
			3
		);

		$found = Blogcraft_Research::competitor_headings(
			array( array( 'url' => 'https://example.com/cold-brew' ) ),
			1
		);

		$this->assertContains( 'How cold brew extraction actually works', $found );
		$this->assertContains( 'Choosing a grind size for cold brew', $found );

		// Furniture and stubs are not sections.
		$this->assertNotContains( 'Site navigation menu', $found );
		$this->assertNotContains( 'Related posts you might like', $found );
		$this->assertNotContains( 'Short', $found );
	}

	public function test_the_outline_prompt_is_told_what_is_already_covered() {
		$messages = Blogcraft_Prompts::outline(
			'cold brew',
			array(),
			'',
			array( 'How cold brew extraction actually works' )
		);

		$user = '';

		foreach ( $messages as $message ) {
			if ( 'user' === $message['role'] ) {
				$user = $message['content'];
			}
		}

		$this->assertStringContainsString( 'How cold brew extraction actually works', $user );
		$this->assertStringContainsString( 'ground already covered', $user );
	}

	public function test_the_outline_prompt_is_unchanged_without_rival_headings() {
		$messages = Blogcraft_Prompts::outline( 'cold brew', array(), '', array() );

		$user = '';

		foreach ( $messages as $message ) {
			if ( 'user' === $message['role'] ) {
				$user = $message['content'];
			}
		}

		$this->assertStringNotContainsString( 'ground already covered', $user );
	}

	// ------------------------------------------------------------ IndexNow.

	public function test_nothing_is_announced_until_it_is_switched_on() {
		$called = false;

		add_filter(
			'pre_http_request',
			function () use ( &$called ) {
				$called = true;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{}',
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertFalse( Blogcraft_Indexnow::submit( $post_id ) );
		$this->assertFalse( $called, 'a URL was announced without anyone asking for it' );
	}

	public function test_a_draft_is_never_announced() {
		// Submitting an unpublished post asks a crawler to come and read a 404.
		Blogcraft_Settings::set( 'indexnow_enabled', true );

		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->assertFalse( Blogcraft_Indexnow::submit( $post_id ) );
	}

	public function test_the_key_is_generated_once_and_kept() {
		$first = Blogcraft_Indexnow::key();

		$this->assertNotSame( '', $first );
		$this->assertSame( $first, Blogcraft_Indexnow::key(), 'the key changed between calls' );
		$this->assertStringContainsString( $first, Blogcraft_Indexnow::key_url() );
	}

	// ------------------------------------------------- refresh, judged fairly.

	public function test_a_post_too_long_to_show_whole_is_refused_not_trimmed() {
		// The rewrite replaces the entire post, so showing the model half of
		// one and saving what comes back deletes the half it never saw.
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => str_repeat( 'word ', 8000 ),
			)
		);

		update_post_meta( $post_id, '_blogcraft_generated', 1 );

		$job_id = Blogcraft_Refresh::enqueue_post( $post_id );
		$this->assertGreaterThan( 0, $job_id );

		$job = Blogcraft_Queue::claim();

		$this->expectException( 'RuntimeException' );
		Blogcraft_Refresh::stage_rewrite( $job );
	}

	public function test_a_rewrite_below_the_threshold_leaves_the_original_alone() {
		Blogcraft_Settings::set( 'quality_threshold', 99 );

		$original = '<!-- wp:paragraph --><p>The original text, still working.</p><!-- /wp:paragraph -->';

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => $original,
			)
		);

		update_post_meta( $post_id, '_blogcraft_generated', 1 );

		$job_id = Blogcraft_Queue::enqueue(
			Blogcraft_Refresh::NAME,
			'save',
			array(
				'post_id' => $post_id,
				'topic'   => 'anything',
				'article' => array(
					'intro'    => 'Thin.',
					'sections' => array( array( 'heading' => 'One', 'paragraphs' => array( 'Also thin.' ) ) ),
				),
			)
		);

		Blogcraft_Refresh::stage_save( Blogcraft_Queue::claim() );

		$this->assertSame(
			$original,
			get_post( $post_id )->post_content,
			'a rewrite that failed the bar overwrote the post anyway'
		);
	}
}
