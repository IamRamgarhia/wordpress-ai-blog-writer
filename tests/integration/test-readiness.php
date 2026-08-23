<?php
/**
 * Judging the brief, before anything is spent on it.
 *
 * The scorecard judges the finished post, which is too late to change what
 * went into it. Somebody could type six words, press the button, and get
 * exactly the article those six words deserved, with nothing having said why.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Readiness extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		delete_option( 'blogcraft_settings' );
	}

	public function tear_down() {
		delete_option( 'blogcraft_settings' );
		parent::tear_down();
	}

	public function test_a_bare_topic_is_reported_as_a_weak_brief() {
		$state = Blogcraft_Readiness::assess( 'standing desks', '', '' );

		$this->assertLessThan( 50, $state['score'] );
	}

	public function test_a_full_brief_scores_well() {
		Blogcraft_Settings::set( 'voice_niche', 'Office furniture, tested properly.' );
		Blogcraft_Settings::set( 'voice_audience', 'People setting up a home office on a budget.' );
		Blogcraft_Settings::set( 'research_wikipedia', true );

		$state = Blogcraft_Readiness::assess(
			'How to choose a standing desk for a small home office',
			'Compare three price brackets and say which is worth it.',
			'We tested nine desks over four months. The cheapest wobbled above 110cm and three of nine were returned.'
		);

		$this->assertSame( 100, $state['score'] );
	}

	public function test_the_thing_a_model_cannot_produce_weighs_most() {
		// If any other single gap outweighed this one, the panel would be
		// pointing people at the wrong field.
		$state = Blogcraft_Readiness::assess( '', '', '' );

		$weights = array();

		foreach ( $state['items'] as $item ) {
			$weights[ $item['key'] ] = $item['weight'];
		}

		$this->assertSame( max( $weights ), $weights['evidence'] );
	}

	public function test_every_gap_says_what_skipping_it_costs() {
		// "This field is required" is not a reason. Each item has to carry an
		// argument, because nothing here is enforced — it only persuades.
		$state = Blogcraft_Readiness::assess( '', '', '' );

		foreach ( $state['items'] as $item ) {
			$this->assertNotSame( '', trim( $item['label'] ), $item['key'] . ' has no label' );
			$this->assertGreaterThan( 40, strlen( $item['why'] ), $item['key'] . ' does not say what it costs' );
		}
	}

	public function test_a_described_voice_is_only_satisfied_by_both_halves() {
		Blogcraft_Settings::set( 'voice_niche', 'Coffee equipment.' );

		$state = Blogcraft_Readiness::assess( '', '', '' );

		foreach ( $state['items'] as $item ) {
			if ( 'voice' === $item['key'] ) {
				$this->assertFalse( $item['ok'], 'a niche with no audience counted as a described voice' );
			}
		}
	}

	public function test_any_research_source_satisfies_research() {
		Blogcraft_Settings::set( 'research_community', true );

		$state = Blogcraft_Readiness::assess( '', '', '' );

		foreach ( $state['items'] as $item ) {
			if ( 'research' === $item['key'] ) {
				$this->assertTrue( $item['ok'] );
			}
		}
	}

	public function test_a_hosted_provider_with_no_key_is_not_configured() {
		// Every hosted provider has a default address, and an address used to
		// count as proof of setup — so they all reported ready before a key
		// had been pasted in at all.
		Blogcraft_Settings::set( 'provider_type', 'openai' );
		Blogcraft_Settings::set( 'provider_model', 'gpt-4o' );
		Blogcraft_Settings::set( 'provider_api_key', '' );

		$this->assertFalse( Blogcraft_Provider_Registry::is_configured() );
	}

	public function test_a_key_saved_for_another_provider_does_not_count_as_configured() {
		// Keys live in one shared setting, so switching provider leaves the
		// previous one's behind. Counting it meant the checklist said "ready"
		// and the first post failed several stages in on authentication.
		Blogcraft_Settings::set( 'provider_type', 'gemini' );
		Blogcraft_Settings::set( 'provider_model', 'gemini-2.5-flash' );
		Blogcraft_Settings::set( 'provider_api_key', 'a-gemini-key' );
		Blogcraft_Settings::set( 'provider_key_owner', 'gemini' );

		$this->assertTrue( Blogcraft_Provider_Registry::is_configured() );

		Blogcraft_Settings::set( 'provider_type', 'anthropic' );

		$this->assertFalse(
			Blogcraft_Provider_Registry::is_configured(),
			'a key belonging to another provider counted as a working setup'
		);
	}

	public function test_asking_about_an_empty_topic_calls_no_provider() {
		$called = false;

		add_filter(
			'pre_http_request',
			function () use ( &$called ) {
				$called = true;

				return new WP_Error( 'http_request_failed', 'should not happen' );
			},
			10,
			3
		);

		$out = Blogcraft_Readiness::suggest_for( '   ' );

		$this->assertSame( array(), $out['questions'] );
		$this->assertFalse( $called );

		remove_all_filters( 'pre_http_request' );
	}
}
