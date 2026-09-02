<?php
/**
 * Whether anything is allowed to come and read what this site publishes.
 *
 * A post can be right in every way the scorecard measures and still be
 * invisible, because the site never let anything fetch it. Two settings
 * decide that and neither of them is in this plugin, so nothing it owned
 * ever mentioned them.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Crawlers extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		Blogcraft_Capabilities::add();
		Blogcraft_Migrator::migrate();

		update_option( 'blog_public', 1 );
		Blogcraft_Crawlers::forget();
	}

	public function tear_down() {
		Blogcraft_Crawlers::forget();
		update_option( 'blog_public', 1 );
		delete_option( 'blogcraft_settings' );
		Blogcraft_Capabilities::remove();

		parent::tear_down();
	}

	/**
	 * Read a robots.txt without going near the network.
	 *
	 * @param string $robots The file as a crawler would receive it.
	 * @return array Agent token => name.
	 */
	private function blocked_by( $robots ) {
		$method = new ReflectionMethod( 'Blogcraft_Crawlers', 'blocked_in' );
		$method->setAccessible( true );

		return (array) $method->invoke( null, $robots );
	}

	public function test_an_open_site_blocks_nobody() {
		$this->assertSame( array(), $this->blocked_by( "User-agent: *\nDisallow:\n" ) );
		$this->assertSame( array(), $this->blocked_by( '' ) );
	}

	public function test_an_ordinary_wordpress_robots_file_blocks_nobody() {
		// What WordPress serves by default. Keeping crawlers out of
		// /wp-admin/ is not keeping them off the site, and reporting it as
		// such would make the check noise on every install.
		$robots = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";

		$this->assertSame( array(), $this->blocked_by( $robots ) );
	}

	public function test_a_crawler_refused_by_name_is_named() {
		$robots = "User-agent: *\nDisallow: /wp-admin/\n\nUser-agent: GPTBot\nDisallow: /\n";

		$blocked = $this->blocked_by( $robots );

		$this->assertArrayHasKey( 'GPTBot', $blocked );
		$this->assertArrayNotHasKey( 'PerplexityBot', $blocked, 'a rule for one crawler was applied to another' );
	}

	public function test_a_blanket_refusal_catches_every_one_of_them() {
		$blocked = $this->blocked_by( "User-agent: *\nDisallow: /\n" );

		$this->assertSame( array_keys( Blogcraft_Crawlers::agents() ), array_keys( $blocked ) );
	}

	public function test_a_group_of_its_own_replaces_the_catch_all_rather_than_adding_to_it() {
		// How a crawler reads this: the most specific group wins outright, so
		// a site that shuts everything out and then lets one back in has let
		// it back in.
		$robots = "User-agent: *\nDisallow: /\n\nUser-agent: ClaudeBot\nAllow: /\nDisallow:\n";

		$blocked = $this->blocked_by( $robots );

		$this->assertArrayNotHasKey( 'ClaudeBot', $blocked, 'a crawler let back in is still reported as blocked' );
		$this->assertArrayHasKey( 'GPTBot', $blocked );
	}

	public function test_agents_sharing_one_group_all_get_its_rules() {
		// Consecutive User-agent lines share what follows them, and reading
		// only the last would let four of the five through unreported.
		$robots = "User-agent: GPTBot\nUser-agent: ClaudeBot\nUser-agent: PerplexityBot\nDisallow: /\n";

		$blocked = $this->blocked_by( $robots );

		$this->assertArrayHasKey( 'GPTBot', $blocked );
		$this->assertArrayHasKey( 'ClaudeBot', $blocked );
		$this->assertArrayHasKey( 'PerplexityBot', $blocked );
	}

	public function test_the_name_is_matched_whatever_case_it_is_written_in() {
		$this->assertArrayHasKey( 'GPTBot', $this->blocked_by( "User-agent: gptbot\nDisallow: /\n" ) );
	}

	public function test_a_comment_is_not_a_rule() {
		$robots = "User-agent: *\n# Disallow: /\nDisallow: /wp-admin/ # keeps them out of the back end\n";

		$this->assertSame( array(), $this->blocked_by( $robots ) );
	}

	public function test_discouraging_search_engines_is_reported_before_anything_else() {
		// WordPress writes the blanket refusal into robots.txt itself when
		// this is on, so parsing the file would report the symptom and send
		// somebody to edit a file that is not where the answer is.
		update_option( 'blog_public', 0 );

		$status = Blogcraft_Crawlers::status();

		$this->assertTrue( $status['discouraged'] );
		$this->assertNotEmpty( $status['blocked'], 'discouraged, and nothing is described as blocked' );

		$this->assertStringContainsString( 'Reading', Blogcraft_Crawlers::line() );
	}

	public function test_an_open_site_has_nothing_to_say() {
		// The line only appears when it is worth appearing. A permanent
		// notice saying everything is fine is furniture by the second visit.
		set_transient(
			Blogcraft_Crawlers::CACHE,
			array(
				'discouraged' => false,
				'blocked'     => array(),
				'known'       => true,
			),
			HOUR_IN_SECONDS
		);

		$this->assertSame( '', Blogcraft_Crawlers::line() );
	}

	public function test_the_line_names_the_assistants_a_reader_would_recognise() {
		set_transient(
			Blogcraft_Crawlers::CACHE,
			array(
				'discouraged' => false,
				'blocked'     => array( 'GPTBot' => 'ChatGPT' ),
				'known'       => true,
			),
			HOUR_IN_SECONDS
		);

		$line = Blogcraft_Crawlers::line();

		// "GPTBot" is the answer to a question nobody asked. Which assistant
		// will not be citing you is the one they did.
		$this->assertStringContainsString( 'ChatGPT', $line );
		$this->assertStringNotContainsString( 'GPTBot', $line );
	}

	public function test_a_site_that_cannot_be_read_claims_nothing() {
		// An unreachable robots.txt is not an open site and must not be
		// reported as one, nor as a blocked one.
		$method = new ReflectionMethod( 'Blogcraft_Crawlers', 'read_robots' );
		$method->setAccessible( true );

		add_filter( 'pre_http_request', array( $this, 'refuse' ) );
		$out = $method->invoke( null );
		remove_filter( 'pre_http_request', array( $this, 'refuse' ) );

		$this->assertNull( $out );
	}

	/**
	 * Stand in for a site that will not answer.
	 *
	 * @return WP_Error
	 */
	public function refuse() {
		return new WP_Error( 'http_request_failed', 'nope' );
	}

	public function test_the_answer_is_kept_so_a_screen_render_is_not_a_web_request() {
		$calls = 0;

		$counter = function ( $pre ) use ( &$calls ) {
			++$calls;

			return array(
				'headers'  => array(),
				'body'     => "User-agent: *\nDisallow:\n",
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $counter );

		Blogcraft_Crawlers::status();
		Blogcraft_Crawlers::status();
		Blogcraft_Crawlers::status();

		remove_filter( 'pre_http_request', $counter );

		$this->assertSame( 1, $calls, 'robots.txt was fetched again for an answer already held' );
	}

	public function test_the_overview_says_so_where_somebody_will_see_it() {
		update_option( 'blog_public', 0 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		Blogcraft_Overview::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'stay away', $html );

		wp_set_current_user( 0 );
	}
}
