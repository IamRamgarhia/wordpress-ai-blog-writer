# Blogcraft: From Autopilot to Content Agent — The Campaign System Plan

**Date:** 2026-08-23
**Goal:** Let a user say *"I want 30 SEO-friendly posts over the next 30 days"* and get: an auto-built content calendar → full editorial control over it → automatic drafting on a known timeline with time & cost estimates → scheduled publishing on their terms → notifications when posts go live → distribution to any other website or platform (including Laravel sites, via webhooks, REST API, and MCP) — all inside Google's 2026 rules, which the plugin keeps up to date on the user's behalf.

**What exists today (from the code audit):** an hourly autopilot with a plain topic-textarea, weekday/hour windows and a per-day cap; a Calendar screen (drag/reorder/remove topics); `publish_at` future-scheduling; a DB-backed stage queue with cost caps; blueprints/archetypes/voice. This plan upgrades that foundation into a campaign engine — and it depends on fixing five known bugs first (listed at the end).

---

## Part 0 — Terminology (the words you asked about)

| Term | What it means | Why it matters here |
|---|---|---|
| **Agentic workflow** | AI that *plans → executes → checks its own work → corrects* across many steps, instead of one-shot generation | Blogcraft's pipeline (research→outline→draft→critique→revise→verify) is already agentic per-post; we extend it to plan whole campaigns |
| **Topical map / topic cluster** | A pillar page + 20-40 supporting posts that together cover one topic exhaustively | Google's strongest relevance signal; the skeleton of every 30-day calendar |
| **Human-in-the-loop (HITL)** | The agent does the work; a human approves at defined checkpoints | Competitors charge for this (theStacc, $99/mo); Google's scaled-content policy effectively rewards it |
| **Drip publishing** | Spreading publication over time instead of bulk-dumping 30 posts at once | Avoids "scaled content abuse" patterns (BrandWell ships this as anti-sandbox protection) |
| **MCP (Model Context Protocol)** | An open standard (created by Anthropic) that lets AI assistants — Claude, Cursor, ChatGPT agents — discover and call *tools* on your server over HTTP | Your "MCB" idea. Lets any external system (including a Laravel app) drive Blogcraft through an AI assistant, or directly |
| **JSON-RPC / Streamable HTTP** | The message format and transport MCP uses | The technical shape of our MCP endpoint |
| **Webhook** | Your site POSTs a JSON message to a URL the user chooses when an event happens | How a Laravel site learns "a post was just published" without polling |
| **HMAC signature** | A cryptographic signature on each webhook (shared secret) | Lets the Laravel side verify the call genuinely came from the user's blog |
| **Canonical URL** | The `<link rel="canonical">` tag marking the "original" copy of content | Mandatory when distributing the same post to multiple sites — avoids duplicate-content penalties |
| **Rule pack** | A versioned data file holding all Google-sensitive rules (banned phrases, scoring weights, schema choices) | How the plugin updates its SEO rules when Google changes, without a plugin release |
| **P50/P90 estimate** | Median and slow-case time estimates | How we honestly say "your 30 posts will be ready by X" |
| **Syndication** | Republishing the same content on other channels | The multi-channel publishing feature, done canonically |

---

## Part 1 — The Campaign Planner (30 days, fully automatic, fully controllable)

### 1.1 The setup wizard (5 questions, ~3 minutes)

A new screen: **Blogcraft → Campaigns → "Plan a campaign"**. It asks only what the generator cannot infer:

1. **Goal** — traffic / topical authority / affiliate income / leads (changes the archetype mix).
2. **Seed topic + audience** — "sourdough baking for beginners" (or: pick an existing Blueprint).
3. **Mix preferences** — sliders: educational vs. commercial, long vs. short, how many listicles/guides/comparisons; "let Blogcraft decide" default.
4. **Volume & rhythm** — 30 posts / 30 days (any numbers); publish days + time windows; draft-vs-publish default; review strictness: *auto-publish*, *hold everything for review* (default), or *approve outlines only*.
5. **Budget guard** — max tokens/month and per-post cap (plugs into existing Cost system).

Evidence packs: the wizard asks for 3–5 first-hand facts/anecdotes (the existing "evidence" field, multiplied) and distributes them across posts — E-E-A-T spread through the whole calendar instead of one post.

### 1.2 How the calendar is generated (the planning agent)

Three staged jobs (same queue machinery as posts — planning is itself a pipeline):

