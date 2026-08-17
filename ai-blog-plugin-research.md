# AI Auto-Blogging WordPress Plugin — Research & Build Plan

## 1. WordPress.org Plugin Directory Rules — Compliance Check

Source: WordPress.org Detailed Plugin Guidelines (developer.wordpress.org) and the official wporg-plugin-guidelines repo.

| Rule | What it means for us | Our plan |
|---|---|---|
| **No locked/paywalled functionality inside one plugin.** "Plugins may not contain functionality that is restricted or locked, only to be made available by payment or upgrade. Functionality may not be disabled after a trial period or quota is met." | Competitors' "free vs Pro" plugins technically only get away with this by shipping Pro as a **separate add-on plugin**, not locked code in the same file. | We don't need this workaround at all — our plugin is 100% free, so this rule is automatically satisfied and is actually a selling point over competitors. |
| **No embedding external links/credits on the public-facing site without explicit user permission.** ("Plugins may not embed external links or credits on the public site without explicitly asking the user's permission.") | This directly rules out the "silent backlink in every blog post/footer" idea. | Any link back to us must be (a) inside the plugin's own wp-admin settings screen, opt-in, and clearly labeled, never auto-inserted into published posts or the theme footer. |
| **SaaS is allowed, trialware is not.** A plugin that calls an external API you run (with usage limits) is fine; a plugin that stops working after N free uses is not. | Because every AI/image call goes to a *third-party* free API (Groq, Gemini free tier, Pollinations) chosen by the *user* with their *own* key, this isn't "our SaaS" at all — cleanest possible model. | Confirmed compliant. |
| **Code must be human-readable — no obfuscation/minified-only code, no mangled variable names.** | Standard PHP, readable, commented. | Straightforward to follow. |
| **Full disclosure of external services in the readme.** Every plugin that calls out to a third-party API must document: which service, why, what data is sent, and link their privacy policy/ToS. | This is exactly what AI Blog Automator's readme does (see the "External Services Used" section on their page) — it's the required format. | We replicate this pattern for every provider we support (Groq, Gemini, Pollinations, custom endpoints). |
| **No tracking without consent.** | No silent analytics, no phone-home telemetry. | Our plugin sends data only to the API endpoint the user configured — nothing to us. |
| **Must use WordPress' own libraries** (no bundling of unnecessary external PHP libraries when a WP core function exists). | Use `wp_remote_post()` for HTTP calls, `wp_insert_post()`/`wp_insert_attachment()` for publishing, WP-Cron for scheduling — not custom cURL wrappers or third-party schedulers. | Sets us up to pass automated code review faster. |
| **GPLv2-or-later compatible license required.** | Plugin must be released under GPL. | Standard, no conflict — being fully free/open makes this trivial. |
| **A complete, working plugin must exist at time of submission** (no placeholder/incomplete submissions). | Build and test fully before submitting to the review queue. | Noted for the roadmap below. |
| **Plugin name can't contain "WordPress" or "WP" in a way that implies official status.** | Pick a distinct brand name. | To decide later. |
| **Review queue reality:** manual human review, can take from a few days to several weeks depending on backlog. | Set expectations — this isn't instant. | Plan for a review-and-wait period after submission. |

**Bottom line: everything in our plan (free provider choice, free tone customization, free images, research step, revise pass) is fully compliant — none of it conflicts with any rule, because we're not locking anything behind payment and not injecting hidden links.**

---

## 2. What Google Actually Rewards (2026) — and What That Means for Plugin Features

Google's public position (Search Central, most recently restated through 2026) is **origin-agnostic**: content isn't penalized for being AI-written. It's evaluated on the same **E-E-A-T** framework as human content — Experience, Expertise, Authoritativeness, Trustworthiness — with **trust** weighted most heavily. What gets penalized is "**scaled content abuse**": mass-producing thin, templated, unreviewed pages purely to manipulate rankings.

Practical implications for plugin features:

