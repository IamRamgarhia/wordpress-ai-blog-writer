# Blogcraft Phase 1 — Provider Layer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user connect *any* LLM API with their own key and get a completion back — with retries, rate-limit backoff, model discovery, capability probing, cost accounting, and graceful degradation when a provider lacks a feature.

**Architecture:** One abstract base class defines the provider contract. A single OpenAI-compatible adapter covers the majority of the market (Groq, OpenRouter, DeepSeek, Together, Mistral, Cerebras, xAI, Azure, OpenAI, and local Ollama/LM Studio/vLLM) via a user-supplied base URL. Gemini and Anthropic get thin native adapters. A JSON-path mapper handles anything else. All HTTP goes through one transport class so timeout, retry, and 429 handling exist in exactly one place. **Providers never throw — they return a response object carrying either text or an error.**

**Tech Stack:** Unchanged from Phase 0 — PHP 7.4–8.5, WordPress 6.0–7.0, `wp_remote_post()`, no runtime dependencies.

**Spec:** [`docs/superpowers/specs/2026-08-17-blogcraft-design.md`](../specs/2026-08-17-blogcraft-design.md) §4.1, §3 L1

## Global Constraints

Identical to Phase 0 — every task's requirements implicitly include these.

- **`Requires PHP` 7.4** — no enums, `match`, constructor promotion, `readonly`, union types, first-class callables. Typed properties and arrow functions allowed.
- **Clean on PHP 8.5** — declare every class property; explicit `?Type`; no `${var}`; never pass `null` to non-nullable internal params.
- **Prefixes:** classes `Blogcraft_`, functions/hooks/options `blogcraft_`, constants `BLOGCRAFT_`. Text domain `blogcraft`.
- **`ABSPATH` guard** at the top of every file.
- **Autoloader mapping is unchanged:** `Blogcraft_Foo_Bar` → `includes/class-blogcraft-foo-bar.php`. Use abstract base classes, **not** interfaces — an interface would need a `interface-*.php` filename the autoloader does not resolve.
- **All HTTP via `wp_remote_*`.** `wp_remote_post()` defaults to a **5-second timeout** — every call must set one explicitly or real generations fail.
- **Never log, echo, or store an API key.** Keys come from `Blogcraft_Settings` (already encrypted at rest) and go only into request headers.
- **`DELETE FROM`, never `TRUNCATE`**, in any test fixture (Phase 0 ruling R13).
- Line-scoped `phpcs:ignore` with named sniffs only. Forbidden: `error_log()`, `var_dump()`, `print_r()`.
- Zero runtime dependencies.

## Interfaces Phase 0 provides

- `Blogcraft_Settings::get( $key )` / `::set( $key, $value )` — secrets encrypted transparently
- `Blogcraft_Settings_Schema::all()` — extend this map, never invent parallel option keys
- `Blogcraft_Crypto::mask( $secret )` — for display
- `Blogcraft_Logger::error( $message, $context, $job_id )` / `::info(...)`
- `Blogcraft_Request::verify_or_die( $action, $nonce )` / `::nonce_field( $action )`
- `Blogcraft_Capabilities::MANAGE`, `Blogcraft_Admin::MENU_SLUG`
- `Blogcraft_Worker::register_stage( $pipeline, $stage, $handler )` — Phase 2 uses this; Phase 1 does not

---

## File Structure

```
includes/
  class-blogcraft-http.php                    Transport: timeout, retry, 429/Retry-After backoff
  class-blogcraft-provider-response.php       Value object: text, tokens, model, error
  class-blogcraft-provider.php                Abstract base: contract + shared helpers
  class-blogcraft-provider-openai.php         OpenAI-compatible (the majority adapter)
  class-blogcraft-provider-gemini.php         Native Gemini
  class-blogcraft-provider-anthropic.php      Native Anthropic
  class-blogcraft-provider-custom.php         JSON-path mapper for arbitrary APIs
  class-blogcraft-provider-registry.php       Discovery, instantiation, fallback chains
  class-blogcraft-cost.php                    Token accounting and spend estimation
  class-blogcraft-connection.php              Admin: connection test screen + handler
tests/integration/
  test-http.php  test-provider-openai.php  test-provider-gemini-anthropic.php
  test-provider-custom.php  test-provider-registry.php  test-cost.php
```

