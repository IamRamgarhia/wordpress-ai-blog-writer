# Blogcraft Phase 0 — Foundations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the compliant, tested foundation every later phase plugs into — plugin skeleton, dev tooling, CI across the full PHP matrix, DB schema with migrations, settings framework, encrypted key storage, security helpers, job queue, timeout-surviving worker, cron health monitoring, admin shell, and a clean Plugin Check pass.

**Architecture:** A single bootstrap class wires together focused, single-responsibility collaborators loaded by a dependency-free custom autoloader. The job queue is a DB table with pessimistic locking; the worker executes exactly one pipeline stage per cron tick so a multi-minute pipeline survives a 30-second `max_execution_time`. No runtime Composer dependencies ship — Composer is dev-only tooling.

**Tech Stack:** PHP 7.4–8.5, WordPress 6.0–7.0, MySQL 5.6+/MariaDB 10.1+, PHPUnit 9.6 + Yoast PHPUnit Polyfills, `@wordpress/env` (Docker) for integration tests, PHPCS + WordPress-Coding-Standards + PHPCompatibilityWP, GitHub Actions.

**Spec:** [`docs/superpowers/specs/2026-08-17-blogcraft-design.md`](../specs/2026-08-17-blogcraft-design.md)

## Global Constraints

Every task's requirements implicitly include this section. Values copied verbatim from the spec.

- **`Requires PHP`: 7.4** — forgo enums, `match`, constructor promotion, `readonly`, union types, first-class callables. Typed properties and arrow functions are allowed.
- **`Requires at least`: 6.0**; **`Tested up to`: 7.0**
- **Runs clean on PHP 8.5** — declare every class property (no dynamic properties); write `?Type` explicitly (no implicit nullables); no `${var}` interpolation; never pass `null` to non-nullable internal params.
- **Prefixes:** classes `Blogcraft_`, functions/hooks/options `blogcraft_`, constants `BLOGCRAFT_`, tables `{$wpdb->prefix}blogcraft_`.
- **Text domain:** `blogcraft` (must match the plugin slug exactly).
- **Database portability:** plain SQL only — no CTEs, window functions, or `JSON_TABLE`. Indexed `varchar` columns capped at **191 chars**. Always `$wpdb->get_charset_collate()` and `$wpdb->prepare()`.
- **Zero runtime dependencies.** Composer is `require-dev` only; no `vendor/` directory ships. Use WordPress' bundled libraries (Guideline 13).
- **Security, every file:** `ABSPATH` guard at top; nonce verification on every state-changing action; capability check on every admin action; sanitize all input; escape all output.
- **No CDN assets** (Guideline 8). No iframes in admin. No self-updater.
- **All admin notices dismissible** (Guideline 11).
- **Copy rule (Guideline 9):** no traffic or ranking promises anywhere in code, UI, or readme.
- **License:** GPLv2 or later.

---

## File Structure

```
blogcraft.php                          Main file: headers, constants, bootstrap
uninstall.php                          Full data removal
readme.txt                             WP.org readme
LICENSE                                GPLv2 text
composer.json                          Dev tooling only
package.json                           wp-env scripts
.wp-env.json                           Integration test environment
phpcs.xml.dist                         WPCS + PHPCompatibility ruleset
phpunit.xml.dist                       Test suites
.github/workflows/ci.yml               PHP 7.4-8.5 matrix

includes/
  class-blogcraft.php                  Bootstrap/container, hook registration
  class-blogcraft-autoloader.php       Dependency-free class autoloader
  class-blogcraft-activator.php        Activation: tables, caps, cron
  class-blogcraft-deactivator.php      Deactivation: unschedule cron
  class-blogcraft-migrator.php         Schema creation + version migrations
  class-blogcraft-crypto.php           Encrypt/decrypt secrets at rest
  class-blogcraft-capabilities.php     Custom capability management
  class-blogcraft-request.php          Nonce + capability verification
  class-blogcraft-settings.php         Schema-driven settings get/set
  class-blogcraft-settings-schema.php  Setting definitions, defaults, sanitizers
  class-blogcraft-logger.php           Structured logging to DB with rotation
  class-blogcraft-job.php              Job value object
  class-blogcraft-queue.php            Enqueue/claim/complete/fail
  class-blogcraft-worker.php           Executes one stage per tick
  class-blogcraft-scheduler.php        Cron registration
  class-blogcraft-cron-health.php      WP-Cron heartbeat detection
  class-blogcraft-admin.php            Admin menu registration
  class-blogcraft-notices.php          Dismissible notice manager

tests/
  bootstrap.php                        Test bootstrap
  integration/                         WordPress-dependent tests
  unit/                                Pure-logic tests
```

---

### Task 1: Plugin skeleton, dev tooling, and CI

**Files:**
- Create: `blogcraft.php`, `includes/class-blogcraft-autoloader.php`, `includes/class-blogcraft.php`
- Create: `composer.json`, `phpcs.xml.dist`, `phpunit.xml.dist`, `package.json`, `.wp-env.json`, `tests/bootstrap.php`, `.github/workflows/ci.yml`, `LICENSE`, `.gitignore`
- Test: `tests/integration/test-bootstrap.php`

**Interfaces:**
- Consumes: nothing
- Produces: constants `BLOGCRAFT_VERSION` (string), `BLOGCRAFT_FILE` (string), `BLOGCRAFT_PATH` (string, trailing slash), `BLOGCRAFT_URL` (string, trailing slash), `BLOGCRAFT_DB_VERSION` (string). Class `Blogcraft_Autoloader` with `public static function register(): void`. Class `Blogcraft` with `public static function instance(): Blogcraft` and `public function run(): void`.

- [ ] **Step 1: Create the repository scaffolding files**

`.gitignore`:
```
vendor/
node_modules/
.phpunit.result.cache
*.log
```

`LICENSE`: paste the full GPLv2 text from https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt

`composer.json`:
```json
{
  "name": "dicecodes/blogcraft",
  "description": "AI blog writer and content generator for WordPress.",
  "type": "wordpress-plugin",
  "license": "GPL-2.0-or-later",
  "require": {},
  "require-dev": {
    "phpunit/phpunit": "^9.6",
    "yoast/phpunit-polyfills": "^2.0",
    "squizlabs/php_codesniffer": "^3.9",
    "wp-coding-standards/wpcs": "^3.1",
    "phpcompatibility/phpcompatibility-wp": "^2.1",
    "dealerdirect/phpcodesniffer-composer-installer": "^1.0"
  },
  "config": {
    "allow-plugins": {
      "dealerdirect/phpcodesniffer-composer-installer": true
    }
  },
  "scripts": {
    "lint": "phpcs",
    "lint:fix": "phpcbf"
  }
}
```

`phpcs.xml.dist`:
```xml
<?xml version="1.0"?>
<ruleset name="Blogcraft">
	<description>Blogcraft coding standards.</description>

	<file>.</file>
	<exclude-pattern>/vendor/*</exclude-pattern>
	<exclude-pattern>/node_modules/*</exclude-pattern>

	<arg name="extensions" value="php"/>
	<arg value="ps"/>

	<rule ref="WordPress">
		<exclude name="WordPress.Files.FileName.InvalidClassFileName"/>
	</rule>

	<rule ref="WordPress.WP.I18n">
		<properties>
			<property name="text_domain" type="array" value="blogcraft"/>
		</properties>
	</rule>

	<rule ref="WordPress.NamingConventions.PrefixAllGlobals">
		<properties>
			<property name="prefixes" type="array" value="blogcraft,BLOGCRAFT,Blogcraft"/>
		</properties>
	</rule>

	<config name="minimum_wp_version" value="6.0"/>
	<config name="testVersion" value="7.4-"/>
	<rule ref="PHPCompatibilityWP"/>
</ruleset>
```

`package.json`:
```json
{
  "name": "blogcraft",
  "private": true,
  "devDependencies": {
    "@wordpress/env": "^10.0.0"
  },
  "scripts": {
    "env:start": "wp-env start",
    "env:stop": "wp-env stop",
    "test": "wp-env run tests-cli --env-cwd=wp-content/plugins/blogcraft vendor/bin/phpunit"
  }
}
```

`.wp-env.json`:
```json
{
  "core": "WordPress/WordPress#master",
  "plugins": ["."],
  "phpVersion": "7.4"
}
```

`phpunit.xml.dist`:
```xml
<?xml version="1.0"?>
<phpunit bootstrap="tests/bootstrap.php" colors="true" convertErrorsToExceptions="true"
         convertNoticesToExceptions="true" convertWarningsToExceptions="true">
	<testsuites>
		<testsuite name="unit">
			<directory suffix=".php">./tests/unit/</directory>
		</testsuite>
		<testsuite name="integration">
			<directory suffix=".php">./tests/integration/</directory>
		</testsuite>
	</testsuites>
</phpunit>
```

`tests/bootstrap.php`:
```php
<?php
/**
 * PHPUnit bootstrap for Blogcraft.
 *
 * @package Blogcraft
 */

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$blogcraft_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $blogcraft_tests_dir ) {
	$blogcraft_tests_dir = '/wordpress-phpunit';
}

require_once $blogcraft_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/blogcraft.php';
	}
);

require $blogcraft_tests_dir . '/includes/bootstrap.php';
```

- [ ] **Step 2: Write the failing test**

`tests/integration/test-bootstrap.php`:
```php
<?php
/**
 * Bootstrap tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Bootstrap extends WP_UnitTestCase {

	public function test_constants_are_defined() {
		$this->assertTrue( defined( 'BLOGCRAFT_VERSION' ) );
		$this->assertTrue( defined( 'BLOGCRAFT_FILE' ) );
		$this->assertTrue( defined( 'BLOGCRAFT_PATH' ) );
		$this->assertTrue( defined( 'BLOGCRAFT_URL' ) );
		$this->assertTrue( defined( 'BLOGCRAFT_DB_VERSION' ) );
	}

	public function test_path_constant_has_trailing_slash() {
		$this->assertSame( trailingslashit( BLOGCRAFT_PATH ), BLOGCRAFT_PATH );
	}

	public function test_autoloader_resolves_plugin_classes() {
		$this->assertTrue( class_exists( 'Blogcraft' ) );
	}

	public function test_instance_returns_singleton() {
		$this->assertSame( Blogcraft::instance(), Blogcraft::instance() );
	}
}
```

- [ ] **Step 3: Run the test to verify it fails**

```bash
npm install && composer install && npm run env:start && npm test
```
Expected: FAIL — `BLOGCRAFT_VERSION` not defined, `Blogcraft` class not found.

- [ ] **Step 4: Write the main plugin file**

