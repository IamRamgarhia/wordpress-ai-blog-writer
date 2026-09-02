<?php
/**
 * A post is finished whichever way it goes live.
 *
 * The plugin tells people to read the draft before anything goes out, and the
 * obvious way to act on that is to open it in WordPress and press Publish.
 * Nothing was listening to that. Everything a published post gets — the
 * featured image, the section pictures, the links from older posts, the
 * search title and description, the ping — happened only inside the tool call
 * that published it, so taking the plugin's own advice produced a barer post
 * than ignoring it.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Finishing extends WP_UnitTestCase {

	/**
	 * Somebody allowed to write.
	 *
	 * @var int
	 */
	private $author = 0;

	public function set_up() {
		parent::set_up();

		Blogcraft_Capabilities::add();
		Blogcraft_Migrator::migrate();

		$this->author = self::factory()->user->create( array( 'role' => 'administrator' ) );

		delete_option( 'blogcraft_settings' );
		delete_option( Blogcraft_Mcp_Auth::OPTION );

		Blogcraft_Settings::set( 'mcp_enabled', true );
		Blogcraft_Settings::set( 'setup_path', Blogcraft_Mode::CLIENT );

		wp_set_current_user( $this->author );

		// The transition hook lives with the rest of the MCP wiring.
		Blogcraft_Mcp::init();
	}

	public function tear_down() {
		remove_action( 'transition_post_status', array( 'Blogcraft_Mcp_Tools', 'on_publish' ), 10 );

		delete_option( 'blogcraft_settings' );
		delete_option( Blogcraft_Mcp_Auth::OPTION );
		Blogcraft_Capabilities::remove();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * A draft, written the way a connected app writes one.
	 *
	 * @return int
	 */
	private function draft() {
		$out = Blogcraft_Mcp_Tools::call(
			'create_draft',
			array(
				'title'            => 'Choosing a kettle',
				'html'             => '<h2>First</h2><p>Words about kettles that go on for a while.</p>'
					. '<h2>Second</h2><p>More of them, at some length.</p>',
				'meta_description' => 'What to look for in a kettle, and what not to bother with.',
				'topic'            => 'kettles',
			)
		);

		$hit = array();
		preg_match( '/draft (\d+)/', (string) $out['text'], $hit );

		$this->assertNotEmpty( $hit, (string) $out['text'] );

		return (int) $hit[1];
	}

	public function test_publishing_from_wordpress_finishes_the_post() {
		// The route the plugin's own advice sends people down.
		$post_id = $this->draft();

		$this->assertSame( '', (string) get_post_meta( $post_id, Blogcraft_Mcp_Tools::DONE_META, true ) );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$this->assertNotSame(
			'',
			(string) get_post_meta( $post_id, Blogcraft_Mcp_Tools::DONE_META, true ),
			'published by hand and nothing finished it'
		);
	}

	public function test_the_search_description_is_written_when_it_goes_live_by_hand() {
		// The one with a visible consequence that needs no picture service:
		// the description the app wrote has to reach where the front end
		// reads it, or it sits in the database unused.
		$post_id = $this->draft();

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$this->assertNotSame(
			'',
			(string) get_post_meta( $post_id, Blogcraft_Seo::SEO_DESC_META, true ),
			'the description was never written where anything reads it'
		);
	}

	public function test_a_post_is_not_finished_twice() {
		// Publishing through the tool fires the same transition, so without a
		// guard the pictures would be fetched and the engines told twice for
		// one post.
		$post_id = $this->draft();

		Blogcraft_Settings::set( 'quality_threshold', 0 );

		Blogcraft_Mcp_Tools::call( 'publish_draft', array( 'post_id' => $post_id ) );

		$first = (string) get_post_meta( $post_id, Blogcraft_Mcp_Tools::DONE_META, true );

		$this->assertNotSame( '', $first );

		// Saving it again must not start the work over.
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Choosing a kettle, revised',
			)
		);

		$this->assertSame(
			$first,
			(string) get_post_meta( $post_id, Blogcraft_Mcp_Tools::DONE_META, true ),
			'the finishing ran a second time'
		);
	}

	public function test_the_tool_still_reports_what_was_done() {
		// The transition now runs the work before the tool call reaches its
		// own finish(), so the answer has to survive being asked for twice.
		$post_id = $this->draft();

		Blogcraft_Settings::set( 'quality_threshold', 0 );

		$out = Blogcraft_Mcp_Tools::call( 'publish_draft', array( 'post_id' => $post_id ) );

		$this->assertStringContainsString( 'Published', (string) $out['text'] );
	}

	public function test_a_post_nobody_here_wrote_is_left_alone() {
		// The guard that keeps this away from somebody's own writing.
		$theirs = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		wp_update_post(
			array(
				'ID'          => $theirs,
				'post_status' => 'publish',
			)
		);

		$this->assertSame(
			'',
			(string) get_post_meta( $theirs, Blogcraft_Mcp_Tools::DONE_META, true ),
			'the plugin finished a post it did not write'
		);
	}
}
