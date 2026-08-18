# Blogcraft — Completion Plan

**Goal:** take Blogcraft from ~30% to submission-ready, in **six work packages with one verification pass at the end** — not a build-and-test cycle per feature.

**Current:** v0.6.1 · 37 classes · 265 tests · PHPCS clean · Phases 0–3 done, 4/5/7/8 partial, 6/9 absent.

**Read first:** [`docs/HANDOFF.md`](../../HANDOFF.md) — environment gotchas and the rulings that still bind. Do not skip it; it is where the expensive mistakes are written down.

---

## Working method

The stop-and-test-every-feature loop is what made this slow. Instead:

1. Build a whole work package (several classes) before running anything.
2. Run `vendor/bin/phpcs` and the full suite **once per package**.
3. Commit the package as one or two commits.
4. Only after **all six packages** are done: one Plugin Check run, one staging upload, one end-to-end test.

Do **not** cut a zip between packages. One build at the end.

**Every package must leave the suite green and PHPCS at zero.** A package that can't is not finished.

---

## Rules that bind every package

Copied here so no package needs to go looking.

- **PHP 7.4 floor** — no enums, `match`, constructor promotion, `readonly`, union types, first-class callables. Must run clean on 8.5: declare every property, explicit `?Type`, no `${var}`.
- **Prefixes** `Blogcraft_` / `blogcraft_` / `BLOGCRAFT_`; text domain `blogcraft`; `ABSPATH` guard on every file.
- **Escape all output, sanitise all input.** Every state-changing admin action goes through `Blogcraft_Request::verify_or_die()`.
- **No CDN assets, no iframes, no self-updater** (Guideline 8). All CSS/JS local.
- **No traffic or ranking promises** anywhere in UI or readme (Guideline 9).
- **Notices dismissible and confined to plugin screens** (Guideline 11).
- **Every provider documented** in readme with purpose, data sent, ToS and privacy links (Guideline 6/7).
- **`DELETE FROM`, never `TRUNCATE`** in fixtures. **`catch ( Throwable )`, never `Exception`.**
- **Never log a credential.** URLs go through `Blogcraft_Http::redact_url()`.
- **Every new persistent artifact must be removed in `uninstall.php`.** The whole-branch review verified that ledger balances; do not break it.
- **Every new setting goes in `Blogcraft_Settings_Schema::all()`.** No parallel option keys.
- Test classes touching the DB call `Blogcraft_Migrator::migrate()` in `set_up()`; those granting capabilities call `Blogcraft_Capabilities::remove()` in `tear_down()`.

---

## Package 1 — Quality gates (Phase 6)

The biggest safety gap: nothing checks a post before it publishes.

**Create:** `class-blogcraft-verify.php`, `class-blogcraft-review.php`
**Modify:** `class-blogcraft-pipeline.php` (new `verify` stage before `publish`), schema, uninstall

- `Blogcraft_Verify::check_links( $article ): array` — HTTP-HEAD every external URL the model produced; strip or flag dead ones. Use `wp_remote_head` with a short timeout and a cap on how many are checked.
- `Blogcraft_Verify::score( $article ): array` — rubric returning 0–100 plus reasons: has an intro, has ≥3 sections, paragraphs under ~60 words, no banned phrase from `Blogcraft_Voice::banned_words()`, FAQ present.
- `Blogcraft_Verify::passes( $article ): bool` — score ≥ configurable `quality_threshold` (default 60).
- New pipeline stage `verify` between `revise` and `publish`: below threshold → force `post_status = pending` regardless of the requested status, and record why in post meta.
- `Blogcraft_Review` — a "Needs review" admin screen listing generated posts in `pending`, showing the score and reasons, with Approve (publish) and Reject (trash) actions through `Blogcraft_Request::verify_or_die()`.

**Settings:** `quality_threshold` (int 60), `verify_links_enabled` (bool true).
**Tests:** rubric scores a good article high and a thin one low; a banned phrase lowers the score; below-threshold forces `pending`; dead link stripped; approve publishes; reject trashes.

---

## Package 2 — Research and information gain (Phase 4)

The biggest quality gap. Posts are currently written from training data alone — which is exactly what search engines discount.

**Create:** `class-blogcraft-research.php`, `class-blogcraft-search-provider.php`
**Modify:** pipeline (new `research` stage first), prompts, schema, uninstall