`blogcraft.php`:
```php
<?php
/**
 * Plugin Name:       Blogcraft
 * Plugin URI:        https://dicecodes.com/blogcraft
 * Description:       AI blog writer and content generator. Connect any AI provider with your own API key.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Dicecodes
 * Author URI:        https://dicecodes.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       blogcraft
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

define( 'BLOGCRAFT_VERSION', '0.1.0' );
define( 'BLOGCRAFT_DB_VERSION', '1' );
define( 'BLOGCRAFT_FILE', __FILE__ );
define( 'BLOGCRAFT_PATH', plugin_dir_path( __FILE__ ) );
define( 'BLOGCRAFT_URL', plugin_dir_url( __FILE__ ) );

require_once BLOGCRAFT_PATH . 'includes/class-blogcraft-autoloader.php';

Blogcraft_Autoloader::register();

Blogcraft::instance()->run();
```

- [ ] **Step 5: Write the autoloader**

`includes/class-blogcraft-autoloader.php`:
```php
<?php
/**
 * Class autoloader.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maps Blogcraft_Foo_Bar to includes/class-blogcraft-foo-bar.php.
 */
class Blogcraft_Autoloader {

	/**
	 * Register the autoloader.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Load a class file.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function autoload( $class_name ) {
		if ( 'Blogcraft' !== $class_name && 0 !== strpos( $class_name, 'Blogcraft_' ) ) {
			return;
		}

		$file = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
		$path = BLOGCRAFT_PATH . 'includes/' . $file;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
```

- [ ] **Step 6: Write the bootstrap class**

`includes/class-blogcraft.php`:
```php
<?php
/**
 * Plugin bootstrap.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's collaborators to WordPress hooks.
 */
class Blogcraft {

	/**
	 * Singleton instance.
	 *
	 * @var Blogcraft|null
	 */
	private static $instance = null;

	/**
	 * Whether run() has already executed.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Private constructor: use instance().
	 */
	private function __construct() {}

	/**
	 * Get the singleton instance.
	 *
	 * @return Blogcraft
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks. Safe to call more than once.
	 *
	 * @return void
	 */
	public function run() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		load_plugin_textdomain( 'blogcraft', false, dirname( plugin_basename( BLOGCRAFT_FILE ) ) . '/languages' );
	}
}
```

- [ ] **Step 7: Run the test to verify it passes**

```bash
npm test
```
Expected: PASS — 4 assertions green.

- [ ] **Step 8: Verify coding standards pass**

```bash
composer lint
```
Expected: no errors. Fix any WPCS findings before continuing — the standard is enforced from the first file, not retrofitted.

- [ ] **Step 9: Add CI covering the full PHP matrix**

`.github/workflows/ci.yml`:
```yaml
name: CI

on: [push, pull_request]

jobs:
  lint:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none
      - run: composer install --prefer-dist --no-progress
      - run: composer lint

  test:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ['7.4', '8.1', '8.2', '8.3', '8.4', '8.5']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: none
      - run: composer install --prefer-dist --no-progress
      - name: Lint for syntax errors
        run: find . -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
```

- [ ] **Step 10: Commit**

```bash
git add .
git commit -m "feat: plugin skeleton, autoloader, dev tooling, and PHP 7.4-8.5 CI"
```

---

### Task 2: Database schema and migrations

**Files:**
- Create: `includes/class-blogcraft-migrator.php`
- Test: `tests/integration/test-migrator.php`

**Interfaces:**
- Consumes: `BLOGCRAFT_DB_VERSION` from Task 1.
- Produces: `Blogcraft_Migrator` with `public static function table_name( string $suffix ): string`, `public static function migrate(): void`, `public static function drop_tables(): void`. Option key `blogcraft_db_version` (string). Tables `{prefix}blogcraft_jobs` and `{prefix}blogcraft_log`.

- [ ] **Step 1: Write the failing test**

`tests/integration/test-migrator.php`:
```php
<?php
/**
 * Migrator tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Migrator extends WP_UnitTestCase {

	public function test_table_name_is_prefixed() {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'blogcraft_jobs', Blogcraft_Migrator::table_name( 'jobs' ) );
	}

	public function test_migrate_creates_jobs_table() {
		global $wpdb;
		Blogcraft_Migrator::migrate();
		$table = Blogcraft_Migrator::table_name( 'jobs' );
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$this->assertSame( $table, $found );
	}

	public function test_migrate_creates_log_table() {
		global $wpdb;
		Blogcraft_Migrator::migrate();
		$table = Blogcraft_Migrator::table_name( 'log' );
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$this->assertSame( $table, $found );
	}

	public function test_migrate_records_db_version() {
		Blogcraft_Migrator::migrate();
		$this->assertSame( BLOGCRAFT_DB_VERSION, get_option( 'blogcraft_db_version' ) );
	}

	public function test_migrate_is_idempotent() {
		Blogcraft_Migrator::migrate();
		Blogcraft_Migrator::migrate();
		$this->assertSame( BLOGCRAFT_DB_VERSION, get_option( 'blogcraft_db_version' ) );
	}

	public function test_drop_tables_removes_tables_and_version() {
		global $wpdb;
		Blogcraft_Migrator::migrate();
		Blogcraft_Migrator::drop_tables();
		$table = Blogcraft_Migrator::table_name( 'jobs' );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		$this->assertFalse( get_option( 'blogcraft_db_version' ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
npm test -- --filter Test_Blogcraft_Migrator
```
Expected: FAIL — class `Blogcraft_Migrator` not found.

- [ ] **Step 3: Write the migrator**

`includes/class-blogcraft-migrator.php`:
```php
<?php
/**
 * Database schema and migrations.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and versions Blogcraft's custom tables.
 */
class Blogcraft_Migrator {

	/**
	 * Option storing the installed schema version.
	 */
	const VERSION_OPTION = 'blogcraft_db_version';

	/**
	 * Build a fully prefixed table name.
	 *
	 * @param string $suffix Table suffix, e.g. 'jobs'.
	 * @return string
	 */
	public static function table_name( $suffix ) {
		global $wpdb;

		return $wpdb->prefix . 'blogcraft_' . $suffix;
	}

	/**
	 * Create or update the schema.
	 *
	 * Uses dbDelta, which is idempotent. Indexed varchar columns are capped at
	 * 191 characters for the utf8mb4 767-byte InnoDB key limit on older MySQL.
	 *
	 * @return void
	 */
	public static function migrate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$jobs            = self::table_name( 'jobs' );
		$log             = self::table_name( 'log' );

		$jobs_sql = "CREATE TABLE {$jobs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			pipeline varchar(64) NOT NULL DEFAULT '',
			stage varchar(64) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			payload longtext NULL,
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			max_attempts smallint(5) unsigned NOT NULL DEFAULT 3,
			available_at datetime NOT NULL,
			locked_at datetime NULL,
			lock_token varchar(64) NULL,
			last_error text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_available (status, available_at),
			KEY lock_token (lock_token)
		) {$charset_collate};";

		$log_sql = "CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NULL,
			level varchar(20) NOT NULL DEFAULT 'info',
			message text NOT NULL,
			context longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY job_id (job_id),
			KEY level_created (level, created_at)
		) {$charset_collate};";

		dbDelta( $jobs_sql );
		dbDelta( $log_sql );

		update_option( self::VERSION_OPTION, BLOGCRAFT_DB_VERSION, false );
	}

	/**
	 * Drop all Blogcraft tables and the version marker.
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;

		foreach ( array( 'jobs', 'log' ) as $suffix ) {
			$table = self::table_name( $suffix );
			// Table name cannot be parameterised; it is built from $wpdb->prefix.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		delete_option( self::VERSION_OPTION );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
npm test -- --filter Test_Blogcraft_Migrator
```
Expected: PASS — 6 assertions green.

- [ ] **Step 5: Commit**

```bash
git add includes/class-blogcraft-migrator.php tests/integration/test-migrator.php
git commit -m "feat: database schema with idempotent migrations"
```

---

### Task 3: Capabilities and activation lifecycle

**Files:**
- Create: `includes/class-blogcraft-capabilities.php`, `includes/class-blogcraft-activator.php`, `includes/class-blogcraft-deactivator.php`
- Modify: `blogcraft.php` (register activation/deactivation hooks)
- Test: `tests/integration/test-activation.php`

**Interfaces:**
- Consumes: `Blogcraft_Migrator::migrate()`, `Blogcraft_Migrator::drop_tables()` from Task 2.
- Produces: `Blogcraft_Capabilities::MANAGE` (string constant, value `manage_blogcraft`), `Blogcraft_Capabilities::add()`, `Blogcraft_Capabilities::remove()`. `Blogcraft_Activator::activate()`, `Blogcraft_Deactivator::deactivate()`.

- [ ] **Step 1: Write the failing test**

`tests/integration/test-activation.php`:
```php
<?php
/**
 * Activation lifecycle tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Activation extends WP_UnitTestCase {

	public function tear_down() {
		Blogcraft_Capabilities::remove();
		parent::tear_down();
	}

	public function test_activation_grants_capability_to_administrator() {
		Blogcraft_Activator::activate();
		$role = get_role( 'administrator' );
		$this->assertTrue( $role->has_cap( Blogcraft_Capabilities::MANAGE ) );
	}

	public function test_activation_does_not_grant_capability_to_subscriber() {
		Blogcraft_Activator::activate();
		$role = get_role( 'subscriber' );
		$this->assertFalse( $role->has_cap( Blogcraft_Capabilities::MANAGE ) );
	}

	public function test_activation_creates_tables() {
		global $wpdb;
		Blogcraft_Activator::activate();
		$table = Blogcraft_Migrator::table_name( 'jobs' );
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
	}

	public function test_remove_revokes_capability() {
		Blogcraft_Activator::activate();
		Blogcraft_Capabilities::remove();
		$role = get_role( 'administrator' );
		$this->assertFalse( $role->has_cap( Blogcraft_Capabilities::MANAGE ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
npm test -- --filter Test_Blogcraft_Activation
```
Expected: FAIL — `Blogcraft_Capabilities` not found.

- [ ] **Step 3: Write the capabilities class**

`includes/class-blogcraft-capabilities.php`:
```php
<?php
/**
 * Custom capability management.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Grants and revokes Blogcraft's own capability.
 *
 * A dedicated capability lets site owners delegate Blogcraft to an editor
 * without handing over full manage_options access.
 */
class Blogcraft_Capabilities {

	/**
	 * Capability required to manage Blogcraft.
	 */
	const MANAGE = 'manage_blogcraft';

	/**
	 * Roles that receive the capability on activation.
	 *
	 * @return array
	 */
	private static function default_roles() {
		return array( 'administrator' );
	}

	/**
	 * Grant the capability to default roles.
	 *
	 * @return void
	 */
	public static function add() {
		foreach ( self::default_roles() as $role_name ) {
			$role = get_role( $role_name );
			if ( $role instanceof WP_Role ) {
				$role->add_cap( self::MANAGE );
			}
		}
	}

	/**
	 * Revoke the capability from every role that has it.
	 *
	 * @return void
	 */
	public static function remove() {
		$roles = wp_roles();

		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role instanceof WP_Role ) {
				$role->remove_cap( self::MANAGE );
			}
		}
	}
}
```

