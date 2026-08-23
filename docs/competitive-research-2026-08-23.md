# BlogCraft — Competitive Market Research Report

**Date:** 2026-08-23
**Scope:** 40+ products researched across three segments — WordPress plugins, standalone AI-writing SaaS tools, and autoblogging/auto-publish platforms — plus Google's current (2025–2026) guidance, AI-Overviews citation studies, structured-data status, and the AI-detection landscape.
**Purpose:** Determine where BlogCraft already beats the market, what it's missing, what to add, and what to remove, to make it the best free AI blog-writing plugin.

---

## Executive Summary

**The headline finding: BlogCraft's core model is genuinely differentiated, and nobody in the market bundles what we already have.** Across every product researched — from $9/mo KoalaWriter to $249/mo BrandWell — not one bundles a staged research → outline → section-by-section draft → critique → revise → verify pipeline with 25 measured quality checks. Competitors compete on scale (bulk credits) or on editing surfaces (live SEO scores), not on generation quality. A free, BYO-key plugin with a built-in quality gate is a real position.

**But the research also exposed hard truths:**

1. **The market's two table-stakes perceptions are things we lack**: a live-scored optimization editor (Surfer's Content Score is the industry's north star) and SERP-mined NLP/entity term lists. Our keyword-density bands have no external search-data grounding.
2. **One of our advertised features is built on retired technology**: Google removed FAQ rich results entirely (gone from SERPs as of May 2026 per multiple reports; limited to gov/health since Aug 2023). Our FAQ schema should be reframed around semantic/AI-understanding value, and our marketing should lead with Article/Breadcrumb schema and author entities instead.
3. **The AI-Overviews era changed what "Google will like" means**: ~48–50% of US queries show an AI Overview, cited pages earn ~120% more organic clicks, and 55% of citations come from the first 30% of the cited page. Answer-first content (which we already enforce) and original data (our evidence field) are exactly what earns citations — but we don't measure or surface either as a "citation-worthiness" capability.
4. **The nearest strategic threat is ZimmWriter** ($24.97/mo, BYO key, 1,000-post bulk, WordPress scheduled auto-publish) — differentiated only by being Windows desktop software rather than a native plugin. Everything ZimmWriter does on the desktop, a free plugin could do natively.
5. **The free-plugin competition is weaker than feared on quality but stronger on breadth**: AI Engine (100k+ installs) wins on chatbots/forms/MCP; GetGenie (80k+) wins on keyword research and GSC feedback loops; the whole category is drowning in credit-anxiety complaints that our BYO-key model neutralizes completely.

---

## Part 1 — Where BlogCraft Already Beats the Market

These are verified against competitor docs — lead with them in positioning:

| Capability | BlogCraft (free) | Market equivalent |
|---|---|---|
| **Staged quality pipeline** (research → outline → draft → critique → revise → verify, 25 measured checks, holds low scorers for review) | Included free | Nobody has it. Writesonic charges $79–99/mo for a multi-expert review loop; theStacc charges $99/mo for "articles go live with editorial review" |
| **BYO key, no credits, no markup** | 15+ text providers incl. Ollama/LM Studio local | ZimmWriter ($24.97/mo) is the only BYO-key competitor, and it's Windows-only. Every SaaS charges $9–$2,000/mo; WP competitors meter words/requests (Jetpack: 20 free requests total) |
| **Voice cloning + measured enforcement** | Learn from 25 existing posts; emulate any URL's structure; every rule both prompted and measured | BrandWell charges $249/mo for 11-factor voice scoring; Jasper $59/seat for Brand Voice |
| **Honest E-E-A-T evidence field** | Author-supplied first-hand facts, verified to appear in the draft | Unique. Originality is exactly what AI engines prefer to cite (Ziptie study: verifiable data wins citations) |
| **Answer-first enforcement** | First two sentences must answer, ≤60 words (measured) | CXL: 55% of AIO citations come from the first 30% of the page; Contently: answer within first 100 words — we already enforce this pattern |
| **Anti-AI-tell writing** | Banned phrase list + throat-clearing detection + sentence-variety measurement | Surfer sells a "Humanizer"; ZimmWriter has "Nuke AI Words" — we build it into the prompts for free |
| **Internal linking** | Real WP_Query targets, in-text anchors, reverse back-linking | Most tools only do this on paid tiers (Koala: Pro+; Surfer: Pro tier) |
| **Cost transparency** | Token cost caps, per-month spend display | Credit systems hide this everywhere else |
| **Local models** | Ollama / LM Studio (zero ongoing cost) | Only AI Engine matches this among plugins; no SaaS does |