- Search adapters behind one contract: Tavily, SerpApi, SearXNG, and **none** (zero-setup). Same shape as `Blogcraft_Provider`: an abstract base plus thin adapters, no interfaces (the autoloader can't resolve them).
- Zero-setup mode uses the site's own posts via `Blogcraft_Seo::related_posts()` plus any user-supplied URLs. **Nothing may hard-fail without a search key.**
- `research` stage runs before `outline`, putting findings in the payload: source snippets with URLs, and an information-gain note on what existing coverage misses.
- **Prompt-injection defence is mandatory:** fetched content is truncated, stripped to plain text, wrapped in delimiters, and explicitly marked untrusted. Never interpolate fetched text into an instruction.
- Prompts gain a cited-statistics instruction: claims that carry a number must carry the source URL.

**Settings:** `research_provider`, `research_api_key` (secret), `research_urls` (textarea).
**Tests:** zero-setup mode returns own-site context and never errors; a fetch failure degrades rather than failing the job; fetched text is delimited and marked untrusted; a research key is never logged.

---

## Package 3 — SEO completion (Phase 4 remainder)

**Modify:** `class-blogcraft-seo.php`, pipeline

- Write meta title and description into **Yoast, Rank Math, AIOSEO and SEOPress** meta keys when the corresponding plugin is active. Detect, don't assume.
- FAQPage JSON-LD when the article has an FAQ block (alongside BlogPosting, still deferring wholly when an SEO plugin is active).
- Table of contents block from the article's own headings.
- External authority links, HTTP-verified via Package 1's checker before they ship.
- IndexNow ping and sitemap ping on publish, both behind settings and off by default.

**Tests:** meta written only when the target plugin is active; FAQPage emitted only with FAQ content; TOC matches actual headings.

---

## Package 4 — Media and content refresh (Phases 5, 8)

**Create:** `class-blogcraft-refresh.php`
**Modify:** `class-blogcraft-images.php`, pipeline, schema, uninstall

- In-article images: one per H2 when enabled, each with alt text, inserted as image blocks.
- Image provider chain with fallback: Pollinations → Pexels → Pixabay → configured. A failure must never fail the post.
- **Content refresh** — the differentiator. `Blogcraft_Refresh::find_stale( $days )` returns generated posts older than N days; a `refresh_post` pipeline rewrites one in place, preserving its URL and revision history, and updates `post_modified`.
- Admin screen listing stale posts with a "Refresh" action, plus an autopilot toggle to refresh on a schedule.

**Settings:** `images_per_section` (bool), `image_provider`, `pexels_api_key`/`pixabay_api_key` (secrets), `refresh_enabled`, `refresh_after_days` (default 180).
**Tests:** fallback chain moves to the next provider on failure; refresh preserves post ID, slug and permalink; stale detection respects the day threshold.

---

## Package 5 — Automation, control and bulk (Phase 7)

**Create:** `class-blogcraft-calendar.php`, `class-blogcraft-cli.php`
**Modify:** autopilot, admin, schema

- Granular scheduling: specific weekdays and an hour, timezone-aware, replacing the blunt hourly tick.
- Calendar screen: queued topics with their planned dates, editable and reorderable.
- Bulk: paste or CSV-import many topics at once; bulk generate.
- **Rollback:** unpublish or trash a batch of generated posts in one action, with a confirmation step.
- WP-CLI: `wp blogcraft generate <topic>`, `wp blogcraft run`, `wp blogcraft status`. Register only when `WP_CLI` is defined.
- Per-topic overrides — angle, target keyword, extra instructions — carried on the queue row and into the prompts. This is the direct fix for the market's loudest complaint, that every post reads like the same template.

**Tests:** a day/hour schedule fires only in its window; CSV import parses and dedupes; rollback affects only generated posts; per-topic instructions reach the prompt.

---

## Package 6 — Ship (Phase 9)

**Create:** `languages/blogcraft.pot`, `.github/workflows/` test job
**Modify:** readme, all screens

- Generate the `.pot` file. Audit every string for a text domain and translators comments.
- Accessibility pass: labels bound to inputs, visible focus, sensible heading order, no colour-only meaning.
- Readme: full external-services disclosure per provider (purpose, data sent, ToS link, privacy link) — Guideline 6/7. **Re-read the whole readme once against Guideline 9 alone** and confirm no traffic or ranking claim survives.
- Onboarding: a first-run notice pointing at Settings, dismissible per user.
- **Two blockers to clear:** `Contributors: dicecodes` must be a real WordPress.org username, and **the GitHub repo must be public** (Guideline 4 requires public source access).
- CI: add a real PHPUnit job via wp-env — the current matrix job only runs `php -l`, which cannot catch an 8.5 deprecation.

---

## Final verification — run once, after all six packages

- [ ] `vendor/bin/phpcs` — zero errors. **Read the full output, never through `tail`.**
- [ ] Full suite green
- [ ] `wp plugin check blogcraft` on the **built artifact**, in a directory named `blogcraft`. Zero errors in `plugin_repo`.
- [ ] Build the zip **including `assets/`**; verify forward-slash entries and a single `blogcraft/` root
- [ ] Fresh install: activate → tables created → uninstall leaves no table, option, user meta or scheduled event
- [ ] One real generation against a live key on staging, end to end
- [ ] Confirm no API key appears in `wp_blogcraft_log`, in any error message, or on any rendered page

Only then hand it over for testing.

---

## Order and why

1 before 2 because Package 2's external links need Package 1's verifier. 3 depends on 2's research output. 4 and 5 are independent and can swap. 6 is last because it audits everything the others added.

If context runs short, the packages are independently valuable and land in priority order: **quality gates protect the user, research improves what they publish, and the rest is reach.**
