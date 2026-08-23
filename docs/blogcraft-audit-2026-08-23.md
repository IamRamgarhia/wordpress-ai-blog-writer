# BlogCraft v0.31.0 — Full Plugin Audit Report

**Date:** 2026-08-23
**Scope:** Every file in the plugin read — all 66 `includes/` classes, all assets, tests, docs, uninstall, providers data.
**Code changes made:** None (read-only audit, as requested).

---

## Executive Summary

BlogCraft is an unusually well-engineered plugin for a free product. The generation pipeline is staged (research → outline → section-by-section draft → critique → revise → verify → publish), nearly every writing control is both **sent to the model and measured afterwards** with a repair loop, API keys are encrypted at rest, and every state-changing handler has nonce + capability checks. The prompt layer genuinely enforces Google-friendly, human-sounding writing rules (banned AI tells, answer-first intros, sentence-length variety, keyword-density bands, honest E-E-A-T via author-supplied evidence).

However, the audit found:

| Severity | Count | Summary |
|---|---|---|
| Critical | 5 | Verified functional bugs that break the plugin's own core promises (dead settings inputs, TOC toggle ignored, two structurally unpassable scorecard checks, per-post zero discarded) |
| High | 15 | Duplicate-post risk, cron/autopilot defects, no runtime migration, verify measures a different document than published, refresh pipeline second-class |
| Medium | 20 | Byte-length checks, curly-apostrophe evasion, i18n loading, silent key-save failure, wrong help anchors |
| Unwired | 9 | Features half-plumbed or reachable only from tests |
| Over-engineering | 7 | Retry layers, triplicated voice controls, duplicated fields/help |
| Missing for "best free" | 18 | Fallback SEO head meta, PAA/SERP FAQs, author box, schema gaps, per-post archetype picker, and more |

The single most important theme: **the quality-gate system — the plugin's signature feature — is undermined by four wiring defects** (items C1–C4 below). Every post starts with guaranteed-failing checks, the user's threshold setting is discarded, and the verify stage scores a document that is not the one that gets published.

> Note: the older `docs/blogcraft-audit-and-roadmap.md` is outdated — everything it flagged (single-shot drafting, footer-only links, no paid image providers) is already fixed in 0.31.0. This report supersedes it.

---

## 1. Critical Bugs (verified line-by-line)

### C1. `quality_threshold` and `refresh_after_days` are dead inputs
- **Rendered:** `includes/class-blogcraft-connection.php:559,564` (`number_row(...)`).
- **Enforced:** `includes/class-blogcraft-pipeline.php:837` (publish-or-hold decision), `includes/class-blogcraft-refresh.php:51,183` (stale-post detection), `includes/class-blogcraft-verify.php:235`.
- **Never saved:** `handle_save()` (`connection.php:1141-1232`) persists `$plain` fields, caps, autopilot numbers, toggles and secrets — neither key appears anywhere in the save path (grep-verified).
- **Effect:** the quality gate is permanently stuck at the schema default (60) and refresh at 180 days. The readme's promise — *"Anything below your threshold is held for review instead of published"* — is broken. The user types a number, sees "Settings saved.", and it is discarded.

### C2. The TOC toggle is ignored at publish
- `stage_publish` unconditionally prepends `Blogcraft_Seo::render_toc( $article )` — `includes/class-blogcraft-pipeline.php:867` (same in `class-blogcraft-refresh.php:173`).
- `render_toc()` gates only on "≥ 4 sections" and never reads `$blueprint['toc']` — `includes/class-blogcraft-seo.php:452-471`.
- The toggle exists in the composer (`class-blogcraft-generate.php:625`), the blueprint screen (`class-blogcraft-blueprint-screen.php:752`), and only affects the preview panel (`class-blogcraft-preview.php:58`).
- **Effect:** default is `toc => false` (`includes/class-blogcraft-blueprint.php:197`), yet every 4+-section post ships with an H2 "What is covered" list.

