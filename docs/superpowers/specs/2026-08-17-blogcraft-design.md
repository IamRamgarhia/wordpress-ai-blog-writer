# Blogcraft — Design Specification

**Date:** 2026-08-17
**Status:** Draft for review
**Author site:** https://dicecodes.com

---

## 1. Identity

| Field | Value |
|---|---|
| Brand | **Blogcraft** |
| WordPress.org slug (permanent) | `blogcraft` |
| Directory title (post-approval) | Blogcraft – AI Blog Writer, Auto Content Generator & SEO Autoblogging |
| Submission Plugin Name header | `Blogcraft` (short, so the slug locks clean) |
| Tags (max 5, per Guideline 12) | `ai content generator`, `ai writer`, `autoblogging`, `content generator`, `seo content` |
| Text domain | `blogcraft` |
| PHP prefixes | `Blogcraft_` (classes), `blogcraft_` (functions, hooks, options), `BLOGWRIGHT_` (constants) |
| DB table prefix | `{$wpdb->prefix}blogcraft_` |
| Author URI | `https://dicecodes.com` |
| Plugin URI | `https://dicecodes.com/blogcraft` |
| Public source repo | GitHub (required by Guideline 4) |
| License | GPLv2 or later |

**Naming rationale.** "Craft" is direct counter-positioning against the "autoblogger = spam" perception that dominates this category — the market's loudest complaint is templated, generic output, so a name asserting craft says the opposite of what competitors' names say. "Blog" earns keyword credit in the directory's highest-weighted search field. The word is unambiguous to spell, say, and recall.

**Verified 2026-08-17:**
- WordPress.org slug `blogcraft` — **0 plugins, free** (the permanent, unchangeable asset)
- No AI or blog-writing product named Blogcraft exists — absent from every 2026 tool roundup
- `blogcraft.com` registered 2003 but sits on Afternic parking nameservers: for sale, **not an active product**
- No trademark, WordPress/WP, or AI-vendor conflict

**Rejected names and why:**
- **Blogwright** — `blogwright.com` is an **active direct competitor**: an AI blog platform running a plan→research→draft→refine workflow with bring-your-own API keys. Nearly our exact product. Critically, Google search did *not* surface it; only a direct domain fetch did. **Lesson applied: verify the domain directly, never rely on search alone.**
- **Postwright** — `postwright.app` is a live content drafting and scheduling product
- **ScribeFlow, PostForge, QuillCraft, Inkwright, Draftsmith** — all existing products or companies
- **"AI Blogcraft"** — redundant (AI already appears in the title tail), dates fast the way "Cloud-" and "Smart-" did, and yields a worse slug
- **"Blogcraft Pro"** — signals paywalled functionality and invites Guideline 5 scrutiny, burns the only upgrade word we have, and free plugins named "Pro" read as nulled software

**Domain strategy.** The `.com` is the least important asset and is not required for brand ranking. Three properties carry the brand term: the `wordpress.org/plugins/blogcraft/` page (high domain authority), `dicecodes.com/blogcraft`, and the public GitHub repo. Launching on `dicecodes.com/blogcraft`; `blogcraft.io` optional later with no code impact beyond the Plugin URI header.

---

## 2. Product Positioning

> The plugin that makes your existing site rank, not just adds posts to it. It reads your Search Console, finds what you nearly rank for, writes in your own analyzed voice, links it into your existing content in both directions, and refreshes your decaying posts — free, with any API key.

**Market reality (researched 2026-08-17).** The strongest free competitor is AIPower, which already ships multi-provider BYO-key, a DB-backed job queue with cron health monitoring, bulk generation, and image providers. It is a broad Swiss-army-knife (chatbots, forms, WooCommerce, voice agents), not a focused content-quality engine. No free plugin does SERP-driven writing, topical maps, backward internal linking, GSC opportunity mining, or content refresh. That is the gap Blogcraft occupies.

**Market's top complaints (our feature spec, written by users):**
1. "Same template with the keyword swapped" — generic, identical output post to post
2. Content depth below publish-ready
3. Thin-content penalties from bulk publishing
4. Auto-publish with no review path
5. No scheduling granularity ("every Tuesday at 9am")

**Compliance constraint on marketing (Guideline 9):** We may not promise traffic or ranking increases anywhere in the readme, plugin UI, or directory listing. All copy describes capability, never outcome.

