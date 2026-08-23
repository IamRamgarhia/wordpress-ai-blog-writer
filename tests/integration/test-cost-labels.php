<?php
/**
 * Every provider says whether it costs money.
 *
 * "Which of these will bill me" is the first question anyone picking from a
 * list of fifteen providers has, and the answer used to be invisible: the
 * labels named the company and the model family and stopped there, so the
 * only way to find out was to pick one and wait for an invoice.
 *
 * These tests pin the labelling rather than the wording, so the copy stays
 * free to change — what they will not let through is a new provider added to
 * the catalogue with no cost signal at all, which is exactly the way this
 * gap would quietly come back.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Cost_Labels extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( 'blogcraft_settings' );
	}

	public function tear_down() {
		delete_option( 'blogcraft_settings' );
		parent::tear_down();
	}

	/**
	 * Whether a label carries any statement about cost.
	 *
	 * Deliberately loose: it accepts "free", "free tier", "some free" and
	 * "paid" in any position, because the point is that a claim was made, not
	 * that it was made in a particular phrasing.
	 *
	 * @param string $label Provider label.
	 * @return bool
	 */
	private function states_a_cost( $label ) {
		$label = strtolower( (string) $label );

		return ( false !== strpos( $label, 'free' ) ) || ( false !== strpos( $label, 'paid' ) );
	}

	// ------------------------------------------------------------- writing.

	public function test_every_text_provider_says_whether_it_costs_money() {
		foreach ( Blogcraft_Provider_Registry::types() as $id => $label ) {
			// The custom endpoint is the one honest exception: it is whatever
			// address the reader typed in, and this plugin has no way to know
			// what that charges. Claiming either way would be a guess.
			if ( 'custom' === $id ) {
				continue;
			}

			$this->assertTrue(
				$this->states_a_cost( $label ),
				$id . ' does not say whether it is free or paid: "' . $label . '"'
			);
		}
	}

	public function test_the_local_runtimes_are_marked_free() {
		// These run on the reader's own machine and contact nobody, so they
		// are the only two that are free with no qualifier at all — worth
		// pinning separately, because they are the answer for someone who
		// wants to spend nothing and the label is how they find that out.
		$types = Blogcraft_Provider_Registry::types();

		foreach ( array( 'ollama', 'lmstudio' ) as $id ) {
			$this->assertArrayHasKey( $id, $types );
			$this->assertStringContainsString( 'free', strtolower( $types[ $id ] ), $id );
		}
	}

	// ------------------------------------------------------------ pictures.

	public function test_every_picture_service_says_whether_it_costs_money() {
		foreach ( Blogcraft_Images::providers() as $id => $label ) {
			$this->assertTrue(
				$this->states_a_cost( $label ),
				$id . ' does not say whether it is free or paid: "' . $label . '"'
			);
		}
	}

	public function test_the_keyless_and_free_key_picture_services_are_marked_free() {
		$providers = Blogcraft_Images::providers();

		foreach ( array( 'pollinations', 'pexels', 'pixabay' ) as $id ) {
			$this->assertArrayHasKey( $id, $providers );
			$this->assertStringContainsString( 'free', strtolower( $providers[ $id ] ), $id );
		}
	}

	public function test_every_generating_picture_service_is_marked_paid() {
		// All four bill per image. Blogcraft never falls back to them — they
		// run only when explicitly chosen — but somebody choosing one should
		// see the cost in the same breath as the name.
		foreach ( Blogcraft_Image_Models::providers() as $id => $label ) {
			$this->assertStringContainsString( 'paid', strtolower( $label ), $id );
		}
	}

	// ------------------------------------------------------------- honesty.

	public function test_no_label_promises_a_specific_free_allowance() {
		// A quota is the provider's to change and this plugin's to get wrong.
		// Naming one in a label would be a number nobody updates, going stale
		// silently — the same failure as the retired model id this plugin
		// once shipped in a hint. The settings screen links to each
		// provider's live pricing page instead.
		$labels = array_merge(
			array_values( Blogcraft_Provider_Registry::types() ),
			array_values( Blogcraft_Images::providers() )
		);

		foreach ( $labels as $label ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\d[\d,.]*\s*(free|requests|tokens|images|per day|per month|\/day|\/month|rpm|rpd)/i',
				(string) $label,
				'a label names a specific allowance, which will go stale: "' . $label . '"'
			);
		}
	}
}
