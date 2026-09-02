<?php
/**
 * A second copy of the article markup, printed by something that is not us.
 *
 * The plugin stands down for the SEO plugins it can name, because each one
 * announces itself with a constant. A theme announces nothing, and a great
 * many themes print their own BlogPosting into the head — so on those sites
 * every post went out carrying two Article blocks describing the same page.
 *
 * Found on a real site: the theme emitted BlogPosting and BreadcrumbList,
 * the plugin emitted its own, and nothing on any screen said so. The two
 * were only distinguishable by their JSON escaping.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Schema_Doubled extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		Blogcraft_Migrator::migrate();

		delete_option( 'blogcraft_settings' );
		delete_transient( Blogcraft_Schema_Watch::CACHE );
	}

	public function tear_down() {
		delete_option( 'blogcraft_settings' );
		delete_transient( Blogcraft_Schema_Watch::CACHE );

		parent::tear_down();
	}

	/**
	 * One JSON-LD block, written the way a page writes it.
	 *
	 * @param string $json The block body.
	 * @return string
	 */
	private function block( $json ) {
		return '<script type="application/ld+json">' . $json . '</script>';
	}

	/**
	 * What this plugin prints, escaping and all.
	 *
	 * @return string
	 */
	private function ours() {
		return $this->block( '{"@context":"https:\/\/schema.org","@type":"BlogPosting","headline":"How Cold Brew Works"}' );
	}

	/**
	 * What the theme on the site this was found on prints.
	 *
	 * @return string
	 */
	private function the_theme() {
		return $this->block( '{"@context":"https://schema.org","@type":"BlogPosting","headline":"How Cold Brew Works"}' )
			. $this->block( '{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[]}' );
	}

	public function test_two_article_blocks_on_one_page_are_counted_as_two() {
		// The exact shape found on the site: theme first, ours after, the
		// two of them distinguishable only by their slash escaping.
		$page = '<head>' . $this->the_theme() . $this->ours() . '</head>';

		$this->assertSame( 2, Blogcraft_Schema_Watch::count_articles( $page ) );
	}

	public function test_our_markup_alone_reads_as_one() {
		$this->assertSame( 1, Blogcraft_Schema_Watch::count_articles( '<head>' . $this->ours() . '</head>' ) );
	}

	public function test_breadcrumbs_are_not_an_article() {
		// A page can carry any number of these without anything being wrong.
		$page = $this->block( '{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[]}' )
			. $this->block( '{"@type":"Organization","name":"A Site"}' )
			. $this->block( '{"@type":"WebSite","name":"A Site"}' );

		$this->assertSame( 0, Blogcraft_Schema_Watch::count_articles( $page ) );
	}

	public function test_an_article_wrapped_in_a_graph_is_found() {
		// How Yoast and Rank Math both write it. A check that only looked at
		// the top-level @type would report no duplicate on the majority of
		// sites that have one.
		$page = $this->block( '{"@context":"https://schema.org","@graph":[{"@type":"WebSite"},{"@type":"Article","headline":"x"}]}' );

		$this->assertSame( 1, Blogcraft_Schema_Watch::count_articles( $page ) );
	}

	public function test_a_type_given_as_a_list_is_found() {
		$page = $this->block( '{"@type":["BlogPosting","Article"],"headline":"x"}' );

		$this->assertSame( 1, Blogcraft_Schema_Watch::count_articles( $page ) );
	}

	public function test_a_bare_list_of_blocks_is_found() {
		$page = $this->block( '[{"@type":"Organization"},{"@type":"NewsArticle","headline":"x"}]' );

		$this->assertSame( 1, Blogcraft_Schema_Watch::count_articles( $page ) );
	}

	public function test_markup_that_is_not_json_is_ignored_rather_than_fatal() {
		$page = $this->block( 'not json at all {' ) . $this->ours();

		$this->assertSame( 1, Blogcraft_Schema_Watch::count_articles( $page ) );
	}

	public function test_single_quoted_type_attributes_are_read() {
		$page = "<script type='application/ld+json'>" . '{"@type":"BlogPosting"}' . '</script>';

		$this->assertSame( 1, Blogcraft_Schema_Watch::count_articles( $page ) );
	}

	public function test_the_plugin_prints_structured_data_unless_told_not_to() {
		// The default has to stay on. Most themes emit nothing, and a site
		// that quietly lost its Article on update would lose the rich result
		// with it and never be told why.
		$this->assertTrue( (bool) Blogcraft_Settings::get( 'schema_enabled' ) );
		$this->assertFalse( Blogcraft_Seo::schema_handled_elsewhere() );
	}

	public function test_switching_it_off_stands_the_plugin_down() {
		Blogcraft_Settings::set( 'schema_enabled', false );

		$this->assertTrue(
			Blogcraft_Seo::schema_handled_elsewhere(),
			'the switch is ignored, so a site whose theme emits its own Article cannot stop the second copy'
		);
	}

	public function test_the_guard_knows_more_than_four_seo_plugins() {
		// The four it knew are the four biggest, which is not the same as
		// the four that exist.
		$source = (string) file_get_contents( BLOGCRAFT_PATH . 'includes/class-blogcraft-seo.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		foreach (
			array(
				'THE_SEO_FRAMEWORK_VERSION',
				'SLIM_SEO_VER',
				'SQ_VERSION',
				'SSP_VERSION',
				'WP_SCHEMA_PRO_VER',
				'SASWP_VERSION',
			) as $constant
		) {
			$this->assertStringContainsString(
				$constant,
				$source,
				$constant . ' is not recognised, so that plugin\'s users get two copies'
			);
		}
	}

	public function test_nothing_is_claimed_when_the_page_could_not_be_read() {
		// A site behind basic auth, or one whose loopback is blocked, must
		// report "do not know" rather than "all clear" — a false all-clear
		// is how a real duplicate stays hidden.
		$status = Blogcraft_Schema_Watch::status( true );

		if ( ! $status['known'] ) {
			$this->assertSame( 0, $status['articles'] );
			$this->assertSame( '', Blogcraft_Schema_Watch::line() );
		}

		$this->assertIsBool( $status['known'] );
	}

	public function test_the_warning_stays_silent_when_there_is_only_one_copy() {
		set_transient(
			Blogcraft_Schema_Watch::CACHE,
			array(
				'known'    => true,
				'articles' => 1,
				'ours'     => true,
				'url'      => 'https://example.com/a-post/',
			),
			60
		);

		$this->assertSame( '', Blogcraft_Schema_Watch::line() );
		$this->assertFalse( Blogcraft_Schema_Watch::is_doubled() );
	}

	public function test_the_warning_names_the_switch_it_wants_flipped() {
		set_transient(
			Blogcraft_Schema_Watch::CACHE,
			array(
				'known'    => true,
				'articles' => 2,
				'ours'     => true,
				'url'      => 'https://example.com/a-post/',
			),
			60
		);

		$line = Blogcraft_Schema_Watch::line();

		$this->assertNotSame( '', $line );
		$this->assertTrue( Blogcraft_Schema_Watch::is_doubled() );

		// A warning that does not say what to do about it is a worry, not a
		// fix. The words here have to match the label on the settings screen.
		$this->assertStringContainsString( 'Add search-engine structured data to each post', $line );
	}

	public function test_a_duplicate_that_is_not_ours_says_so() {
		set_transient(
			Blogcraft_Schema_Watch::CACHE,
			array(
				'known'    => true,
				'articles' => 2,
				'ours'     => false,
				'url'      => 'https://example.com/a-post/',
			),
			60
		);

		$line = Blogcraft_Schema_Watch::line();

		$this->assertStringContainsString( 'not coming from here', $line );
	}

	public function test_the_label_on_the_settings_screen_is_the_one_quoted() {
		// Pins the two together: the warning quotes the label, so renaming
		// the label without renaming the quote sends people looking for a
		// control that is not there.
		ob_start();
		Blogcraft_Settings::set( 'setup_path', Blogcraft_Mode::API );
		Blogcraft_Connection::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Add search-engine structured data to each post', $html );
		$this->assertStringContainsString( 'name="schema_enabled"', $html );
	}

	public function test_saving_the_settings_forgets_the_cached_answer() {
		set_transient(
			Blogcraft_Schema_Watch::CACHE,
			array(
				'known'    => true,
				'articles' => 2,
				'ours'     => true,
				'url'      => 'https://example.com/a-post/',
			),
			60
		);

		Blogcraft_Schema_Watch::forget();

		$this->assertFalse( get_transient( Blogcraft_Schema_Watch::CACHE ) );
	}
}