### C3. The external-links check is unsatisfiable by design
- Every drafting prompt mandates: *"Plain text only in every field. No markdown, no HTML."* — `includes/class-blogcraft-prompts.php:220,289,346,380,527`.
- The scorecard counts `<a href>` tags in rendered markup and demands a minimum (default 2) — `includes/class-blogcraft-scorecard.php:406-426`, counting via `includes/class-blogcraft-metrics.php:301-328`; default target at `includes/class-blogcraft-blueprint.php:214`.
- **Effect:** the model is forbidden from producing the only thing the check accepts. Posts take a guaranteed 5-weight deduction and receive a repair instruction ("Cite a reputable source…") that asks for something the output format forbids. The only escapes are the opt-in `block_sources` (default off, `pipeline.php:570-572`) or a model disobeying the plain-text rule.

### C4. The internal-links check always fails at verify
- Internal links are woven into the content during `stage_publish` — `includes/class-blogcraft-pipeline.php:875-894`.
- `stage_verify` measures the article *before* that — `includes/class-blogcraft-pipeline.php:807`.
- Default: `internal_links_target = 3`, `internal_links_enabled = true` (`includes/class-blogcraft-settings-schema.php:71-74`, `blueprint.php:213`).
- **Effect:** the check sees 0 internal links against a target of 3, every time (acknowledged in-code at `scorecard.php:439-441` but still paid in the publish/hold decision). Combined with C3, every post starts ~8 weight-points in the hole, distorting the threshold decision the whole plugin is built around.

### C5. Per-post "0" overrides are silently discarded
- `Blogcraft_Blueprint::with_overrides()` skips integer/float values equal to 0 — `includes/class-blogcraft-blueprint.php:409-411`.
- The composer's sliders for "Pictures in the body" (`o_images_target`) and "Sources to cite" (`o_external_links_target`) start at 0 — `includes/class-blogcraft-generate.php:738,827`.
- **Effect:** sliding either to zero posts `"0"`, which is thrown away; the post silently keeps the blueprint default. There is no way to say "no images this time" per post.

---

## 2. High-Priority Bugs

### H1. `stage_publish` is not idempotent — duplicate posts on retry/reclaim
`wp_insert_post` runs at `includes/class-blogcraft-pipeline.php:913` **before** image sideloading (`pipeline.php:947-962`), which can legitimately take minutes (`download_url(..., 45)` per image at `includes/class-blogcraft-images.php:246`; up to 3 section images + featured, plus generation calls). If the stage exceeds `RECLAIM_AFTER_SECONDS` (600s, `includes/class-blogcraft-queue.php:26`) or anything after the insert fatals, `reclaim_stale()` (`queue.php:278-332`) requeues the job and `stage_publish` inserts a **second** post. No "payload already carries post_id" guard exists.

### H2. `reclaim_stale()` can steal a job from a live, slow worker
A single legitimate stage can exceed the 600s cutoff: HTTP allows 3 attempts × 60s plus 2 × 30s Retry-After sleeps (~240s per provider call, `includes/class-blogcraft-http.php:27,41,53`); research adds a search (25s × 3) plus up to MAX_SOURCES × 12s fetches (`includes/class-blogcraft-research.php:318-329,251-257`); `check_links` does up to 12 × 8s HEADs (`includes/class-blogcraft-verify.php:23,71-77`). A reclaimed job is re-executed while the original worker still runs it → double provider spend, possible double post (with H1).

### H3. Deactivation leaves the autopilot cron scheduled
`includes/class-blogcraft-deactivator.php:23-27` only calls `Blogcraft_Scheduler::unschedule()`. `Blogcraft_Autopilot::unschedule()` (`includes/class-blogcraft-autopilot.php:55-57`) is called nowhere except `uninstall.php`. The hourly `blogcraft_autopilot_tick` event survives deactivation and re-fires as a no-op forever (WP-Cron reschedules recurring events even with no callbacks).

### H4. Autopilot daily counter rolls at UTC midnight while the schedule window uses site time
`includes/class-blogcraft-autopilot.php:209-211` compares `gmdate('Y-m-d')`, but `in_window()` (`autopilot.php:118-121`) uses `wp_date()` in the site timezone. On a UTC+10 site the "posts per day" cap resets at 10am local; `plan()` (`autopilot.php:133-186`) computes allowances with local-time rules that don't match the counter's day boundary — Calendar projections drift from what `tick()` enforces.

### H5. `autopilot_per_day = 0` means "unlimited" in `tick()` but "1" in `plan()`
`tick()` at `autopilot.php:294`: `if ( $cap > 0 && self::generated_today() >= $cap )` — 0 skips the cap entirely (up to 24 posts/day). `plan()` at `autopilot.php:148`: `max( 1, (int) ... )`. The field is a number input with `min="0"` (`connection.php:568`) whose description never says 0 = unlimited. A user typing 0 to mean "stop" gets unlimited generation.