1. **Map job** — research providers (SerpApi/Tavily already integrated) + model build a **topical map**: one pillar + clusters. Every planned post gets: cluster assignment, target keyphrase, search intent, and its sibling/pillar links. Internal linking is decided *at plan time*, which structurally fixes the audit's "internal links woven after verify" defect.
2. **Calendar job** — the 30 topics get sequenced: pillar early, cluster posts grouped with spacing, difficulty ramps, publish slots filled per the user's rhythm. Each item carries: title, brief, keyphrase, intent, archetype, evidence-pack assignment, draft-by date, publish-at datetime, estimated tokens.
3. **Estimate job** — from the plugin's **own historical stage timings** (the Logger already records durations): P50/P90 completion dates and total cost estimate. The user sees: *"30 posts drafted by Sep 12–15 · ~1.8M tokens · ≈$14 with your provider · publishing Mon/Wed/Fri 9:00."*

### 1.3 The editable calendar (full control — your explicit requirement)

Upgraded Calendar screen:
- **Drag any post to any day**; edit title/topic/angle/notes per item; **lock** items you love (regeneration never touches locked items).
- **Regenerate one day** / **regenerate the plan** (keeps locks) / **fill gaps** when you delete items.
- **Pause / resume / stop** the whole campaign; change rhythm mid-flight (remaining items reschedule).
- **CSV import/export** of the calendar (programmatic control; agency reuse).
- Status column per item: `planned → queued → drafting (with live stage: research/outline/…) → ready → scheduled → published / held for review / failed`, each linking to the post or the Needs-Review screen.
- **Start button** — nothing generates until the user approves the plan. This is the human-in-the-loop gate that keeps 30-post automation inside Google's scaled-content policy, and it's the thing competitors charge $99/month for.

### 1.4 The drafting engine (automatic, paced, self-correcting)

- A new campaign scheduler (replacing the plain topic-textarea autopilot) enqueues each item **N days before its publish slot** (default 2-day lead, configurable) so drafts are ready for review before their date.
- **Daily volume caps** default conservatively (≤2–3/day) — drip publishing.
- **Cross-post variety guard** (new check, from the Google research): consecutive posts must differ in opener pattern, angle, and structure; a similarity score runs at plan time and verify time — directly countering "scaled content abuse" detection patterns.
- **Multi-pass repair**: verify → revise → re-measure (up to 2 loops) instead of today's single revise pass.
- Everything flows through the existing queue/worker/cost-cap machinery; campaign jobs respect the monthly token ceiling and *pause the campaign with a notification* rather than silently stopping (fixes today's silent-cap behavior).

### 1.5 Publishing control

- Per-item `publish_at` (exists) + campaign-level windows (extend existing weekday/hour settings).
- Quality gate already forces low scorers to "held for review" — **after fixing bug C1** so the user's threshold actually saves.
- On publish: canonical URL, meta (Yoast/RankMath or the fallback head output from the roadmap), schema per the current rule pack.

---

## Part 2 — Notifications ("post published" → email, WhatsApp, Slack, and more)

### 2.1 Events (not just publish)

`campaign_planned` · `post_drafted` (ready for review) · `post_published` · `post_held_for_review` (with the failed checks) · `generation_failed` · `cost_threshold_reached` · `weekly_digest` (posts drafted/published, spend, top scores).

### 2.2 Channels, in build order

| Channel | Build | Notes |
|---|---|---|
| **Email** | v1 | HTML template: post link, score, check summary. Works with any SMTP plugin. |
| **Slack** | v1 | Incoming-webhook URL — 2-minute setup, free, zero API approval. |
| **Discord** | v1 | Same webhook mechanics as Slack. |
| **Telegram** | v1.5 | Bot token + chat ID; free, no approval process, huge in blogging/SEO communities. Also becomes an *input* channel (see 4.7). |
| **Generic webhook** | v1 | Signed JSON POST (HMAC-SHA256 header, shared secret, retry with backoff). This is what any developer/Laravel site consumes. |
| **WhatsApp** | v2 | Native WhatsApp Business Cloud API requires Meta business verification and per-conversation fees — **not worth it natively**. Instead: official Zapier/Make recipes (user connects their WhatsApp there, our webhook triggers it). Document this honestly. |
| **Push (admin)** | v2 | In-dashboard notification center (the Activity screen grows a bell). |

Digest mode: one daily/weekly email instead of 30 pings (configurable per channel).

---

## Part 3 — Multi-site & multi-platform distribution (your Laravel/MCP scenario)

Three layers, from simplest to most powerful. All opt-in, all behind the existing `manage_blogcraft` capability or per-site API keys.

### 3.1 Outbound: signed webhooks + canonical distribution

On publish, Blogcraft POSTs the complete article package — HTML + block markup + title/excerpt/meta/images (as URLs) + schema — with an HMAC signature. Any Laravel/Node/Python app consumes it in ~20 lines. We ship a **ready-made Laravel receipt example** (controller + signature-verification middleware) in the docs. The payload includes the canonical URL so the receiving site marks the WordPress original — no duplicate-content risk.

### 3.2 Programmatic: REST API

`/wp-json/blogcraft/v1/*` with API-key auth:
- `POST /campaigns` (create a 30-day campaign from JSON), `GET /campaigns/{id}`, `POST /campaigns/{id}/items/{slot}` (edit a day), `POST /topics` (queue a single post), `GET /posts/{id}` (retrieve finished content), `GET /status` (queue/worker/health).
Use case: the user's Laravel site is the business hub → it submits topics programmatically → Blogcraft writes → webhook returns the finished post. Blogcraft becomes a **content service** their other platforms call.

### 3.3 Agentic: MCP server (the "MCB")

Blogcraft exposes its own tools over the Model Context Protocol (Streamable HTTP endpoint at `/wp-json/blogcraft/v1/mcp`, bearer-token auth). Any MCP client — **Claude Desktop, Cursor, ChatGPT agents, or a script on the Laravel server** — can then:

- `blogcraft_plan_campaign(seed, days, goal)` → returns the proposed calendar
- `blogcraft_list_calendar()` / `blogcraft_move_item(slot, new_date)` / `blogcraft_edit_item(...)` / `blogcraft_regenerate_item(slot)`
- `blogcraft_queue_topic(topic, options)`
- `blogcraft_get_post(id)` / `blogcraft_regenerate_section(post, heading)`
- `blogcraft_get_status()` / `blogcraft_update_blueprint(fields)`

Every tool call enforces the same capability checks as the admin UI — MCP is a control surface, not a bypass. Precedent: AI Engine (100k+ installs) ships a free MCP server/client, and MCP support is the fastest-growing integration pattern of 2026. This makes Blogcraft the first *AI writing* plugin an AI assistant can operate end-to-end: *"Hey Claude, plan October for my baking blog, move the starter guide to the 3rd, and hold everything for my review."*

### 3.4 Inbound connectors (later)

- **Telegram capture**: forward a link/voice note to your blog's bot → it becomes a campaign topic (voice transcribed by the user's text provider).
- **RSS/URL ingestion**: competitor post or industry article → gap-analyzed → added to the calendar (from the competitive-research gap list).
- **Composer SDK**: a small `blogcast/client` Composer package (PHP) wrapping the REST API + webhook verification, so Laravel devs install and connect in minutes.