---

### Task 1: HTTP transport and the provider contract

**Files:**
- Create: `includes/class-blogcraft-http.php`, `includes/class-blogcraft-provider-response.php`, `includes/class-blogcraft-provider.php`
- Test: `tests/integration/test-http.php`

**Interfaces:**
- Consumes: `Blogcraft_Logger`, `Blogcraft_Settings`
- Produces:
  - `Blogcraft_Http::post_json( string $url, array $body, array $headers = array(), int $timeout = 60 ): array` returning `array( 'code' => int, 'body' => array, 'error' => string )` — `error` is `''` on success
  - `Blogcraft_Http::get_json( string $url, array $headers = array(), int $timeout = 30 ): array` — same shape
  - `Blogcraft_Http::MAX_ATTEMPTS` = 3
  - `Blogcraft_Provider_Response` public props `$text` (string), `$model` (string), `$prompt_tokens` (int), `$completion_tokens` (int), `$finish_reason` (string), `$error` (string), and `::is_error(): bool`, `::total_tokens(): int`
  - `Blogcraft_Provider` abstract: `__construct( array $config )`, abstract `complete( array $messages, array $options = array() ): Blogcraft_Provider_Response`, abstract `list_models(): array`, `capabilities(): array`, `id(): string`, `label(): string`, protected `config( $key, $default = null )`

- [ ] **Step 1: Write the failing test**