---

## 3. Architecture — 8 Layers

Every feature plugs into these. New capability = new stage class or new adapter, never a refactor.

### L1. Provider Layer
Abstracts every AI, image, and research service behind stable interfaces.

- **Universal OpenAI-compatible adapter** — base URL + key + model. Covers Groq, OpenRouter, DeepSeek, Together, Mistral, Cerebras, xAI, Azure OpenAI, OpenAI, and local Ollama / LM Studio / vLLM, including providers that do not exist yet.
- **Native adapters** — Google Gemini, Anthropic.
- **Custom JSON-path mapper** — user maps request/response paths by hand. The true "any API" escape hatch.
- Model auto-discovery (`/v1/models` where available), capability probing (JSON mode, context window, vision, tool use, streaming), per-stage model routing, ordered fallback chains, 429/`Retry-After` backoff, token accounting and cost estimation.

### L2. Context Builder
Assembles everything the model needs to write *this user's* post. This layer is why output stops sounding generic.

Inputs: brand profile, voice fingerprint, author persona, style rules, glossary, experience bank, site knowledge, research findings, structural spec, per-topic overrides. Output: a deterministic, inspectable prompt bundle stored with the job for full traceability.

### L3. Pipeline Engine
Generation is an ordered list of **pluggable stages**. Each stage is independent, resumable, retryable, individually toggleable and reorderable by the user, and declares its input/output contract.

### L4. Job Queue
Custom DB table. **One stage per cron tick** so a 3-minute pipeline survives a 30-second PHP `max_execution_time`. Retries with exponential backoff, concurrency caps, time budgeting, dead-letter handling, and a full per-job execution trace.

### L5. Knowledge Layer
Indexes the site: post titles, URLs, excerpts, categories, tags, publish dates, and content fingerprints. Powers internal linking in both directions, duplicate/cannibalization detection, and voice analysis. Refreshed incrementally on `save_post`.

### L6. Quality Gates
Verification runs **between** stages, not at the end. A post failing any gate routes to human review instead of publishing.

### L7. Output Formatter
Emits native Gutenberg block markup (`<!-- wp:heading -->` etc.) so posts are fully editable, not a grey Classic blob. Fallback renderers for Classic, Elementor, Divi, Bricks.

### L8. Admin & API
Dashboard, setup wizard, calendar, queue UI, prompt editor, REST API, WP-CLI, webhooks. All assets bundled locally — **no CDN** (Guideline 8).

---

## 4. Complete Feature Inventory

### 4.1 Connections
- Universal OpenAI-compatible provider (base URL, key, model, custom headers)
- Native Google Gemini adapter
- Native Anthropic adapter
- Custom JSON-path mapper for arbitrary APIs
- Local models: Ollama, LM Studio, vLLM (no key, unlimited, private)
- Model auto-discovery and manual model entry
- Capability probe with graceful degradation
- Per-stage model routing (cheap model for research/outline, strong model for draft/polish)
- Ordered provider fallback chains
- Connection test with live token/cost readout
- Image providers: Pollinations (no key), Pexels, Pixabay, Unsplash, OpenAI Images, Gemini Imagen, Replicate, Stable Diffusion / local, custom endpoint
- Research providers: Tavily, SerpApi, SearXNG (self-hosted), Brave, Google PSE, DataForSEO, provider-native web search, RSS via SimplePie, user-supplied URLs
- Google Search Console (OAuth)
- Google Analytics 4 (optional)
- API key storage: encrypted at rest, `wp-config.php` constant support, masked on redisplay, never exposed to REST/JS