### H6. Heartbeat recorded only by the WP-Cron callback — CLI/admin runs look "stale"
`includes/class-blogcraft-scheduler.php:94` is the only `record_heartbeat()` caller. `wp blogcraft run` (`includes/class-blogcraft-cli.php:79-86`) and "Run the queue now" (`generate.php:1176-1202`) never record it — even though the CLI docblock (`cli.php:12-15`) recommends driving the queue with real system cron. In that exact setup `Cron_Health::is_stale()` stays true and the false "Blogcraft has not processed its queue recently" warning (`includes/class-blogcraft-notices.php:143-152`) shows forever.

### H7. No runtime DB migration
`Blogcraft_Migrator::migrate()` runs only on activation (`includes/class-blogcraft-activator.php:21`). Nothing compares `blogcraft_db_version` (`includes/class-blogcraft-migrator.php:18,84`) with `BLOGCRAFT_DB_VERSION` during normal loads — one-click plugin updates (which do not re-fire activation) never receive schema changes.

### H8. Verify measures a different document than the one published
Critique/verify render via `Blogcraft_Blocks::render( $article )` only (`pipeline.php:637,807`). The published post additionally has the TOC (extra H2, extra words), the "Read next" block, in-text internal links, and section images. The review screen's heading counts, word counts, link counts, and alt-text checks therefore never describe the live post.

### H9. Refresh pipeline is second-class
`stage_save` gates rewrites on the superseded legacy `Blogcraft_Verify::score()` (`includes/class-blogcraft-refresh.php:181-194`) while new posts use `Blogcraft_Scorecard::evaluate()` (the comment at `pipeline.php:807-811` explicitly calls the old heuristic superseded). It also truncates the existing post to 6000 bytes before rewriting (`refresh.php:120-124`) and does a single-shot rewrite with no critique/revise/verify stages. A rewrite of an existing post is judged by different rules than the original.

### H10. Worker rate-limit detection relies on untranslated-format substring matching
`includes/class-blogcraft-worker.php:112` matches `strpos($message, 'HTTP 429')` / `stripos(..., 'exceeded your current quota')`. Those strings come from translatable formats (`__()` in `class-blogcraft-provider-openai.php:201-207`, `class-blogcraft-provider-gemini.php:241-254`, `class-blogcraft-http.php:137-141`). On a localized site the defer path silently stops working; the second pattern is OpenAI-specific wording, and Gemini's "Resource exhausted" only survives via the "HTTP 429" half.

### H11. `strip_dead_links` can corrupt live URLs or silently keep dead ones
`str_replace( $url, '', $encoded )` on the raw URL (`includes/class-blogcraft-verify.php:116-123`): a dead `https://example.com` also gets cut out of a live `https://example.com/page` elsewhere in the article; and if the replacement produces invalid JSON, `json_decode` fails and the original article (dead links intact) is returned — while `stage_verify` still logs "Removed links that did not resolve" (`pipeline.php:793-800`).

### H12. No cross-process concurrency lock
WP-Cron tick, `wp blogcraft run`, and the admin "Run the queue now" button can all drain the queue simultaneously. The claim token prevents double-claiming one job, but nothing prevents N processes each claiming different jobs and multiplying provider spend; `run_queue` is also unguarded against overlapping executions when both page-load cron and real cron are active.

### H13. Read-modify-write races
`Queue::fail()` (`queue.php:176-186`) reads attempts then writes attempts+1 with no condition on lock_token — combined with H2 a reclaimed job can be double-incremented. `Autopilot::increment_today()` (`autopilot.php:219-224`) is a non-atomic option read-modify-write.

### H14. Cron-health grace period contradicts its documented math
`includes/class-blogcraft-scheduler.php:29-31` comment says "Three times this interval is exactly Cron_Health's default staleness threshold (900s)", but `includes/class-blogcraft-cron-health.php:87` uses `2 * RECURRENCE_SECONDS` = 600s. The test locks in 2× (`tests/integration/test-scheduler.php:77-85`), so the comment (and the "cannot drift apart" claim) is wrong, or the code should be `3 *`.