- [ ] **Step 4: Write the activator and deactivator**

`includes/class-blogcraft-activator.php`:
```php
<?php
/**
 * Activation routine.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the plugin is activated.
 */
class Blogcraft_Activator {

	/**
	 * Create schema and grant capabilities.
	 *
	 * @return void
	 */
	public static function activate() {
		Blogcraft_Migrator::migrate();
		Blogcraft_Capabilities::add();
	}
}
```

`includes/class-blogcraft-deactivator.php`:
```php
<?php
/**
 * Deactivation routine.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs when the plugin is deactivated.
 *
 * Deliberately leaves tables and settings intact — data removal belongs in
 * uninstall.php, so deactivating for troubleshooting is non-destructive.
 */
class Blogcraft_Deactivator {

	/**
	 * Tear down scheduled work.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Blogcraft_Scheduler::unschedule();
	}
}
```

- [ ] **Step 5: Register the lifecycle hooks**

In `blogcraft.php`, insert immediately after the `Blogcraft_Autoloader::register();` line:

```php
register_activation_hook( __FILE__, array( 'Blogcraft_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Blogcraft_Deactivator', 'deactivate' ) );
```

Note: `Blogcraft_Deactivator::deactivate()` calls `Blogcraft_Scheduler::unschedule()`, which is created in Task 8. Until then, deactivation tests are not run — the activation tests in this task do not exercise that path.

- [ ] **Step 6: Run the test to verify it passes**

```bash
npm test -- --filter Test_Blogcraft_Activation
```
Expected: PASS — 4 assertions green.

- [ ] **Step 7: Commit**

```bash
git add includes/class-blogcraft-capabilities.php includes/class-blogcraft-activator.php includes/class-blogcraft-deactivator.php blogcraft.php tests/integration/test-activation.php
git commit -m "feat: custom capability and activation lifecycle"
```

---

### Task 4: Encrypted secret storage

**Files:**
- Create: `includes/class-blogcraft-crypto.php`
- Test: `tests/integration/test-crypto.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Blogcraft_Crypto::encrypt( string $plaintext ): string`, `Blogcraft_Crypto::decrypt( string $ciphertext ): string` (returns `''` on failure), `Blogcraft_Crypto::mask( string $secret ): string`, `Blogcraft_Crypto::is_available(): bool`.

- [ ] **Step 1: Write the failing test**

`tests/integration/test-crypto.php`:
```php
<?php
/**
 * Crypto tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Crypto extends WP_UnitTestCase {

	public function test_encrypt_decrypt_roundtrip() {
		$secret = 'sk-test-1234567890abcdef';
		$cipher = Blogcraft_Crypto::encrypt( $secret );
		$this->assertSame( $secret, Blogcraft_Crypto::decrypt( $cipher ) );
	}

	public function test_ciphertext_does_not_contain_plaintext() {
		$secret = 'sk-test-1234567890abcdef';
		$cipher = Blogcraft_Crypto::encrypt( $secret );
		$this->assertStringNotContainsString( $secret, $cipher );
	}

	public function test_encrypting_same_value_twice_yields_different_ciphertext() {
		$secret = 'sk-test-1234567890abcdef';
		$this->assertNotSame(
			Blogcraft_Crypto::encrypt( $secret ),
			Blogcraft_Crypto::encrypt( $secret )
		);
	}

	public function test_decrypt_returns_empty_string_on_garbage() {
		$this->assertSame( '', Blogcraft_Crypto::decrypt( 'not-valid-ciphertext' ) );
	}

	public function test_decrypt_returns_empty_string_on_empty_input() {
		$this->assertSame( '', Blogcraft_Crypto::decrypt( '' ) );
	}

	public function test_encrypt_returns_empty_string_on_empty_input() {
		$this->assertSame( '', Blogcraft_Crypto::encrypt( '' ) );
	}

	public function test_mask_reveals_only_last_four_characters() {
		$this->assertSame( '••••cdef', Blogcraft_Crypto::mask( 'sk-test-1234567890abcdef' ) );
	}

	public function test_mask_of_short_secret_reveals_nothing() {
		$this->assertSame( '••••', Blogcraft_Crypto::mask( 'abc' ) );
	}

	public function test_mask_of_empty_secret_is_empty() {
		$this->assertSame( '', Blogcraft_Crypto::mask( '' ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
npm test -- --filter Test_Blogcraft_Crypto
```
Expected: FAIL — `Blogcraft_Crypto` not found.

- [ ] **Step 3: Write the crypto class**

`includes/class-blogcraft-crypto.php`:
```php
<?php
/**
 * Secret encryption at rest.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts API keys before they are written to the options table.
 *
 * The key is derived from the site's SECURE_AUTH salt, so secrets are not
 * portable between installs and a database dump alone does not disclose them.
 * If the salt is rotated, existing ciphertext becomes undecryptable; decrypt()
 * returns an empty string and the UI prompts for re-entry.
 */
class Blogcraft_Crypto {

	/**
	 * Prefix marking a value as Blogcraft ciphertext.
	 */
	const PREFIX = 'bcv1:';

	/**
	 * Whether the sodium extension is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'sodium_crypto_secretbox' );
	}

	/**
	 * Derive the 32-byte encryption key from WordPress salts.
	 *
	 * @return string
	 */
	private static function key() {
		return hash( 'sha256', wp_salt( 'secure_auth' ), true );
	}

	/**
	 * Encrypt a secret.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string Prefixed base64 ciphertext, or '' on empty input.
	 */
	public static function encrypt( $plaintext ) {
		if ( '' === $plaintext || ! self::is_available() ) {
			return '';
		}

		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );

		return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a secret.
	 *
	 * @param string $ciphertext Value produced by encrypt().
	 * @return string Plaintext, or '' if the value cannot be decrypted.
	 */
	public static function decrypt( $ciphertext ) {
		if ( '' === $ciphertext || ! self::is_available() ) {
			return '';
		}

		if ( 0 !== strpos( $ciphertext, self::PREFIX ) ) {
			return '';
		}

		$raw = base64_decode( substr( $ciphertext, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );

		return false === $plain ? '' : $plain;
	}

	/**
	 * Produce a display-safe rendering of a secret.
	 *
	 * @param string $secret Secret to mask.
	 * @return string
	 */
	public static function mask( $secret ) {
		if ( '' === $secret ) {
			return '';
		}

		if ( strlen( $secret ) <= 4 ) {
			return '••••';
		}

		return '••••' . substr( $secret, -4 );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
npm test -- --filter Test_Blogcraft_Crypto
```
Expected: PASS — 9 assertions green.

- [ ] **Step 5: Commit**

```bash
git add includes/class-blogcraft-crypto.php tests/integration/test-crypto.php
git commit -m "feat: encrypted secret storage with masked display"
```

---

### Task 5: Settings framework

**Files:**
- Create: `includes/class-blogcraft-settings-schema.php`, `includes/class-blogcraft-settings.php`
- Test: `tests/integration/test-settings.php`

**Interfaces:**
- Consumes: `Blogcraft_Crypto::encrypt()`, `Blogcraft_Crypto::decrypt()` from Task 4.
- Produces: `Blogcraft_Settings_Schema::all(): array` (map of key => `array( 'default' => mixed, 'type' => string, 'secret' => bool )`), `Blogcraft_Settings::get( string $key )`, `Blogcraft_Settings::set( string $key, $value ): bool`, `Blogcraft_Settings::all(): array`, `Blogcraft_Settings::delete( string $key ): bool`. Option key `blogcraft_settings`.

- [ ] **Step 1: Write the failing test**

`tests/integration/test-settings.php`:
```php
<?php
/**
 * Settings tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Settings extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( 'blogcraft_settings' );
		parent::tear_down();
	}

	public function test_get_returns_schema_default_when_unset() {
		$this->assertSame( 3, Blogcraft_Settings::get( 'queue_max_attempts' ) );
	}

	public function test_get_returns_null_for_unknown_key() {
		$this->assertNull( Blogcraft_Settings::get( 'no_such_setting' ) );
	}

	public function test_set_then_get_roundtrip() {
		Blogcraft_Settings::set( 'queue_max_attempts', 5 );
		$this->assertSame( 5, Blogcraft_Settings::get( 'queue_max_attempts' ) );
	}

	public function test_set_rejects_unknown_key() {
		$this->assertFalse( Blogcraft_Settings::set( 'no_such_setting', 'x' ) );
	}

	public function test_integer_setting_is_cast() {
		Blogcraft_Settings::set( 'queue_max_attempts', '7' );
		$this->assertSame( 7, Blogcraft_Settings::get( 'queue_max_attempts' ) );
	}

	public function test_boolean_setting_is_cast() {
		Blogcraft_Settings::set( 'cron_health_notice_enabled', '1' );
		$this->assertTrue( Blogcraft_Settings::get( 'cron_health_notice_enabled' ) );
	}

	public function test_string_setting_is_sanitised() {
		Blogcraft_Settings::set( 'provider_base_url', '  https://api.groq.com/openai/v1  ' );
		$this->assertSame( 'https://api.groq.com/openai/v1', Blogcraft_Settings::get( 'provider_base_url' ) );
	}

	public function test_secret_is_not_stored_in_plaintext() {
		Blogcraft_Settings::set( 'provider_api_key', 'sk-secret-value-1234' );
		$raw = get_option( 'blogcraft_settings' );
		$this->assertStringNotContainsString( 'sk-secret-value-1234', wp_json_encode( $raw ) );
	}

	public function test_secret_roundtrips_through_get() {
		Blogcraft_Settings::set( 'provider_api_key', 'sk-secret-value-1234' );
		$this->assertSame( 'sk-secret-value-1234', Blogcraft_Settings::get( 'provider_api_key' ) );
	}

	public function test_delete_restores_default() {
		Blogcraft_Settings::set( 'queue_max_attempts', 9 );
		Blogcraft_Settings::delete( 'queue_max_attempts' );
		$this->assertSame( 3, Blogcraft_Settings::get( 'queue_max_attempts' ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
npm test -- --filter Test_Blogcraft_Settings
```
Expected: FAIL — `Blogcraft_Settings` not found.

- [ ] **Step 3: Write the settings schema**