### 4.2 Voice & Identity — "writes how the user wants"
- Site profile: niche, sub-niches, geography, languages
- Audience personas (multiple, assignable per category)
- Business context: what you sell, USPs, offers, CTAs, competitors never to mention
- Author personas: name, bio, credentials, photo, social links, E-E-A-T signals; assignable per category
- **Voice fingerprint** — auto-derived from the user's existing posts: sentence length distribution, paragraph length, vocabulary level, contraction rate, jargon density, POV, formality, humour
- Manual controls: tone (multi-select + freeform), POV (1st/2nd/3rd/we), formality, reading level (Flesch target), humour, emotional register
- Writing sample input (paste, or select existing posts)
- Style rules engine: banned words (ships with a curated AI-slop list — "delve", "tapestry", "in today's fast-paced world"), banned phrases, required phrases, em-dash policy, Oxford comma, sentence-length cap, paragraph-length cap, active-voice target, emoji policy, question-headings policy
- Banned topics and prohibited claims (compliance)
- Glossary and terminology enforcement ("customers" not "users")
- Brand spelling and capitalisation rules
- Content policy: YMYL mode with mandatory disclaimers and medical/legal/financial guardrails
- **Experience bank** — a store of the user's anecdotes, opinions, proprietary data, and case studies, one woven into each post to supply the "E" in E-E-A-T that pure AI content structurally lacks
- Per-category and per-topic overrides of every setting above
- **Full raw prompt editing on every pipeline stage**, with documented variable placeholders
- Custom system prompt and few-shot examples

### 4.3 Topics & Planning
- Manual topic entry; bulk CSV import; paste-a-list
- Keyword research integration (via configured research provider)
- **Topical map generator** — one seed → a pillar-and-cluster plan of N posts, queued and editable
- **GSC opportunity miner** — queries ranking 8–20, high-impression/low-CTR pages, queries with no matching page
- Competitor content-gap analysis
- Trending topics (Google Trends, RSS, Reddit, YouTube)
- Drag-and-drop content calendar
- Queue with priorities, statuses, and per-item custom instructions, angle, and target keyword
- Seasonal vs evergreen tagging
- Duplicate and cannibalisation detection against existing posts
- Search-intent classification (informational / commercial / transactional / navigational)
- 13 content-type templates: how-to, listicle, review, comparison/vs, roundup, news, case study, glossary, FAQ, pillar, opinion, interview, product

### 4.4 Research
- SERP scrape of top N results
- Entity and NLP semantic-term extraction
- Competitor heading/outline extraction
- Word-count target derived from SERP average
- **Information-gain analysis** — what the top results omit
- People Also Ask harvesting (feeds the FAQ block)
- Statistic and source harvesting with real URLs
- News and freshness feeds (SimplePie)
- Reddit / forum / Q&A real-user-voice mining
- YouTube transcript ingestion
- User-supplied source URLs, PDFs, documents
- RAG over the user's own existing posts
- Fact grounding: claims tied to sources
- **Prompt-injection defence** — all fetched content truncated, stripped to plain text, delimiter-wrapped, and explicitly marked as untrusted data

### 4.5 Generation
- Outline generation → **user-editable outline** (add/remove/reorder H2/H3, per-section instructions)
- Section-by-section generation for long-form (avoids context limits)
- Draft → self-critique → revise (multi-pass, toggleable — roughly doubles token cost)
- Editorial scoring pass against a configurable rubric
- Direct-answer opening paragraph
- Key-takeaways box
- FAQ block built from real PAA data
- Comparison tables (among the most-cited formats in AI answers)
- Pros/cons blocks, expert-quote callouts
- Cited statistics with source links
- Word-count control, global and per-section
- Keyword placement and density targeting (primary, secondary, NLP/LSI terms)
- Readability enforcement pass
- **AI-slop detection and rewrite pass** — banned-phrase sweep, sentence-variance correction
- Multilingual generation and translation of existing posts
- Regenerate a section or the whole post
- Streaming preview in the editor

### 4.6 Media
- Featured image generation
- In-article images (per H2)
- Provider fallback chain so images never hard-fail
- Stock photo search (Pexels / Pixabay / Unsplash)
- Auto alt text
- Descriptive filenames, title, caption, description
- Compression and WebP conversion
- Aspect-ratio presets
- Charts and infographics from extracted data
- YouTube search and embed
- Diagram generation
- Media-library deduplication

### 4.7 SEO
- SEO title, meta description, URL slug
- Focus keyword assignment
- **Writes directly into Yoast / Rank Math / AIOSEO / SEOPress meta fields** where installed
- Schema suite: Article, BlogPosting, FAQPage, HowTo, Product, Review, Breadcrumb, Person/Author — **with conflict detection**, deferring to an active SEO plugin by default
- **Forward internal linking** — new post → relevant existing posts, validated against real permalinks, hallucinated links stripped
- **Backward internal linking** — inserts links from relevant existing posts *to* the new post
- External authority links, HTTP-verified
- Anchor-text variation
- Orphan-page detection and repair
- Table of contents; excerpt generation
- Open Graph and Twitter card fields
- Hreflang for multilingual sites
- IndexNow ping and Google Indexing API submission
- Sitemap ping