### H15. Uninstall does not remove every option
`uninstall.php:36-41` deletes 5 options but NOT `blogcraft_blueprints` (`includes/class-blogcraft-blueprint.php:32`), `blogcraft_active_blueprint` (`blueprint.php:360`), or `blogcraft_blueprints_migrated` (`blueprint.php:775`) — contradicting the file's own "removes every trace" claim. The wiring test only checks post meta, not options (`tests/integration/test-wiring.php:238-267`).

---

## 3. Medium Bugs / Risk Notes

| # | Issue | Where |
|---|---|---|
| M1 | **Byte-length title/meta checks** penalize multibyte titles/descriptions (accents, em dashes, CJK) while the prompt specifies "characters" | `editorial.php:556,603`; also `seo.php:193` |
| M2 | **Curly apostrophes evade detection** — throat-clearing phrases compare straight-apostrophe literals; "let's face it" with U+2019 escapes | `editorial.php:43-75`, `metrics.php:200-203`; `verify.php:198-207` also matches "delve" inside "delved" |
| M3 | **`load_plugin_textdomain()` never called** — translations never load outside wp.org distribution despite headers + `.pot` | grep: 0 hits in `includes/` + `blogcraft.php` |
| M4 | **Silent key-save failure** — `Settings::set()` returns false without saving when sodium is unavailable; `handle_save` ignores it and shows "Settings saved."; nothing bundles `sodium_compat` | `settings.php:131-139`, `connection.php:1213-1229`, `composer.json` (empty require) |
| M5 | **Wrong help anchors** — two links point to `#bc-card-automation` but the picture settings live in `#bc-card-pictures` (card moved in 0.29.0) | `generate.php:891`, `overview.php:344` |
| M6 | **Gemini text key in URL query string** while the Gemini *image* route correctly uses the `x-goog-api-key` header (with a comment explaining why) — text key lands in intermediary access logs | `provider-gemini.php:214-216` vs `image-models.php:443-446` |
| M7 | **API keys cannot be cleared** — blank submission means "keep"; no remove-key control | `connection.php:1210-1229` |
| M8 | **Provider-help block hidden for keyless providers** — Ollama/LM Studio (where exact model ids matter most) lose the docs link despite valid `docs_url` values | `connection.php:172-177`, `admin.js:44-47`, `providers.json:93,100` |
| M9 | **Preview/brief panels fail silently when the nonce expires** (~12-24h) — no visible error on long-open tabs | `compose.js:139-145`, `blueprint.js:112-120` |
| M10 | **`put()` in blueprint.js cannot fill chip multi-selects** (`name="x[]"`) — latent; will silently fail the moment an archetype sets literary devices | `blueprint.js:181-212` vs `controls.php:144` |
| M11 | **Legacy heuristic misfires shown to users** — "No FAQ section"/"No key takeaways" deductions surface on the review screen even when the blueprint deliberately disabled them (e.g. opinion archetype) | `verify.php:186-194`, `pipeline.php:826-828`, `archetypes.php:150-152` |
| M12 | **Preview density math inconsistent** — computes needed occurrences without dividing by phrase word count, unlike the real formula; multi-word estimates inflated | `preview.php:203-207` vs `metrics.php:183` |
| M13 | `stage_extras` unguarded `$payload['article']` access (every other stage guards it) | `pipeline.php:586` |
| M14 | `answer_first` word band mis-fires on single-sentence intros ("barely an opening at all" for a valid 15-word opener) | `editorial.php:520-531` |
| M15 | Duplicate-queued error message gap: topic skipped by the queued-duplicate check gets the generic "could not be queued" message | `generate.php:1149-1166` vs `pipeline.php:65-67` |
| M16 | `handle_bulk` counts *any* enqueue failure as "skipped as too similar" | `generate.php:1229-1243` |
| M17 | `plan()`/`hour_row()` timezone parsing: `strtotime('... UTC+5:30')` fails silently on manual-offset zones → partial/empty Calendar | `autopilot.php:162-164`, `connection.php:928` |
| M18 | Uninstall guard is non-idiomatic (`defined(WP_UNINSTALL_PLUGIN) || defined(ABSPATH) || exit`) — safe today, easy to break | `uninstall.php:10` |
| M19 | `.bc-clash` / `.bc-only-this` classes have no CSS — duplicate-topic warning renders unstyled | `generate.php:475`, grep of `admin.css`/`blueprint.css` |
| M20 | Overview "How this works" toggle label never changes from "Show the steps" | `overview.php:215-218` |

