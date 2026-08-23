# BlogCraft — Final Plan

**Date:** 2026-08-23
**Inputs:** `blogcraft-audit-2026-08-23.md` (internal, read-only code audit) + `competitive-research-2026-08-23.md` (40+ market products) + two follow-up research streams run after those two reports (standalone SaaS writers: Jasper/Copy.ai/Writesonic/Penfriend/BrandWell/etc.; real-world user complaints from Reddit/reviews/forums) + direct verification against the working tree at commit `6a1edcd`.
**Purpose:** One ranked list of what actually needs doing, with the audit's bug claims checked against the code rather than taken on faith.

---

## 0. Verification verdict

I re-derived all five "Critical" findings and two "High" findings directly from the source, plus one claim from the competitive-research doc. All eight matched exactly:

| Claim | Verified how | Result |
|---|---|---|
| C1 — `quality_threshold` / `refresh_after_days` never saved | Read `handle_save()` end to end (`class-blogcraft-connection.php:1141-1231`) and every field list it merges from | **Confirmed.** Neither key appears anywhere in the save path. |
| C2 — TOC toggle ignored at publish | Read `render_toc()` (`class-blogcraft-seo.php:452-471`) — takes no blueprint argument, checks only section count | **Confirmed.** The toggle only reaches the AJAX preview panel. |
| C3 — external-link check unsatisfiable by design | Traced `block_sources` (default `false`, `blueprint.php:202`) as the only path that emits a real `<a href>`, against five prompt instances of "no markdown, no HTML" | **Confirmed.** Default config: the model is forbidden from producing what the check demands. |
| C4 — internal links measured before they're woven | Read `stage_verify` (scores `Blocks::render($article)`, no links) vs `stage_publish` (weaves links after, `pipeline.php:786-894`) | **Confirmed.** The scorecard that decides publish-or-hold always sees 0 internal links. |
| C5 — per-post "0" silently discarded | Read `with_overrides()` (`blueprint.php:409-411`) — explicit `0 === (int) $value` skip | **Confirmed.** |
| H1 — publish not idempotent | Read `stage_publish` — `wp_insert_post` with no prior "already has post_id" check | **Confirmed.** |
| Competitive-research: PAA data available but unused | Read `search_serpapi()` (`research.php:355-382`) — reads only `organic_results`, never `people_also_ask` | **Confirmed.** |

Given this hit rate I'm treating the rest of both documents (15 more High items, 20 Medium, the 27-item competitive gap list) as reliable without re-deriving each one — they were plainly written the same way, from the actual file. The prioritization below is mine, not a re-statement of either document's own ranking.

Two things happened **after** both reports were written that change the picture slightly:
- The Guideline 7 compliance pass (research/images now opt-in, Reddit removed) — already shipped, both reports were checked against a tree that already includes it.
- Two more research agents landed just now (standalone SaaS writers, real-user complaints). Their findings are folded in below, and one of them **contradicts a claim in `docs/naming-recommendation-2026-08-23.md`** — see §4.

---

## 1. Fix first — the quality gate is currently decorative

This is the audit's Tier 1, and it's first for a reason that isn't in either doc but follows directly from both: the competitive-research report's entire pitch (*"holds anything below your threshold for review instead of publishing it"*) is the one sentence the code doesn't currently deliver on. C1 means the threshold the user sets is never read. C3+C4 mean every post starts roughly 10 weight-points down from two checks that cannot pass under default settings, before the threshold (which is stuck at the schema default of 60 because of C1) ever gets compared against anything meaningful. Shipping any market-facing feature before this is fixed means marketing a promise the code silently breaks.

1. **C1** — save `quality_threshold` and `refresh_after_days` in `handle_save()`.
2. **C2** — make `render_toc()` read `$blueprint['toc']`, or drop the setting and always/never render — right now it's a UI element with no effect.
3. **C3** — pick one: either let the model emit a defined "citation" field the renderer turns into a real link (like `sources` already does), or stop scoring external links against a format the prompts forbid. Asking the model to do the second while enforcing the first is the bug — not the individual pieces.
4. **C4** — move the internal-link weave before `stage_verify`, or re-run the scorecard's internal-link check after weaving at publish time. Either fixes it; leaving it means the check is permanent theater.
5. **C5** — give the composer's zero-sliders a real "explicitly none" value (audit suggests a sentinel; a checkbox next to the slider works too).
6. **M5** — two help links point at `#bc-card-automation` for content that lives at `#bc-card-pictures`. One-line fix, doing it here since you're already in this file.

