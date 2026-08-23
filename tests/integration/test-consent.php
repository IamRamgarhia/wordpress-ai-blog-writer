<?php
/**
 * Nothing reaches a third party until somebody has asked for it.
 *
 * Guideline 7 of the plugin directory is that a plugin may not contact an
 * external server without explicit consent. Pasting a provider key is consent
 * for that provider. It is not consent for a reference work, a forum, or a
 * picture service the reader never chose — and a default of true is not a
 * choice, it is a decision made on their behalf before they arrived.
 *
 * These are here because defaults drift. Someone flips one to true to make a
 * demo look better, the change is one word, and it reads as harmless in a diff.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Consent extends WP_UnitTestCase {

	/**
	 * Every outbound request seen during a test.
	 *
	 * @var array
	 */
	private $calls = array();

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		delete_option( 'blogcraft_settings' );

		$this->calls = array();

		add_filter( 'pre_http_request', array( $this, 'record' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'record' ), 10 );
		delete_option( 'blogcraft_settings' );
		parent::tear_down();
	}

	/**
	 * Note an outbound request and answer it without letting it leave.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request arguments.
	 * @param string $url  Address being called.
	 * @return array
	 */
	public function record( $pre, $args, $url ) {
		$this->calls[] = $url;

		return array(
			'headers'  => array(),
			'body'     => '{}',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
		);
	}

	// -------------------------------------------------------------- defaults.

	public function test_no_research_source_is_on_before_anyone_asks() {
		foreach ( array_keys( Blogcraft_Research::free_sources() ) as $key ) {
			$this->assertFalse(
				(bool) Blogcraft_Settings::get( $key ),
				$key . ' contacts somebody on a fresh install'
			);
		}
	}

	public function test_pictures_are_off_before_anyone_asks() {
		// Switching pictures on is how a reader picks an image service, so the
		// switch is the consent. On by default, the first post fetches a
		// picture from a company nobody chose.
		$this->assertFalse( (bool) Blogcraft_Settings::get( 'images_enabled' ) );
	}

	// ------------------------------------------------------------ behaviour.

	public function test_a_fresh_install_calls_nobody_for_research() {
		Blogcraft_Research::free_material( 'espresso machines' );

		$this->assertSame(
			array(),
			$this->calls,
			'a fresh install called: ' . implode( ', ', $this->calls )
		);
	}

	public function test_turning_a_source_on_is_what_makes_the_call() {
		// The other half of the guarantee. A default of false is only honest if
		// the switch still works, or this test would pass on a broken feature.
		Blogcraft_Settings::set( 'research_wikipedia', true );

		Blogcraft_Research::free_material( 'espresso machines' );

		$this->assertNotSame( array(), $this->calls, 'the Wikipedia switch does nothing' );

		foreach ( $this->calls as $url ) {
			$this->assertStringContainsString( 'wikipedia.org', $url, 'unexpected call to ' . $url );
		}
	}

	public function test_the_community_source_no_longer_reaches_reddit() {
		// Reddit wants a registered application for automated reading, and
		// refuses anonymous requests from datacentre addresses, which is most
		// shared hosting. It was both an exposure and a source that quietly
		// returned nothing for most of the people who switched it on.
		Blogcraft_Settings::set( 'research_community', true );

		Blogcraft_Research::free_material( 'espresso machines' );

		foreach ( $this->calls as $url ) {
			$this->assertStringNotContainsString( 'reddit.com', $url );
		}
	}
}