---

## 4. Unwired Things

| # | Item | Detail |
|---|---|---|
| U1 | `Blogcraft_Autopilot::unschedule()` | No deactivation caller (see H3) |
| U2 | `Blogcraft_Queue::release()` | No production caller; tests only (`queue.php:227-236`) |
| U3 | Queue status `'deferred'` | Accepted by `cancel()` but never written — `defer()` writes `'pending'` (`queue.php:254,425`) |
| U4 | `Blogcraft_Endpoints::provider()` | Never called; registry builds from `text()` directly (`endpoints.php:85-100`) |
| U5 | `Registry::complete_with_fallback()` | Multi-provider fallback has zero production call sites; would double-record cost if wired into `ask()` (`provider-registry.php:332-362` vs `pipeline.php:201-206`) |
| U6 | `Blogcraft_Verify::passes()` | Never called (`verify.php:234-239`) |
| U7 | `Blogcraft_Prompts::draft()` | Superseded by intro/section stages; only a test references it (`prompts.php:194`) |
| U8 | `Blogcraft_Job` value object | `$status`, `$attempts`, `$max_attempts` populated but never read — `fail()` re-reads attempts from DB (`job.php:44,58,65`) |
| U9 | No self-healing schedule | `Scheduler::schedule()`/`Autopilot::schedule()` run only at activation; deleted cron events = queue silently never runs again; `is_scheduled()` has no production caller |

---

## 5. Over-Engineering

| # | Item | Detail |
|---|---|---|
| O1 | **Four overlapping retry layers** | HTTP retries (3 attempts + Retry-After sleeps, `http.php:92-162`) + worker 429→defer 30min (`worker.php:112-121`) + queue exponential backoff 60/120/240 (`queue.php:206`) + stale-reclaim attempt increments. One quota error can be retried on three clocks. |
| O2 | **Three near-identical voice layers** | Settings card 04 ("Who you write for"), Blueprint Voice pane ("Who is reading"), and the per-post Voice tab ("Describe the reader") all end up in prompts via slightly different paths (`voice.php:105+`, `blueprint.php:479-618`, `generate.php:641-690`) |
| O3 | **Composer duplicates ~40 of the blueprint's 48 fields** with different prefixes and semantics — the source of C5's zero-value asymmetry | `generate.php:319-372` vs `blueprint.php:162-243` |
| O4 | **154-line dismissible-notices framework serves exactly one notice** (cron health) | `notices.php` |
| O5 | **Help content exists twice** — folded per-card panels largely duplicate the Help screen nearly verbatim | `connection.php:656-749` vs `docs.php:71-171` |
| O6 | **Multi-blueprint plumbing, single-blueprint UI** — the option store is keyed by slug with `active_slug()`/`save($slug,...)`, but no create/switch/list UI exists | `blueprint.php:326-380` |
| O7 | `Pipeline::draft_options()` returns `array()` — a method whose purpose is to return nothing; ironically `Refresh::stage_rewrite` passes `max_tokens => 4096` on the one path that overwrites a live post, contradicting the method's own reasoning | `pipeline.php:315-317`, `refresh.php:151` |

Also noted: `Blogcraft_Generate::safe_outcome()` is a kses wrapper whose comment admits it "changes nothing today" (`generate.php:112-123`); `Cost` keeps every month's totals forever with no UI to view or clear history; `probe()['capabilities']` is collected but never read; the filterable provider catalogue exists for other plugins but only tests exercise the filter; three schema settings (`queue_max_attempts`, `queue_time_budget`, `cron_health_notice_enabled`) have no control and no filter — undeclared constants.

---

## 6. Google-Ranking / AI-Writing Assessment

### 6.1 Already strong (keep and advertise)