**The pricing gap we should say out loud** (entry monthly prices, Aug 2026): KoalaWriter $9 · SEOWriting.ai ~$12–19 · ZimmWriter $24.97 · AgilityWriter $28 · Frase $39 · Surfer $49 · RightBlogger $59 · Scalenut $59 · KWHero $59 · Jasper $59/seat · Writesonic $79–99 · Byword $99 · BrandWell $249. A serious blogger's stack costs $49–99/mo forever; the raw LLM cost of the same article with a BYO key is cents to a dollar (ZimmWriter publicly markets ~1M words of mini-model output ≈ $1). **Our pitch: "the workflows they charge $49–$249/month for, free — you pay only your API usage, typically 1–5% of their subscription price."**

---

## Part 2 — The Competitive Landscape

### 2.1 WordPress plugins (direct competitors)

| Plugin | Installs / rating | Model | What they do better | What they do worse |
|---|---|---|---|---|
| **AI Engine** (Meow Apps) | 100,000+ / 4.9 | BYO key, free core, $79 Pro | Chatbots, AI Forms, editor Copilot, MCP server/client, vector DBs, iOS app | No article pipeline, no SEO scoring; SEO deferred to separate plugin |
| **GetGenie** | 80,000+ / 4.8 | Credit-metered SaaS (2,500 free words/mo) | Keyword research (volume/CPC/Trends), SERP + competitor analysis, content scoring, GSC-connected SEO Insights, AEO/AI-Overview tools, 40+ templates | No BYO key, formulaic "in a world..." output, credit anxiety, no scheduling/bulk |
| **AI Power / "AI Puffer"** | 10,000+ (falling) / 4.6 | BYO key free tier | Content writer (single/bulk/RSS/CSV/Sheets), scheduled Automation Engine, vector DB training, role-based usage limits, sellable credit packages, WooCommerce tools | Rebrand confusion, knowledge-base management painful, vendor domains dead |
| **Jetpack AI** | 3M+ (Jetpack) / 3.8 | 20 free requests, then paid | Editor-block integration, tone/translate/expand toolbar, title optimization, readability fixes | Tiny free tier, bundling backlash (582 one-stars), no BYO key |
| **Elementor AI ("Angie")** | huge base / n/a | Credit-metered, AI excluded from Editor Pro | Agentic page building, design systems from screenshots, code generation | Opaque 1–40 credits/action, AI not in the paid tiers users already own |
| **10Web AI Assistant** | .org plugin closed Sept 2024 | Credits from $10/mo | In-editor assistant, Yoast readability fixes | Plugin permanently closed; product moved into hosted builder |
| **Bertha AI** | plugin deprecated | 500 free words/mo | Bulk alt-text, 25 languages, Chrome extension | Plugin abandoned; Chrome-extension-only now |
| **AI Content Writer** (BeautifulPlugins) | 100+ / 5.0 | BYO key, free pipeline | **Closest analog**: campaigns (keyword/RSS/trending/autopilot that studies your posts), review queue, SEO-score gate holding low scorers, Yoast/RankMath/AIOSEO/SEOPress filling, FAQ schema, related-posts linking | Tiny user base; Pro gates citations/drip/webhooks |
| **BotWriter** | 3,000+ / 4.4 | Hybrid BYO key + cloud routing | 7 text + 9 image providers, autoblog cron, RSS/news rewriting, Super Tasks bulk, WebP/1200px image optimization, OG/Twitter output | Free tier neutered to 10 posts/mo; paying API costs + subscription enrages users |
| **AutoWP** | 1,000+ / 3.8 | SaaS subscription | RSS/Google News rewriting, content planner with per-section HTML, spam detection | "Content is poor", posts later dropped from Google, scheduler under-delivers |
| **Soro SEO Autopilot** | 10,000+ / 5.0 | SaaS client | Autopilot from site/keyword analysis, IndexNow instant Bing indexing, meta into all SEO plugins | Thin client for paid SaaS; no BYO key |
| **AI Bud** | ~3,000 / 4.5 | BYO key | Bulk content editor, playground with temperature controls, WooCommerce metaboxes | Macro-limited generic output |
| **ZipAI / ZipWP** | 800+ / beta | BYOK or plan | Conversational site-building agent, MCP | Beta, FSE-only, not a content tool |
| **ContentBot** | 400+ / 4.7 | Credit SaaS | 30 short-form templates | Trialware objections, drifts off-topic |