`includes/class-blogcraft-settings-schema.php`:
```php
<?php
/**
 * Settings definitions.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for every setting: default, type, and secrecy.
 *
 * Later phases extend this map rather than inventing parallel option keys.
 */
class Blogcraft_Settings_Schema {

	/**
	 * All known settings.
	 *
	 * Types: 'int', 'bool', 'string', 'url'.
	 * Secrets are encrypted at rest and masked in the UI.
	 *
	 * @return array
	 */
	public static function all() {
		return array(
			'queue_max_attempts'         => array(
				'default' => 3,
				'type'    => 'int',
				'secret'  => false,
			),
			'queue_time_budget'          => array(
				'default' => 20,
				'type'    => 'int',
				'secret'  => false,
			),
			'cron_health_notice_enabled' => array(
				'default' => true,
				'type'    => 'bool',
				'secret'  => false,
			),
			'provider_base_url'          => array(
				'default' => '',
				'type'    => 'url',
				'secret'  => false,
			),
			'provider_api_key'           => array(
				'default' => '',
				'type'    => 'string',
				'secret'  => true,
			),
		);
	}

	/**
	 * Look up one setting definition.
	 *
	 * @param string $key Setting key.
	 * @return array|null
	 */
	public static function get( $key ) {
		$all = self::all();

		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}
}
```

- [ ] **Step 4: Write the settings accessor**

`includes/class-blogcraft-settings.php`:
```php
<?php
/**
 * Settings storage.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes plugin settings as a single option.
 *
 * One option keeps autoloaded row count low and makes export/import trivial.
 * Values are sanitised on write according to the schema, so readers never
 * have to defend against bad types.
 */
class Blogcraft_Settings {

	/**
	 * Option key holding all settings.
	 */
	const OPTION = 'blogcraft_settings';

	/**
	 * Read the raw stored array.
	 *
	 * @return array
	 */
	private static function raw() {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Coerce a value to its declared type.
	 *
	 * @param mixed  $value Incoming value.
	 * @param string $type  Declared type.
	 * @return mixed
	 */
	private static function sanitize( $value, $type ) {
		switch ( $type ) {
			case 'int':
				return (int) $value;
			case 'bool':
				return (bool) $value;
			case 'url':
				return esc_url_raw( trim( (string) $value ) );
			default:
				return sanitize_text_field( trim( (string) $value ) );
		}
	}

	/**
	 * Get a setting value, falling back to its schema default.
	 *
	 * @param string $key Setting key.
	 * @return mixed Null if the key is not in the schema.
	 */
	public static function get( $key ) {
		$definition = Blogcraft_Settings_Schema::get( $key );

		if ( null === $definition ) {
			return null;
		}

		$stored = self::raw();

		if ( ! array_key_exists( $key, $stored ) ) {
			return $definition['default'];
		}

		$value = $stored[ $key ];

		if ( $definition['secret'] ) {
			return Blogcraft_Crypto::decrypt( (string) $value );
		}

		return $value;
	}

	/**
	 * Write a setting value.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value New value.
	 * @return bool False if the key is not in the schema.
	 */
	public static function set( $key, $value ) {
		$definition = Blogcraft_Settings_Schema::get( $key );

		if ( null === $definition ) {
			return false;
		}

		$stored = self::raw();

		if ( $definition['secret'] ) {
			$stored[ $key ] = Blogcraft_Crypto::encrypt( (string) $value );
		} else {
			$stored[ $key ] = self::sanitize( $value, $definition['type'] );
		}

		update_option( self::OPTION, $stored, false );

		return true;
	}

	/**
	 * Remove a stored value so the default applies again.
	 *
	 * @param string $key Setting key.
	 * @return bool False if the key is not in the schema.
	 */
	public static function delete( $key ) {
		if ( null === Blogcraft_Settings_Schema::get( $key ) ) {
			return false;
		}

		$stored = self::raw();
		unset( $stored[ $key ] );
		update_option( self::OPTION, $stored, false );

		return true;
	}

	/**
	 * Every setting resolved to its effective value.
	 *
	 * @return array
	 */
	public static function all() {
		$out = array();

		foreach ( array_keys( Blogcraft_Settings_Schema::all() ) as $key ) {
			$out[ $key ] = self::get( $key );
		}

		return $out;
	}
}
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
npm test -- --filter Test_Blogcraft_Settings
```
Expected: PASS — 10 assertions green.

- [ ] **Step 6: Commit**

```bash
git add includes/class-blogcraft-settings-schema.php includes/class-blogcraft-settings.php tests/integration/test-settings.php
git commit -m "feat: schema-driven settings with encrypted secrets"
```

---

### Task 6: Request verification helper

**Files:**
- Create: `includes/class-blogcraft-request.php`
- Test: `tests/integration/test-request.php`

**Interfaces:**
- Consumes: `Blogcraft_Capabilities::MANAGE` from Task 3.
- Produces: `Blogcraft_Request::verify( string $action, string $nonce_value ): bool`, `Blogcraft_Request::verify_or_die( string $action, string $nonce_value ): void`, `Blogcraft_Request::nonce_field( string $action ): void`.

- [ ] **Step 1: Write the failing test**

`tests/integration/test-request.php`:
```php
<?php
/**
 * Request verification tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Request extends WP_UnitTestCase {

	public function test_verify_passes_for_capable_user_with_valid_nonce() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		Blogcraft_Capabilities::add();

		$nonce = wp_create_nonce( 'blogcraft_save' );

		$this->assertTrue( Blogcraft_Request::verify( 'blogcraft_save', $nonce ) );
	}

	public function test_verify_fails_for_invalid_nonce() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		Blogcraft_Capabilities::add();

		$this->assertFalse( Blogcraft_Request::verify( 'blogcraft_save', 'bogus-nonce' ) );
	}

	public function test_verify_fails_for_user_without_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$nonce = wp_create_nonce( 'blogcraft_save' );

		$this->assertFalse( Blogcraft_Request::verify( 'blogcraft_save', $nonce ) );
	}

	public function test_verify_fails_for_logged_out_user() {
		wp_set_current_user( 0 );

		$this->assertFalse( Blogcraft_Request::verify( 'blogcraft_save', 'anything' ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
npm test -- --filter Test_Blogcraft_Request
```
Expected: FAIL — `Blogcraft_Request` not found.

- [ ] **Step 3: Write the request verifier**

`includes/class-blogcraft-request.php`:
```php
<?php
/**
 * Admin request verification.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Single choke point for nonce and capability checks.
 *
 * Every state-changing admin action routes through here, so Plugin Check's
 * nonce and capability rules are satisfied by construction rather than by
 * remembering to add a check in each handler.
 */
class Blogcraft_Request {

	/**
	 * Verify capability and nonce together.
	 *
	 * @param string $action      Nonce action name.
	 * @param string $nonce_value Nonce value supplied by the request.
	 * @return bool
	 */
	public static function verify( $action, $nonce_value ) {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			return false;
		}

		return (bool) wp_verify_nonce( $nonce_value, $action );
	}

	/**
	 * Verify, or halt the request with a 403.
	 *
	 * @param string $action      Nonce action name.
	 * @param string $nonce_value Nonce value supplied by the request.
	 * @return void
	 */
	public static function verify_or_die( $action, $nonce_value ) {
		if ( ! self::verify( $action, $nonce_value ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'blogcraft' ),
				esc_html__( 'Permission denied', 'blogcraft' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Print a nonce field for a Blogcraft form.
	 *
	 * @param string $action Nonce action name.
	 * @return void
	 */
	public static function nonce_field( $action ) {
		wp_nonce_field( $action, '_blogcraft_nonce' );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
npm test -- --filter Test_Blogcraft_Request
```
Expected: PASS — 4 assertions green.

- [ ] **Step 5: Commit**

```bash
git add includes/class-blogcraft-request.php tests/integration/test-request.php
git commit -m "feat: centralised nonce and capability verification"
```

---

### Task 7: Logger

**Files:**
- Create: `includes/class-blogcraft-logger.php`
- Test: `tests/integration/test-logger.php`

**Interfaces:**
- Consumes: `Blogcraft_Migrator::table_name()` from Task 2.
- Produces: `Blogcraft_Logger::log( string $level, string $message, array $context = array(), ?int $job_id = null ): void`, `Blogcraft_Logger::error()`, `Blogcraft_Logger::info()`, `Blogcraft_Logger::recent( int $limit = 50 ): array`, `Blogcraft_Logger::rotate( int $keep = 1000 ): int`, `Blogcraft_Logger::clear(): void`.

- [ ] **Step 1: Write the failing test**

`tests/integration/test-logger.php`:
```php
<?php
/**
 * Logger tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Logger extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		Blogcraft_Logger::clear();
	}

	public function test_info_writes_a_row() {
		Blogcraft_Logger::info( 'hello world' );
		$rows = Blogcraft_Logger::recent( 10 );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'hello world', $rows[0]['message'] );
		$this->assertSame( 'info', $rows[0]['level'] );
	}

	public function test_error_records_level_and_job_id() {
		Blogcraft_Logger::error( 'boom', array( 'code' => 500 ), 42 );
		$rows = Blogcraft_Logger::recent( 10 );
		$this->assertSame( 'error', $rows[0]['level'] );
		$this->assertSame( 42, $rows[0]['job_id'] );
		$this->assertSame( array( 'code' => 500 ), $rows[0]['context'] );
	}

	public function test_recent_returns_newest_first() {
		Blogcraft_Logger::info( 'first' );
		Blogcraft_Logger::info( 'second' );
		$rows = Blogcraft_Logger::recent( 10 );
		$this->assertSame( 'second', $rows[0]['message'] );
	}

	public function test_recent_respects_limit() {
		Blogcraft_Logger::info( 'a' );
		Blogcraft_Logger::info( 'b' );
		Blogcraft_Logger::info( 'c' );
		$this->assertCount( 2, Blogcraft_Logger::recent( 2 ) );
	}

	public function test_rotate_trims_to_keep_count() {
		for ( $i = 0; $i < 10; $i++ ) {
			Blogcraft_Logger::info( 'row ' . $i );
		}
		$deleted = Blogcraft_Logger::rotate( 4 );
		$this->assertSame( 6, $deleted );
		$this->assertCount( 4, Blogcraft_Logger::recent( 100 ) );
	}

	public function test_rotate_deletes_nothing_when_under_limit() {
		Blogcraft_Logger::info( 'only one' );
		$this->assertSame( 0, Blogcraft_Logger::rotate( 100 ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
npm test -- --filter Test_Blogcraft_Logger
```
Expected: FAIL — `Blogcraft_Logger` not found.

- [ ] **Step 3: Write the logger**