- **One post a day, reviewed, is safe. Hundreds of unreviewed posts a day is what gets sites penalized.** Our plugin should default to sensible volume and make draft-review easy, not encourage spam-scale output.
- **"Information gain" matters** — Google explicitly downgrades pages that just summarize what's already out there with nothing new. This is why a **research-before-writing step** (pulling current, real source material) is a ranking feature, not just a "nice to have."
- **Google explicitly recommends disclosing AI/automation use to readers** when it would reasonably be expected. This should be a plugin setting: an optional "This post was AI-assisted, reviewed by [name]" byline block.
- **On YMYL topics (health, money, legal, safety), a named, credentialed human reviewer is close to non-negotiable for trust.** If the user's niche touches these, the plugin should nudge toward draft-mode, not auto-publish.
- **Quality raters are now specifically trained to spot low-effort AI content** (per the 2024 Quality Rater Guidelines update) — generic, unedited AI output can get the lowest quality rating even though AI itself isn't penalized. This reinforces why the "revise pass" and fact-check flag matter for output quality, not just user experience.

---

## 3. Additional Features Found in Research (Beyond What We Already Planned)

On top of the free-provider-choice, tone-brief, research step, and revise pass already planned, here's what dedicated SEO/AI-content research surfaces as high-value and currently missing from every competitor's **free** tier:

1. **Schema/structured data markup** — auto-generating Article/BlogPosting JSON-LD schema on every post (author, datePublished, headline) helps search engines and AI answer engines (Google AI Overviews, ChatGPT, Perplexity) parse and cite the content correctly. None of the competitor free tiers mention this.
2. **"Information gain" check** — as part of the research step, explicitly instruct the AI to identify what existing top-ranking content on the topic is missing, and write to fill that gap rather than restate it.
3. **Readable-for-AI-search structuring** — clear H2/H3 hierarchy, a direct-answer paragraph near the top, FAQ block at the end — this format is increasingly what gets *cited* inside AI Overviews/ChatGPT answers, not just ranked traditionally. (BlogWolf's Pro tier does some of this; make it free and default.)
4. **Internal linking using real post data**, not guessed — pull actual titles/URLs from the user's existing WP posts via `WP_Query`, don't hallucinate link targets.
5. **Optional AI-disclosure byline** — aligns with Google's stated guidance and builds reader trust, costs nothing to implement.
6. **A content calendar / topic queue view** — so the user can see and edit what's coming before it's written, rather than being surprised by whatever the AI picked.
7. **Duplicate/near-duplicate detection** before publish — compare the new draft's topic fingerprint against recent posts to avoid repetitive content, which directly maps to the "scaled/thin content" risk Google penalizes.
8. **Image alt-text + descriptive filenames generated automatically** — small SEO detail every competitor claims but worth confirming it's genuinely automatic, not manual.
9. **A "pause on low-confidence" setting** — if the AI's research step turns up little reliable source material on a topic, skip or flag for review rather than publishing a thin/generic post anyway.

---

## 4. Full Feature List for the Plugin (Free, All Included)

**Setup / Onboarding**
- Guided setup wizard: niche, audience, tone, style rules, banned topics/words, writing sample for style-matching
- Provider connector: any AI API (Groq, Gemini free tier, OpenAI, Claude, or custom OpenAI-compatible endpoint) — user supplies their own key
- Image connector: Pollinations (no key) or custom image API

**Writing Pipeline**
- Research step: pull a few current, relevant source snippets before writing (free RSS/news feeds)
- Draft → self-critique → revise pass (two-step generation, not one-shot)
- Information-gain instruction: write to fill gaps in existing coverage, not restate it
- Duplicate/near-duplicate topic check against recent posts
- Internal linking pulled from real existing posts
- Auto FAQ block + direct-answer opening paragraph (AI-search-friendly structure)
- Auto JSON-LD Article schema
- Auto alt text + descriptive image filenames
- Optional AI-disclosure byline

**Publishing & Control**
- Draft-or-auto-publish toggle (with a strong nudge toward draft-review for sensitive/YMYL niches)
- Flexible scheduling (daily, custom intervals) via WP-Cron
- Category/tag intelligence (match existing or create)
- Content calendar/topic queue view, editable before generation
- Error logging + stats dashboard

**Compliance**
- Full external-services disclosure in readme.txt per provider
- No hidden links/credits injected into published content
- GPLv2-or-later, human-readable code, no locked features

---

## 5. Suggested Next Step

Scaffold the plugin: main plugin file, onboarding screen, provider connector abstraction, WP-Cron job, and the research→draft→revise pipeline — then test locally before submitting to the WordPress.org review queue.