---

## Part 4 — Google-Rules Currency (the "we update when Google changes" promise)

The problem: today every Google-sensitive rule is hardcoded in PHP classes. The FAQ-rich-results retirement of May 2026 would require a plugin release.

### The Rule Pack system

1. **Extract** all mutable rules into a versioned `data/rules.json` (the plugin already uses this data-file pattern for `providers.json`): banned-phrase lists, scorecard weights, structure rules, schema-emphasis flags, meta-length rules, citation-worthiness checks.
2. **Remote update channel**: a weekly cron pulls a signed rules file (ed25519 signature verified; falls back to the bundled copy on any failure). Pulled updates show a changelog in admin — *"Rules 2026.09 applied: de-emphasized FAQ schema, added answer-first weighting"* — with per-site override and an opt-out. No user data is ever sent (pull-only, matching the studio's privacy stance).
3. **Studio commitment**: monitor Google Search Central announcements and ship a rule-pack update within days of material changes (the research documented exactly what to watch: spam-policy edits, structured-data retirements, AI-Overviews guidance).
4. **Versioned tests**: each rule-pack version runs against a fixture corpus (PHPUnit suite already exists) so an update can never silently break scoring.

This turns "we follow Google's rules" from a marketing line into a mechanism.

### The standing compliance layer (baked into campaigns, per the 2026 research)

- **Scaled-content abuse**: drip pacing, default daily caps, mandatory plan approval, cross-post variety guard, "unique value" check (each item must state its differentiator — the evidence packs power this).
- **E-E-A-T**: author-entity pack (schema + sameAs + front-end author box from the roadmap) applied to every campaign post.
- **AI-Overviews era**: answer-first intros (already enforced), key-stats-in-first-30% check, quotable-passage generator.
- **Never**: undetectability claims, mass-generated doorway pages, expired-domain tricks — the plan explicitly encodes what Google's spam policies prohibit.

---

## Part 5 — Additional scenarios (you asked for more; here's the set)

1. **Seasonal campaigns** — seed with an event (Diwali, Black Friday, exam season); calendar auto-skips dead days and front-loads prep posts.
2. **Competitor-gap campaigns** — paste 3 competitor URLs/domains → their covered topics are mapped → the calendar fills only the gaps.
3. **Refresh campaigns** — instead of new posts, schedule rewrites of stale content (GSC-powered decay detection once the GSC feedback loop lands; rebuilds on the fixed Refresh pipeline).
4. **Multi-language campaigns** — one plan, N language variants (Blueprint `language` field exists), interlinked with `hreflang` (new).
5. **Agency mode** — one Dashboard-quality site drives client blogs via MCP/REST (pairs with the Open Reports plugin idea; keep out of v1 scope).
6. **Budget guardian** — pre-flight cost estimate per campaign, hard monthly ceiling, mid-campaign "you're 80% through budget" alert, auto-pause at 100%.
7. **Outline-approval mode** — strictest HITL: each post's outline waits for a one-click approve before drafting (some users want editorial control at every level).
8. **Section-level regeneration in the block editor** — the control layer for finished drafts ("make this section longer", "add a table here") from the earlier roadmap.
9. **Public ideas inbox** — a (privately shareable) form where a client/team submits topic ideas that land as unslotted calendar items the planner can pull from.
10. **AI-visibility check** — post-publish: does this post appear in AI Overviews for its keyphrase? (green-field feature from the research; phase 3.)

---

## Part 6 — Architecture, data model, and phasing

### Data model (new tables)

- `wp_blogcraft_campaigns` — id, name, goal, seed, config (JSON: mix/rhythm/budget/review mode), status, rule_pack_version, timestamps.
- `wp_blogcraft_campaign_items` — campaign_id, slot date, sequence, topic/title/brief/keyphrase/intent/archetype, pillar_id/sibling ids (cluster), evidence_pack_id, status, job_id, post_id, publish_at, locked, edit notes.
- Settings additions: notification channels (per-event matrix), API keys for the REST/MCP surface, rule-pack remote URL + current version.
- Everything else (queue, jobs, costs, activity) is reused as-is.

### Phase plan

**Phase 0 — foundation fixes (required before campaigns multiply volume):**
C1 threshold/refresh settings not saving · H1 non-idempotent publish (duplicate posts) · H4 autopilot UTC-day bug · H5 `per_day=0` meaning unlimited · H6 heartbeat/CLI mismatch · H12 concurrency lock. At 1–2 posts/day these are annoyances; at 30-post campaigns they are guarantee-breaking.

**Phase 1 — Campaign core (the product):**
Wizard → topical-map planner → editable calendar with locks/regeneration → estimates (time P50/P90 + cost) → lead-time drafting → per-item scheduling → variety guard → email/Slack/Discord/webhook notifications with digests → start/approval gate.

**Phase 2 — developer & rules layer:**
REST API + HMAC webhooks + Laravel receipt example → **MCP server** with the tool set above → rules.json extraction + signed remote rule-pack updates with admin changelog → Telegram channel + weekly digest.

**Phase 3 — growth layer:**
GSC feedback loop (real performance informs future calendars) → refresh & competitor-gap campaigns → multi-language + hreflang → WhatsApp via integrations → Telegram capture/RSS ingestion → AI-visibility check → Composer SDK → social distribution (or as its own plugin, given API-policy risk).

### Build-size reality check

Phase 1 ≈ a Blogcraft-scale effort (the pipeline was ~24k lines; campaigns are mostly orchestration over existing machinery, so smaller — the planner, calendar UI, and notifications are the new surface). Phase 2's MCP server is modest (AI Engine proves a single plugin can host one). The rules-pack system is small but must be done carefully (signing, testing).

---

## Part 7 — What success looks like

> A user opens Blogcraft, types "beginner sourdough baking, 30 posts, Mon/Wed/Fri at 9am, hold everything for my review, max $20", clicks **Plan**, reviews a clustered 30-day calendar with real titles and a completion estimate, drags two posts to different days, locks one, clicks **Start** — and then just answers emails: *"Draft ready for review"* (Slack), *"Published — here's the link + score"* (email), while their Laravel site receives every published post via webhook automatically, and Claude can reschedule any day on request. When Google retires a rich-result type in November, a signed rule pack quietly updates every scoring decision, with a one-line changelog in the dashboard.

That is a workflow Surfer ($49/mo), SEOWriting.ai ($12–19/mo), and RightBlogger ($59/mo) each only partially deliver — free, with the user holding every lever, and with the quality pipeline none of them have.
