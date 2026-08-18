<?php
/**
 * Provider registry tests.
 *
 * @package Blogcraft
 */

/**
 * Stub provider that always returns a canned response, counting calls so
 * fallback tests can prove later providers are never invoked once one
 * succeeds.
 */
class Blogcraft_Test_Counting_Provider extends Blogcraft_Provider {

	/**
	 * Number of times complete() has been called.
	 *
	 * @var int
	 */
	public $call_count = 0;

	/**
	 * Canned response to return.
	 *
	 * @var Blogcraft_Provider_Response
	 */
	private $canned;

	/**
	 * @param Blogcraft_Provider_Response $canned Response to return from complete().
	 */
	public function __construct( Blogcraft_Provider_Response $canned ) {
		parent::__construct( array() );
		$this->canned = $canned;
	}

	public function id() {
		return 'test-counting';
	}

	public function label() {
		return 'Test Counting Provider';
	}

	public function complete( $messages, $options = array() ) {
		$this->call_count++;
		return $this->canned;
	}

	public function list_models() {
		return array();
	}
}

class Test_Blogcraft_Provider_Registry extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	public function test_types_contains_all_four_ids() {
		$types = Blogcraft_Provider_Registry::types();

		$this->assertArrayHasKey( 'openai', $types );
		$this->assertArrayHasKey( 'gemini', $types );
		$this->assertArrayHasKey( 'anthropic', $types );
		$this->assertArrayHasKey( 'custom', $types );
	}

	public function test_make_returns_correct_class_per_type() {
		$this->assertInstanceOf( 'Blogcraft_Provider_Openai', Blogcraft_Provider_Registry::make( 'openai' ) );
		$this->assertInstanceOf( 'Blogcraft_Provider_Gemini', Blogcraft_Provider_Registry::make( 'gemini' ) );
		$this->assertInstanceOf( 'Blogcraft_Provider_Anthropic', Blogcraft_Provider_Registry::make( 'anthropic' ) );
		$this->assertInstanceOf( 'Blogcraft_Provider_Custom', Blogcraft_Provider_Registry::make( 'custom' ) );
	}

	public function test_make_unknown_type_returns_null_not_fatal() {
		$this->assertNull( Blogcraft_Provider_Registry::make( 'nonsense' ) );
	}

	public function test_make_passes_config_through() {
		$provider = Blogcraft_Provider_Registry::make( 'openai', array( 'model' => 'gpt-test' ) );
		$this->assertInstanceOf( 'Blogcraft_Provider_Openai', $provider );
	}

	public function test_from_settings_builds_provider_from_stored_settings() {
		Blogcraft_Settings::set( 'provider_type', 'openai' );
		Blogcraft_Settings::set( 'provider_base_url', 'https://example.test/v1' );
		Blogcraft_Settings::set( 'provider_api_key', 'sk-abc' );
		Blogcraft_Settings::set( 'provider_model', 'gpt-test' );

		$provider = Blogcraft_Provider_Registry::from_settings();

		$this->assertInstanceOf( 'Blogcraft_Provider_Openai', $provider );
	}

	public function test_from_settings_returns_null_when_provider_type_unknown() {
		Blogcraft_Settings::set( 'provider_type', 'bogus-type' );

		$this->assertNull( Blogcraft_Provider_Registry::from_settings() );
	}

	public function test_fallback_returns_first_success() {
		$failing = new Blogcraft_Provider_Response();
		$failing->error = 'nope';
		$success = new Blogcraft_Provider_Response();
		$success->text = 'ok';

		$p1 = new Blogcraft_Test_Counting_Provider( $failing );
		$p2 = new Blogcraft_Test_Counting_Provider( $success );

		$response = Blogcraft_Provider_Registry::complete_with_fallback( array( $p1, $p2 ), array() );

		$this->assertFalse( $response->is_error() );
		$this->assertSame( 'ok', $response->text );
	}

	public function test_fallback_returns_last_error_when_all_fail() {
		$err1 = new Blogcraft_Provider_Response();
		$err1->error = 'first error';
		$err2 = new Blogcraft_Provider_Response();
		$err2->error = 'second error';

		$p1 = new Blogcraft_Test_Counting_Provider( $err1 );
		$p2 = new Blogcraft_Test_Counting_Provider( $err2 );

		$response = Blogcraft_Provider_Registry::complete_with_fallback( array( $p1, $p2 ), array() );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'second error', $response->error );
	}

	public function test_fallback_does_not_call_later_providers_after_success() {
		$success = new Blogcraft_Provider_Response();
		$success->text = 'ok';
		$never = new Blogcraft_Provider_Response();
		$never->error = 'should never be seen';

		$p1 = new Blogcraft_Test_Counting_Provider( $success );
		$p2 = new Blogcraft_Test_Counting_Provider( $never );

		Blogcraft_Provider_Registry::complete_with_fallback( array( $p1, $p2 ), array() );

		$this->assertSame( 1, $p1->call_count );
		$this->assertSame( 0, $p2->call_count );
	}

	public function test_fallback_with_empty_array_returns_error_response() {
		$response = Blogcraft_Provider_Registry::complete_with_fallback( array(), array() );

		$this->assertTrue( $response->is_error() );
		$this->assertNotSame( '', $response->error );
	}
}
