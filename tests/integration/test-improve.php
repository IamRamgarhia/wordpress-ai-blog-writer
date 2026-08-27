<?php
/**
 * A second pass at a draft, and the honesty about what it cannot reach.
 *
 * The pipeline already runs critique then revise before anybody sees a draft,
 * so this button is a second helping of a pass that has run once. That is
 * worth offering — a rewrite is not deterministic and the measurements are —
 * but only where a rewrite owns the problem.
 *
 * "Internal links: 1, wanted 3" is the case that matters. It is not a writing
 * fault: the site has nothing else on the subject to point at, and no rewrite
 * invents three related posts. Charging somebody a request to produce the same
 * draft and the same score is the failure this file exists to prevent.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Improve extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		Blogcraft_Worker::reset_stages();
		Blogcraft_Pipeline::register();

		global $wpdb;
		$table = Blogcraft_Migrator::table_name( 'jobs' );
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		delete_option( 'blogcraft_settings' );
	}

	public function tear_down() {
		Blogcraft_Worker::reset_stages();
		delete_option( 'blogcraft_settings' );
		parent::tear_down();
	}

	/**
	 * A check result, shaped the way the scorecard emits them.
	 *
	 * @param string $key    Machine name.
	 * @param bool   $pass   Whether it passed.
	 * @param string $repair Repair instruction, or '' when a rewrite cannot help.
	 * @return array
	 */
	private function check( $key, $pass, $repair ) {
		return array(
			'key'    => $key,
			'label'  => ucfirst( str_replace( '_', ' ', $key ) ),
			'pass'   => $pass,
			'actual' => '1',
			'target' => '3',
			'weight' => 3,
			'repair' => $repair,
		);
	}

	// ---------------------------------------- telling the two kinds apart.

	public function test_a_failure_with_an_instruction_is_one_a_rewrite_owns() {
		$checks = array( $this->check( 'word_count', false, 'Cut about 40 words.' ) );

		$this->assertCount( 1, Blogcraft_Scorecard::fixable( $checks ) );
		$this->assertCount( 0, Blogcraft_Scorecard::needs_you( $checks ) );
	}

	public function test_a_failure_with_no_instruction_is_one_for_the_person() {
		// Internal links is the real case: the check is deliberately written
		// with no repair note, because the answer is more posts, not more
		// words.
		$checks = array( $this->check( 'internal_links', false, '' ) );

		$this->assertCount( 0, Blogcraft_Scorecard::fixable( $checks ) );
		$this->assertCount( 1, Blogcraft_Scorecard::needs_you( $checks ) );
	}

	public function test_a_passing_check_is_neither() {
		$checks = array( $this->check( 'word_count', true, 'Cut about 40 words.' ) );

		$this->assertSame( array(), Blogcraft_Scorecard::fixable( $checks ) );
		$this->assertSame( array(), Blogcraft_Scorecard::needs_you( $checks ) );
	}

	public function test_the_real_internal_links_check_carries_no_repair_note() {
		// Pinned against the actual scorecard rather than a fixture, because
		// somebody adding a helpful-sounding repair line to that check is
		// exactly how the button would start promising to fix it.
		$blueprint                          = Blogcraft_Blueprint::defaults();
		$blueprint['internal_links_target'] = 3;

		// Measured for real rather than hand-built, so a renamed metric key
		// fails here instead of quietly producing a check that never runs.
		$metrics = Blogcraft_Metrics::measure(
			'<h2>One</h2><p>A sentence about coffee that is long enough to measure.</p>',
			$blueprint
		);

		$metrics['internal_links'] = 1;

		$checks = Blogcraft_Scorecard::checks( $metrics, $blueprint );
		foreach ( $checks as $check ) {
			if ( 'internal_links' === $check['key'] ) {
				$this->assertSame( '', $check['repair'], 'internal links gained a repair note it cannot honour' );
			}
		}
	}

	// ------------------------------------------------- reopening the job.

	public function test_a_held_draft_can_be_sent_back_for_another_pass() {
		$job_id = Blogcraft_Queue::enqueue( 'write_post', 'publish', array( 'topic' => 'cold brew' ) );
		Blogcraft_Queue::hold( $job_id, array( 'topic' => 'cold brew' ) );

		$this->assertTrue( Blogcraft_Queue::reopen( $job_id, 'revise' ) );

		$job = Blogcraft_Queue::find( $job_id );

		$this->assertSame( 'pending', $job->status );
		$this->assertSame( 'revise', $job->stage );
	}

	public function test_a_job_still_working_is_not_reopened() {
		// It is already moving. Resetting its stage underneath it would throw
		// away the step it is in the middle of.
		$job_id = Blogcraft_Queue::enqueue( 'write_post', 'draft', array() );

		$this->assertFalse( Blogcraft_Queue::reopen( $job_id, 'revise' ) );
	}

	public function test_a_finished_job_is_not_reopened() {
		// There is a post on the site. Rewriting the payload would not touch
		// it, so the button would appear to work and change nothing.
		$job_id = Blogcraft_Queue::enqueue( 'write_post', 'publish', array() );
		Blogcraft_Queue::complete( $job_id );

		$this->assertFalse( Blogcraft_Queue::reopen( $job_id, 'revise' ) );
	}

	public function test_reopening_clears_the_attempts_so_it_is_not_born_exhausted() {
		$job_id = Blogcraft_Queue::enqueue( 'write_post', 'publish', array() );
		Blogcraft_Queue::fail( $job_id, 'something went wrong' );
		Blogcraft_Queue::hold( $job_id, array() );

		Blogcraft_Queue::reopen( $job_id, 'revise' );

		$job = Blogcraft_Queue::find( $job_id );

		$this->assertSame( 0, (int) $job->attempts );
	}

	public function test_a_reopened_draft_holds_again_rather_than_publishing_itself() {
		// The whole point of the review screen. A second pass must land back in
		// front of the person, not quietly put a post on their site.
		$job_id = Blogcraft_Queue::enqueue(
			'write_post',
			'publish',
			array(
				'topic'        => 'cold brew',
				'await_review' => true,
			)
		);
		Blogcraft_Queue::hold( $job_id, array( 'topic' => 'cold brew', 'await_review' => true ) );
		Blogcraft_Queue::reopen( $job_id, 'revise' );

		$this->assertSame( 'pending', Blogcraft_Queue::find( $job_id )->status );
		$this->assertNotSame( 'complete', Blogcraft_Queue::find( $job_id )->status );
	}

	// ------------------------------------ what would hold this back.

	public function test_an_existing_post_on_the_same_subject_is_named() {
		// Two of your own pages answering one question split it between them,
		// and search engines pick one — not reliably the better one.
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'How to choose a standing desk for a home office',
			)
		);

		$found = Blogcraft_Prospects::blockers(
			0,
			array(),
			array(),
			array(
				'topic'    => 'Choosing a standing desk for a home office',
				'evidence' => 'We tested nine desks over four months.',
			)
		);

		$keys = wp_list_pluck( $found, 'key' );

		$this->assertContains( 'cannibal', $keys );
	}

	public function test_an_unrelated_post_is_not_called_a_rival() {
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Sourdough starters for absolute beginners',
			)
		);

		$found = Blogcraft_Prospects::blockers(
			0,
			array(),
			array(),
			array(
				'topic'    => 'Choosing a standing desk for a home office',
				'evidence' => 'We tested nine desks over four months.',
			)
		);

		$this->assertNotContains( 'cannibal', wp_list_pluck( $found, 'key' ) );
	}

	public function test_a_post_with_nothing_of_its_own_is_flagged() {
		$found = Blogcraft_Prospects::blockers( 0, array(), array(), array( 'topic' => 'standing desks' ) );

		$this->assertContains( 'nothing_new', wp_list_pluck( $found, 'key' ) );
	}

	public function test_supplying_your_own_material_clears_that_one() {
		$found = Blogcraft_Prospects::blockers(
			0,
			array(),
			array(),
			array(
				'topic'    => 'standing desks',
				'evidence' => 'Three of nine were returned within a month.',
			)
		);

		$this->assertNotContains( 'nothing_new', wp_list_pluck( $found, 'key' ) );
	}

	public function test_a_post_nothing_links_to_is_flagged() {
		$checks = array( $this->check( 'internal_links', false, '' ) );

		$found = Blogcraft_Prospects::blockers(
			0,
			array(),
			$checks,
			array( 'topic' => 'standing desks', 'evidence' => 'Ours wobbled.' )
		);

		$this->assertContains( 'orphan', wp_list_pluck( $found, 'key' ) );
	}

	public function test_nothing_is_promised_about_where_it_will_rank() {
		// The one claim this screen must never make. A number here would be
		// invented, and an invented number beside nineteen measured ones costs
		// the measured ones their credibility.
		$caveat = Blogcraft_Prospects::caveat();

		$this->assertNotSame( '', trim( $caveat ) );
		$this->assertStringContainsString( 'predicts', $caveat );

		$found = Blogcraft_Prospects::blockers( 0, array(), array(), array( 'topic' => 'x', 'evidence' => 'y' ) );

		foreach ( $found as $blocker ) {
			$words = strtolower( $blocker['title'] . ' ' . $blocker['detail'] );

			foreach ( array( 'will rank', 'position ', 'guarantee', 'page one', 'top 10' ) as $claim ) {
				$this->assertStringNotContainsString( $claim, $words, $blocker['key'] . ' promises a ranking' );
			}
		}
	}
}