`tests/integration/test-http.php`:
```php
<?php
/**
 * HTTP transport tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Http extends WP_UnitTestCase {

	private $requests = array();

	public function set_up() {
		parent::set_up();
		$this->requests = array();
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * Queue canned responses returned in order.
	 *
	 * @param array $responses Each: array( 'code' => int, 'body' => string, 'headers' => array ).
	 * @return void
	 */
	private function fake_http( $responses ) {
		$queue = $responses;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$queue ) {
				$this->requests[] = array( 'url' => $url, 'args' => $args );
				$next = array_shift( $queue );
				if ( null === $next ) {
					return new WP_Error( 'http_request_failed', 'queue exhausted' );
				}
				if ( isset( $next['wp_error'] ) ) {
					return new WP_Error( 'http_request_failed', $next['wp_error'] );
				}
				return array(
					'response' => array( 'code' => $next['code'] ),
					'body'     => $next['body'],
					'headers'  => isset( $next['headers'] ) ? $next['headers'] : array(),
				);
			},
			10,
			3
		);
	}

	public function test_post_json_returns_decoded_body_on_success() {
		$this->fake_http( array( array( 'code' => 200, 'body' => '{"ok":true}' ) ) );
		$result = Blogcraft_Http::post_json( 'https://example.test/v1', array( 'a' => 1 ) );
		$this->assertSame( 200, $result['code'] );
		$this->assertSame( array( 'ok' => true ), $result['body'] );
		$this->assertSame( '', $result['error'] );
	}

	public function test_post_json_sets_an_explicit_timeout() {
		$this->fake_http( array( array( 'code' => 200, 'body' => '{}' ) ) );
		Blogcraft_Http::post_json( 'https://example.test/v1', array(), array(), 45 );
		$this->assertSame( 45, $this->requests[0]['args']['timeout'] );
	}

	public function test_post_json_sends_json_content_type_and_body() {
		$this->fake_http( array( array( 'code' => 200, 'body' => '{}' ) ) );
		Blogcraft_Http::post_json( 'https://example.test/v1', array( 'x' => 'y' ) );
		$args = $this->requests[0]['args'];
		$this->assertSame( 'application/json', $args['headers']['Content-Type'] );
		$this->assertSame( '{"x":"y"}', $args['body'] );
	}

	public function test_retries_on_429_then_succeeds() {
		$this->fake_http(
			array(
				array( 'code' => 429, 'body' => '{}', 'headers' => array( 'retry-after' => '0' ) ),
				array( 'code' => 200, 'body' => '{"ok":true}' ),
			)
		);
		$result = Blogcraft_Http::post_json( 'https://example.test/v1', array() );
		$this->assertSame( 200, $result['code'] );
		$this->assertCount( 2, $this->requests );
	}

	public function test_retries_on_500_then_succeeds() {
		$this->fake_http(
			array(
				array( 'code' => 500, 'body' => 'server error' ),
				array( 'code' => 200, 'body' => '{"ok":true}' ),
			)
		);
		$result = Blogcraft_Http::post_json( 'https://example.test/v1', array() );
		$this->assertSame( 200, $result['code'] );
	}

	public function test_does_not_retry_on_400() {
		$this->fake_http(
			array(
				array( 'code' => 400, 'body' => '{"error":{"message":"bad"}}' ),
				array( 'code' => 200, 'body' => '{"ok":true}' ),
			)
		);
		$result = Blogcraft_Http::post_json( 'https://example.test/v1', array() );
		$this->assertSame( 400, $result['code'] );
		$this->assertCount( 1, $this->requests );
	}

	public function test_gives_up_after_max_attempts() {
		$this->fake_http(
			array(
				array( 'code' => 500, 'body' => 'a' ),
				array( 'code' => 500, 'body' => 'b' ),
				array( 'code' => 500, 'body' => 'c' ),
			)
		);
		$result = Blogcraft_Http::post_json( 'https://example.test/v1', array() );
		$this->assertSame( 500, $result['code'] );
		$this->assertCount( Blogcraft_Http::MAX_ATTEMPTS, $this->requests );
		$this->assertNotSame( '', $result['error'] );
	}

	public function test_wp_error_is_reported_not_thrown() {
		$this->fake_http( array( array( 'wp_error' => 'dns failure' ), array( 'wp_error' => 'dns failure' ), array( 'wp_error' => 'dns failure' ) ) );
		$result = Blogcraft_Http::post_json( 'https://example.test/v1', array() );
		$this->assertSame( 0, $result['code'] );
		$this->assertStringContainsString( 'dns failure', $result['error'] );
	}

	public function test_invalid_json_body_is_reported_as_error() {
		$this->fake_http( array( array( 'code' => 200, 'body' => 'not json' ) ) );
		$result = Blogcraft_Http::post_json( 'https://example.test/v1', array() );
		$this->assertNotSame( '', $result['error'] );
		$this->assertSame( array(), $result['body'] );
	}

	public function test_response_object_reports_error_state() {
		$ok = new Blogcraft_Provider_Response();
		$ok->text = 'hello';
		$this->assertFalse( $ok->is_error() );

		$bad = new Blogcraft_Provider_Response();
		$bad->error = 'boom';
		$this->assertTrue( $bad->is_error() );
	}

	public function test_response_totals_tokens() {
		$r                    = new Blogcraft_Provider_Response();
		$r->prompt_tokens     = 10;
		$r->completion_tokens = 5;
		$this->assertSame( 15, $r->total_tokens() );
	}
}
```

- [ ] **Step 2: Run it and confirm it fails**

```
MSYS_NO_PATHCONV=1 npx wp-env run tests-cli --env-cwd=wp-content/plugins/blogcraft -- vendor/bin/phpunit --filter Test_Blogcraft_Http
```
Expected: FAIL — `Blogcraft_Http` not found.

- [ ] **Step 3: Write `Blogcraft_Provider_Response`**

```php
<?php
/**
 * Provider response value object.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * What every provider returns. Never thrown, always returned.
 *
 * A provider that fails sets $error and leaves $text empty; callers branch on
 * is_error() rather than catching, so one misbehaving provider cannot abort a
 * pipeline stage mid-flight.
 */
class Blogcraft_Provider_Response {

	/**
	 * Generated text.
	 *
	 * @var string
	 */
	public $text = '';

	/**
	 * Model that produced the response.
	 *
	 * @var string
	 */
	public $model = '';

	/**
	 * Prompt token count, 0 when the provider does not report it.
	 *
	 * @var int
	 */
	public $prompt_tokens = 0;

	/**
	 * Completion token count, 0 when the provider does not report it.
	 *
	 * @var int
	 */
	public $completion_tokens = 0;

	/**
	 * Why generation stopped, when reported.
	 *
	 * @var string
	 */
	public $finish_reason = '';

	/**
	 * Human-readable error, empty on success.
	 *
	 * @var string
	 */
	public $error = '';

	/**
	 * Whether this response represents a failure.
	 *
	 * @return bool
	 */
	public function is_error() {
		return '' !== $this->error;
	}

	/**
	 * Prompt plus completion tokens.
	 *
	 * @return int
	 */
	public function total_tokens() {
		return $this->prompt_tokens + $this->completion_tokens;
	}
}
```

