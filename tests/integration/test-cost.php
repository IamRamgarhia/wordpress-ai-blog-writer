<?php
/**
 * Token accounting tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Cost extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		Blogcraft_Cost::reset();
	}

	public function tear_down() {
		Blogcraft_Cost::reset();
		delete_option( 'blogcraft_settings' );
		parent::tear_down();
	}

	public function test_totals_start_at_zero() {
		$this->assertSame(
			array(
				'prompt'     => 0,
				'completion' => 0,
				'requests'   => 0,
			),
			Blogcraft_Cost::month_totals()
		);
	}

	public function test_record_accumulates() {
		Blogcraft_Cost::record( 'openai', 'gpt', 10, 5 );
		Blogcraft_Cost::record( 'openai', 'gpt', 3, 2 );

		$totals = Blogcraft_Cost::month_totals();
		$this->assertSame( 13, $totals['prompt'] );
		$this->assertSame( 7, $totals['completion'] );
		$this->assertSame( 2, $totals['requests'] );
	}

	public function test_negative_token_counts_are_clamped_to_zero() {
		Blogcraft_Cost::record( 'openai', 'gpt', -50, -1 );

		$totals = Blogcraft_Cost::month_totals();
		$this->assertSame( 0, $totals['prompt'] );
		$this->assertSame( 0, $totals['completion'] );
		$this->assertSame( 1, $totals['requests'] );
	}

	public function test_unknown_month_returns_zeroes() {
		Blogcraft_Cost::record( 'openai', 'gpt', 10, 5 );

		$totals = Blogcraft_Cost::month_totals( '1999-01' );
		$this->assertSame( 0, $totals['prompt'] );
	}

	public function test_reset_clears_everything() {
		Blogcraft_Cost::record( 'openai', 'gpt', 10, 5 );
		Blogcraft_Cost::reset();

		$this->assertSame( 0, Blogcraft_Cost::month_totals()['requests'] );
	}

	public function test_over_cap_is_false_when_cap_is_zero() {
		Blogcraft_Settings::set( 'monthly_token_cap', 0 );
		Blogcraft_Cost::record( 'openai', 'gpt', 100000, 100000 );

		$this->assertFalse( Blogcraft_Cost::over_cap() );
	}

	public function test_over_cap_is_false_below_the_cap() {
		Blogcraft_Settings::set( 'monthly_token_cap', 100 );
		Blogcraft_Cost::record( 'openai', 'gpt', 40, 40 );

		$this->assertFalse( Blogcraft_Cost::over_cap() );
	}

	public function test_over_cap_is_true_at_the_cap() {
		Blogcraft_Settings::set( 'monthly_token_cap', 100 );
		Blogcraft_Cost::record( 'openai', 'gpt', 60, 40 );

		$this->assertTrue( Blogcraft_Cost::over_cap() );
	}

	public function test_textarea_setting_preserves_newlines() {
		$template = "{\n  \"prompt\": \"{{prompt}}\"\n}";
		Blogcraft_Settings::set( 'provider_request_template', $template );

		$this->assertStringContainsString( "\n", Blogcraft_Settings::get( 'provider_request_template' ) );
	}
}