### 2.2 Standalone SaaS tools (workflow benchmarks)

| Tool | Entry price | Signature workflow | Most instructive for us |
|---|---|---|---|
| **Surfer** | $49/mo | Content Editor: 500+ signals from top-10 competitors + AI-cited pages; real-time Content Score (target 68+); NLP term chips with live counts; Auto-Optimize; Humanizer | The live-scored editor is the category's most-praised feature |
| **KoalaWriter** | $9/mo | One-click SERP-based outlines; one-click Amazon affiliate reviews with live product data; site-indexed internal linking (Pro+) | Affiliate-review mode is a proven, sought-after workflow |
| **AgilityWriter** | $28/mo | 1-Click/Advanced/Optimize/Review modes; 60–150 model calls per article; Smart Outline from SERP competitors; bulk to 200; G-Smart audits drafts against Helpful Content + Rater Guidelines with before/after diffs; GSC Action Center; 12 social posts/article | The most complete pipeline after ours; their G-Smart audit = our critique stage, productized |
| **Scalenut** | $59 ($24 sale) | Cruise Mode 5-min drafts; GEO score; AI detector + humanizer (50k words/mo); Link Manager | Bundles detection + humanizing as standard |
| **Frase** | $39/mo | Listen (visitor questions widget) → Research → Write → Optimize → Publish (1-click WP) → **Content Guard** (monitors rank decay, writes the fix, republishes on approval) | Content Guard = the refresh feature done right; our refresh is our weakest stage |
| **SEOWriting.ai** | ~$12–19/mo + free tier | 1-click + bulk to 100 tasks/run from Excel; real-time SERP keeps keyword frequency under 2%; WordPress auto-post with scheduling; Media Hub (AI images + YouTube embeds) | Cheapest bulk+WP-autopost in the market |
| **Byword** | $99/mo | Programmatic SEO: templates + datasets, CSV at scale, Pages workflow | pSEO mode |
| **BrandWell** | $249/mo | RankWell: 11-factor voice scoring, 12-factor pre-publish SEO grading, "Weekly 5" (pages closest to page one + projected gains), drip publishing to avoid sandbox | Pre-publish SEO grading = our scorecard, productized; drip publishing protects against scaled-abuse patterns |
| **KWHero** | $59/mo | Research → Plan (clusters) → Create (entity-led) → Interlink (crawled suggestions) → Monitor (Topical Authority, LLM Presence across 62 countries) | The full topical-authority loop incl. AI-visibility tracking |
| **RightBlogger** | $59/mo | Unlimited words; 90+ tools; autoblogging queue (30 posts, 5/day); **Site Agent** auto-maintains published posts (internal-link fixes, broken-link cleanup); backlink exchange network | Flat pricing + site-maintenance agent |
| **ZimmWriter** | $24.97/mo | **BYO key**; 1,000-post bulk; 1,000-URL bulk rewrite; 50-product roundups; 625 local-SEO pages; Style Mimic; Nuke AI Words; Link Packs (up to 1,000 links); scheduled auto-publish to 100 WP installs | Our closest strategic rival — but Windows desktop-only |
| **Writesonic** | $79–99/mo | 100+ step agentic pipeline; multi-expert review; **information-gain scoring blocks publishing if originality is too low**; Humanizer gate; E-E-A-T schema; sitemap-aware internal linking | Their information-gain gate is the best "scaled abuse" defense we've seen |
| **Jasper** | $59/seat | Brand-voice campaigns, agents, knowledge assets | Not SERP-focused; brand consistency at team scale |
| **Outranking** | ~$69/mo | SERP briefs, optimize button, audits | Learning curve; weaker than Surfer |
| **Copy.ai / Hypotenuse** | $29 / custom | — | Both pivoted away (GTM automation / ecommerce PXM) — the blog-writing niche is being vacated by generalists |

### 2.3 Autoblogging / auto-publish platforms

| Tool | Price | Notes |
|---|---|---|
| **Autoblogging.ai** | $12–649/mo | "Godlike Mode" (SERP + entities + knowledge graph), Amazon mode, news mode, 500-article CSV bulk, WP plugin with scheduled auto-post + auto internal linking; **AI Visibility Score** (citations, sentiment, share-of-voice in AI Overviews) — the new differentiator |
| **Arvow** (was Journalist AI) | $39–249/mo | AutoBlog: set niche + schedule; writes/schedules/publishes to WP + Shopify; auto social posts |
| **BlogSEO.ai** | $15–39/mo | SEO + AEO + GEO in 31 languages; WP/Shopify |
| **CyberSEO Pro / WP Content Pilot** | — | RSS/XML/CSV/YouTube ingestion pipelines |
| **AI-agent wave** | — | n8n/Gumloop/Make DIY auto-blogs; theStacc sells human-in-the-loop review as the premium feature |

