<?php
/**
 * Editorial check tests.
 *
 * The blueprint had asked for statistics, citations and first-hand experience
 * since it was written and none of the three was measured. These assert that
 * each one now fails when it should, passes when it should, and explains what
 * to do about it — because a check with no repair text is a deduction, not a
 * finding.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Editorial extends WP_UnitTestCase {

	/**
	 * Checks a rewrite cannot act on, so silence is the right answer.
	 *
	 * The rule everywhere else is that a failing check must say what to do
	 * about it, because a deduction with no instruction is a deduction
	 * dressed as a finding. The address is the exception: it is decided at
	 * the outline and lives nowhere in the prose, so telling the model to
	 * fix it spends an instruction on something it cannot reach.
	 */
	const UNREPAIRABLE = array( 'keyword_in_slug' );

	/**
	 * A blueprint that asks for everything measurable.
	 *
	 * @return array
	 */
	private function blueprint() {
		return Blogcraft_Blueprint::normalise(
			array(
				'primary_keyword'    => 'cold brew coffee',
				'meta_title_max'     => 60,
				'meta_desc_max'      => 155,
				'require_statistics' => true,
				'require_citations'  => true,
				'require_experience' => true,
			)
		);
	}

	/**
	 * One check out of a set, by key.
	 *
	 * @param array  $checks Checks.
	 * @param string $key    Key wanted.
	 * @return array|null
	 */
	private function verdict( $checks, $key ) {
		foreach ( $checks as $check ) {
			if ( $check['key'] === $key ) {
				return $check;
			}
		}

		return null;
	}

	/**
	 * An opening that does what it should.
	 *
	 * @return string
	 */
	private function good_opening() {
		return '<p>Cold brew coffee is steeped in cold water for twelve hours, which pulls fewer bitter compounds than heat does. That is the whole difference in taste.</p><h2>A</h2><p>Body.</p>';
	}

	// ----------------------------------------------------------- data points.

	public function test_figures_with_units_are_counted() {
		$this->assertSame( array( '42%' ), Blogcraft_Editorial::data_points( 'Yield rose 42% overnight.' ) );
		$this->assertSame( array( '12 hours' ), Blogcraft_Editorial::data_points( 'Steep for 12 hours.' ) );
		$this->assertSame( array( '2019' ), Blogcraft_Editorial::data_points( 'Since 2019 it has changed.' ) );
	}

	public function test_bare_small_integers_are_prose_not_evidence() {
		$this->assertSame( array(), Blogcraft_Editorial::data_points( 'There are three reasons and 7 ideas.' ) );
	}

	public function test_the_same_figure_twice_is_one_data_point() {
		$this->assertCount( 1, Blogcraft_Editorial::data_points( '42% today and 42% again.' ) );
	}

	// -------------------------------------------------------------- mentions.

	public function test_a_phrase_is_found_however_it_is_worded() {
		// Otherwise the model is pushed towards stuffing the exact phrase into
		// a sentence that does not want it.
		$this->assertTrue( Blogcraft_Editorial::mentions( 'Coffee brewed cold at home', 'cold brew coffee' ) );
	}

	public function test_a_phrase_that_is_absent_is_absent() {
		$this->assertFalse( Blogcraft_Editorial::mentions( 'A guide to tea', 'cold brew coffee' ) );
	}

	public function test_short_words_must_match_outright() {
		$this->assertFalse( Blogcraft_Editorial::mentions( 'A tea guide', 'ted' ) );
	}

	// ---------------------------------------------------------- answer-first.

	public function test_an_opening_that_clears_its_throat_fails() {
		$content = '<p>In today\'s fast-paced world, coffee matters more than ever to everyone.</p><h2>A</h2><p>Body.</p>';
		$check   = $this->verdict( Blogcraft_Editorial::checks( $content, $this->blueprint() ), 'answer_first' );

		$this->assertFalse( $check['pass'] );
		$this->assertStringContainsString( 'wind-up sentence', $check['repair'] );
	}

	public function test_an_answer_first_opening_passes() {
		$check = $this->verdict( Blogcraft_Editorial::checks( $this->good_opening(), $this->blueprint() ), 'answer_first' );

		$this->assertTrue( $check['pass'] );
	}

	public function test_an_opening_that_never_names_the_subject_fails() {
		$content = '<p>Tea has a long and storied history across many different cultures worldwide. It is worth understanding properly.</p><h2>A</h2>';
		$check   = $this->verdict( Blogcraft_Editorial::checks( $content, $this->blueprint() ), 'answer_first' );

		$this->assertFalse( $check['pass'] );
	}

	// -------------------------------------------------------- title and meta.

	public function test_a_good_title_passes_both_of_its_checks() {
		$context = array( 'title' => 'How Cold Brew Coffee Works, And Why It Tastes Sweeter' );
		$checks  = Blogcraft_Editorial::checks( $this->good_opening(), $this->blueprint(), $context );

		$this->assertTrue( $this->verdict( $checks, 'meta_title' )['pass'] );
		$this->assertTrue( $this->verdict( $checks, 'keyword_in_title' )['pass'] );
	}

	public function test_an_overlong_title_fails() {
		$context = array( 'title' => str_repeat( 'Cold Brew Coffee ', 6 ) );
		$checks  = Blogcraft_Editorial::checks( $this->good_opening(), $this->blueprint(), $context );

		$this->assertFalse( $this->verdict( $checks, 'meta_title' )['pass'] );
	}

	public function test_a_missing_meta_description_fails() {
		$checks = Blogcraft_Editorial::checks( $this->good_opening(), $this->blueprint(), array( 'meta_description' => '' ) );

		$this->assertFalse( $this->verdict( $checks, 'meta_description' )['pass'] );
	}

	public function test_a_good_meta_description_passes() {
		$context = array( 'meta_description' => 'Cold brew coffee steeps for twelve hours in cold water, which pulls fewer bitter compounds and tastes sweeter as a result.' );
		$checks  = Blogcraft_Editorial::checks( $this->good_opening(), $this->blueprint(), $context );

		$this->assertTrue( $this->verdict( $checks, 'meta_description' )['pass'] );
	}

	public function test_context_that_is_missing_is_skipped_not_failed() {
		// The score is earned-over-offered, so a question that could not be
		// asked must cost nothing rather than cost marks.
		$checks = Blogcraft_Editorial::checks( $this->good_opening(), $this->blueprint() );

		$this->assertNull( $this->verdict( $checks, 'meta_title' ) );
		$this->assertNull( $this->verdict( $checks, 'meta_description' ) );
		$this->assertNull( $this->verdict( $checks, 'source_overlap' ) );
	}

	// ----------------------------------------------------- keyword placement.

	public function test_the_subject_in_a_heading_passes() {
		$content = $this->good_opening() . '<h2>Why cold brew coffee tastes sweeter</h2><p>Body.</p>';
		$checks  = Blogcraft_Editorial::checks( $content, $this->blueprint() );

		$this->assertTrue( $this->verdict( $checks, 'keyword_in_heading' )['pass'] );
	}

	public function test_the_subject_in_no_heading_fails() {
		$checks = Blogcraft_Editorial::checks( $this->good_opening(), $this->blueprint() );

		$this->assertFalse( $this->verdict( $checks, 'keyword_in_heading' )['pass'] );
	}

	public function test_an_article_with_no_headings_is_not_failed_for_this() {
		$content = '<p>Cold brew coffee is steeped cold for twelve hours, which is the whole trick here.</p>';
		$checks  = Blogcraft_Editorial::checks( $content, $this->blueprint() );

		$this->assertTrue( $this->verdict( $checks, 'keyword_in_heading' )['pass'] );
	}

	// -------------------------------------------------------------- evidence.

	public function test_vague_quantities_fail() {
		$content = '<p>Cold brew coffee is much better and significantly smoother for many people.</p>';
		$checks  = Blogcraft_Editorial::checks( $content, $this->blueprint() );

		$this->assertFalse( $this->verdict( $checks, 'data_points' )['pass'] );
	}

	public function test_three_concrete_figures_pass() {
		$content = '<p>Cold brew coffee steeps 12 hours, cuts acidity by 67%, and costs $4 a litre to make.</p>';
		$checks  = Blogcraft_Editorial::checks( $content, $this->blueprint() );

		$this->assertTrue( $this->verdict( $checks, 'data_points' )['pass'] );
	}

	// ------------------------------------------------------------- citations.

	public function test_the_figure_check_is_named_for_what_it_measures() {
		// It finds a figure in a section with no link. It does not open the link
		// and confirm the number is there — which is the shape most fabricated
		// citations take — so the label must not imply that it did.
		$content = '<h2>The chemistry</h2><p>It cuts acidity by 67%.</p>';
		$check   = $this->verdict( Blogcraft_Editorial::checks( $content, $this->blueprint() ), 'unsupported_claims' );

		$this->assertStringNotContainsString( 'source', strtolower( $check['label'] ) );
		$this->assertStringContainsString( 'link', strtolower( $check['label'] ) );
	}

	public function test_a_link_to_anywhere_satisfies_the_figure_check() {
		// Stated plainly so nobody is surprised by it later: the link is not
		// followed, so any link in the section passes.
		$content = '<h2>The chemistry</h2><p>It cuts acidity by 67%. <a href="https://example.com/unrelated">Something else</a></p>';
		$check   = $this->verdict( Blogcraft_Editorial::checks( $content, $this->blueprint() ), 'unsupported_claims' );

		$this->assertTrue( $check['pass'] );
	}

	public function test_a_figure_with_nothing_to_check_it_against_fails() {
		$content = '<h2>The chemistry</h2><p>It cuts acidity by 67% every time.</p>';
		$checks  = Blogcraft_Editorial::checks( $content, $this->blueprint() );

		$this->assertFalse( $this->verdict( $checks, 'unsupported_claims' )['pass'] );
	}

	public function test_a_sourced_figure_passes() {
		$content = '<h2>The chemistry</h2><p>It cuts acidity by <a href="https://example.com">67%</a>.</p>';
		$checks  = Blogcraft_Editorial::checks( $content, $this->blueprint() );

		$this->assertTrue( $this->verdict( $checks, 'unsupported_claims' )['pass'] );
	}

	public function test_a_section_stating_no_figures_needs_no_source() {
		$content = '<h2>The chemistry</h2><p>It tastes rounder and less sharp.</p>';
		$checks  = Blogcraft_Editorial::checks( $content, $this->blueprint() );

		$this->assertTrue( $this->verdict( $checks, 'unsupported_claims' )['pass'] );
	}

	public function test_a_figure_in_the_introduction_is_caught_too() {
		$this->assertSame(
			array( 'the introduction' ),
			Blogcraft_Editorial::unsupported_sections( '<p>Acidity drops 67%.</p><h2>A</h2><p>Body.</p>' )
		);
	}

	// ------------------------------------------------------------ experience.

	public function test_an_article_written_from_nowhere_fails() {
		$content = '<p>Cold brew is widely considered to be smoother than hot coffee by most drinkers.</p>';
		$checks  = Blogcraft_Editorial::checks( $content, $this->blueprint() );

		$this->assertFalse( $this->verdict( $checks, 'experience' )['pass'] );
	}

	public function test_a_first_hand_account_passes() {
		$content = '<p>I tested nine jars over a month. What I found was that our own results never matched the label.</p>';
		$checks  = Blogcraft_Editorial::checks( $content, $this->blueprint() );

		$this->assertTrue( $this->verdict( $checks, 'experience' )['pass'] );
	}

	// ----------------------------------------------------------- originality.

	/**
	 * Research sources to compare a draft against.
	 *
	 * @return array
	 */
	private function sources() {
		return array(
			array(
				'url'     => 'https://example.com/a',
				'excerpt' => 'Cold water extraction produces noticeably fewer bitter compounds from coffee grounds than hot water extraction does, which explains the rounder flavour.',
			),
		);
	}

	public function test_a_sentence_lifted_from_a_source_is_caught() {
		$text = 'Cold water extraction produces noticeably fewer bitter compounds from coffee grounds than hot water extraction does.';

		$this->assertCount( 1, Blogcraft_Editorial::borrowed_sentences( $text, $this->sources() ) );
	}

	public function test_original_writing_is_not_flagged() {
		$text = 'Nine jars later, the fridge batches consistently measured half a point lower on the refractometer than the counter batches.';

		$this->assertSame( array(), Blogcraft_Editorial::borrowed_sentences( $text, $this->sources() ) );
	}

	public function test_with_no_sources_there_is_nothing_to_compare() {
		$text = 'Cold water extraction produces noticeably fewer bitter compounds from coffee grounds than hot water does.';

		$this->assertSame( array(), Blogcraft_Editorial::borrowed_sentences( $text, array() ) );
	}

	public function test_a_short_sentence_is_not_judged_either_way() {
		$this->assertSame( array(), Blogcraft_Editorial::borrowed_sentences( 'It is good.', $this->sources() ) );
	}

	// ---------------------------------------------------------- own material.

	public function test_figures_the_writer_supplied_but_the_model_dropped_are_named() {
		$evidence = 'We tested 9 desks over 4 months. The 220 bracket wobbled above 110cm. Our returns rate was 3 in 9.';
		$article  = 'We tested desks for a while and the cheap ones wobbled above 110cm.';

		$missing = Blogcraft_Editorial::unused_evidence( $article, $evidence );

		$this->assertContains( '3 in 9', $missing );
		$this->assertNotContains( '110cm', $missing );
	}

	public function test_evidence_fully_used_leaves_nothing_missing() {
		$evidence = 'The bracket wobbled above 110cm and the returns rate was 3 in 9.';
		$article  = 'Above 110cm it wobbled, and our own returns rate came to 3 in 9 across the batch.';

		$this->assertSame( array(), Blogcraft_Editorial::unused_evidence( $article, $evidence ) );
	}

	public function test_evidence_carrying_no_figures_asks_for_nothing() {
		// Someone who typed a sentence of context rather than numbers has not
		// promised anything checkable, so nothing is demanded of the draft.
		$this->assertSame( array(), Blogcraft_Editorial::unused_evidence( 'Anything.', 'We think cheap desks wobble.' ) );
	}

	public function test_the_check_only_runs_when_material_was_supplied() {
		$without = Blogcraft_Editorial::checks( $this->good_opening(), $this->blueprint() );

		$this->assertNull( $this->verdict( $without, 'own_material' ) );

		$with = Blogcraft_Editorial::checks(
			$this->good_opening(),
			$this->blueprint(),
			array( 'evidence' => 'Our returns rate was 3 in 9.' )
		);

		$this->assertNotNull( $this->verdict( $with, 'own_material' ) );
		$this->assertFalse( $this->verdict( $with, 'own_material' )['pass'] );
	}

	// ------------------------------------------------------------- scorecard.

	public function test_every_check_arrives_in_the_scorecard_shape() {
		$checks = Blogcraft_Editorial::checks(
			'<p>Cold brew coffee steeps 12 hours, cuts acidity by 67%, and costs $4 a litre.</p>',
			$this->blueprint(),
			array(
				'title'            => 'How Cold Brew Coffee Works',
				'meta_description' => 'x',
				'sources'          => $this->sources(),
				'evidence'         => 'Our own acidity reading came out at 67%.',
			)
		);

		// Counted rather than pinned to a number. This asserted exactly ten
		// for several releases and broke the moment a rule was added, which
		// tells nobody anything about the shape it is supposed to be testing.
		$this->assertNotEmpty( $checks );

		foreach ( $checks as $check ) {
			foreach ( array( 'key', 'label', 'pass', 'actual', 'target', 'weight', 'repair' ) as $field ) {
				$this->assertArrayHasKey( $field, $check );
			}

			$this->assertGreaterThan( 0, (int) $check['weight'] );

			if ( $check['pass'] ) {
				$this->assertSame( '', $check['repair'], $check['key'] . ' passed but carries repair text' );
			} elseif ( ! in_array( $check['key'], self::UNREPAIRABLE, true ) ) {
				$this->assertNotSame( '', trim( $check['repair'] ), $check['key'] . ' failed with nothing to do about it' );
			}
		}
	}

	public function test_the_scorecard_merges_them_in() {
		$assessment = Blogcraft_Scorecard::evaluate(
			$this->good_opening(),
			$this->blueprint(),
			array( 'title' => 'How Cold Brew Coffee Works' )
		);

		$keys = wp_list_pluck( $assessment['checks'], 'key' );

		$this->assertContains( 'answer_first', $keys );
		$this->assertContains( 'meta_title', $keys );
		$this->assertContains( 'words', $keys, 'the original checks must survive' );
	}

	public function test_the_scorecard_still_works_with_no_context_at_all() {
		$assessment = Blogcraft_Scorecard::evaluate( $this->good_opening(), $this->blueprint() );

		$this->assertGreaterThanOrEqual( 0, $assessment['score'] );
		$this->assertLessThanOrEqual( 100, $assessment['score'] );
		$this->assertNotEmpty( $assessment['checks'] );
	}

	// --------------------------------- what the current guides all agree on.

	/**
	 * Run the editorial checks and return them keyed by name.
	 *
	 * @param string $content Block markup.
	 * @param array  $context Title, description, slug and the rest.
	 * @return array
	 */
	private function checks_for( $content, $context ) {
		$blueprint                    = Blogcraft_Blueprint::defaults();
		$blueprint['primary_keyword'] = 'cold brew coffee';

		$out = array();

		foreach ( Blogcraft_Editorial::checks( $content, $blueprint, $context ) as $check ) {
			$out[ $check['key'] ] = $check;
		}

		return $out;
	}

	public function test_the_subject_has_to_arrive_in_the_first_hundred_words() {
		// Somebody who has just clicked a search result needs telling within a
		// sentence or two that they are in the right place. Every current guide
		// says so, and nothing measured it.
		$filler = str_repeat( 'Some words about something else entirely. ', 30 );

		$late = $this->checks_for( '<p>' . $filler . '</p><p>Cold brew coffee, at last.</p>', array() );
		$this->assertFalse( $late['keyword_in_opening']['pass'] );

		$early = $this->checks_for( '<p>Cold brew coffee is made without heat.</p><p>' . $filler . '</p>', array() );
		$this->assertTrue( $early['keyword_in_opening']['pass'] );
	}

	public function test_present_in_the_title_is_not_the_same_as_early_in_it() {
		$late = $this->checks_for(
			'<p>Anything.</p>',
			array( 'title' => 'A long and winding introduction to cold brew coffee' )
		);

		$this->assertTrue( $late['keyword_in_title']['pass'], 'the keyword is in the title' );
		$this->assertFalse( $late['keyword_early_in_title']['pass'], 'but it arrives far too late in it' );

		$early = $this->checks_for(
			'<p>Anything.</p>',
			array( 'title' => 'Cold brew coffee: a short guide' )
		);

		$this->assertTrue( $early['keyword_early_in_title']['pass'] );
	}

	public function test_the_address_is_measured_before_it_is_too_late_to_change() {
		$wrong = $this->checks_for( '<p>Anything.</p>', array( 'slug' => 'my-latest-post' ) );
		$this->assertFalse( $wrong['keyword_in_slug']['pass'] );

		$right = $this->checks_for( '<p>Anything.</p>', array( 'slug' => 'cold-brew-coffee-guide' ) );
		$this->assertTrue( $right['keyword_in_slug']['pass'] );
	}

	public function test_the_slug_check_asks_nothing_of_the_writer() {
		// The slug lives on the outline, not in the prose, so a rewrite cannot
		// reach it. A repair note here would spend an instruction on something
		// impossible to act on.
		$checks = $this->checks_for( '<p>Anything.</p>', array( 'slug' => 'my-latest-post' ) );

		$this->assertSame( '', $checks['keyword_in_slug']['repair'] );
	}

	public function test_the_search_result_line_has_to_name_the_subject() {
		$without = $this->checks_for( '<p>Anything.</p>', array( 'meta_description' => 'A guide to making a very nice drink at home without any heat at all.' ) );
		$this->assertFalse( $without['keyword_in_description']['pass'] );

		$with = $this->checks_for( '<p>Anything.</p>', array( 'meta_description' => 'How to make cold brew coffee at home, and what changes if you leave it longer.' ) );
		$this->assertTrue( $with['keyword_in_description']['pass'] );
	}

	public function test_none_of_the_new_checks_run_without_a_keyword_to_check() {
		// A site that never set a primary keyword must not be marked down on
		// four checks it cannot possibly pass.
		$blueprint                    = Blogcraft_Blueprint::defaults();
		$blueprint['primary_keyword'] = '';

		$keys = wp_list_pluck(
			Blogcraft_Editorial::checks( '<p>Anything.</p>', $blueprint, array( 'title' => 'A title', 'slug' => 'a-slug' ) ),
			'key'
		);

		foreach ( array( 'keyword_in_opening', 'keyword_early_in_title', 'keyword_in_slug', 'keyword_in_description' ) as $key ) {
			$this->assertNotContains( $key, $keys, $key . ' runs with no keyword to look for' );
		}
	}
}
