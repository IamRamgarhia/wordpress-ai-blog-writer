<?php
/**
 * One row per connection, not one row per credential.
 *
 * Signing an app in mints two tokens: the one it calls with, and the one it
 * renews with. The screen listed both, beside every token issued by hand,
 * with nothing saying which was which — so one app appeared twice, the second
 * row claiming it had never been used, and revoking the wrong one either did
 * nothing visible or left the app free to renew itself straight back in.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Connections extends WP_UnitTestCase {

	/**
	 * Somebody allowed to connect apps.
	 *
	 * @var int
	 */
	private $author = 0;

	public function set_up() {
		parent::set_up();

		Blogcraft_Capabilities::add();
		Blogcraft_Migrator::migrate();

		$this->author = self::factory()->user->create( array( 'role' => 'administrator' ) );

		delete_option( Blogcraft_Mcp_Auth::OPTION );
		delete_option( 'blogcraft_settings' );

		Blogcraft_Settings::set( 'mcp_enabled', true );
		Blogcraft_Settings::set( 'setup_path', Blogcraft_Mode::CLIENT );

		wp_set_current_user( $this->author );
	}

	public function tear_down() {
		delete_option( Blogcraft_Mcp_Auth::OPTION );
		delete_option( 'blogcraft_settings' );
		Blogcraft_Capabilities::remove();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Sign an app in, the way the token endpoint does.
	 *
	 * @param string $client Which app.
	 * @param string $label  What to call it.
	 * @return array The two secrets.
	 */
	private function sign_in( $client, $label ) {
		return array(
			Blogcraft_Mcp_Auth::issue( $this->author, $label, array( 'client' => $client, 'expires' => time() + DAY_IN_SECONDS ) ),
			Blogcraft_Mcp_Auth::issue( $this->author, $label, array( 'client' => $client, 'kind' => 'refresh', 'expires' => 0 ) ),
		);
	}

	public function test_an_app_that_signed_in_is_one_connection_not_two() {
		$this->sign_in( 'bc_claude', 'Claude' );

		$this->assertCount( 2, Blogcraft_Mcp_Auth::all(), 'signing in should still mint two credentials' );
		$this->assertCount( 1, Blogcraft_Mcp_Auth::connections(), 'and they are one connection' );
	}

	public function test_a_token_typed_in_by_hand_is_its_own_connection() {
		Blogcraft_Mcp_Auth::issue( $this->author, 'my laptop' );
		$this->sign_in( 'bc_claude', 'Claude' );

		$this->assertCount( 2, Blogcraft_Mcp_Auth::connections() );
	}

	public function test_a_connection_says_how_it_got_in() {
		Blogcraft_Mcp_Auth::issue( $this->author, 'my laptop' );
		$this->sign_in( 'bc_claude', 'Claude' );

		$how = wp_list_pluck( Blogcraft_Mcp_Auth::connections(), 'signed_in' );

		$this->assertContains( true, $how, 'the app that signed in is not marked as such' );
		$this->assertContains( false, $how, 'the hand-issued token is not marked as such' );
	}

	public function test_the_last_use_is_the_later_of_the_pair() {
		// The renewal token is often never used at all, and a row saying
		// "Never" beside a connection working perfectly well is a row that
		// invites somebody to revoke the wrong thing.
		list( $access ) = $this->sign_in( 'bc_claude', 'Claude' );

		$request = new WP_REST_Request( 'POST', '/' . Blogcraft_Mcp::REST_NAMESPACE . Blogcraft_Mcp::REST_ROUTE );
		$request->set_header( 'authorization', 'Bearer ' . $access );

		Blogcraft_Mcp_Auth::user_for( $request );

		$connections = Blogcraft_Mcp_Auth::connections();
		$one         = reset( $connections );

		$this->assertNotEmpty( $one['used'], 'the connection looks unused although it was just used' );
	}

	public function test_disconnecting_takes_every_credential_with_it() {
		// The whole point. Leaving the renewal token behind is a
		// disconnection that appears to work and then does not.
		$this->sign_in( 'bc_claude', 'Claude' );

		$connections = Blogcraft_Mcp_Auth::connections();
		$id          = array_key_first( $connections );

		$this->assertTrue( Blogcraft_Mcp_Auth::disconnect( $id ) );
		$this->assertSame( array(), Blogcraft_Mcp_Auth::all(), 'a credential survived the disconnection' );
	}

	public function test_disconnecting_one_app_leaves_the_others_alone() {
		$this->sign_in( 'bc_claude', 'Claude' );
		$this->sign_in( 'bc_chatgpt', 'ChatGPT' );
		Blogcraft_Mcp_Auth::issue( $this->author, 'my laptop' );

		$this->assertCount( 3, Blogcraft_Mcp_Auth::connections() );

		foreach ( Blogcraft_Mcp_Auth::connections() as $id => $one ) {
			if ( 'Claude' === $one['label'] ) {
				Blogcraft_Mcp_Auth::disconnect( $id );
				break;
			}
		}

		$left = wp_list_pluck( Blogcraft_Mcp_Auth::connections(), 'label' );

		$this->assertNotContains( 'Claude', $left );
		$this->assertContains( 'ChatGPT', $left );
		$this->assertContains( 'my laptop', $left );
	}

	public function test_a_disconnected_app_can_no_longer_call_anything() {
		list( $access ) = $this->sign_in( 'bc_claude', 'Claude' );

		$connections = Blogcraft_Mcp_Auth::connections();
		Blogcraft_Mcp_Auth::disconnect( array_key_first( $connections ) );

		$request = new WP_REST_Request( 'POST', '/' . Blogcraft_Mcp::REST_NAMESPACE . Blogcraft_Mcp::REST_ROUTE );
		$request->set_header( 'authorization', 'Bearer ' . $access );

		$this->assertSame( 0, Blogcraft_Mcp_Auth::user_for( $request ) );
	}

	public function test_the_screen_lists_connections_and_offers_to_end_them() {
		$this->sign_in( 'bc_claude', 'Claude' );

		ob_start();
		Blogcraft_Connection::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Connected apps', $html );
		$this->assertStringContainsString( 'Disconnect', $html );

		// One row, not two, for one app.
		$this->assertSame(
			1,
			substr_count( $html, 'name="connection"' ),
			'an app that signed in is listed more than once'
		);
	}

	public function test_the_screen_offers_nothing_to_disconnect_when_nothing_is() {
		ob_start();
		Blogcraft_Connection::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Nothing is connected yet', $html );
		$this->assertStringNotContainsString( 'name="connection"', $html );
	}

	public function test_a_connection_with_no_name_is_named_everywhere() {
		// A token issued without a label is listed as "Unnamed" in the table
		// and was an empty string in the strip above it, which joined the
		// names with commas and so read "Writing , Claude".
		Blogcraft_Mcp_Auth::issue( $this->author, '' );
		$this->sign_in( 'bc_claude', 'Claude' );

		ob_start();
		Blogcraft_Connection::render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<span class="bc-status-is">, ', $html, 'the strip is naming nothing' );
		$this->assertStringContainsString( 'Unnamed, Claude', $html );
	}

	public function test_a_connection_says_whether_it_is_live() {
		// "Last used: September 1" is a fact somebody then has to do
		// arithmetic on. Whether it is working today is the question.
		Blogcraft_Mcp_Auth::issue( $this->author, 'never called' );

		$states = wp_list_pluck( Blogcraft_Mcp_Auth::connections(), 'state' );

		$this->assertSame( array( 'never' ), array_values( $states ) );
	}

	public function test_a_connection_used_today_is_active() {
		$secret = Blogcraft_Mcp_Auth::issue( $this->author, 'my laptop' );

		$request = new WP_REST_Request( 'POST', '/' . Blogcraft_Mcp::REST_NAMESPACE . Blogcraft_Mcp::REST_ROUTE );
		$request->set_header( 'authorization', 'Bearer ' . $secret );
		Blogcraft_Mcp_Auth::user_for( $request );

		$connections = Blogcraft_Mcp_Auth::connections();
		$one         = reset( $connections );

		$this->assertSame( 'active', $one['state'] );
	}

	public function test_a_connection_that_has_gone_quiet_is_idle() {
		// Long enough to be worth noticing, and not so short that somebody
		// writing a post a fortnight sees their own setup called idle.
		Blogcraft_Mcp_Auth::issue( $this->author, 'an old one' );

		$tokens = Blogcraft_Mcp_Auth::all();
		$key    = array_key_first( $tokens );

		$tokens[ $key ]['used'] = time() - ( Blogcraft_Mcp_Auth::RECENTLY + DAY_IN_SECONDS );
		update_option( Blogcraft_Mcp_Auth::OPTION, $tokens, false );

		$connections = Blogcraft_Mcp_Auth::connections();
		$one         = reset( $connections );

		$this->assertSame( 'idle', $one['state'] );
	}

	public function test_the_screen_says_which_connections_are_live() {
		Blogcraft_Mcp_Auth::issue( $this->author, 'never called' );

		ob_start();
		Blogcraft_Connection::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Status', $html );
		$this->assertStringContainsString( 'Never used', $html );
		$this->assertStringContainsString( 'bc-conn-state is-never', $html );
	}
}
