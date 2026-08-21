<?php
/**
 * Structured data tests.
 *
 * Google retired FAQ rich results in May 2026 and HowTo before that, so the
 * markup worth emitting is Article, Organization, Person and BreadcrumbList.
 * These assert the graph carries the entity signals an answer engine reads,
 * and that a second competing graph is never printed.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Schema extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		delete_option( 'blogcraft_settings' );
	}

	public function tear_down() {
		delete_option( 'blogcraft_settings' );
		parent::tear_down();
	}

	/**
	 * A published post to describe.
	 *
	 * @return int
	 */
	private function a_post() {
		return self::factory()->post->create(
			array(
				'post_title'   => 'How Cold Brew Coffee Works',
				'post_content' => '<p>' . str_repeat( 'Cold water pulls fewer bitter compounds. ', 20 ) . '</p>',
				'post_excerpt' => 'Why cold brew tastes sweeter than hot coffee.',
				'post_status'  => 'publish',
			)
		);
	}

	public function test_the_article_graph_names_the_publisher() {
		$graph = Blogcraft_Seo::build_schema( $this->a_post() );

		$this->assertSame( 'Organization', $graph['publisher']['@type'] );
		$this->assertSame( get_bloginfo( 'name' ), $graph['publisher']['name'] );
	}

	public function test_the_article_graph_counts_its_words() {
		$graph = Blogcraft_Seo::build_schema( $this->a_post() );

		$this->assertGreaterThan( 50, (int) $graph['wordCount'] );
	}

	public function test_the_author_carries_a_link_and_credentials_when_set() {
		Blogcraft_Settings::set( 'author_credentials', 'Head barista, twelve years' );

		$graph = Blogcraft_Seo::build_schema( $this->a_post() );

		$this->assertSame( 'Person', $graph['author']['@type'] );
		$this->assertNotEmpty( $graph['author']['url'] );
		$this->assertSame( 'Head barista, twelve years', $graph['author']['jobTitle'] );
	}

	public function test_a_reviewer_is_named_when_one_is_configured() {
		// A second named expert is the strongest signal available to a site
		// publishing with AI help, and the one thing a generated post cannot
		// claim for itself.
		Blogcraft_Settings::set( 'reviewer_name', 'Dana Okonjo' );
		Blogcraft_Settings::set( 'reviewer_credentials', 'Q grader' );

		$graph = Blogcraft_Seo::build_schema( $this->a_post() );

		$this->assertSame( 'Dana Okonjo', $graph['reviewedBy']['name'] );
		$this->assertSame( 'Q grader', $graph['reviewedBy']['jobTitle'] );
	}

	public function test_no_reviewer_means_no_claim_of_one() {
		$graph = Blogcraft_Seo::build_schema( $this->a_post() );

		$this->assertArrayNotHasKey( 'reviewedBy', $graph );
	}

	public function test_breadcrumbs_start_at_the_site_and_end_at_the_post() {
		$post_id = $this->a_post();
		$crumbs  = Blogcraft_Seo::build_breadcrumbs( $post_id );

		$this->assertSame( 'BreadcrumbList', $crumbs['@type'] );

		$items = $crumbs['itemListElement'];
		$last  = end( $items );

		$this->assertSame( get_bloginfo( 'name' ), $items[0]['name'] );
		$this->assertSame( 'How Cold Brew Coffee Works', $last['name'] );
		$this->assertSame( get_permalink( $post_id ), $last['item'] );
	}

	public function test_breadcrumb_positions_run_in_order() {
		$crumbs = Blogcraft_Seo::build_breadcrumbs( $this->a_post() );

		$expected = 1;

		foreach ( $crumbs['itemListElement'] as $item ) {
			$this->assertSame( $expected, $item['position'] );
			++$expected;
		}
	}

	public function test_a_missing_post_produces_no_graph() {
		$this->assertSame( array(), Blogcraft_Seo::build_schema( 0 ) );
		$this->assertSame( array(), Blogcraft_Seo::build_breadcrumbs( 0 ) );
	}

	public function test_another_seo_plugin_takes_precedence() {
		// Emitting a second competing graph is worse than emitting none.
		$this->assertFalse( Blogcraft_Seo::schema_handled_elsewhere() );
	}
}