**Key takeaway from Area 1:** nobody bundles a quality pipeline; they compete on scale and channel breadth. The market is moving toward (a) AI-Overview citation tracking and (b) editorial-review gates — we already have (b), and (a) is a green field for a free plugin.

---

## Part 3 — Feature Gap Analysis: What BlogCraft Is Missing

Ranked by how widely the feature appears across competitors and how strongly users praise it.

### Tier A — Table stakes we lack (the market perceives these as core)

1. **Live content editor with real-time SEO score.** Surfer's Content Score, Scalenut's grade, Frase's SEO/GEO score — the single most-praised feature in the niche. We verify at the end with no score-that-updates-as-you-edit surface. *Plugin-native version:* a "Blogcraft score" panel in the block-editor sidebar, re-running our existing scorecard on debounced content changes.
2. **NLP/entity term chips mined from the SERP, with per-term usage counts.** We have keyword-density bands over one phrase; the market mines the top-10 results for entity lists. Our SerpApi integration already fetches organic results — extracting terms is a small step (we already auto-derive terms from fetched sources; users just can't see or steer the list).
3. **Competitor outline diffing / heading-gap analysis.** Show exactly which H2/H3s the top-10 cover that our outline doesn't (Surfer Outline Builder, AgilityWriter Smart Outline, Writesonic SERP-gap). We fetch competitor pages in research but never extract their headings into the outline prompt.
4. **One-click "Auto-Optimize" to raise the score.** We have critique → revise once; the market has score-targeted re-optimization loops with before/after diffs (AgilityWriter G-Smart). Multiple revise passes with re-measurement would close this.
5. **AI-detection readout + humanizer option.** Half the market sells this (Surfer Humanizer, Scalenut, Writesonic gate, SEOWriting toggle, ZimmWriter Nuke AI Words). *Strategic note:* the evidence says Google does **not** rank on detection (Ahrefs ~600K-page study; 200 top-ranking articles flagged as AI across 5 detectors) — so build it as an optional readout, not a gate, and never market "undetectable."

### Tier B — Proven workflows we don't cover

6. **Programmatic CSV/Excel bulk mode with per-row variables and reusable templates** (SEOWriting 100-task import, Byword templates+datasets, ZimmWriter 1,000 posts, BrandWell pSEO). Our bulk is a topic textarea.
7. **Affiliate product-review generation with live product data + auto-inserted links + roundup tables** (Koala's most-loved feature; AgilityWriter Review modes; ZimmWriter 50-product roundups). Our archetype system is a natural home for "product review" and "roundup" shapes.
8. **Site-wide internal-linking manager** — crawl the archive, suggest/insert links across old posts, fix broken links (Surfer 1-click, Koala indexing, KWHero Interlink, RightBlogger Site Agent). We link new→old and old→new per post, but there's no archive-level manager.
9. **Content audit with rank-decay detection and auto-fix republishing** (Frase Content Guard — "monitors live pages, writes the fix, republishes on approval"). Our refresh stage is our weakest: legacy scorer, truncated input, no repair loop.
10. **External ingestion: RSS → article, URL → article, YouTube → article, Google News/trending** (AgilityWriter YouTube mode, ZimmWriter 1,000-URL rewrite, AutoWP, AI Puffer, AI Content Writer campaigns). Users explicitly ask for URL-based factual generation.
11. **Inline AI images per heading + YouTube embeds** (Koala, SEOWriting Media Hub, Writesonic). We generate featured + section images — good — but no video embeds.
12. **Topical-map / cluster planning tied to the writer** (Surfer Topical Map, KWHero Plan, BrandWell Weekly 5). We have no strategy layer above individual posts.
13. **Plagiarism/originality pre-publish check** (Surfer, Scalenut/Copyscape, Outranking). Our borrowed-sentences check is a cheaper local version — surface it as a feature.
14. **GSC-connected feedback loop** (GetGenie SEO Insights: impressions/clicks/CTR per generated post; AgilityWriter GSC Action Center). Close the write → rank → learn loop with the data every site already has.
15. **AI-visibility / citation tracking** (Autoblogging.ai's AI Visibility Score; KWHero LLM Presence; Frase AI Visibility). Genuinely new territory; even a lightweight "check if your post appears in AI Overviews for its keyphrase" would be a first among free plugins.

### Tier C — Adjacent capabilities competitors bundle

16. In-editor Copilot / inline rewrite (AI Engine spacebar Copilot, Jetpack toolbar) — meet the user inside the block editor, not only on our own screens.
17. Front-end chatbot / AI Forms (AI Engine, AI Puffer) — out of scope for a writing plugin; **skip**.
18. MCP server (AI Engine free, ZIP AI) — let Claude/Cursor drive Blogcraft; fast-growing 2026 pattern, cheap to add later.
19. WooCommerce product copy (AI Puffer, GetGenie, BotWriter) — possible extension, not core.
20. Role-based usage quotas (AI Puffer's Usage & Billing) — matters only for multi-author sites.
21. Bulk alt-text across the media library; image optimization pipelines (BotWriter's WebP/1200px/~120KB Google-Discover targeting is instructive).
22. Social auto-share + repurposing (AgilityWriter's 12 social posts/article; RightBlogger) — publish webhooks would cover it generically.
23. IndexNow pinging on publish (Soro) — free, instant Bing indexing, trivial to add.

### Tier D — 2026-era ranking features (from the AI-Overviews research)

24. **Citation-worthiness scorer**: direct self-contained answer in the first 100 words (we enforce this — surface it as a metric), key statistics in the top 30% of the page, and a "quotable passage" per section (1–2 sentence extractable statements AI engines can quote verbatim).
25. **Original-data insertion engine**: extend the evidence field into structured stat blocks ("According to [site]'s 2026 analysis of N…") — original attributable research wins disproportionate citations because AI engines prefer verifiable data.
26. **Topical-cluster autopilot**: replace single-topic scheduling with pillar/spoke planning — ranking across a cluster beats a head term for citation odds.
27. **Author-entity hygiene pack**: author schema + sameAs + credentialed bio blocks on every post (our internal audit already flagged the missing front-end author box).

---

## Part 4 — What to REMOVE or Deprioritize

From the combination of market research and the internal audit (`docs/blogcraft-audit-2026-08-23.md`):

1. **Don't build a humanizer as a core feature** — Google ranks on quality, not detection scores; market "measured human-style writing" (which we have) instead of "undetectable AI" (a race to the bottom that Surfer/StealthGPT own and that Google's scaled-content policy makes risky).
2. **Don't chase chatbots/AI Forms/WooCommerce** — that's AI Engine's and AI Puffer's game; our differentiation is the writing pipeline. Breadth would dilute it.
3. **Reframe FAQ schema** — rich results are retired (gone from SERPs as of May 2026); keep FAQs for on-page/semantic value and AI-answer alignment, stop counting "FAQ schema" as an SEO win in the readme. Lead with Article/Breadcrumb + author entity signals instead.
4. **Simplify the three overlapping voice layers** (internal audit O2) — competitors have ONE voice concept; ours confuses.
5. **Drop or wire the dead code** (internal audit section 4): multi-provider fallback, deferred status, release(), etc. Every unwired feature is maintenance cost with no user value.
6. **Don't add more providers before fixing the five critical bugs** — 15 text + 7 image providers already beats every competitor; AI Engine is the only one comparable.

---

## Part 5 — Strategic Positioning

**The one-line pitch the research supports:**
> "The staged, self-critiquing writing pipeline that Surfer, AgilityWriter and Writesonic charge $49–$249/month for — free, in WordPress, with your own API key. It researches before writing, measures 25 quality checks after, and holds anything below your threshold for review instead of publishing it."

**The three defensible moats:**
1. **Quality pipeline** — nobody bundles it at any price; we ship it free.
2. **BYO-key economics** — 1–5% of competitors' subscription cost; works with free/local models (Ollama), which no SaaS offers at all.
3. **Honest E-E-A-T** — the evidence field + answer-first enforcement + anti-AI-tell measurement is exactly aligned with what earns AI-Overview citations in 2026, and none of it depends on retired rich-result types.

**The category's pain points we neutralize** (from competitor complaint analysis): credit/quota anxiety ("fake free" tiers), paying twice (API costs + subscription), generic formulaic output, schedulers that under-deliver, vendor lock-in without BYO key, feature regression/abandonment (Bertha, 10Web, BotWriter's neutered free tier).

---

## Part 6 — Prioritized Roadmap (combined view)

### Fix first (from internal audit — the foundation)
1. The 5 critical wiring bugs (dead threshold settings, TOC toggle, unpassable link checks, zero overrides) — then the duplicate-post guard and runtime migration. *These undermine every marketing claim above.*

### Fast wins (small effort, high perception value)
2. **SERP term chips + competitor heading diff** in the outline stage — the data is already fetched; surface it and feed headings into the outline prompt.
3. **PAA harvesting for FAQs** — SerpApi returns People-Also-Ask; use real questions instead of invented ones.
4. **Fallback head meta** (`<meta name="description">`, OG/Twitter) when no SEO plugin — makes our meta generation visible on bare themes.
5. **IndexNow ping on publish** — free instant Bing indexing.
6. **Citation-worthiness metrics** — surface existing answer-first compliance + add first-30%-statistics check as named, visible checks.
7. **Author box + sameAs** (front-end E-E-A-T).

### Medium builds (the perception gap-closers)
8. **Block-editor score panel** — re-run the existing scorecard live in a sidebar.
9. **Multi-pass auto-optimize** — revise → re-measure → revise until score or attempt budget reached.
10. **Programmatic CSV bulk** with per-row variables.
11. **URL/RSS/YouTube → article ingestion.**
12. **Refresh-stage rebuild** (scorecard-based, Content Guard-style rank-decay detection via GSC).
13. **Per-post archetype picker** + affiliate review/roundup archetypes.

### Later / optional
14. Site-wide internal-link manager; topical-cluster planner; GSC feedback dashboard; AI-visibility check; publish webhooks; MCP server.

---

## Appendix — Sources

**WordPress plugins:** wordpress.org plugin pages and review tabs (ai-engine, gpt3-ai-content-generator, getgenie, ai-assistant-by-10web, jetpack, ai-content-writer, zip-ai, aibuddy-openai-chatgpt, content-bot, soro-seo, autowp-ai-content-writer-rewriter, botwriter); meowapps.com/ai-engine; getgenie.ai/pricing; 10web.io/ai-assistant + /pricing; jetpack.com/ai; elementor.com/products/ai + /pricing; bertha.ai; zipwp.com.

**SaaS tools:** surferseo.com (pricing, content-editor); jasper.ai (pricing, platform); koala.sh/pricing + support.koala.sh; agilitywriter.ai + /help/bulk-advanced-mode; scalenut.com (pricing, cruise-mode); frase.io (pricing, product pages); outranking.io + originality.ai review; docs.seowriting.ai/article/bulk-generation; byword.ai + originality.ai review; brandwell.ai (+ pricing/terms via ddiy.co, f6s.com); kwhero.com; rightblogger.com/pricing; zimmwriter.com; writesonic.com (pricing, ai-article-writer); hypotenuse.ai; copy.ai.

**Autoblogging:** autoblogging.ai (pricing, knowledge-base); arvow.com/pricing; blogseo.ai (+ skywork.ai review); cyberseo.net; wpcontentpilot.com; blog2social.com.

**Google guidance & studies:** developers.google.com/search/docs/essentials/spam-policies; /blog/2023/02/google-search-and-ai-content; /blog/2024/03/core-update-spam-policies; /blog/2024/11/site-reputation-abuse; /blog/2023/08/howto-faq-changes; /docs/fundamentals/creating-helpful-content; /docs/appearance/structured-data/search-gallery + speakable; blog.google March 2024 core update; ahrefs.com/blog/ai-overview-citations-top-10; surferseo.com/blog/ai-citation-report; cxl.com/blog/google-ai-overview-citation-sources; contedly.com (AI-citation how-to); ziptie.dev (original research & citations); wellows.com + digitalapplied.com (citation ranking studies); semrush.com/blog/google-ai-mode; semrush.com/blog/eeat; searchenginejournal.com (E-E-A-T first-hand experience; structured-data retirements); omnibound.ai + quickseo.ai (AIO statistics roundups); gsqi.com (March 2024 analysis).

**Detection:** gptzero.me/news benchmark; originality.ai (studies round-up, tool reviews); copyleaks.com blog; businessinsider.com AI-detector guide; pangram.com comparison; ampifire.com comparison; c2pa.org; contentauthenticity.org; CDC AI disclosure guidance; authorsguild.org.

*Caveat: prices and feature sets captured August 2026; several vendors run aggressive sales or show JS-heavy pricing pages (Outranking conflicted between sources) — re-verify before publishing any public comparison page.*
