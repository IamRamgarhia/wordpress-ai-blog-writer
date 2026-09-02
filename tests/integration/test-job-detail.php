<?php
/**
 * A finished job is worth opening, and used not to be openable.
 *
 * The Activity table linked a job only while it was still moving, and the
 * screen it linked to rendered an outcome only for a job held for review. So
 * a job that finished — the one somebody most wants to look at, because it
 * has a score, a list of what failed and a post at the end of it — was
 * reachable from nowhere, and reachable by address showed twelve unticked
 * steps and the word "Working" over a post written days earlier.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Job_Detail extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		Blogcraft_Capabilities::add();
		Blogcraft_Migrator::migrate();

		delete_option( 'blogcraft_settings' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		delete_option( 'blogcraft_settings' );
		Blogcraft_Capabilities::remove();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * A job that has run to the end, with the post it produced.
	 *
	 * @param string $status Job status.
	 * @return array Job id and post id.
	 */
	private function finished_job( $status = 'complete' ) {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'How long cold brew should steep',
				'post_status' => 'draft',
			)
		);

		update_post_meta( $post_id, '_blogcraft_quality', 79 );
		update_post_meta(
			$post_id,
			'_blogcraft_checks',
			array(
				array(
					'key'    => 'words',
					'label'  => 'Length',
					'pass'   => false,
					'actual' => '900',
					'target' => '1600-2400',
					'weight' => 5,
					'repair' => 'Expand the thinnest sections with specifics.',
				),
			)
		);

		$job_id = Blogcraft_Queue::enqueue(
			'post',
			'finishing',
			array(
				'topic'   => 'How long cold brew coffee should steep',
				'post_id' => $post_id,
			)
		);

		if ( 'complete' === $status ) {
			Blogcraft_Queue::complete( (int) $job_id );
		} elseif ( 'running' === $status ) {
			Blogcraft_Queue::claim_job( (int) $job_id );
		}

		return array( (int) $job_id, (int) $post_id );
	}

	/**
	 * The progress screen for one job.
	 *
	 * @param int $job_id Job id.
	 * @return string
	 */
	private function screen( $job_id ) {
		$_GET['job'] = (int) $job_id;

		ob_start();
		Blogcraft_Progress::render();
		$html = (string) ob_get_clean();

		unset( $_GET['job'] );

		return $html;
	}

	public function test_the_activity_table_links_a_job_that_has_finished() {
		list( $job_id ) = $this->finished_job();

		ob_start();
		Blogcraft_Activity::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString(
			'page=' . Blogcraft_Progress::PAGE_SLUG,
			$html,
			'a finished job is listed with no way to open it'
		);

		$this->assertStringContainsString( 'job=' . $job_id, $html );
	}

	public function test_a_finished_job_shows_what_it_scored() {
		list( $job_id ) = $this->finished_job();

		$html = $this->screen( $job_id );

		$this->assertStringContainsString( '79', $html, 'the score it was judged by is not on the screen' );
	}

	public function test_a_finished_job_shows_what_failed_and_how_to_fix_it() {
		list( $job_id ) = $this->finished_job();

		$html = $this->screen( $job_id );

		$this->assertStringContainsString( 'Length', $html );
		$this->assertStringContainsString( 'Expand the thinnest sections', $html, 'the repair note is missing' );
	}

	public function test_a_finished_job_offers_the_post_it_wrote() {
		list( $job_id, $post_id ) = $this->finished_job();

		$html = $this->screen( $job_id );

		$this->assertStringContainsString( 'How long cold brew should steep', $html );
		$this->assertStringContainsString( 'post=' . $post_id, $html, 'no way through to the post' );
	}

	public function test_a_finished_job_is_not_described_as_working() {
		// It stopped days ago. Polling it and calling it "Working" are both
		// answers to a question nobody asked.
		list( $job_id ) = $this->finished_job();

		$this->screen( $job_id );

		$method = new ReflectionMethod( 'Blogcraft_Progress', 'is_settled' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( null, $job_id ) );
	}

	public function test_a_job_still_running_is_still_watched() {
		// The other half: a job in flight must keep its live screen.
		list( $job_id ) = $this->finished_job( 'running' );

		$method = new ReflectionMethod( 'Blogcraft_Progress', 'is_settled' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( null, $job_id ) );
	}

	public function test_a_finished_job_whose_post_is_gone_says_so() {
		list( $job_id, $post_id ) = $this->finished_job();

		wp_delete_post( $post_id, true );

		$html = $this->screen( $job_id );

		$this->assertStringContainsString( 'no longer here', $html );
	}
}
