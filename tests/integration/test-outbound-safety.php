<?php
/**
 * An address the site did not choose is fetched the careful way.
 *
 * wp_remote_get() will follow whatever it is handed — to 127.0.0.1, into a
 * private range, or to a cloud provider's metadata service on a link-local
 * address. wp_safe_remote_get() is the one that refuses those, and the
 * difference only matters for addresses that came from somewhere else.
 *
 * Two here did: the research list, typed into a settings field, and the
 * results a search service hands back, which nobody on this site chose at
 * all. Both were being fetched with the plain call while the voice reader,
 * taking the same kind of input, already used the safe one.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Outbound_Safety extends WP_UnitTestCase {

	/**
	 * The plain call is right only for an address built from this site's own.
	 *
	 * Keyed by file, with the reason, so adding one is a decision somebody
	 * writes down rather than a default.
	 *
	 * @return array
	 */
	private function may_use_the_plain_call() {
		return array(
			// Reads this site's own robots.txt. The safe call refuses
			// private addresses, and a staging or intranet install whose
			// home_url resolves to one would report "cannot tell" forever.
			'class-blogcraft-crawlers.php'     => 'home_url',
			// Reads one of this site's own published posts, same reasoning.
			'class-blogcraft-schema-watch.php' => 'get_permalink',
			// Calls this site's own MCP endpoint to prove a token works.
			'class-blogcraft-mcp.php'          => 'rest_url',
			// The provider call, and the one place the plain call is not
			// merely allowed but required: Ollama, LM Studio, Jan and
			// llama.cpp all answer on http://localhost, and the safe call
			// refuses loopback. Switching this would silently break every
			// local model the plugin advertises.
			'class-blogcraft-http.php'         => 'wp_remote_request',
		);
	}

	public function test_only_this_site_s_own_pages_are_fetched_the_plain_way() {
		$offenders = array();

		foreach ( (array) glob( BLOGCRAFT_PATH . 'includes/*.php' ) as $path ) {
			$body = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$name = basename( $path );

			// wp_safe_remote_get contains "remote_get" too, so the plain
			// call is matched only where it is not preceded by "safe_".
			if ( ! preg_match( '/(?<!safe_)wp_remote_(get|post|request)\s*\(/', $body ) ) {
				continue;
			}

			if ( ! isset( $this->may_use_the_plain_call()[ $name ] ) ) {
				$offenders[] = $name;
			}
		}

		sort( $offenders );

		$this->assertSame(
			array(),
			$offenders,
			'these fetch an address with the plain call and are not on the list of files allowed to: ' . implode( ', ', $offenders )
		);
	}

	public function test_each_exemption_still_rests_on_what_it_claimed() {
		// Three of these are exempt because the address is this site's own,
		// and the fourth because loopback is the point. Each names the call
		// that makes its case; if that call goes, the reason went with it
		// and somebody should look again rather than inherit the exemption.
		foreach ( $this->may_use_the_plain_call() as $name => $marker ) {
			$path = BLOGCRAFT_PATH . 'includes/' . $name;

			$this->assertFileExists( $path );

			$body = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			$this->assertStringContainsString(
				$marker . '(',
				$body,
				$name . ' is exempt on the strength of ' . $marker . '(), which it no longer calls'
			);
		}
	}

	public function test_the_research_reader_uses_the_safe_call() {
		$body = (string) file_get_contents( BLOGCRAFT_PATH . 'includes/class-blogcraft-research.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertSame(
			2,
			preg_match_all( '/wp_safe_remote_get\s*\(/', $body ),
			'research should make both of its fetches the safe way'
		);

		$this->assertSame(
			0,
			preg_match_all( '/(?<!safe_)wp_remote_get\s*\(/', $body ),
			'research is fetching an address it was given with the plain call'
		);
	}

	public function test_pages_fetched_from_outside_are_capped_in_size() {
		// Nothing is wanted from these pages beyond headings and an excerpt,
		// both of which arrive early. Without a cap, a page that answers
		// with a gigabyte is read into memory in full.
		$body = (string) file_get_contents( BLOGCRAFT_PATH . 'includes/class-blogcraft-research.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertSame(
			2,
			preg_match_all( '/limit_response_size/', $body ),
			'both outside fetches should cap what they will read'
		);

		$this->assertGreaterThan( 0, Blogcraft_Research::MAX_FETCH_BYTES );
		$this->assertLessThanOrEqual( 8 * 1024 * 1024, Blogcraft_Research::MAX_FETCH_BYTES );
	}
}