`includes/class-blogcraft-logger.php`:
```php
<?php
/**
 * Structured logging.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes plugin events to a rotated custom table.
 *
 * A dedicated table rather than error_log() keeps diagnostics visible to the
 * site owner in wp-admin, and rotation stops it growing without bound.
 * error_log() is additionally forbidden by Plugin Check.
 */
class Blogcraft_Logger {

	/**
	 * Record an event.
	 *
	 * @param string   $level   One of 'info', 'warning', 'error'.
	 * @param string   $message Human-readable message.
	 * @param array    $context Structured detail.
	 * @param int|null $job_id  Related job, if any.
	 * @return void
	 */
	public static function log( $level, $message, $context = array(), $job_id = null ) {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Blogcraft_Migrator::table_name( 'log' ),
			array(
				'job_id'     => $job_id,
				'level'      => substr( (string) $level, 0, 20 ),
				'message'    => (string) $message,
				'context'    => empty( $context ) ? null : wp_json_encode( $context ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Record an informational event.
	 *
	 * @param string   $message Message.
	 * @param array    $context Context.
	 * @param int|null $job_id  Job id.
	 * @return void
	 */
	public static function info( $message, $context = array(), $job_id = null ) {
		self::log( 'info', $message, $context, $job_id );
	}

	/**
	 * Record an error event.
	 *
	 * @param string   $message Message.
	 * @param array    $context Context.
	 * @param int|null $job_id  Job id.
	 * @return void
	 */
	public static function error( $message, $context = array(), $job_id = null ) {
		self::log( 'error', $message, $context, $job_id );
	}

	/**
	 * Fetch the most recent entries, newest first.
	 *
	 * @param int $limit Maximum rows.
	 * @return array
	 */
	public static function recent( $limit = 50 ) {
		global $wpdb;

		$table = Blogcraft_Migrator::table_name( 'log' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['id']      = (int) $row['id'];
			$rows[ $index ]['job_id']  = null === $row['job_id'] ? null : (int) $row['job_id'];
			$rows[ $index ]['context'] = null === $row['context'] ? array() : (array) json_decode( $row['context'], true );
		}

		return $rows;
	}

	/**
	 * Delete all but the newest entries.
	 *
	 * @param int $keep Number of rows to retain.
	 * @return int Rows deleted.
	 */
	public static function rotate( $keep = 1000 ) {
		global $wpdb;

		$table = Blogcraft_Migrator::table_name( 'log' );

		$cutoff = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", $keep )
		);

		if ( null === $cutoff ) {
			return 0;
		}

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $cutoff )
		);
	}

	/**
	 * Remove every log entry.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;

		$table = Blogcraft_Migrator::table_name( 'log' );
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
npm test -- --filter Test_Blogcraft_Logger
```
Expected: PASS — 6 assertions green.

- [ ] **Step 5: Commit**

```bash
git add includes/class-blogcraft-logger.php tests/integration/test-logger.php
git commit -m "feat: structured logging with rotation"
```

---

### Task 8: Job queue

**Files:**
- Create: `includes/class-blogcraft-job.php`, `includes/class-blogcraft-queue.php`
- Test: `tests/integration/test-queue.php`

**Interfaces:**
- Consumes: `Blogcraft_Migrator::table_name()` (Task 2), `Blogcraft_Settings::get()` (Task 5), `Blogcraft_Logger::error()` (Task 7).
- Produces: `Blogcraft_Job` with public typed properties `$id` (int), `$pipeline` (string), `$stage` (string), `$status` (string), `$payload` (array), `$attempts` (int), `$max_attempts` (int), and `public static function from_row( array $row ): Blogcraft_Job`. `Blogcraft_Queue::enqueue( string $pipeline, string $stage, array $payload ): int`, `::claim(): ?Blogcraft_Job`, `::complete( int $job_id ): void`, `::advance( int $job_id, string $next_stage, array $payload ): void`, `::fail( int $job_id, string $error ): void`, `::release( int $job_id ): void`, `::count_by_status( string $status ): int`. Status values: `pending`, `running`, `complete`, `failed`.

- [ ] **Step 1: Write the failing test**

`tests/integration/test-queue.php`:
```php
<?php
/**
 * Queue tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Queue extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		global $wpdb;
		$table = Blogcraft_Migrator::table_name( 'jobs' );
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	public function test_enqueue_returns_job_id() {
		$id = Blogcraft_Queue::enqueue( 'write_post', 'research', array( 'topic' => 'coffee' ) );
		$this->assertGreaterThan( 0, $id );
	}

	public function test_enqueue_creates_pending_job() {
		Blogcraft_Queue::enqueue( 'write_post', 'research', array() );
		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'pending' ) );
	}

	public function test_claim_returns_job_with_payload() {
		Blogcraft_Queue::enqueue( 'write_post', 'research', array( 'topic' => 'coffee' ) );
		$job = Blogcraft_Queue::claim();
		$this->assertInstanceOf( 'Blogcraft_Job', $job );
		$this->assertSame( 'write_post', $job->pipeline );
		$this->assertSame( 'research', $job->stage );
		$this->assertSame( array( 'topic' => 'coffee' ), $job->payload );
	}

	public function test_claim_marks_job_running() {
		Blogcraft_Queue::enqueue( 'write_post', 'research', array() );
		Blogcraft_Queue::claim();
		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'running' ) );
		$this->assertSame( 0, Blogcraft_Queue::count_by_status( 'pending' ) );
	}

	public function test_claim_returns_null_when_queue_empty() {
		$this->assertNull( Blogcraft_Queue::claim() );
	}

	public function test_claim_does_not_return_an_already_claimed_job() {
		Blogcraft_Queue::enqueue( 'write_post', 'research', array() );
		Blogcraft_Queue::claim();
		$this->assertNull( Blogcraft_Queue::claim() );
	}

	public function test_complete_marks_job_complete() {
		$id = Blogcraft_Queue::enqueue( 'write_post', 'research', array() );
		Blogcraft_Queue::claim();
		Blogcraft_Queue::complete( $id );
		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'complete' ) );
	}

	public function test_advance_moves_job_to_next_stage_and_requeues() {
		$id = Blogcraft_Queue::enqueue( 'write_post', 'research', array() );
		Blogcraft_Queue::claim();
		Blogcraft_Queue::advance( $id, 'draft', array( 'sources' => 3 ) );

		$job = Blogcraft_Queue::claim();
		$this->assertSame( 'draft', $job->stage );
		$this->assertSame( array( 'sources' => 3 ), $job->payload );
	}

	public function test_fail_requeues_job_with_incremented_attempts() {
		$id = Blogcraft_Queue::enqueue( 'write_post', 'research', array() );
		Blogcraft_Queue::claim();
		Blogcraft_Queue::fail( $id, 'network timeout' );

		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'pending' ) );
	}

	public function test_fail_marks_job_failed_after_max_attempts() {
		$id = Blogcraft_Queue::enqueue( 'write_post', 'research', array() );

		for ( $i = 0; $i < 3; $i++ ) {
			global $wpdb;
			$table = Blogcraft_Migrator::table_name( 'jobs' );
			$wpdb->query( "UPDATE {$table} SET available_at = '2000-01-01 00:00:00'" );
			Blogcraft_Queue::claim();
			Blogcraft_Queue::fail( $id, 'network timeout' );
		}

		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'failed' ) );
	}

	public function test_release_returns_job_to_pending() {
		$id = Blogcraft_Queue::enqueue( 'write_post', 'research', array() );
		Blogcraft_Queue::claim();
		Blogcraft_Queue::release( $id );
		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'pending' ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
npm test -- --filter Test_Blogcraft_Queue
```
Expected: FAIL — `Blogcraft_Queue` not found.

- [ ] **Step 3: Write the job value object**

`includes/class-blogcraft-job.php`:
```php
<?php
/**
 * Job value object.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * A single unit of queued work.
 *
 * All properties are declared explicitly — dynamic properties are deprecated
 * as of PHP 8.2 and emit notices on 8.5.
 */
class Blogcraft_Job {

	/**
	 * Job id.
	 *
	 * @var int
	 */
	public $id = 0;

	/**
	 * Pipeline name.
	 *
	 * @var string
	 */
	public $pipeline = '';

	/**
	 * Current stage name.
	 *
	 * @var string
	 */
	public $stage = '';

	/**
	 * Job status.
	 *
	 * @var string
	 */
	public $status = 'pending';

	/**
	 * Stage payload.
	 *
	 * @var array
	 */
	public $payload = array();

	/**
	 * Attempts made so far.
	 *
	 * @var int
	 */
	public $attempts = 0;

	/**
	 * Attempt ceiling.
	 *
	 * @var int
	 */
	public $max_attempts = 3;

	/**
	 * Build a job from a database row.
	 *
	 * @param array $row Associative row from the jobs table.
	 * @return Blogcraft_Job
	 */
	public static function from_row( $row ) {
		$job               = new self();
		$job->id           = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$job->pipeline     = isset( $row['pipeline'] ) ? (string) $row['pipeline'] : '';
		$job->stage        = isset( $row['stage'] ) ? (string) $row['stage'] : '';
		$job->status       = isset( $row['status'] ) ? (string) $row['status'] : 'pending';
		$job->attempts     = isset( $row['attempts'] ) ? (int) $row['attempts'] : 0;
		$job->max_attempts = isset( $row['max_attempts'] ) ? (int) $row['max_attempts'] : 3;

		$decoded      = isset( $row['payload'] ) ? json_decode( (string) $row['payload'], true ) : array();
		$job->payload = is_array( $decoded ) ? $decoded : array();

		return $job;
	}
}
```

- [ ] **Step 4: Write the queue**