- [ ] **Step 4: Write `Blogcraft_Http`**

Requirements the tests pin down — implement exactly these semantics:

- `post_json()` JSON-encodes `$body`, sets `Content-Type: application/json`, merges `$headers`, and sets `timeout` explicitly (never relying on the 5-second default).
- Retry only on **429** and **5xx** and on `WP_Error`. Never retry 4xx other than 429 — those are caller errors and retrying wastes quota.
- Honour a `Retry-After` response header when present and numeric; otherwise back off `1s, 2s` between attempts. Cap total attempts at `MAX_ATTEMPTS` (3).
- Return `array( 'code' => int, 'body' => array, 'error' => string )`. `code` is `0` for a `WP_Error`. `body` is `array()` when decoding fails, with a non-empty `error`.
- Never include request headers in the returned error string — they carry the API key.
- Log failures via `Blogcraft_Logger::error()` with the URL and status code, **never the headers or body**.

- [ ] **Step 5: Write `Blogcraft_Provider` abstract base**

```php
<?php
/**
 * Provider contract.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Base class every LLM provider extends.
 *
 * An abstract class rather than an interface so the existing autoloader
 * (Blogcraft_Foo -> class-blogcraft-foo.php) resolves it without a special case.
 */
abstract class Blogcraft_Provider {

	/**
	 * Provider configuration.
	 *
	 * @var array
	 */
	protected $config = array();

	/**
	 * Build a provider.
	 *
	 * @param array $config Keys vary by provider; typically base_url, api_key, model.
	 */
	public function __construct( $config = array() ) {
		$this->config = is_array( $config ) ? $config : array();
	}

	/**
	 * Read a config value.
	 *
	 * @param string $key     Config key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	protected function config( $key, $default = null ) {
		return array_key_exists( $key, $this->config ) ? $this->config[ $key ] : $default;
	}

	/**
	 * Machine id, e.g. 'openai'.
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Human label for the UI.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Generate a completion.
	 *
	 * @param array $messages Ordered array of array( 'role' => 'system|user|assistant', 'content' => string ).
	 * @param array $options  max_tokens, temperature, json_mode.
	 * @return Blogcraft_Provider_Response Never throws.
	 */
	abstract public function complete( $messages, $options = array() );

	/**
	 * Model ids this provider offers, best-effort.
	 *
	 * @return array Empty when discovery is unsupported or fails.
	 */
	abstract public function list_models();

	/**
	 * Declared capabilities, so callers degrade rather than fail.
	 *
	 * @return array
	 */
	public function capabilities() {
		return array(
			'json_mode'  => false,
			'streaming'  => false,
			'vision'     => false,
			'max_tokens' => 4096,
		);
	}
}
```

- [ ] **Step 6: Run tests, then lint**

```
MSYS_NO_PATHCONV=1 npx wp-env run tests-cli --env-cwd=wp-content/plugins/blogcraft -- vendor/bin/phpunit
vendor/bin/phpcs
```
Expected: all green including Phase 0's 92; zero PHPCS errors.

- [ ] **Step 7: Commit**

```bash
git add includes/class-blogcraft-http.php includes/class-blogcraft-provider-response.php includes/class-blogcraft-provider.php tests/integration/test-http.php
git commit -m "feat: HTTP transport with retry and backoff, plus provider contract"
```

---

### Task 2: OpenAI-compatible adapter

**Files:** Create `includes/class-blogcraft-provider-openai.php`; Test `tests/integration/test-provider-openai.php`

**Interfaces:**
- Consumes: `Blogcraft_Http`, `Blogcraft_Provider`, `Blogcraft_Provider_Response`
- Produces: `Blogcraft_Provider_Openai` with config keys `base_url`, `api_key`, `model`, `headers` (array, optional)

This one adapter covers Groq, OpenRouter, DeepSeek, Together, Mistral, Cerebras, xAI, Azure, OpenAI, and local Ollama / LM Studio / vLLM — the user just supplies a different `base_url`.