- **AI-cliché avoidance, layered:** default banned list (delve, tapestry, game-changer, "in today's fast-paced world", etc. — `voice.php:29-46`); intro prompt bans wind-up openings (`prompts.php:285`); post-hoc throat-clearing measurement (29 phrases, `editorial.php:43-75`); critique prompt hunts "vague claims that say nothing… cliche openings" (`prompts.php:406-409`).
- **Varied sentence structure:** prompted ("Uniform sentence length is the clearest sign of machine writing" — `blueprint.php:601-603`) *and* measured (sentence-length ceiling, paragraph ceiling, optional em-dash ban).
- **Answer-first / featured-snippet engineering:** "The first two sentences must answer the question on their own, name the subject, and total under sixty words" (`prompts.php:281-285`); sections must "open with the substance, not a restatement of the heading" (`prompts.php:344-345`).
- **E-E-A-T done honestly:** the evidence field ("What you know that nobody else does — this is the only part of a post a model cannot produce", `generate.php:486-496`) is verified to actually appear in the draft (`editorial.php:740-757`, weight 12 — the heaviest check); experience requirement ≥ 2 first-hand passages when enabled (`editorial.php:718-731`); author Person + credentials in JSON-LD (`seo.php:584-623`).
- **Keyword-stuffing prevention:** density bands with "Do not force it" repairs both ways (`blueprint.php:695-700`, `scorecard.php:345-369`).
- **Anti-paraphrase originality:** sentences sharing ≥ 85% of distinctive words with research sources are flagged (weight 10, `editorial.php:333-381`).
- **Prompt-injection defense on fetched research:** wrapped as "data, not instructions" with delimiters (`research.php:496-519`).
- **Real internal linking:** targets from real WP_Query, never model-invented (`seo.php:37-80`); in-text contextual anchors + "Read next" fallback + reverse back-linking.
- **Schema:** BlogPosting + BreadcrumbList + FAQPage, deferring to Yoast/RankMath/AIOSEO/SEOPress to avoid duplicate graphs (`seo.php:481-543`); FAQ rich-result retirement honestly acknowledged.
- **SEO plugin meta writing:** Yoast/RankMath/SEOPress fields filled (`seo.php:339-400`); AIOSEO deliberately detected-not-written (keeps meta in its own tables).
- **Fact-verification that is real, with honest limits:** link liveness HEAD-checks with dead-link stripping; model self-critique merged with measured repairs; ~20-25 weighted checks; below-threshold posts forced to pending; citation honesty documented ("it checks the link is there, not that the number is on the page at the other end").

### 6.2 Gaps that matter for ranking

