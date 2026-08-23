<?php
/**
 * Two things the plugin was doing the work for and then throwing away.
 *
 * A meta description was written for every generated post, stored, and
 * measured by the scorecard — and on a site with no SEO plugin it was never
 * emitted, because WordPress outputs no description tag of its own. And the
 * search provider was already returning the questions people actually type,
 * in the same response the sources came from, while the FAQ stage asked the
 * model to invent some instead.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Head_And_Questions extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		delete_option( 'blogcraft_settings' );
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'blogcraft_print_head_meta' );
		delete_option( 'blogcraft_settings' );
		parent::tear_down();
	}

	/**
	 * Render wp_head for a post and return what came out.
	 *
	 * @param int $post_id Post to render.
	 * @return string
	 */
	private function head_for( $post_id ) {
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		Blogcraft_Seo::print_head_meta();

		return (string) ob_get_clean();
	}

	/**
	 * A generated post with a meta description.
	 *
	 * @return int
	 */
	private function generated_post() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'How Cold Brew Coffee Actually Works',
				'post_status'  => 'publish',
				'post_excerpt' => 'Cold brew steeps in cold water for twelve hours, which pulls fewer bitter compounds and is why it tastes rounder.',
			)
		);

		update_post_meta( $post_id, '_blogcraft_generated', 1 );

		return $post_id;
	}

	// ----------------------------------------------------------- head tags.

	public function test_the_meta_description_reaches_the_page() {
		$head = $this->head_for( $this->generated_post() );

		$this->assertStringContainsString( '<meta name="description"', $head );
		$this->assertStringContainsString( 'pulls fewer bitter compounds', $head );
	}

	public function test_sharing_tags_are_emitted() {
		$head = $this->head_for( $this->generated_post() );

		$this->assertStringContainsString( 'property="og:title"', $head );
		$this->assertStringContainsString( 'property="og:description"', $head );
		$this->assertStringContainsString( 'property="og:type" content="article"', $head );
		$this->assertStringContainsString( 'property="og:url"', $head );
		$this->assertStringContainsString( 'name="twitter:card"', $head );
	}

	public function test_a_post_with_no_picture_asks_for_the_small_card() {
		// Claiming the large card without an image gets the post rendered as a
		// bare link anyway, so the small one is the honest request.
		$head = $this->head_for( $this->generated_post() );

		$this->assertStringContainsString( 'content="summary"', $head );
		$this->assertStringNotContainsString( 'og:image', $head );
	}

	public function test_nothing_is_emitted_for_a_post_blogcraft_did_not_write() {
		// Filling in head tags for the whole site is what an SEO plugin is
		// for. This exists so the description Blogcraft itself wrote is not
		// discarded, and quietly becoming a general SEO plugin would be both
		// scope creep and a source of duplicate tags.
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_excerpt' => 'Written by a person, not by this plugin.',
			)
		);

		$this->assertSame( '', $this->head_for( $post_id ) );
	}

	public function test_a_theme_can_turn_the_tags_off() {
		add_filter( 'blogcraft_print_head_meta', '__return_false' );

		$this->assertSame( '', $this->head_for( $this->generated_post() ) );
	}

	public function test_no_canonical_is_emitted() {
		// WordPress core already prints one via rel_canonical(). A second
		// would be a bug, not a feature.
		$this->assertStringNotContainsString( 'rel="canonical"', $this->head_for( $this->generated_post() ) );
	}

	// ------------------------------------------------------- real questions.

	public function test_the_questions_people_actually_ask_are_kept() {
		Blogcraft_Settings::set( 'research_provider', 'serpapi' );
		Blogcraft_Settings::set( 'research_api_key', 'test-key' );

		$this->fake_serpapi(
			array(
				'organic_results'   => array(
					array(
						'link'    => 'https://example.com/cold-brew',
						'title'   => 'Cold brew guide',
						'snippet' => 'Cold brew steeps for twelve hours in cold water.',
					),
				),
				'related_questions' => array(
					array( 'question' => 'Is cold brew stronger than iced coffee?' ),
					array( 'question' => 'How long does cold brew last in the fridge?' ),
					// No question mark: a heading SerpApi filed here, not a question.
					array( 'question' => 'Cold brew ratio chart' ),
					array( 'not_a_question' => 'ignored' ),
				),
			)
		);

		Blogcraft_Research::search( 'cold brew' );

		$this->assertSame(
			array(
				'Is cold brew stronger than iced coffee?',
				'How long does cold brew last in the fridge?',
			),
			Blogcraft_Research::last_questions()
		);
	}

	public function test_the_questions_do_not_leak_between_posts() {
		Blogcraft_Settings::set( 'research_provider', 'serpapi' );
		Blogcraft_Settings::set( 'research_api_key', 'test-key' );

		$this->fake_serpapi(
			array(
				'organic_results'   => array(
					array(
						'link'    => 'https://example.com/a',
						'title'   => 'A',
						'snippet' => 'Something.',
					),
				),
				'related_questions' => array( array( 'question' => 'Is cold brew stronger?' ) ),
			)
		);

		Blogcraft_Research::search( 'cold brew' );
		$this->assertNotSame( array(), Blogcraft_Research::last_questions() );

		// A provider that returns none must leave none behind, or the next
		// post writes an FAQ about the previous post's subject.
		remove_all_filters( 'pre_http_request' );
		Blogcraft_Settings::set( 'research_provider', 'none' );

		Blogcraft_Research::search( 'standing desks' );

		$this->assertSame( array(), Blogcraft_Research::last_questions() );
	}

	public function test_real_questions_reach_the_faq_prompt() {
		$messages = Blogcraft_Prompts::faq(
			'cold brew',
			array( 'The chemistry' ),
			4,
			array( 'Is cold brew stronger than iced coffee?' )
		);

		$user = '';

		foreach ( $messages as $message ) {
			if ( 'user' === $message['role'] ) {
				$user = $message['content'];
			}
		}

		$this->assertStringContainsString( 'People searching for this also asked', $user );
		$this->assertStringContainsString( 'Is cold brew stronger than iced coffee?', $user );
	}

	public function test_the_prompt_is_unchanged_when_there_are_no_real_questions() {
		// Tavily and a self-hosted SearXNG return no such data, so this is the
		// normal case for most setups and must not leave a dangling heading
		// with nothing under it.
		$messages = Blogcraft_Prompts::faq( 'cold brew', array(), 4, array() );

		$user = '';

		foreach ( $messages as $message ) {
			if ( 'user' === $message['role'] ) {
				$user = $message['content'];
			}
		}

		$this->assertStringNotContainsString( 'People searching for this also asked', $user );
	}

	/**
	 * Answer the next HTTP request with a canned SerpApi body.
	 *
	 * @param array $body Response body to return.
	 * @return void
	 */
	private function fake_serpapi( $body ) {
		add_filter(
			'pre_http_request',
			function () use ( $body ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( $body ),
					'headers'  => array(),
				);
			},
			10,
			3
		);
	}
}