- [ ] **Step 1: Write the failing test**

Cover, using the same `pre_http_request` faking approach as Task 1:
- A successful completion parses `choices[0].message.content` into `$text`, `usage.prompt_tokens`/`completion_tokens` into the token fields, `model` into `$model`, and `choices[0].finish_reason` into `$finish_reason`
- The request URL is `{base_url}/chat/completions` with exactly one slash between segments, whatever trailing slash the user typed
- `Authorization: Bearer {key}` is sent
- **When `api_key` is empty, no Authorization header is sent at all** — local Ollama rejects an empty bearer
- Custom `headers` from config are merged in
- `json_mode` option sets `response_format` to `array( 'type' => 'json_object' )`; absent otherwise
- `max_tokens` and `temperature` are forwarded when supplied
- An API error body (`{"error":{"message":"invalid key"}}`) produces `is_error() === true` with that message in `$error`, and does **not** throw
- A 200 response with an unexpected shape produces an error rather than an empty success
- `list_models()` GETs `{base_url}/models` and returns the `data[].id` values, and returns `array()` on failure rather than erroring
- `capabilities()` reports `json_mode => true`

- [ ] **Step 2–3: Run (fail), implement**

Implementation notes: build the endpoint with `rtrim( $base, '/' ) . '/chat/completions'`. Never interpolate the key into a URL or error string. Treat a missing `choices[0].message.content` as an error with the raw body's `error.message` when present.

- [ ] **Step 4: Test, lint, commit** — `feat: OpenAI-compatible provider adapter`

---

### Task 3: Gemini and Anthropic adapters (batched)

**Files:** Create `includes/class-blogcraft-provider-gemini.php`, `includes/class-blogcraft-provider-anthropic.php`; Test `tests/integration/test-provider-gemini-anthropic.php`

These are the same shape as Task 2 with different JSON, so they are one dispatch.

**Gemini** — `POST {base}/models/{model}:generateContent?key={api_key}`; messages map to `contents[]` with `role` `user`/`model` (Gemini has no `assistant`), a system message becomes `system_instruction`; text at `candidates[0].content.parts[0].text`; tokens at `usageMetadata.promptTokenCount` / `candidatesTokenCount`. `list_models()` GETs `{base}/models?key=`. Default base `https://generativelanguage.googleapis.com/v1beta`.

**Anthropic** — `POST {base}/messages`; headers `x-api-key` and `anthropic-version: 2023-06-01`; **system messages go in a top-level `system` field, not in `messages`**; text at `content[0].text`; tokens at `usage.input_tokens` / `output_tokens`. `max_tokens` is **required** by the API — default it to 4096 when the caller omits it. `list_models()` returns `array()` (no public discovery endpoint) — that is correct, not a gap.

**Tests must cover for each:** successful parse, the role/system mapping above, error-body handling without throwing, and the key travelling in the right place (query string for Gemini, `x-api-key` header for Anthropic).

- [ ] Commit — `feat: native Gemini and Anthropic provider adapters`

---

### Task 4: Custom JSON-path mapper and provider registry

**Files:** Create `includes/class-blogcraft-provider-custom.php`, `includes/class-blogcraft-provider-registry.php`; Test `tests/integration/test-provider-custom.php`, `tests/integration/test-provider-registry.php`

**`Blogcraft_Provider_Custom`** — the true "any API" escape hatch. Config: `endpoint`, `api_key`, `auth_header` (default `Authorization`), `auth_prefix` (default `Bearer `), `request_template` (JSON string with `{{prompt}}` and `{{model}}` placeholders), `text_path` (dot path, e.g. `choices.0.message.content`), `prompt_tokens_path`, `completion_tokens_path`.

Produces a static helper `Blogcraft_Provider_Custom::dig( array $data, string $path )` returning the value at a dot path or `null`. Test it directly with nested arrays, numeric indices, and missing paths.

**`Blogcraft_Provider_Registry`** — `::types(): array` (id => label), `::make( string $type, array $config ): ?Blogcraft_Provider`, `::from_settings(): ?Blogcraft_Provider` building from `Blogcraft_Settings`, and `::complete_with_fallback( array $providers, array $messages, array $options ): Blogcraft_Provider_Response` trying each in order and returning the first non-error, or the **last** error when all fail.