`includes/class-blogcraft-queue.php`:
```php
<?php
/**
 * Job queue.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database-backed work queue with pessimistic locking.
 *
 * Claiming writes a random lock token in a conditional UPDATE and then reads
 * the row back by that token. Two concurrent cron runs therefore cannot claim
 * the same job: only one UPDATE can match the pending row.
 */
class Blogcraft_Queue {

	/**
	 * Add a job to the queue.
	 *
	 * @param string $pipeline Pipeline name.
	 * @param string $stage    Starting stage.
	 * @param array  $payload  Initial payload.
	 * @return int New job id.
	 */
	public static function enqueue( $pipeline, $stage, $payload = array() ) {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Blogcraft_Migrator::table_name( 'jobs' ),
			array(
				'pipeline'     => (string) $pipeline,
				'stage'        => (string) $stage,
				'status'       => 'pending',
				'payload'      => wp_json_encode( $payload ),
				'attempts'     => 0,
				'max_attempts' => (int) Blogcraft_Settings::get( 'queue_max_attempts' ),
				'available_at' => $now,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Claim the next available job.
	 *
	 * @return Blogcraft_Job|null Null when nothing is ready to run.
	 */
	public static function claim() {
		global $wpdb;

		$table = Blogcraft_Migrator::table_name( 'jobs' );
		$token = wp_generate_password( 32, false );
		$now   = current_time( 'mysql', true );

		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = 'running', lock_token = %s, locked_at = %s, updated_at = %s
				 WHERE status = 'pending' AND available_at <= %s
				 ORDER BY id ASC
				 LIMIT 1",
				$token,
				$now,
				$now,
				$now
			)
		);

		if ( ! $updated ) {
			return null;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE lock_token = %s LIMIT 1", $token ),
			ARRAY_A
		);

		return is_array( $row ) ? Blogcraft_Job::from_row( $row ) : null;
	}

	/**
	 * Mark a job finished.
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	public static function complete( $job_id ) {
		self::update(
			$job_id,
			array(
				'status'     => 'complete',
				'lock_token' => null,
				'locked_at'  => null,
			)
		);
	}

	/**
	 * Move a job to its next stage and return it to the queue.
	 *
	 * @param int    $job_id     Job id.
	 * @param string $next_stage Stage to run next.
	 * @param array  $payload    Payload to carry forward.
	 * @return void
	 */
	public static function advance( $job_id, $next_stage, $payload = array() ) {
		self::update(
			$job_id,
			array(
				'status'       => 'pending',
				'stage'        => (string) $next_stage,
				'payload'      => wp_json_encode( $payload ),
				'attempts'     => 0,
				'lock_token'   => null,
				'locked_at'    => null,
				'available_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Record a failed attempt, retrying with exponential backoff.
	 *
	 * @param int    $job_id Job id.
	 * @param string $error  Error message.
	 * @return void
	 */
	public static function fail( $job_id, $error ) {
		global $wpdb;

		$table = Blogcraft_Migrator::table_name( 'jobs' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT attempts, max_attempts FROM {$table} WHERE id = %d", $job_id ),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return;
		}

		$attempts = (int) $row['attempts'] + 1;
		$exhausted = $attempts >= (int) $row['max_attempts'];

		Blogcraft_Logger::error( $error, array( 'attempt' => $attempts ), (int) $job_id );

		if ( $exhausted ) {
			self::update(
				$job_id,
				array(
					'status'     => 'failed',
					'attempts'   => $attempts,
					'last_error' => (string) $error,
					'lock_token' => null,
					'locked_at'  => null,
				)
			);

			return;
		}

		// Backoff: 60s, 120s, 240s ...
		$delay = 60 * pow( 2, $attempts - 1 );

		self::update(
			$job_id,
			array(
				'status'       => 'pending',
				'attempts'     => $attempts,
				'last_error'   => (string) $error,
				'lock_token'   => null,
				'locked_at'    => null,
				'available_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
			)
		);
	}

	/**
	 * Return a claimed job to the pending pool without counting an attempt.
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	public static function release( $job_id ) {
		self::update(
			$job_id,
			array(
				'status'     => 'pending',
				'lock_token' => null,
				'locked_at'  => null,
			)
		);
	}

	/**
	 * Count jobs in a given status.
	 *
	 * @param string $status Status value.
	 * @return int
	 */
	public static function count_by_status( $status ) {
		global $wpdb;

		$table = Blogcraft_Migrator::table_name( 'jobs' );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status )
		);
	}

	/**
	 * Apply an update to one job, always stamping updated_at.
	 *
	 * @param int   $job_id Job id.
	 * @param array $data   Column => value pairs.
	 * @return void
	 */
	private static function update( $job_id, $data ) {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql', true );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Blogcraft_Migrator::table_name( 'jobs' ),
			$data,
			array( 'id' => (int) $job_id )
		);
	}
}
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
npm test -- --filter Test_Blogcraft_Queue
```
Expected: PASS — 11 assertions green.

- [ ] **Step 6: Commit**

```bash
git add includes/class-blogcraft-job.php includes/class-blogcraft-queue.php tests/integration/test-queue.php
git commit -m "feat: database-backed job queue with locking and backoff"
```

---

### Task 9: Worker — one stage per tick

**Files:**
- Create: `includes/class-blogcraft-worker.php`
- Test: `tests/integration/test-worker.php`

**Interfaces:**
- Consumes: `Blogcraft_Queue` (Task 8), `Blogcraft_Settings::get( 'queue_time_budget' )` (Task 5).
- Produces: `Blogcraft_Worker::register_stage( string $pipeline, string $stage, callable $handler ): void`, `::run( ?int $budget_seconds = null ): int` (returns stages executed), `::reset_stages(): void`. A stage handler receives `Blogcraft_Job $job` and returns an array: `array( 'next' => string|null, 'payload' => array )`. A `null` next stage completes the job; a thrown exception fails it.

- [ ] **Step 1: Write the failing test**

`tests/integration/test-worker.php`:
```php
<?php
/**
 * Worker tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Worker extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		global $wpdb;
		$table = Blogcraft_Migrator::table_name( 'jobs' );
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		Blogcraft_Worker::reset_stages();
	}

	public function test_run_executes_a_registered_stage() {
		$ran = false;

		Blogcraft_Worker::register_stage(
			'demo',
			'only',
			static function ( $job ) use ( &$ran ) {
				$ran = true;
				return array( 'next' => null, 'payload' => array() );
			}
		);

		Blogcraft_Queue::enqueue( 'demo', 'only', array() );
		Blogcraft_Worker::run();

		$this->assertTrue( $ran );
	}

	public function test_null_next_stage_completes_the_job() {
		Blogcraft_Worker::register_stage(
			'demo',
			'only',
			static function ( $job ) {
				return array( 'next' => null, 'payload' => array() );
			}
		);

		Blogcraft_Queue::enqueue( 'demo', 'only', array() );
		Blogcraft_Worker::run();

		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'complete' ) );
	}

	public function test_worker_runs_only_one_stage_per_job_per_tick() {
		$calls = 0;

		Blogcraft_Worker::register_stage(
			'demo',
			'first',
			static function ( $job ) use ( &$calls ) {
				$calls++;
				return array( 'next' => 'second', 'payload' => array( 'x' => 1 ) );
			}
		);
		Blogcraft_Worker::register_stage(
			'demo',
			'second',
			static function ( $job ) use ( &$calls ) {
				$calls++;
				return array( 'next' => null, 'payload' => array() );
			}
		);

		Blogcraft_Queue::enqueue( 'demo', 'first', array() );
		Blogcraft_Worker::run( 0 );

		$this->assertSame( 1, $calls );
	}

	public function test_payload_carries_between_stages() {
		$seen = array();

		Blogcraft_Worker::register_stage(
			'demo',
			'first',
			static function ( $job ) {
				return array( 'next' => 'second', 'payload' => array( 'token' => 'abc' ) );
			}
		);
		Blogcraft_Worker::register_stage(
			'demo',
			'second',
			static function ( $job ) use ( &$seen ) {
				$seen = $job->payload;
				return array( 'next' => null, 'payload' => array() );
			}
		);

		Blogcraft_Queue::enqueue( 'demo', 'first', array() );
		Blogcraft_Worker::run( 0 );
		Blogcraft_Worker::run( 0 );

		$this->assertSame( array( 'token' => 'abc' ), $seen );
	}

	public function test_thrown_exception_fails_the_job() {
		Blogcraft_Worker::register_stage(
			'demo',
			'boom',
			static function ( $job ) {
				throw new RuntimeException( 'stage exploded' );
			}
		);

		Blogcraft_Queue::enqueue( 'demo', 'boom', array() );
		Blogcraft_Worker::run( 0 );

		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'pending' ) );
		$this->assertSame( 0, Blogcraft_Queue::count_by_status( 'running' ) );
	}

	public function test_unregistered_stage_fails_the_job() {
		Blogcraft_Queue::enqueue( 'demo', 'missing', array() );
		Blogcraft_Worker::run( 0 );

		$this->assertSame( 0, Blogcraft_Queue::count_by_status( 'running' ) );
	}

	public function test_run_returns_zero_when_queue_is_empty() {
		$this->assertSame( 0, Blogcraft_Worker::run( 0 ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
npm test -- --filter Test_Blogcraft_Worker
```
Expected: FAIL — `Blogcraft_Worker` not found.

- [ ] **Step 3: Write the worker**

`includes/class-blogcraft-worker.php`:
```php
<?php
/**
 * Pipeline worker.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Executes queued pipeline stages, one stage per job per tick.
 *
 * Running a single stage per tick is what lets a multi-minute generation
 * pipeline survive a 30-second PHP max_execution_time on shared hosting:
 * no individual request ever has to finish the whole pipeline.
 */
class Blogcraft_Worker {

	/**
	 * Registered stage handlers, keyed "pipeline:stage".
	 *
	 * @var array
	 */
	private static $stages = array();

	/**
	 * Register a handler for one pipeline stage.
	 *
	 * @param string   $pipeline Pipeline name.
	 * @param string   $stage    Stage name.
	 * @param callable $handler  Receives Blogcraft_Job, returns array with
	 *                           'next' (string|null) and 'payload' (array).
	 * @return void
	 */
	public static function register_stage( $pipeline, $stage, $handler ) {
		self::$stages[ $pipeline . ':' . $stage ] = $handler;
	}

	/**
	 * Forget all registered stages. Test support.
	 *
	 * @return void
	 */
	public static function reset_stages() {
		self::$stages = array();
	}

	/**
	 * Drain the queue until the time budget is spent.
	 *
	 * @param int|null $budget_seconds Wall-clock budget; null uses the setting.
	 * @return int Number of stages executed.
	 */
	public static function run( $budget_seconds = null ) {
		if ( null === $budget_seconds ) {
			$budget_seconds = (int) Blogcraft_Settings::get( 'queue_time_budget' );
		}

		$started  = time();
		$executed = 0;

		do {
			$job = Blogcraft_Queue::claim();

			if ( null === $job ) {
				break;
			}

			self::execute( $job );
			$executed++;

		} while ( ( time() - $started ) < $budget_seconds );

		return $executed;
	}

	/**
	 * Run one job's current stage.
	 *
	 * @param Blogcraft_Job $job Claimed job.
	 * @return void
	 */
	private static function execute( Blogcraft_Job $job ) {
		$key = $job->pipeline . ':' . $job->stage;

		if ( ! isset( self::$stages[ $key ] ) ) {
			Blogcraft_Queue::fail( $job->id, 'No handler registered for stage ' . $key );

			return;
		}

		try {
			$result = call_user_func( self::$stages[ $key ], $job );
		} catch ( Exception $e ) {
			Blogcraft_Queue::fail( $job->id, $e->getMessage() );

			return;
		}

		$next    = isset( $result['next'] ) ? $result['next'] : null;
		$payload = isset( $result['payload'] ) && is_array( $result['payload'] ) ? $result['payload'] : array();

		if ( null === $next || '' === $next ) {
			Blogcraft_Queue::complete( $job->id );

			return;
		}

		Blogcraft_Queue::advance( $job->id, $next, $payload );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
npm test -- --filter Test_Blogcraft_Worker
```
Expected: PASS — 7 assertions green.

- [ ] **Step 5: Commit**

