<?php
/**
 * Every server this plugin can reach is named in the readme, with its policies.
 *
 * The directory requires each third-party service to be disclosed: what it is,
 * what is sent, when, and links to its terms and privacy policy. The review
 * flagged this twice — first for services that were not listed at all, then
 * for a policy link that answered 404, and again for two that answered 429.
 *
 * All three were the same failure with different symptoms: the readme and the
 * code drifted apart, and only a human reading both could tell. This asks the
 * question mechanically, so adding a provider without disclosing it fails
 * here rather than in somebody's inbox a week later.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_External_Services extends WP_UnitTestCase {

	/**
	 * Hosts that are not third-party services and need no disclosure.
	 *
	 * @return array
	 */
	private function not_a_service() {
		return array(
			'dicecodes.com'            => true,
			'www.dicecodes.com'        => true,
			'staging.dicecodes.com'    => true,
			'schema.org'               => true,
			'www.schema.org'           => true,
			'developer.wordpress.org'  => true,
			'wordpress.org'            => true,
			'www.gnu.org'              => true,
			'github.com'               => true,
			'example.com'              => true,
			'www.example.com'          => true,
			'news.ycombinator.com'     => true,
		);
	}

	/**
	 * The External Services section of the readme.
	 *
	 * @return string
	 */
	private function disclosure() {
		$readme = (string) file_get_contents( BLOGCRAFT_PATH . 'readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( ! preg_match( '/^== External Services ==$(.*?)(?=^== |\z)/ms', $readme, $found ) ) {
			return '';
		}

		return $found[1];
	}

	/**
	 * The host of a URL.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	private function host_of( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) ? strtolower( $host ) : '';
	}

	public function test_the_readme_has_a_section_disclosing_external_services() {
		$this->assertNotSame( '', $this->disclosure(), 'readme.txt has no External Services section' );
	}

	public function test_every_address_the_code_can_reach_is_disclosed() {
		$section = $this->disclosure();
		$skip    = $this->not_a_service();
		$missing = array();

		foreach ( (array) glob( BLOGCRAFT_PATH . 'includes/*.php' ) as $path ) {
			$body = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( ! preg_match_all( '#https://[a-z0-9.-]+\.[a-z]{2,}#i', $body, $hits ) ) {
				continue;
			}

			foreach ( array_unique( $hits[0] ) as $url ) {
				$host = $this->host_of( $url );

				if ( '' === $host || isset( $skip[ $host ] ) ) {
					continue;
				}

				if ( false === strpos( $section, $host ) ) {
					$missing[ $host ] = $host . ' (' . basename( $path ) . ')';
				}
			}
		}

		sort( $missing );

		$this->assertSame(
			array(),
			$missing,
			'these are contacted but not disclosed: ' . implode( ', ', $missing )
		);
	}

	public function test_every_provider_endpoint_is_disclosed() {
		// The addresses live in a data file rather than in the code, so the
		// sweep above cannot see them. These are the ones actually called —
		// key_url and docs_url are links shown on screen for the reader to
		// visit themselves, and reach no server on the reader's behalf.
		$raw = (string) file_get_contents( BLOGCRAFT_PATH . 'data/providers.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( $raw, true );

		$this->assertIsArray( $data, 'data/providers.json does not decode' );

		$section = $this->disclosure();
		$missing = array();

		$called = self::gather( $data, array( 'endpoint', 'base_url' ) );

		$this->assertNotEmpty( $called, 'no provider endpoints found to check' );

		foreach ( $called as $url ) {
			$host = $this->host_of( $url );

			// The local runners answer on the machine itself, so nothing
			// leaves the site and there is no third party to disclose.
			if ( '' === $host || 0 === strpos( $host, 'localhost' ) || '127.0.0.1' === $host ) {
				continue;
			}

			if ( false === strpos( $section, $host ) ) {
				$missing[ $host ] = $host;
			}
		}

		sort( $missing );

		$this->assertSame(
			array(),
			$missing,
			'these provider endpoints are not disclosed: ' . implode( ', ', $missing )
		);
	}

	public function test_every_disclosed_service_says_where_its_policies_are() {
		// A named service with no link is the finding the review raised. A
		// service that publishes nothing, or refuses automated readers, is
		// allowed — but it has to say so rather than stay silent.
		$lines   = preg_split( '/\R/', $this->disclosure() );
		$silent  = array();
		$checked = 0;

		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line || 0 !== strpos( $line, '* ' ) ) {
				continue;
			}

			++$checked;

			if ( false !== strpos( $line, 'http' ) ) {
				continue;
			}

			// The wording used for the ones that genuinely have nothing to
			// link, so the exception has to be stated on the line itself.
			if ( preg_match( '/publishes no|cannot be linked|no policy to link|listed under/i', $line ) ) {
				continue;
			}

			$silent[] = substr( $line, 0, 60 );
		}

		$this->assertGreaterThan( 10, $checked, 'the disclosure lists suspiciously few services' );

		$this->assertSame(
			array(),
			$silent,
			'these services are named with no policy link and no reason given: ' . implode( ' | ', $silent )
		);
	}

	public function test_the_section_fits_inside_what_the_readme_parser_keeps() {
		// Giving every provider its own links pushed this to 6,008. The
		// parser truncates at 5,000 — so the fix for "undocumented service"
		// would have silently cut the last services back off again.
		$this->assertLessThan(
			5000,
			strlen( $this->disclosure() ),
			'the External Services section is over the 5000-character ceiling and will be truncated'
		);
	}

	/**
	 * Every value stored under one of the given keys, at any depth.
	 *
	 * @param mixed $node  Data to walk.
	 * @param array $names Keys worth collecting.
	 * @return array
	 */
	private static function gather( $node, $names ) {
		$found = array();

		if ( ! is_array( $node ) ) {
			return $found;
		}

		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) ) {
				$found = array_merge( $found, self::gather( $value, $names ) );

				continue;
			}

			if ( is_string( $value ) && in_array( (string) $key, $names, true ) && 0 === strpos( $value, 'http' ) ) {
				$found[] = $value;
			}
		}

		return $found;
	}
}