Everything past this point is genuinely optional until these five land — no roadmap item below is worth shipping on top of a quality gate that doesn't gate.

---

## 2. Reliability — before turning autopilot on for anyone

These don't break a stated promise the way §1 does, but they're the ones a background cron will eventually hit for real, unattended, with nobody watching:

7. **H1** — idempotent publish: store `post_id` in the payload once inserted, skip re-insert on reclaim.
8. **H2** — the 600s reclaim window is shorter than a single slow provider call can legitimately take (§H2 traces ~240s of HTTP retry alone, before research/verify overhead) — widen it or make it per-stage.
9. **H3** — `Autopilot::unschedule()` has no deactivation caller; the hourly cron survives deactivation as a no-op forever.
10. **H4/H5** — autopilot's daily counter rolls at UTC midnight while the posting window uses site time, and `0` means "unlimited" in one place and "1" in another. Both are the kind of thing that produces either silence or a burst of 24 posts, and a user has no way to predict which from the settings screen.
11. **H6** — record the heartbeat on any successful queue drain, not only the WP-Cron callback, so real-cron/CLI setups (which the plugin's own docs recommend) don't show a permanent false "stale" warning.
12. **H7** — no runtime DB-version check; a one-click update never re-runs migration.
13. **H15** — uninstall leaves blueprint options behind, contradicting its own "removes every trace" claim.

The new complaints research (§4) is worth reading alongside this section: the two most-documented real-world failure patterns in the whole competitive set — Journalist AI/Arvow "flooded my blog with hundreds of duplicate posts," and multiple silent-cron-failure reports across the WordPress ecosystem generally — are exactly H1 and H6/H3. This isn't a theoretical bug list; it's the specific shape of the complaints that sink competitors' reviews.

---

## 3. Market-facing gaps — worth building, roughly in order of leverage

Merged from the audit's "Missing for best free" section and the competitive research's Tier A/B, deduplicated, and re-ordered by (data already available ÷ effort):

14. **Fallback SEO head output** (`<meta name="description">`, OG/Twitter, canonical) when no SEO plugin is active. Both reports independently call this the single highest-value gap — right now, on a bare theme, the carefully generated meta description does nothing at all.
15. **PAA-driven FAQs.** Confirmed above: SerpApi's response already contains `people_also_ask` in the raw JSON; Blogcraft's `search_serpapi()` reads only `organic_results`. This is not a new API call or a new integration — it's reading a field that's already in the response and wiring it into the FAQ prompt instead of asking the model to invent questions.
16. **Front-end author box + `sameAs`.** E-E-A-T is currently JSON-LD only — real, but invisible to a human reader and to anything that doesn't parse structured data.
17. **Competitor heading-diff into the outline prompt.** Research already fetches competitor excerpts; extracting their H2/H3s and handing the gap to the outline stage is incremental on infrastructure that exists, not a new capability.
18. **Citation-worthiness surfacing.** The answer-first check already exists and is already measured — this is exposing it as a named, visible metric rather than building new machinery.
19. **IndexNow ping on publish.** Free, instant Bing indexing, one HTTP call. Both reports flag it; no reason to defer.
20. **Refresh-stage rebuild onto the scorecard path (H9).** Refresh currently runs on the superseded legacy scorer with no critique/revise loop — a rewrite of an existing post is judged by different rules than a new one, and it's the plugin's weakest stage by both reports' independent assessment.
21. **Per-post archetype picker**, plus the shapes both reports and the new SaaS research converge on as the most-requested absence: product-review/roundup (Koala's most-loved feature per the SaaS research), case study, buyer's guide.

Items 14–19 are all small, self-contained, and use data or measurements the plugin already has. 20–21 are real work. Nothing past this list (topical-cluster planning, GSC feedback loop, site-wide internal-link manager, CSV bulk with per-row templates, AI-visibility/citation tracking) is worth scoping until §1 and §2 are done — they're the kind of feature that gets measured by a quality gate that currently can't be trusted to measure anything.

---

## 4. What to explicitly not build, and one correction

**Don't build an AI-detector gate or a "humanizer" as a scored/marketed feature.** This was already the competitive-research report's recommendation on the grounds that Google doesn't rank on detection scores. The new complaints research adds a sharper reason: **BrandWell's corporate predecessor (Workado LLC, f/k/a Content at Scale AI) was hit with a finalized FTC consent order in 2025** for advertising its AI-content detector as "98% accurate" when FTC testing found real-world accuracy as low as 53.2% on non-academic content. Independent 2026 benchmarks put false-positive rates at 4–12% across detector vendors generally. A detection gate isn't just strategically weak here — it's the exact feature that produced a federal enforcement action against a direct competitor. Blogcraft's existing anti-AI-tell measurement (banned phrases, sentence-variety enforcement) is the defensible version of this; leave it there.

**Don't lead marketing copy with "autoblogging."** New finding, not in either original report: the term carries roughly twenty years of "splog" (spam-blog) baggage — Wikipedia dates the coinage to 2005, and a live 2026 BlackHatWorld thread on AI autoblogging shows even black-hat practitioners warning each other off it ("Google will strike your ranking with a big update"). The audience most likely to read a WordPress.org listing closely — SEO-literate bloggers — is exactly the audience for whom that word pre-loads distrust.

**Correction needed in `docs/naming-recommendation-2026-08-23.md`:** it cites "AI Power → AI Puffer" as an example of a rename causing an install collapse. The new complaints research checked this directly and found the opposite — AI Puffer currently shows 4.6★/165 reviews and ~10,000 active installs, actively maintained, no evidence of collapse. That specific example should come out. Two better-verified cautionary examples are now available to replace it: **SiteGround's AI Agent plugin**, auto-installed pre-activated across ~850,000–1,000,000 customer sites without opt-in, which fell to 1.1★ (35 of 36 reviews at 1-star) within days — the sharpest confirmation yet that Blogcraft's own "ask once, take no for an answer" consent pattern from this session is the right instinct, not just a compliance checkbox — and **Mediavine's termination of a publisher's ad account** for "overuse of artificially created content," which is the concrete version of the ad-network risk worth naming if Blogcraft ever markets itself toward monetized blogs.

**Two guardrails worth writing down**, surfaced by the complaints research and not stated as design principles in either original doc:
- Never auto-enable a new capability without per-feature consent — extend the Guideline-7 pattern already shipped this session to every future feature, not just data sources.
- If a paid tier is ever added, no auto-renewal or trial-auto-enrollment without a clear, revisitable toggle — the single most-repeated complaint category across the entire competitive set (Jasper, Rank Math Content AI, Arvow all named specifically) is billing surprise, not quality.

---

## Appendix — sources for this synthesis

- `docs/blogcraft-audit-2026-08-23.md` — full internal read-only audit, checked against `6a1edcd`.
- `docs/competitive-research-2026-08-23.md` — 40+ product research (WordPress plugins, SaaS tools, autoblogging platforms) + Google guidance/AI-Overview studies.
- Two follow-up agent runs (2026-08-23, this session, not yet written to disk as separate files): standalone SaaS AI blog-writers (Jasper, Copy.ai, Writesonic, Junia, SEObot, Autoblogging.ai, RightBlogger, Penfriend, BrandWell, Koala, Article Forge, WordAI/Spin Rewriter) and real-world user complaints (WordPress.org reviews, Trustpilot, BlackHatWorld, Search Engine Journal, Wikipedia). Key new facts: the BrandWell/FTC consent order, the SiteGround AI Agent backlash, the Mediavine termination, and the correction to the AI Puffer install claim.
- Direct code verification against the working tree, this session: `class-blogcraft-connection.php`, `class-blogcraft-seo.php`, `class-blogcraft-pipeline.php`, `class-blogcraft-blueprint.php`, `class-blogcraft-scorecard.php`, `class-blogcraft-blocks.php`, `class-blogcraft-research.php`, `class-blogcraft-metrics.php`.