### 4.8 GEO / AEO
- Extractability scoring (short 2–3 line paragraphs)
- Statistic and citation density targets
- Table-presence check
- Direct-answer optimisation for AI Overviews, ChatGPT, Perplexity, Copilot
- `llms.txt` generation — **optional and de-emphasised**; Google's May 2026 guidance explicitly calls it unnecessary, and the UI will say so

### 4.9 Quality Control
- Every external link HTTP-verified; 404s stripped or flagged
- Statistic and citation verification
- Hallucination heuristics
- Self-similarity / cannibalisation check against own site
- Readability gate; SEO gate
- Custom rubric scoring (0–100) with configurable pass threshold
- **Pause on low confidence** — thin research → skip or flag rather than publish
- Banned-word sweep
- YMYL compliance check (required disclaimers present)
- Human review queue: approve / reject / edit
- **Email, Slack, and Telegram notifications with one-click approve/reject links** — zero-touch in practice, human-reviewed on paper
- Reviewer assignment and editorial roles

### 4.10 Publishing
- Draft / pending review / scheduled / published
- Any post type including CPTs
- Taxonomy intelligence: match existing categories and tags, or create
- Real WP user assigned as author
- Publish-date control and backdating
- **Granular scheduling** — daily, weekly, specific days + times, cron expressions, timezone-aware, randomised jitter
- Volume caps per day/week with new-site ramp-up mode
- Native Gutenberg block output; Classic / Elementor / Divi / Bricks fallbacks
- Bulk generate and bulk publish
- **Bulk rollback** — unpublish or revert a batch in one click
- Multisite support
- **Content refresh** — rewrite and update existing posts in place, preserving revision history
- Optional AI-disclosure byline block (opt-in, default off, per Guideline 10)
- Newsletter and social auto-share hooks

### 4.11 Automation & Ops
- DB-backed job queue with retries and exponential backoff
- One stage per tick; survives PHP timeouts
- WP-Cron plus system-cron support with **health monitoring and a dismissible warning** when WP-Cron is not firing
- Action Scheduler compatibility when present
- Concurrency limits and time budgeting
- Per-provider rate-limit handling
- Token budget caps, cost estimation, spend dashboard
- Error log with rotation
- Job history and per-post generation trace: which prompt, which sources, which model, what cost
- Webhooks; REST API; WP-CLI commands
- Settings import/export as JSON

### 4.12 Analytics & Improvement
- GSC performance per generated post
- GA4 traffic per post
- **Content decay monitor** with automatic refresh triggers
- A/B title testing
- Model/prompt performance reporting — which settings produce the best-performing content

### 4.13 Admin UX
- Dashboard overview
- Onboarding wizard
- **Dry run** — generate one post and show it before committing to a schedule
- Gutenberg sidebar integration: rewrite / expand / shorten this block
- Settings search
- Role and capability management
- Full i18n; accessibility; dark mode

---

## 5. Compliance Layer (built in from day 1)

Derived from all 18 WordPress.org Detailed Plugin Guidelines and the Plugin Check requirements.

| Guideline | Implementation |
|---|---|
| **G1** GPL | GPLv2-or-later; every bundled asset GPL-compatible |
| **G2** Developer responsibility | Third-party API ToS reviewed and documented before ship |
| **G3** Stable version | Directory version always current with public repo |
| **G4** Human-readable | No obfuscation, no minified-only files; **public GitHub repo linked in readme**, build tooling documented |
| **G5** No trialware | Zero locked features, zero quotas, zero time limits. Nothing to unlock |
| **G6** SaaS permitted | Each provider documented with purpose, data sent, **ToS link and privacy policy link** |
| **G7** No tracking | No telemetry, no phone-home, no analytics. Data goes only to the user-configured endpoint |
| **G8** No third-party execution | **All admin CSS/JS bundled locally, no CDN**; no iframes in admin; no self-updater; no remote code |
| **G9** No dishonest actions | **No traffic or ranking promises** in readme, UI, or listing. No keyword stuffing |
| **G10** No unauthorized credits | No links injected into published content or theme. AI-disclosure byline opt-in, default off |
| **G11** No admin hijacking | All notices dismissible, contextual, confined to plugin pages. No dashboard ads |
| **G12** No readme spam | **Exactly 5 tags, no competitor names.** Readme written for humans |
| **G13** WP libraries | **SimplePie** for RSS, `wp_mail`/PHPMailer for email, bundled jQuery, `wp_remote_*` for HTTP, `wp_insert_post`/`media_sideload_image` for publishing |
| **G14** Commit discipline | SVN for releases only, descriptive messages |
| **G15** Version increments | Version bumped every release; trunk readme always current |
| **G16** Complete at submission | Fully working plugin submitted; no name reservation |
| **G17** Trademarks | `blogcraft` contains no protected term. No provider brand in name or slug |
| **G18** Directory rights | Acknowledged |