```bash
git add includes/class-blogcraft-worker.php tests/integration/test-worker.php
git commit -m "feat: worker executing one pipeline stage per tick"
```

---

### Task 10: Cron scheduling and health monitoring

**Files:**
- Create: `includes/class-blogcraft-scheduler.php`, `includes/class-blogcraft-cron-health.php`
- Modify: `includes/class-blogcraft-activator.php` (schedule cron on activation)
- Test: `tests/integration/test-scheduler.php`

**Interfaces:**
- Consumes: `Blogcraft_Worker::run()` (Task 9), `Blogcraft_Logger` (Task 7), `Blogcraft_Settings` (Task 5).
- Produces: `Blogcraft_Scheduler::HOOK` (string, `blogcraft_run_queue`), `::schedule(): void`, `::unschedule(): void`, `::is_scheduled(): bool`, `::run_queue(): void`. `Blogcraft_Cron_Health::HEARTBEAT_OPTION` (string), `::record_heartbeat(): void`, `::last_heartbeat(): int`, `::is_stale( int $threshold_seconds = 900 ): bool`.

- [ ] **Step 1: Write the failing test**

`tests/integration/test-scheduler.php`:
```php
<?php
/**
 * Scheduler and cron health tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Scheduler extends WP_UnitTestCase {

	public function tear_down() {
		Blogcraft_Scheduler::unschedule();
		delete_option( Blogcraft_Cron_Health::HEARTBEAT_OPTION );
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
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
npm test -- --filter Test_Blogcraft_Scheduler
```
Expected: FAIL — `Blogcraft_Scheduler` not found.

- [ ] **Step 3: Write the cron health monitor**

`includes/class-blogcraft-cron-health.php`:
```php
<?php
/**
 * WP-Cron health detection.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detects when WP-Cron is not actually firing.
 *
 * WP-Cron only runs when someone loads a page. On a low-traffic site
 * scheduled work silently never happens — the single most common support
 * complaint for scheduled-publishing plugins. Recording a heartbeat each time
 * the queue runs lets the admin surface a real warning instead of leaving the
 * user to wonder why nothing published.
 */
class Blogcraft_Cron_Health {

	/**
	 * Option storing the last queue-run timestamp.
	 */
	const HEARTBEAT_OPTION = 'blogcraft_cron_heartbeat';

	/**
	 * Stamp the current time as the last successful run.
	 *
	 * @return void
	 */
	public static function record_heartbeat() {
		update_option( self::HEARTBEAT_OPTION, time(), false );
	}

	/**
	 * Timestamp of the last recorded run.
	 *
	 * @return int Zero if never recorded.
	 */
	public static function last_heartbeat() {
		return (int) get_option( self::HEARTBEAT_OPTION, 0 );
	}

	/**
	 * Whether the heartbeat is older than the threshold.
	 *
	 * @param int $threshold_seconds Age beyond which cron is considered broken.
	 * @return bool
	 */
	public static function is_stale( $threshold_seconds = 900 ) {
		$last = self::last_heartbeat();

		if ( 0 === $last ) {
			return true;
		}

		return ( time() - $last ) > $threshold_seconds;
	}
}
```

- [ ] **Step 4: Write the scheduler**

`includes/class-blogcraft-scheduler.php`:
```php
<?php
/**
 * Cron scheduling.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and tears down the recurring queue-processing event.
 */
class Blogcraft_Scheduler {

	/**
	 * Cron hook name.
	 */
	const HOOK = 'blogcraft_run_queue';

	/**
	 * Wire the cron callback.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_queue' ) );
	}

	/**
	 * Schedule the recurring event if it is not already scheduled.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::HOOK );
		}
	}

	/**
	 * Remove every scheduled instance of the event.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Whether the event is currently scheduled.
	 *
	 * @return bool
	 */
	public static function is_scheduled() {
		return (bool) wp_next_scheduled( self::HOOK );
	}

	/**
	 * Cron callback: drain the queue and record a heartbeat.
	 *
	 * @return void
	 */
	public static function run_queue() {
		Blogcraft_Cron_Health::record_heartbeat();
		Blogcraft_Worker::run();
		Blogcraft_Logger::rotate( 1000 );
	}
}
```

- [ ] **Step 5: Schedule cron on activation and wire the hook on load**

In `includes/class-blogcraft-activator.php`, add a final line to `activate()`:

```php
		Blogcraft_Scheduler::schedule();
```

In `includes/class-blogcraft.php`, add to the end of `run()`:

```php
		Blogcraft_Scheduler::init();
```

- [ ] **Step 6: Run the test to verify it passes**

```bash
npm test -- --filter Test_Blogcraft_Scheduler
```
Expected: PASS — 7 assertions green.

- [ ] **Step 7: Commit**

```bash
git add includes/class-blogcraft-scheduler.php includes/class-blogcraft-cron-health.php includes/class-blogcraft-activator.php includes/class-blogcraft.php tests/integration/test-scheduler.php
git commit -m "feat: cron scheduling with heartbeat health detection"
```

---

### Task 11: Admin menu and dismissible notices

**Files:**
- Create: `includes/class-blogcraft-notices.php`, `includes/class-blogcraft-admin.php`
- Modify: `includes/class-blogcraft.php` (boot admin)
- Test: `tests/integration/test-notices.php`

**Interfaces:**
- Consumes: `Blogcraft_Capabilities::MANAGE` (Task 3), `Blogcraft_Cron_Health` (Task 10), `Blogcraft_Request` (Task 6), `Blogcraft_Settings` (Task 5).
- Produces: `Blogcraft_Notices::dismiss( string $notice_id, int $user_id ): void`, `::is_dismissed( string $notice_id, int $user_id ): bool`, `::handle_dismiss(): void`. `Blogcraft_Admin::init(): void`, `::MENU_SLUG` (string, `blogcraft`).

- [ ] **Step 1: Write the failing test**

`tests/integration/test-notices.php`:
```php
<?php
/**
 * Notice tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Notices extends WP_UnitTestCase {

	public function test_notice_is_not_dismissed_by_default() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertFalse( Blogcraft_Notices::is_dismissed( 'cron_health', $user_id ) );
	}

	public function test_dismiss_persists_for_that_user() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		Blogcraft_Notices::dismiss( 'cron_health', $user_id );
		$this->assertTrue( Blogcraft_Notices::is_dismissed( 'cron_health', $user_id ) );
	}

	public function test_dismiss_does_not_affect_other_users() {
		$one = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$two = self::factory()->user->create( array( 'role' => 'administrator' ) );
		Blogcraft_Notices::dismiss( 'cron_health', $one );
		$this->assertFalse( Blogcraft_Notices::is_dismissed( 'cron_health', $two ) );
	}

	public function test_dismissing_one_notice_does_not_dismiss_another() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		Blogcraft_Notices::dismiss( 'cron_health', $user_id );
		$this->assertFalse( Blogcraft_Notices::is_dismissed( 'other_notice', $user_id ) );
	}

	public function test_admin_menu_slug_is_registered() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		Blogcraft_Capabilities::add();

		set_current_screen( 'dashboard' );
		Blogcraft_Admin::register_menu();

		global $admin_page_hooks;
		$this->assertArrayHasKey( Blogcraft_Admin::MENU_SLUG, $admin_page_hooks );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
npm test -- --filter Test_Blogcraft_Notices
```
Expected: FAIL — `Blogcraft_Notices` not found.

- [ ] **Step 3: Write the notice manager**

`includes/class-blogcraft-notices.php`:
```php
<?php
/**
 * Dismissible admin notices.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Per-user dismissible notices.
 *
 * Guideline 11 requires notices to be dismissible and contextual. Dismissal
 * is stored per user so one administrator hiding a warning does not hide it
 * from their colleagues.
 */
class Blogcraft_Notices {

	/**
	 * User meta key holding dismissed notice ids.
	 */
	const META_KEY = 'blogcraft_dismissed_notices';

	/**
	 * Nonce action for the dismiss request.
	 */
	const DISMISS_ACTION = 'blogcraft_dismiss_notice';

	/**
	 * Wire the dismissal handler.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_blogcraft_dismiss_notice', array( __CLASS__, 'handle_dismiss' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_cron_health_notice' ) );
	}

	/**
	 * Mark a notice dismissed for a user.
	 *
	 * @param string $notice_id Notice identifier.
	 * @param int    $user_id   User id.
	 * @return void
	 */
	public static function dismiss( $notice_id, $user_id ) {
		$dismissed = get_user_meta( $user_id, self::META_KEY, true );

		if ( ! is_array( $dismissed ) ) {
			$dismissed = array();
		}

		$dismissed[ (string) $notice_id ] = true;

		update_user_meta( $user_id, self::META_KEY, $dismissed );
	}

	/**
	 * Whether a user has dismissed a notice.
	 *
	 * @param string $notice_id Notice identifier.
	 * @param int    $user_id   User id.
	 * @return bool
	 */
	public static function is_dismissed( $notice_id, $user_id ) {
		$dismissed = get_user_meta( $user_id, self::META_KEY, true );

		return is_array( $dismissed ) && ! empty( $dismissed[ (string) $notice_id ] );
	}

	/**
	 * Handle the dismiss request.
	 *
	 * @return void
	 */
	public static function handle_dismiss() {
		$nonce  = isset( $_REQUEST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_blogcraft_nonce'] ) ) : '';
		$notice = isset( $_REQUEST['notice'] ) ? sanitize_key( wp_unslash( $_REQUEST['notice'] ) ) : '';

		Blogcraft_Request::verify_or_die( self::DISMISS_ACTION, $nonce );

		if ( '' !== $notice ) {
			self::dismiss( $notice, get_current_user_id() );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Build a dismissal URL for a notice.
	 *
	 * @param string $notice_id Notice identifier.
	 * @return string
	 */
	public static function dismiss_url( $notice_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'blogcraft_dismiss_notice',
					'notice' => rawurlencode( $notice_id ),
				),
				admin_url( 'admin-post.php' )
			),
			self::DISMISS_ACTION,
			'_blogcraft_nonce'
		);
	}

	/**
	 * Warn when WP-Cron appears not to be firing.
	 *
	 * @return void
	 */
	public static function render_cron_health_notice() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			return;
		}

		if ( ! Blogcraft_Settings::get( 'cron_health_notice_enabled' ) ) {
			return;
		}

		if ( self::is_dismissed( 'cron_health', get_current_user_id() ) ) {
			return;
		}

		if ( ! Blogcraft_Cron_Health::is_stale() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p><p><a href="%s">%s</a></p></div>',
			esc_html__( 'Blogcraft has not processed its queue recently. WordPress only runs scheduled tasks when someone visits your site, so low-traffic sites may need a real system cron job.', 'blogcraft' ),
			esc_url( self::dismiss_url( 'cron_health' ) ),
			esc_html__( 'Dismiss this notice', 'blogcraft' )
		);
	}
}
```

