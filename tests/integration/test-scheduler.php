<?php
/**
 * Scheduler and cron health tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Scheduler extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
	}

	public function tear_down() {
		Blogcraft_Scheduler::unschedule();
		delete_option( Blogcraft_Cron_Health::HEARTBEAT_OPTION );
		delete_option( Blogcraft_Cron_Health::ACTIVATED_OPTION );
		parent::tear_down();
	}

	public function test_schedule_registers_the_event() {
		Blogcraft_Scheduler::schedule();
		$this->assertTrue( Blogcraft_Scheduler::is_scheduled() );
	}

	public function test_schedule_is_idempotent() {
		Blogcraft_Scheduler::schedule();
		Blogcraft_Scheduler::schedule();
		$this->assertTrue( Blogcraft_Scheduler::is_scheduled() );
	}

	public function test_unschedule_removes_the_event() {
		Blogcraft_Scheduler::schedule();
		Blogcraft_Scheduler::unschedule();
		$this->assertFalse( Blogcraft_Scheduler::is_scheduled() );
	}

	public function test_run_queue_records_a_heartbeat() {
		Blogcraft_Scheduler::run_queue();
		$this->assertGreaterThan( 0, Blogcraft_Cron_Health::last_heartbeat() );
	}

	public function test_health_is_stale_when_no_heartbeat_recorded() {
		delete_option( Blogcraft_Cron_Health::HEARTBEAT_OPTION );
		$this->assertTrue( Blogcraft_Cron_Health::is_stale() );
	}

	public function test_health_is_not_stale_immediately_after_heartbeat() {
		Blogcraft_Cron_Health::record_heartbeat();
		$this->assertFalse( Blogcraft_Cron_Health::is_stale() );
	}

	public function test_health_is_stale_when_heartbeat_is_old() {
		update_option( Blogcraft_Cron_Health::HEARTBEAT_OPTION, time() - 3600, false );
		$this->assertTrue( Blogcraft_Cron_Health::is_stale( 900 ) );
	}

	public function test_registered_recurrence_is_300_seconds() {
		$schedules = wp_get_schedules();
		$this->assertArrayHasKey( Blogcraft_Scheduler::RECURRENCE, $schedules );
		$this->assertSame( 300, $schedules[ Blogcraft_Scheduler::RECURRENCE ]['interval'] );
	}

	public function test_schedule_uses_the_five_minute_recurrence() {
		Blogcraft_Scheduler::schedule();
		$event = wp_get_scheduled_event( Blogcraft_Scheduler::HOOK );
		$this->assertSame( Blogcraft_Scheduler::RECURRENCE, $event->schedule );
	}

	public function test_fresh_activation_is_not_stale_with_no_heartbeat() {
		delete_option( Blogcraft_Cron_Health::HEARTBEAT_OPTION );
		Blogcraft_Cron_Health::record_activation();
		$this->assertFalse( Blogcraft_Cron_Health::is_stale() );
	}

	public function test_is_stale_once_grace_period_elapses_with_no_heartbeat() {
		delete_option( Blogcraft_Cron_Health::HEARTBEAT_OPTION );
		update_option(
			Blogcraft_Cron_Health::ACTIVATED_OPTION,
			time() - ( 2 * Blogcraft_Scheduler::RECURRENCE_SECONDS ) - 1,
			false
		);
		$this->assertTrue( Blogcraft_Cron_Health::is_stale() );
	}

	public function test_is_stale_when_no_heartbeat_and_no_activation_recorded() {
		delete_option( Blogcraft_Cron_Health::HEARTBEAT_OPTION );
		delete_option( Blogcraft_Cron_Health::ACTIVATED_OPTION );
		$this->assertTrue( Blogcraft_Cron_Health::is_stale() );
	}
}