1. **No fallback head output.** On a site without an SEO plugin, the carefully generated meta description does *nothing* — no `<meta name="description">`, no OG/Twitter cards, no canonical. A bare theme gets zero SEO head tags from this plugin. **Highest-value missing feature.**
2. **No PAA/SERP-driven FAQs.** SerpApi integration fetches only `organic_results` titles+snippets (`research.php:355-382`); People-Also-Ask and related searches are ignored. FAQ questions are model-invented (`prompts.php:371-393`) instead of harvested — a big, cheap win.
3. **Competitor H2s never reach the outline prompt** (excerpts only, 1200 chars each, `research.php:27`); no per-heading content-gap scoring.
4. **E-E-A-T is JSON-LD only.** No front-end author box, no `sameAs` profile links, no per-author expertise pages. (`reviewedBy` is not a documented schema.org CreativeWork property — the "strongest signal available" comment at `seo.php:608-610` overstates.)
5. **Schema gaps vs. archetypes:** no HowTo (tutorial), no ItemList (listicle), no Review/Product markup (review archetype).
6. **TOC is decoration:** no anchors because WordPress adds no heading IDs (`seo.php:443-448`); a `the_content` filter adding IDs would make it navigable.
7. **No keyphrase-in-slug check.**
8. **Hardcoded `post_type => 'post'`** (`pipeline.php:900`, `seo.php:51`, `review.php:110`) — no CPT support.
9. **One revise pass only,** no re-measure-and-retry; verification is structural (link/figure presence), never content-level.
10. **No cross-post scaled-content variety guard** (per-post dedup exists via `backlinks.php:263`; nothing ensures a week of autopilot posts don't read identically).
11. **Content freshness is a crude full-rewrite** (see H9) — no diff-based update, no "what changed" section, no updated-date strategy.

---

## 7. Post Types / Categories (Archetypes)

### 7.1 The 8 existing shapes (`class-blogcraft-archetypes.php:31-209`)

| Shape | Preset |
|---|---|
| **guide** | "Definitive guide" — 2200 words, 6-10 sections, TOC, FAQ 5, tables, citations+statistics, 4 images |
| **listicle** | "Numbered list, with a verdict" — 1800 words, "an actual recommendation rather than 'it depends'", require_experience |
| **tutorial** | "Step by step" — 1400 words, 22-word sentences, simple reading level, next_steps ending |
| **comparison** | "This against that" — 1600 words, same criteria, a table, "a straight answer about who each one suits" |
| **study** | "Data study" — 2000 words, "the method stated and every number sourced", 8 outbound links |
| **opinion** | "An argued opinion" — 1200 words, first person, 34-word sentences, no hedging |
| **explainer** | "Quick explainer" — 800 words, "answers in the first two sentences and stops" |
| **review** | "Hands-on review" — 1700 words, "specific about what went wrong as well as what worked" |

Archetype fields are validated against real blueprint fields and valid choice values (`archetypes.php:222-264`) — well engineered.

### 7.2 Missing

- **No per-post archetype picker.** Archetypes are only a blueprint-screen preset; `override_fields()` (`generate.php:319-372`) has no archetype key. You cannot pick "this one is a listicle" without switching the standing blueprint.
- **Missing shapes users will ask for:** news/announcement post, multi-item roundup ("10 best X"), case study, pillar/cluster hub, glossary/definition post, FAQ-only post, checklist/cheat-sheet, interview/Q&A, buyer's guide, **business/press-release post** (your "business post" ask), comparison of 3+ items.

---

## 8. Where the User Writes (UX Walkthrough)

- **Overview** — setup checklist (4 steps), "Needs you" alerts, token stats, recent posts. No inputs.
- **Write a post** (`generate.php:190-286`) — the composer: topic (required), angle, evidence field, draft/publish choice, six override tabs (Shape / Voice / Search / Sounding human / Pictures / Publishing with category, tags, author, `publish_at`), live AJAX "What you will get" panel (structure preview, not prose — deliberate), queue counters, bulk topic textarea, 24h rollback.
- **How it writes** (blueprint editor) — 48 fields across 7 panes, 8 archetype presets + "match an article" URL box, live literal prompt text in the right rail.
- **Settings** — six numbered cards (Provider / Pictures / Research / Voice / Automation / Test connection).
- **Flow:** topic → queue → cron/CLI/manual drain → staged pipeline → post (draft default; `publish` honored unless score < threshold → forced pending) → Needs review screen (approve/trash).
- **No live prose preview or in-plugin editor exists anywhere**; no "regenerate section" / "make it longer" iteration on a finished draft.

### UX gaps vs. "best free plugin" bar
1. No onboarding wizard (the Overview checklist is the closest).
2. Queue opacity: no per-job progress, no auto-refresh; user must discover Activity.
3. **Form values are lost on error** — `handle_queue` failure redirects back with a notice but the composer re-renders blank (all fields from blueprint defaults, not `$_POST`).
4. Sticky inputs: can't clear an API key (M7); can't zero images/links per post (C5); two dead settings (C1).
5. Accessibility: solid ARIA baseline, but compose tabs lack arrow-key navigation; rollback confirm is inline CSP-hostile JS (`generate.php:280`).
6. Bulk generation is a textarea only — no CSV upload, no per-row status.
7. i18n is exemplary server-side (every string, `_n()` with translator comments) but never loaded at runtime (M3), and no JS `wp.i18n`.

---

## 9. Security Assessment (mostly clean)

**Good:** every state-changing handler routes through `Blogcraft_Request::verify_or_die`/`verify` (nonce + `manage_blogcraft` capability) — verified across all 14 admin-post + 4 AJAX handlers. All SQL is prepared or built from `$wpdb->prefix` + fixed suffixes. Keys are sodium-encrypted at rest, masked in UI, never passed to JS, stripped from logs via `redact_url()`. CSRF-hardened redirects throughout. Front-end exposure: none.

**Gaps:** silent key-save failure without sodium (M4); `random_bytes()` can throw uncaught in `Crypto::encrypt` (`crypto.php:54`); a file-scoped PHPCS output-escaping disable over the composer section relies entirely on `Blogcraft_Controls` staying escape-safe (`generate.php:435`); Gemini text key in query string (M6); no `map_meta_cap` fallback if the custom cap grant is ever lost (admins lock themselves out).

---

## 10. Prioritized Recommendations

### Tier 1 — surgical fixes (small diffs, restore broken promises)
1. Save `quality_threshold` + `refresh_after_days` in `handle_save()` (C1).
2. Honor `$blueprint['toc']` in `render_toc` / `stage_publish` (C2).
3. Reconcile external links: either allow the model to emit links in a defined field, or count the sources block / weave real outbound links from research at publish like internal links — and stop asking the model for what the format forbids (C3).
4. Measure internal links after weaving (move measurement to publish-time or re-run scorecard post-weaving) (C4).
5. Make per-post zeros meaningful (explicit "none" option or `-1` sentinel) (C5).
6. Fix the two `#bc-card-automation` anchors → `#bc-card-pictures` (M5).

### Tier 2 — reliability
7. Idempotent publish: store `post_id` in payload, skip insert if present (H1); lengthen or per-stage the reclaim window (H2).
8. `Autopilot::unschedule()` on deactivation (H3); self-heal schedules on admin loads (U9).
9. Unify autopilot day boundary on site time; make 0 mean "off" (H4/H5).
10. Record heartbeat on any successful queue drain (H6).
11. Runtime `blogcraft_db_version` check on `plugins_loaded`/admin init (H7).
12. Delete blueprint options in uninstall (H15).

### Tier 3 — the ranking-carrying features (the "best free" gap)
13. **Fallback head output:** `<meta name="description">`, OG/Twitter, when no SEO plugin — makes the generated meta actually do something on bare themes.
14. **PAA/related-search harvesting** from SerpApi for FAQ generation.
15. Feed competitor H2 structures into the outline prompt.
16. Front-end author box + `sameAs` (visible E-E-A-T).
17. HowTo/ItemList schema for matching archetypes; heading-ID filter to make the TOC navigable.
18. Per-post archetype picker on the Write screen + new shapes (business/press release, roundup, case study, pillar page, glossary, FAQ-only, checklist, interview, buyer's guide).
19. Upgrade refresh to the scorecard path with a repair loop (H9).
20. Multibyte-safe length checks, curly-apostrophe normalization, structured (non-string) rate-limit signaling from providers (M1/M2/H10).

### Tier 4 — simplification
21. Collapse the three voice layers into one source of truth with per-post deltas.
22. Reduce retry layers to two with one clock.
23. Delete or wire the dead code in section 4; merge the duplicated help content.

---

## Appendix — End-to-End Pipeline Flow (as verified)

1. **User action:** "Write a post" form → `admin-post.php` → `handle_queue` (`generate.php:1130`, nonce+cap) → `Pipeline::enqueue_topic` (`pipeline.php:54-93`): duplicate checks (published + queued) → `Queue::enqueue('write_post', 'research', payload)` with blueprint snapshot.
2. **Trigger:** activation schedules `blogcraft_run_queue` every 5 min (`scheduler.php:64-68`); manual button and CLI call the worker directly.
3. **Worker:** token-claim lock (`queue.php:95-122`), one stage per run (`worker.php:95-139`); success → `advance()`; Throwable → `fail()` backoff; rate-limit strings → `defer()`.
4. **Stages** (`pipeline.php:30-41`): research (never fails the job) → outline → draft/intro → section loop → faq → extras → critique (model + scorecard repair notes) → revise (conditional) → verify (link check + scorecard + threshold) → publish.
5. **Provider call** (`pipeline.php:177-215`): cost cap → registry → adapter → HTTP (3 attempts, redacted logging) → cost record → `extract_json`.
6. **Publish:** title/slug/excerpt, TOC + blocks, internal links woven, placement validated, `wp_insert_post`, meta (quality/checks/metrics/FAQ schema), images best-effort, back-links, complete.
7. **Autopilot:** hourly tick → enabled + site-time window + UTC-day cap + cost cap → enqueue+consume or `maybe_refresh` (legacy-scored rewrite).

**Fragility points:** publish non-idempotence (H1), reclaim window vs. stage duration (H2), string-matched deferral (H10), heartbeat/CLI mismatch (H6), refresh on the superseded scorer (H9), autopilot time semantics (H4/H5), and the four quality-gate wiring defects (C1-C4).

---

*Report generated from a full read of all plugin files. All `file:line` references verified against the working tree at commit `6a1edcd` (branch `phase-0-foundations`).*