- [ ] **Step 4: Write the admin shell**

`includes/class-blogcraft-admin.php`:
```php
<?php
/**
 * Admin interface.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers Blogcraft's admin menu.
 */
class Blogcraft_Admin {

	/**
	 * Top-level menu slug.
	 */
	const MENU_SLUG = 'blogcraft';

	/**
	 * Wire admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		Blogcraft_Notices::init();
	}

	/**
	 * Register the top-level menu page.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'Blogcraft', 'blogcraft' ),
			__( 'Blogcraft', 'blogcraft' ),
			Blogcraft_Capabilities::MANAGE,
			self::MENU_SLUG,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-edit-large',
			30
		);
	}

	/**
	 * Render the dashboard page.
	 *
	 * @return void
	 */
	public static function render_dashboard() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'blogcraft' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Blogcraft', 'blogcraft' ) . '</h1>';
		echo '<p>' . esc_html__( 'Queue status', 'blogcraft' ) . '</p>';
		echo '<ul>';
		foreach ( array( 'pending', 'running', 'complete', 'failed' ) as $status ) {
			printf(
				'<li>%s: %d</li>',
				esc_html( $status ),
				(int) Blogcraft_Queue::count_by_status( $status )
			);
		}
		echo '</ul>';
		echo '</div>';
	}
}
```

- [ ] **Step 5: Boot the admin from the bootstrap**

In `includes/class-blogcraft.php`, add to the end of `run()`:

```php
		if ( is_admin() ) {
			Blogcraft_Admin::init();
		}
```

- [ ] **Step 6: Run the test to verify it passes**

```bash
npm test -- --filter Test_Blogcraft_Notices
```
Expected: PASS — 5 assertions green.

- [ ] **Step 7: Commit**

```bash
git add includes/class-blogcraft-notices.php includes/class-blogcraft-admin.php includes/class-blogcraft.php tests/integration/test-notices.php
git commit -m "feat: admin menu and dismissible cron health notice"
```

---

### Task 12: Uninstall cleanup and GDPR hooks

**Files:**
- Create: `uninstall.php`
- Test: `tests/integration/test-uninstall.php`

**Interfaces:**
- Consumes: `Blogcraft_Migrator::drop_tables()` (Task 2), `Blogcraft_Capabilities::remove()` (Task 3), `Blogcraft_Scheduler::unschedule()` (Task 10).
- Produces: function `blogcraft_uninstall_cleanup(): void`.

- [ ] **Step 1: Write the failing test**

`tests/integration/test-uninstall.php`:
```php
<?php
/**
 * Uninstall tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Uninstall extends WP_UnitTestCase {

	public function test_cleanup_removes_tables() {
		global $wpdb;

		Blogcraft_Activator::activate();
		require_once dirname( dirname( __DIR__ ) ) . '/uninstall.php';
		blogcraft_uninstall_cleanup();

		$table = Blogcraft_Migrator::table_name( 'jobs' );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
	}

	public function test_cleanup_removes_settings() {
		Blogcraft_Activator::activate();
		Blogcraft_Settings::set( 'queue_max_attempts', 9 );

		require_once dirname( dirname( __DIR__ ) ) . '/uninstall.php';
		blogcraft_uninstall_cleanup();

		$this->assertFalse( get_option( 'blogcraft_settings' ) );
	}

	public function test_cleanup_removes_capability() {
		Blogcraft_Activator::activate();

		require_once dirname( dirname( __DIR__ ) ) . '/uninstall.php';
		blogcraft_uninstall_cleanup();

		$role = get_role( 'administrator' );
		$this->assertFalse( $role->has_cap( Blogcraft_Capabilities::MANAGE ) );
	}

	public function test_cleanup_unschedules_cron() {
		Blogcraft_Activator::activate();

		require_once dirname( dirname( __DIR__ ) ) . '/uninstall.php';
		blogcraft_uninstall_cleanup();

		$this->assertFalse( Blogcraft_Scheduler::is_scheduled() );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
npm test -- --filter Test_Blogcraft_Uninstall
```
Expected: FAIL — `uninstall.php` not found.

- [ ] **Step 3: Write the uninstall routine**

`uninstall.php`:
```php
<?php
/**
 * Uninstall routine.
 *
 * Removes every trace of Blogcraft when the plugin is deleted.
 *
 * @package Blogcraft
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'blogcraft_uninstall_cleanup' ) ) {

	/**
	 * Delete all Blogcraft data.
	 *
	 * @return void
	 */
	function blogcraft_uninstall_cleanup() {
		if ( class_exists( 'Blogcraft_Scheduler' ) ) {
			Blogcraft_Scheduler::unschedule();
		}

		if ( class_exists( 'Blogcraft_Capabilities' ) ) {
			Blogcraft_Capabilities::remove();
		}

		if ( class_exists( 'Blogcraft_Migrator' ) ) {
			Blogcraft_Migrator::drop_tables();
		}

		delete_option( 'blogcraft_settings' );
		delete_option( 'blogcraft_cron_heartbeat' );

		delete_metadata( 'user', 0, 'blogcraft_dismissed_notices', '', true );
	}
}

if ( defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-blogcraft-autoloader.php';

	if ( ! defined( 'BLOGCRAFT_PATH' ) ) {
		define( 'BLOGCRAFT_PATH', plugin_dir_path( __FILE__ ) );
	}

	Blogcraft_Autoloader::register();
	blogcraft_uninstall_cleanup();
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
npm test -- --filter Test_Blogcraft_Uninstall
```
Expected: PASS — 4 assertions green.

- [ ] **Step 5: Commit**

```bash
git add uninstall.php tests/integration/test-uninstall.php
git commit -m "feat: complete data removal on uninstall"
```

---

### Task 13: readme.txt and Plugin Check pass

**Files:**
- Create: `readme.txt`
- Test: manual verification via the Plugin Check plugin

**Interfaces:**
- Consumes: everything above.
- Produces: a submission-ready readme and a clean Plugin Check "Plugin repo" category result.

- [ ] **Step 1: Write readme.txt**

`readme.txt`:
```
=== Blogcraft ===
Contributors: dicecodes
Tags: ai content generator, ai writer, autoblogging, content generator, seo content
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI blog writer and content generator. Connect any AI provider with your own API key. Every feature included, free.

== Description ==

Blogcraft writes blog posts for your WordPress site using an AI provider you choose and connect with your own API key.

Every feature is included. Nothing is locked, nothing expires, and there are no credits or quotas. Your only cost is whatever your chosen AI provider charges — several offer free tiers.

**How it works**

1. Tell Blogcraft about your site: niche, audience, tone, and style rules.
2. Connect an AI provider using your own API key.
3. Choose whether posts are saved as drafts for review or published automatically.

Blogcraft researches a topic before writing, drafts the post, critiques its own draft, and revises it. Posts are saved as native block content so they remain fully editable in the block editor.

**You stay in control**

Blogcraft defaults to saving drafts for your review. Volume limits are set conservatively. Nothing is published without settings you choose.

== External Services ==

Blogcraft does not send your data anywhere unless you configure a provider. It never contacts the plugin author's servers, and it collects no analytics or telemetry.

When you configure an AI provider, the topic, your style settings, and any research material are sent to that provider's API so it can generate the post. This happens only when a post is generated.

Providers are added in later releases; each will be documented here with its purpose, the data sent, and links to its terms of service and privacy policy before that provider ships.

== Frequently Asked Questions ==

= Do I need to pay for anything? =

The plugin is free and complete. You need an API key from an AI provider, and several providers offer free tiers.

= Will posts publish without my review? =

Only if you turn that on. Blogcraft saves drafts by default.

= Scheduled posts are not being created. Why? =

WordPress only runs scheduled tasks when someone visits your site. On a low-traffic site you may need a real system cron job. Blogcraft shows a notice in your dashboard when it detects this.

== Changelog ==

= 0.1.0 =
* Initial foundation release: settings, encrypted key storage, job queue, scheduling, and admin dashboard.
```

Copy rule check before committing: the readme must contain **no** promise of traffic, rankings, or search position (Guideline 9). Read it once with only that question in mind.

- [ ] **Step 2: Install Plugin Check in the test environment**

```bash
npm run env:start
npx wp-env run cli wp plugin install plugin-check --activate
```

- [ ] **Step 3: Run Plugin Check**

```bash
npx wp-env run cli wp plugin check blogcraft
```
Expected: zero **errors** in the `plugin_repo` category. Warnings in other categories are acceptable but should be reviewed.

- [ ] **Step 4: Fix every plugin_repo error**

Work through each reported error and fix it. Common findings at this stage: missing translators comments on `printf` with placeholders, a `Tested up to` mismatch, direct database calls needing the documented `phpcs:ignore`, and missing escaping on output. Re-run Step 3 after each fix until the category is clean.

- [ ] **Step 5: Run the full test suite and linter**

```bash
npm test && composer lint
```
Expected: all tests pass, zero PHPCS errors.

- [ ] **Step 6: Commit**

```bash
git add readme.txt
git commit -m "docs: submission-ready readme and Plugin Check compliance"
```

---

## Phase 0 Definition of Done

- [ ] All 13 tasks complete, each committed separately
- [ ] `npm test` — every test green
- [ ] `composer lint` — zero PHPCS errors
- [ ] CI green across PHP 7.4, 8.1, 8.2, 8.3, 8.4, 8.5
- [ ] `wp plugin check blogcraft` — zero errors in the `plugin_repo` category
- [ ] Plugin activates and deactivates cleanly on a fresh WordPress install
- [ ] Deleting the plugin leaves no tables, options, user meta, or scheduled events behind

---

## Self-Review Notes

**Spec coverage.** This plan implements the Phase 0 row of spec Section 8, plus the Section 6 compatibility constraints, the Section 7 `jobs` and `log` tables, and the Section 5 compliance items that apply to foundation code (ABSPATH guards, nonces, capabilities, sanitisation, escaping, no CDN, dismissible notices, uninstall cleanup, prefixing, text domain, readme headers, external-services disclosure, no ranking claims). The remaining Section 7 tables — `topics`, `knowledge`, `sources`, `profiles`, `experience` — are deliberately deferred to the phases that consume them; creating them now would be unused schema.

**Deferred to later phases, by design:** provider adapters (Phase 1), the generation pipeline stages (Phase 2), GDPR export/erase hooks (added in Phase 3 alongside the first personal data storage — there is no personal data in Phase 0 beyond notice dismissal meta, which uninstall removes).

**Known forward reference:** `Blogcraft_Deactivator::deactivate()` is written in Task 3 but calls `Blogcraft_Scheduler::unschedule()`, created in Task 10. This is called out inline in Task 3 Step 5. Deactivation is not exercised by tests until Task 10.
