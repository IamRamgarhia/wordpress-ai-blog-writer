# Blogcraft — Handoff

**Read this first in a new session.** It exists so you can start building in minutes instead of re-deriving decisions.

**Repo:** https://github.com/IamRamgarhia/blogcraft (private) · branch `phase-0-foundations`
**Spec:** `docs/superpowers/specs/2026-08-17-blogcraft-design.md` — full ~200-feature inventory, compliance mapping, data model
**Plans:** `docs/superpowers/plans/` — Phase 0 and Phase 1
**Ledgers:** `.superpowers/sdd/*/progress.md` — every finding and all 17 rulings, with reasoning

---

## State as of v0.5.0

37 classes · 6,061 lines shipped · 22 test files · **265 tests passing** · PHPCS 0 findings · 41 commits

**Works end to end:** connect any AI provider with your own key → enter a topic → outline, draft, self-critique, revise, publish → Gutenberg draft with featured image, internal links, and schema.

| Phase | State |
|---|---|
| 0 Foundations | Complete |
| 1 Provider layer | Complete |
| 2 Generation pipeline | Complete |
| 3 Voice & control | Core only |
| 4 Research & SEO | Partial — linking and schema only |
| 5 Media | Partial — featured image only |
| 6 Quality gates | **Nothing built** |
| 7 Automation | Partial — topic queue and daily cap |
| 8 The moat | Partial — backward linking, duplicate detection |
| 9 Ship | **Nothing built** |

Roughly **30% of the spec's features**, but the architecture for the rest is in place: most remaining work is a class plus a settings entry plus a pipeline stage, not new plumbing.

---

## Do this before writing any more code

**Nothing in this plugin has ever met a real model.** Every test uses a faked `pre_http_request` layer. The single highest-value action is a real run against a live API key on a staging site.

The likeliest failure: a model returns JSON in a shape the parser doesn't expect. `Blogcraft_Prompts::extract_json()` recovers from code fences and surrounding prose, but not from a restructured schema. Failures surface as a failed job with the reason in `wp_blogcraft_log`.

Fix whatever that run reveals before adding features on top.

---

## Environment — hard-won, saves hours

wp-env is provisioned. **Do not re-provision it.**

```
# Tests. The MSYS_NO_PATHCONV=1 prefix is REQUIRED under Git Bash, or the
# container path is rewritten to C:/Program Files/Git/var/... and fails.
MSYS_NO_PATHCONV=1 npx wp-env run tests-cli --env-cwd=wp-content/plugins/blogcraft -- vendor/bin/phpunit

# One class:  append --filter Test_Blogcraft_Foo
# Lint:       vendor/bin/phpcs     Auto-fix: vendor/bin/phpcbf
```

- **Never** run `wp-env start/stop/destroy`. First start clones WordPress core's full history (~449k objects) and cost ~390k tokens of stalls in Phase 0.
- **Never** run `composer install/update` from a subagent — a permission classifier blocks it. If genuinely needed, run it yourself with:
  `COMPOSER_AUTH="{\"github-oauth\":{\"github.com\":\"$(gh auth token)\"}}" composer install --prefer-dist`
  (plain form hits GitHub 429s).
- Per-file PHPUnit invocation by path is broken here. Use `--filter <ClassName>`.
- **Read command output in full.** Piping PHPCS or Plugin Check through `tail` hid real errors twice, and both times a "clean" claim was made that wasn't true.

---

## Rulings that still bind

- **R13** — `DELETE FROM`, never `TRUNCATE`, in fixtures. TRUNCATE is DDL and forces an implicit COMMIT that breaks `WP_UnitTestCase`'s per-test rollback suite-wide.
- **R14** — `catch ( Throwable )`, not `Exception`. `Error` doesn't extend `Exception`, and an escaping one strands a job in `running` forever.
- **R16** — Plugin Check is judged against the **built artifact**, not the working tree. The dist folder must be named `blogcraft` or every i18n call reports a false text-domain mismatch.
- **P1-R1** — Provider error precedence: body's `error.message` first, *then* the HTTP-level error. Reversing it hides "Incorrect API key provided" behind "Request failed with HTTP 401".
- **Never log a URL unredacted.** `Blogcraft_Http::redact_url()` keeps scheme/host/path only. Gemini authenticates by query string; logging the raw URL leaked a live API key in cleartext into an admin-readable table. Allowlist components, never denylist parameter names — the custom provider lets users name their credential anything.
- Every test class touching the DB calls `Blogcraft_Migrator::migrate()` in `set_up()`, and any class granting a capability calls `Blogcraft_Capabilities::remove()` in `tear_down()` (`WP_Roles` is a process-level singleton the transaction rollback doesn't reset).

---

## Recommended build order

**1. Phase 6 — Quality gates.** The biggest *safety* gap: nothing checks a post before it publishes. Link and statistic verification, rubric scoring with a pass threshold, a review queue, approve-by-email. Small and high value.

**2. Phase 4 — Research.** The biggest *quality* gap. Posts are currently written from training data alone — exactly the "summarises what's already out there" problem the spec identifies as what search engines discount. Needs: SERP fetch, information-gain analysis, PAA harvesting, cited statistics, and writing to Yoast/Rank Math meta fields. Note the spec's constraint — free search tiers are thin, so zero-setup mode uses the user's own site plus supplied URLs.

**3. Phase 9 — Ship.** i18n, accessibility, Plugin Check against the built artifact, and the two blockers: `Contributors: dicecodes` must be a real WordPress.org username, and Guideline 4 requires the **repo to be public**.

**4. Phases 5, 7, 8 remainder.** In-article images, calendar and granular scheduling, bulk ops with rollback, WP-CLI and REST, then Search Console mining and content refresh.

---

## Process note

Subagent-per-task was right for Phase 0's security and compliance work and caught a credential leak, a security regression, and a shipping-blocker. It cost 200–350k tokens per task. Writing routine code directly cost a fraction and found just as many bugs, because the bugs surfaced from running tests rather than from review.

Use agents for credential handling, concurrency, and anything with a compliance surface. Write the rest directly.

Of the findings across this project, **most were defects in the plan or the instructions, not the implementations.** Budget review effort accordingly — the specification is where errors live.