**Plugin Check — must pass the entire "Plugin repo" category:**
ABSPATH guards on every file · nonce verification on every state-changing action · capability checks (`manage_options` / custom caps) · full input sanitisation and output escaping · `$wpdb->prepare` on all queries · no forbidden functions (`eval`, `base64_decode`, `file_get_contents` on remote URLs, `error_log`, `var_dump`) · consistent text domain · correct i18n functions · complete plugin headers · valid readme with `Requires at least`, `Tested up to`, `Requires PHP`, `Stable tag` · consistent prefixing on every global symbol · no plugin updater · declared-minimum-version function compatibility.

**Additional security beyond the checks:**
`wp_kses_post` on all AI output before storage · prompt-injection defences on all fetched content · API keys encrypted at rest and never returned to the browser · `uninstall.php` with full data cleanup · cron cleared on deactivation · GDPR export/erase hooks.

---

## 6. Platform Compatibility

Decided from live WordPress.org usage data (`api.wordpress.org/stats/`, retrieved 2026-08-17), not assumption.

### Declared support

| Header | Value |
|---|---|
| `Requires PHP` | **7.4** |
| `Requires at least` | **6.0** |
| `Tested up to` | **7.0** — bumped every release; freshness and compatibility are directory ranking signals |
| Actively tested | PHP 7.4, 8.1, 8.2, 8.3, 8.4, **8.5** |
| Database | MySQL 5.6+ / MariaDB 10.1+ (recommend MySQL 8.0+ / MariaDB 10.11+) |

### Why these floors

**PHP 7.4** — real distribution: 8.2 (24.9%), 8.3 (24.7%), **7.4 (17.6%)**, 8.1 (11.8%), 8.4 (8.3%), 8.0 (4.2%), 8.5 (2.5%). A 7.4 floor reaches **93.9%** of sites; an 8.0 floor reaches only **76.4%**. Excluding the 7.4 bucket would forfeit ~18% of the potential install base, and install count drives directory ranking. WordPress core's own stated floor is 7.4, so anyone who can run WordPress can run Blogcraft.

**WordPress 6.0** — reaches **~92%** of installs (7.0 alone is 66.1%, 6.9 is 9.0%, 6.8 is 6.5%). Raising the floor to 6.5 recovers only 4.7 points and buys no API we need.

### Coding standard: write for 7.4, run clean on 8.5

Forgo (8.0+ only): enums, `match`, constructor promotion, `readonly`, union types, first-class callables. Typed properties, arrow functions, null-coalescing assignment, and spread are available in 7.4 and used freely.

Deprecation discipline — these produce notices on modern PHP and must be avoided from the first file:

- **Declare every class property.** Dynamic properties deprecated in 8.2.
- **Write `?Type` explicitly.** Implicit nullable parameters deprecated in 8.4.
- **No `${var}` string interpolation.** Deprecated in 8.2.
- **Never pass `null` to non-nullable internal function parameters.** Deprecated in 8.1; a common source of noise in legacy plugin code.

### Database portability

Plain SQL only — no CTEs, window functions, or `JSON_TABLE` (MySQL 8.0+ only). Indexed `varchar` columns capped at **191 characters** for the utf8mb4 767-byte InnoDB key limit on older MySQL. Always use `$wpdb->get_charset_collate()` and `$wpdb->prepare()`.

### CI

The test suite runs the full PHP matrix (7.4 → 8.5) on every commit, so a 7.4 syntax error or an 8.5 deprecation notice cannot reach a release.

---

## 7. Data Model