**Tests:** unknown type returns `null` not a fatal; fallback returns the first success; fallback returns the last error when every provider fails; a provider that succeeds means later providers are never called.

- [ ] Commit — `feat: custom JSON-path provider and provider registry with fallback`

---

### Task 5: Capability probe, model discovery, and cost accounting

**Files:** Create `includes/class-blogcraft-cost.php`; Modify `includes/class-blogcraft-provider-registry.php`; Test `tests/integration/test-cost.php`

- `Blogcraft_Cost::record( string $provider, string $model, int $prompt_tokens, int $completion_tokens ): void` — accumulate into an option keyed by `YYYY-MM`, stored with autoload `false`
- `Blogcraft_Cost::month_totals( ?string $month = null ): array` — `array( 'prompt' => int, 'completion' => int, 'requests' => int )`
- `Blogcraft_Cost::reset(): void`
- `Blogcraft_Provider_Registry::probe( Blogcraft_Provider $p ): array` — merges `capabilities()` with a live `list_models()` result into `array( 'models' => array, 'capabilities' => array, 'reachable' => bool, 'error' => string )`

**New settings** (extend `Blogcraft_Settings_Schema::all()`, do not create parallel options): `provider_type` (string, default `openai`), `provider_model` (string), `provider_headers` (string, default `''`), `monthly_token_cap` (int, default `0` meaning unlimited).

**`Blogcraft_Cost` must be consulted before a request when `monthly_token_cap` is non-zero** — add `Blogcraft_Cost::over_cap(): bool` and have the registry return an error response rather than calling out when it is true. Test that path.

**Uninstall:** add the new cost option to `blogcraft_uninstall_cleanup()`. Phase 0's whole-branch review verified every created artifact is removed — do not break that ledger.

- [ ] Commit — `feat: capability probe, model discovery, and token cost accounting`

---

### Task 6: Settings UI and connection test

**Files:** Create `includes/class-blogcraft-connection.php`; Modify `includes/class-blogcraft-admin.php`; Test `tests/integration/test-connection.php`

- A **Settings** submenu under `Blogcraft_Admin::MENU_SLUG` with fields for provider type, base URL, API key (rendered **masked** via `Blogcraft_Crypto::mask()`, never the plaintext), model, and monthly token cap
- Saving routes through `Blogcraft_Request::verify_or_die( 'blogcraft_save_settings', $_POST['_blogcraft_nonce'] )` before any write; all `$_POST` reads `wp_unslash()`ed and sanitised
- **An empty API-key field means "leave unchanged", not "clear"** — otherwise every settings save wipes the stored key, since the field renders masked. Test this explicitly; it is the single most likely bug in this task.
- A **Test connection** button POSTing to `admin_post_blogcraft_test_connection`, which runs a one-token completion and reports reachability, the resolved model list, and detected capabilities
- Every output escaped; every string translatable; the page gated on `Blogcraft_Capabilities::MANAGE`

- [ ] Commit — `feat: provider settings screen with masked keys and connection test`

---

## Phase 1 Definition of Done

- [ ] All 6 tasks committed
- [ ] Full suite green (Phase 0's 92 plus Phase 1's additions)
- [ ] `vendor/bin/phpcs` zero errors
- [ ] Plugin Check still zero errors on the shipped artifact
- [ ] A user can enter a Groq or Gemini key, click Test connection, and see a real model list
- [ ] No API key appears in any log, error message, or rendered page
- [ ] Uninstall still removes every artifact, including the new cost option

## Self-Review Notes

**Spec coverage:** implements §4.1's LLM provider bullets and §3's L1. Image providers, research providers, GSC and GA4 are deliberately deferred — they belong to Phases 4, 5 and 8 and would be unused code here.

**Deliberate omissions:** no streaming (nothing consumes it until the editor UI in a later phase); no per-stage model routing yet (Phase 2 introduces stages, so the setting would have nothing to route).

**Carried Phase 0 rulings:** R13 (`DELETE FROM` in fixtures), R8 (controller provisions the environment; implementers never run `wp-env start` or composer), and the reporting-honesty rule.