| Table | Purpose |
|---|---|
| `blogcraft_jobs` | Queue: status, current stage, attempts, payload, trace, cost, timestamps |
| `blogcraft_topics` | Topic queue and calendar: title, keyword, intent, type, per-item overrides, schedule |
| `blogcraft_knowledge` | Site index: post ID, title, URL, excerpt, fingerprint, embedding, taxonomy |
| `blogcraft_sources` | Research artefacts per job: URL, snippet, stat, verification status |
| `blogcraft_profiles` | Brand, voice, persona, and style-rule sets |
| `blogcraft_experience` | Experience bank entries |
| `blogcraft_log` | Errors and events, rotated |

Post meta records the generation trace, model used, cost, source list, and quality scores per generated post.

---

## 8. Build Phases

Each phase ends in something usable.

| Phase | Ships | Usable at end |
|---|---|---|
| **0. Foundations** | Skeleton, settings framework, security model, encrypted key storage, DB schema, job queue, cron health | Internal |
| **1. Provider Layer** | Universal + Gemini + Anthropic + custom mapper, discovery, capability probe, routing, fallbacks, cost meter | Connect and test any API |
| **2. Core Pipeline** | Outline → sections → critique → revise, Gutenberg output, draft publishing | **Generate a real post on demand** |
| **3. Voice & Control** | Full brand/voice/persona system, style rules, prompt editor, experience bank, per-topic overrides | **Writes how you want** |
| **4. Research & SEO** | SERP research, information gain, PAA, cited stats, schema, SEO-plugin integration, bidirectional internal linking | **Matches the paid tools' core loop** |
| **5. Media** | Image chain, alt text, filenames, WebP, tables, charts, YouTube | Media-rich posts |
| **6. Quality Gates** | Link/stat verification, rubric scoring, dedup, readability, review queue, approve-by-email | Safe to run unattended |
| **7. Automation** | Calendar, queue UI, granular scheduling, volume caps, bulk ops, rollback, WP-CLI, REST | **Fully autonomous** |
| **8. The Moat** | GSC integration, opportunity mining, content refresh, decay monitor, topical maps, analytics | **No competitor has this** |
| **9. Ship** | i18n, accessibility, readme, Plugin Check, PHPCS/WPCS, docs, submission | **Public release** |

---

## 9. Known Constraints (stated honestly)

- **Search/SERP data cannot be made reliably free.** Brave withdrew its free tier in late 2025; Google's Custom Search JSON API is discontinued 2027-01-01. Free options: Tavily (1,000 credits/mo), SerpApi (250/mo), self-hosted SearXNG. **Mitigation:** research works with zero setup using the user's own site, supplied URLs, and provider-native web search; it becomes substantially stronger with a search key. Nothing hard-fails without one.
- **LLM inference is genuinely free** at a post-a-day volume: Gemini (15 rpm / 1,500 per day), Groq (30 rpm), Cerebras, Mistral, OpenRouter free models — none requiring a credit card.
- **Provider capability varies.** JSON mode, context window, and vision differ. We probe and degrade gracefully; we do not promise identical output across providers.
- **Directory ranking takes time.** Active installs dominate WordPress.org's phase-two re-ranking. Day one we rank for long-tail terms; head terms follow install growth. Levers we control: keyword-tuned title, 5 tags, algorithm-aware readme, frequent releases for freshness, current "Tested up to", and **support-thread resolution rate** — tracked over a rolling 2 months as a ranking factor and ignored by most developers.
- **Scale.** ~200 features across 9 phases. This is larger than any single plugin currently in the directory. It ships in working milestones, not one release.

---

## 10. Open Items

- Launch page at `dicecodes.com/blogcraft`; optionally register `blogcraft.io`. `blogcraft.com` is listed for sale on Afternic — worth a price check, not worth blocking on
- Create the public GitHub repository (Guideline 4 dependency)
**Resolved 2026-08-17:**
- Platform compatibility floors — see Section 6.
- **Default research provider: zero-setup mode.** The wizard ships research working with no third-party key at all — the user's own site index, user-supplied source URLs, and provider-native web search where the configured LLM offers it. A dedicated search API key (Tavily, SerpApi, SearXNG, DataForSEO) is presented as an optional upgrade that materially improves information-gain analysis. Nothing hard-fails without one; every research stage degrades gracefully.
