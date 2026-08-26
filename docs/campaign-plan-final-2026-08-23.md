# Blogcraft as a Content Agent — The Final Plan

**Date:** 2026-08-23
**Supersedes:** `campaign-agent-plan-2026-08-23.md` (kept for its structure; several of its factual claims are corrected below)
**Method:** six research streams against primary sources, plus direct verification against the working tree at `0ec36cb`.

---

## Part 0 — Corrections to the earlier plan

The earlier campaign doc is a good skeleton. Five of its load-bearing claims are wrong, and one of them changes the architecture completely.

| Claim in the old doc | Reality | Consequence |
|---|---|---|
| "Phase 0 — required first: C1, H1, H4, H5, H6, H12" | **C1, H1, H4, H5, H6 are all fixed** (this session, v0.32.0–0.34.0). Only H12 remains | Phase 0 is essentially done |
| H12 (no concurrency lock) is a blocker | `Queue::claim()` is an **atomic conditional UPDATE + read-by-token**. Two workers cannot claim the same job. H12 is about *parallelism*, not double-work, and the cost cap bounds the spend | Downgrade to "should have". Not a blocker |
| "the Logger already records durations" | **Nothing records per-stage timing.** I grepped: no `microtime`, no elapsed calculation anywhere | The "ready by Sept 12" estimate has **no data source**. Must build instrumentation first |
| Cost estimates come from the existing Cost system | `Cost::record()` aggregates **monthly totals only** — no per-post, no per-job | "This campaign will cost ~$14" needs per-job cost tracking that doesn't exist |
| MCP is the way to reach a Laravel app | **WordPress core 6.9 shipped the Abilities API**, and core itself exposes `/wp-json/wp-abilities/v1/abilities/{name}/run`. A Laravel app can drive Blogcraft with **zero MCP code** | Build abilities first. MCP becomes a thin optional layer, not the foundation |

Also worth stating: **there is no REST API surface in the plugin at all today.** Everything is `admin-ajax`. That is a real dependency for everything in Part 4.

> ⚠️ One thing I could not verify: Docker was down, so I could not confirm `wp_register_ability()` exists in our test WordPress. The research verified it against core source (`@since 6.9.0`) and current core is 7.1. Our plugin's floor is WP 6.0, so this must be **feature-detected**, never assumed.

---

## Part 1 — The words you asked about

| Term | Plain meaning | Whether it's real |
|---|---|---|
| **Agentic workflow** | AI that plans → does → checks itself → corrects, over many steps | Real, and Blogcraft already is one *per post*. Campaigns extend it to a month |
| **Content calendar** | A dated plan of what gets written and when | Real |
| **Topical map / topic cluster** | One broad "pillar" post plus many supporting posts, interlinked | **The idea is real; the evidence is much weaker than the industry implies — see Part 2.1** |
| **Search intent** | Whether a searcher wants to learn, compare, buy, or find a specific site | Real, and Google documents it |
| **Human-in-the-loop (HITL)** | The machine does the work, a person approves at set points | Real. Competitors charge for it |
| **Drip publishing** | Spreading posts out instead of dumping 30 at once | Real, and it maps to a genuine Google policy |
| **Scaled content abuse** | Google's term for mass-producing pages to game search | Real, and it's a named spam policy |
| **MCP (Model Context Protocol)** | An open standard letting AI assistants call tools on your server. Your "MCB" | Real. Anthropic created it; spec revision `2026-07-28` |
| **Abilities API** | WordPress core's own way for a plugin to declare "here is something I can do" | Real, shipped in core 6.9 |
| **Webhook** | Your site POSTs a message to a URL you choose when something happens | Real, and it's the answer to most of your integration questions |
| **HMAC signature** | A cryptographic stamp proving the message came from your site | Real. Use the Standard Webhooks convention |
| **Canonical URL** | The tag saying "this is the original copy" | Real, and mandatory if you republish elsewhere |
| **P50 / P90** | "Half of runs finish by X" / "nine in ten finish by Y" | Real, and the honest way to quote a completion date |
| **Attested vs plausible** | A query someone really typed, vs one a model invented | **The single most important distinction in this plan** |

---

## Part 2 — The campaign system

### 2.1 First, an honest warning about topical authority

You want this plugin to follow Google's rules and produce content that ranks. That means I have to tell you what the research actually found, because it is not what the industry sells.

**The pillar-and-cluster model rests on a startlingly thin evidence base.** The founding experiment — HubSpot's 2017 "Topics Over Keywords" report, which every later vendor cites — was **8 pages over 3 months, with no control group**, run at the same time as a guest-blogging and email link-building campaign that took the site's Domain Authority from 40 to 60. The report's only chart has no axis values, no sample size, and no statistics. The famous "20–30 posts per pillar" figure traces to a single rhetorical quote from a HubSpot editor asking whether a topic is "broad enough to be an umbrella for 20-30 posts."

**A dedicated literature search found zero peer-reviewed papers supporting the claim at all.** DBLP and arXiv return no hits for "topical authority," "topic clusters," or "pillar page" in the web-IR sense the SEO industry uses — the academic term "topical authority" exists, but means a *person's* standing on a topic in a social or citation network (a Twitter account, an academic's subfield), never a website's. Worse, two of the algorithms the practice claims descent from argue the opposite: **Kleinberg's HITS computes hub/authority scores after explicitly deleting every same-domain link** — a pillar-and-spoke site structure is invisible to it by construction — and **Bharat & Henzinger's topic-distillation paper treats many pages on one site linking to the same target as a problem, dividing that link's influence by 1/k rather than rewarding it.** The one legitimately-descended technique is *SERP-overlap query clustering* (Fitzpatrick & Dent, SIGIR '97; Beeferman & Berger, KDD '00, 951 citations) — real precedent that overlapping result lists indicate related queries — but none of those papers set a threshold; "3 shared URLs in the top 10" is an industry convention with no study behind it.

**Google's "topic authority" is a real named system, but it is not what SEOs think.** Google's only official post on it scopes it to *"someone's newsy query in certain specialised topic areas, such as health, politics, or finance"* and names three signals: notability, original reporting cited by other publishers, and source reputation. **Internal linking, pillar pages, and publishing volume appear nowhere in it.** The "siteFocusScore" field cited from the 2024 API leak establishes only that Google computes something with that name — nothing about its weight or whether it affects ranking at all.

**The clustering thresholds nobody validates.** Practitioners use ≥3 or ≥4 shared URLs across the top 7 or top 10 — and disagree. Keyword Insights' marketing page says 30% over top-10 while their own docs say 4-of-top-7. No vendor publishes validation for any threshold.

**A final, decisive piece of research landed after the above was written, and it settles the question rather than just qualifying it.** Nine years after HubSpot's 2016 in-house experiment, no controlled study of the cluster model exists anywhere — I looked. The one causal test that does exist is a real split test, run by SearchPilot, of the exact mechanism campaigns are supposed to leverage: does linking spoke pages to a pillar page raise the pillar's traffic? **Donor pages gained +16% (significant). The recipient — the pillar — showed no detectable impact at all.** HubSpot's own uniform-anchor-text rule, the one concrete prescription the whole model produced, is contradicted by the largest internal-link dataset available (23M links, Zyppy) and has since been silently deleted from HubSpot's own article without correction. And HubSpot itself — the inventor, running the model longer and at greater scale than anyone — lost 76–81% of its organic traffic, which independent analysts attribute to exactly this kind of topic-volume sprawl. The widely-cited "clusters get 30% more traffic, hold rankings 2.5× longer" statistic traces to a defunct marketing agency's AI-generated blog post with no methodology at all, republished by an AI-generated page at a trade publication — it is fabricated and must never appear in our docs, UI copy, or marketing.

**What this means for us.** We should still build the calendar and the clustering — it produces a sensible content plan, keeps a campaign from being incoherent, and genuinely helps a reader navigate related posts. But we build it as **content organisation and reader navigation, not as a ranking mechanism**, and we say so as plainly as the paragraph above. That honesty is a feature: it's the same discipline that made us rename the citation check and drop the "nothing else does this" claims — and here it's not just marketing caution, it's that the one piece of real evidence says the mechanism doesn't work. Every competitor overclaims this; being the one tool that states the actual evidence is worth more than the claim.

**And there is a sharper reason than marketing honesty to be careful here.** A follow-up dive into the actual DOJ antitrust record (*United States v. Google*, exhibit PXR0356 — a February 2025 interview with a named Google VP, HJ Kim) found this, verbatim, describing why Google's page-quality team was founded: *"Content farms paid students 50 cents per article and they wrote 1000s of articles on each topic. Google had a huge problem with that. That's why Google started the team to figure out the authoritative source."* That is sworn testimony, not an SEO blog's inference — and it describes almost exactly the failure mode a careless "30 posts on one topic in 30 days" feature could produce. It's the single strongest reason the safeguards in §2.5 and §5 (drip pacing, mandatory review gate, the cross-post variety guard, conservative daily caps) are **non-negotiable defaults for a campaign, not optional settings** — this feature is the one place in the plugin where getting the guardrails wrong doesn't just weaken a claim, it recreates the exact pattern Google built its quality systems to catch.

### 2.2 The setup: five questions

**Blogcraft → Campaigns → Plan a campaign.** Ask only what cannot be inferred:

1. **What is this blog about, and who reads it?** — pre-filled from the existing Voice settings.
2. **Seed topic** — "sourdough baking for beginners".
3. **How many posts, over how long** — 30 posts / 30 days. Publishing days and time.
4. **Review strictness** — *auto-publish* / *hold everything for me* (default) / *approve the plan, then auto-publish*.
5. **Your own material** — 3–5 things you know first-hand. Spread across the calendar rather than one post.

Budget guard reuses the existing token cap.

### 2.3 How the calendar gets built — "attested, not plausible"

This is the core design decision, and it comes straight from the research.

**An LLM asked to "give me 30 blog topics about sourdough" will produce a list that looks excellent and is largely invented.** Not hallucinated in the dramatic sense — the measured hallucination rate on concrete artifacts is low (0.43% dead URLs across a 16-million-URL study). The real, peer-reviewed problem is **homogenization**: LLM outputs converge. PNAS found LLM-generated stories reuse plot elements where human ones don't; three preregistered studies over 2,200 essays found each additional human essay contributed more new ideas than each additional GPT-4 essay. **Every Blogcraft user asking for 30 sourdough topics would get substantially the same list.** That is a differentiation failure and a quality failure at once.

So: **the LLM never generates the topic list. It edits one.**

**Stage 0 — resolve the seed.** Wikipedia `action=opensearch` maps a messy seed to a canonical article title.

**Stage 1 — harvest queries people actually typed (free, no key).**
Google Suggest — `suggestqueries.google.com/complete/search?client=firefox&q=…` — **verified working, unauthenticated, during this research**. Critically, `/complete/` is **not disallowed** in Google's robots.txt, and Google's ToS scopes its automated-access prohibition to robots.txt violations. This is meaningfully different exposure from scraping the SERP.

The standard sweep: bare seed, suffixes a–z and 0–9, question modifiers (who/what/when/where/why/how/can/is/are/do/does), comparison modifiers (vs, or, alternative to). ~56 requests, 400–700 raw strings, 150–250 unique. Cross-validate against DuckDuckGo `/ac/` and YouTube suggest (both verified working, both free).

**Stage 2 — structural expansion via Wikipedia (free).** This is academically grounded, not a hack — Gabrilovich & Markovitch's ESA (IJCAI-07) and Milne & Witten's link-based relatedness (AAAI 2008) are the canonical citations.
- `prop=sections|tocdata` → article headings are ready-made subtopics
- the hand-curated **"See also"** section → highest-precision relatedness on the site
- `list=search&srsearch=morelike:{title}` → one request, server-side semantic neighbours. **Verified working.**
- categories with `clshow=!hidden`, filtered hard (the category graph has real cycles and administrative junk)

**Stage 3 — score.** Attestation weight (did a real person type this? did two independent suggest sources agree?), Wikipedia pageviews as an interest proxy (`agent=user`, discard the final partial bucket), inbound-link count as prominence, and the **gap heuristic**: a Stub-class article with rising pageviews is an underserved topic.

**Stage 4 — the LLM as editor, never author.** Hand it the scored candidates with an instruction it may **only select, cluster, merge and retitle from the provided list**. Then **programmatically verify every returned title traces back to a source candidate, and drop anything that doesn't.** This closes the invention hole completely.

**Stage 5 — optional paid enrichment.** If the user has a SerpApi key: People Also Ask, related searches, and SERP-overlap clustering (top-7, ≥4 shared URLs — the one published, reproducible algorithm in the category). **Strictly optional**, and here is why:

> **Google is currently suing SerpApi.** *Google LLC v. SerpApi LLC*, N.D. Cal. case 25-10826, filed 19 Dec 2025, alleging DMCA §1201 circumvention of Google's "SearchGuard" protection. SerpApi's motion to dismiss (20 Feb 2026) is **undecided as of August 2026**. Since the user brings their own key the exposure is theirs, not ours — but **Blogcraft must never hard-depend on SerpApi**, and the free Suggest + Wikipedia path must be the default that always works.

### 2.4 The plan row — where we beat everyone

The research examined Penfriend, KWHero and SEObot. **All three ship a plan row that is essentially just a title.** None attaches intent, funnel stage, or content type at planning time. Penfriend's much-marketed "17 articles" turns out to be arithmetic — 1 hub + up to 16 suggestions — with no template behind it.

Blogcraft's plan row should carry, from the start:

```
title · content type (archetype) · search intent · what this covers that the others don't
· cluster + pillar/sibling links · which of your facts it uses · draft-by · publish-at
· estimated tokens · status · locked?
```

That is strictly more than any of the three, and every field is something we can actually derive. **One field needs a precise claim, not a vague one:** "cluster + pillar/sibling links" should be built and described as internal-link hygiene and reader navigation — the same justification the plugin already uses for its existing internal-linking feature — never as "linking these posts together will boost the pillar's rankings." The one controlled test of that specific mechanism found no effect on the recipient page at all (see §2.1).

**Borrow SEObot's best idea — constraint-then-generate.** Build a bounded topic taxonomy from the site's own context *first*, then generate only inside those bounds, and treat "the user rejected most suggestions" as a signal to regenerate **the taxonomy**, not the suggestions. It's cheap and it's the most transferable design in the competitive set.

### 2.5 Full control over the calendar

- **Conservative daily caps are the default, not a slider set to maximum.** A user can raise them; the plugin should never suggest 30 posts in 30 days as if it were a neutral number rather than a real risk (see §2.1's DOJ-record finding above) — the wizard's default rhythm should read as clearly cautious.
- Drag any post to any day. Edit title, angle, notes.
- **Lock** items — regeneration never touches a locked row.
- Regenerate one day / the whole plan (respecting locks) / fill gaps.
- Pause, resume, stop. Change rhythm mid-flight; remaining items reschedule.
- CSV import and export.
- Per-row status: `planned → queued → drafting (live stage) → ready → scheduled → published / held / failed`.
- **Nothing generates until you press Start.** This is the human-in-the-loop gate, and it is also the thing that keeps 30-post automation on the right side of Google's scaled-content policy.

### 2.6 Honest time and cost estimates — needs new instrumentation

**This does not work today and cannot be faked.** Nothing times a stage; nothing costs a post.

Build, in order:
1. Record `started_at`/`finished_at` per stage on the job row, and per-stage token counts.
2. After ~20 posts, compute **P50 and P90** per stage from the site's own history.
3. Quote a **range**, never a point: *"30 posts drafted between Sep 12 and Sep 15 (P50–P90), roughly 1.8M tokens, about $14 at your provider's current rate."*
4. Before any history exists, say so plainly: *"First campaign — we'll estimate from your provider's actual speed once a few posts are done."*

An invented completion date is worse than no date.

---

## Part 3 — Notifications

### 3.1 WhatsApp: no, and here is exactly why

You asked for WhatsApp. **I recommend against building it natively, and the requirements make the case better than an opinion could.**

To send yourself "a post was published" via the WhatsApp Business Cloud API, the user must:

1–5. Facebook account with 2FA → Meta Business Portfolio → Meta developer app → add WhatsApp product → create a WhatsApp Business Account
6. **Delete their personal WhatsApp on that number, or buy a second SIM** — a number already on WhatsApp cannot be registered
7. Register and verify the number · 8. Put a credit card on the account
9–12. Business Settings → create a System User → assign the app asset → assign the WABA asset → generate a permanent token with three specific permissions
13–14. **Create a message template and wait up to 24h for Meta to approve it**
15. Paste Phone Number ID + WABA ID + token into WordPress

**15 steps, 3 separate Meta consoles, a credit card, a dedicated phone number, and a 24-hour wait.**

Two details make it worse. **A pre-approved template is mandatory** — a publish notification is business-initiated by definition, and free-form text only works inside a 24-hour window opened by *you* messaging the bot. The "message your own bot daily to keep the window open" trick genuinely works and is genuinely unusable. And templates **cannot end in a variable** and cap at 550 characters, so the user cannot customise their own message text without another 24-hour approval round.

**Third parties don't rescue it.** Twilio still requires Meta template approval, adds $0.005/message, and — unlike going direct — **requires business verification**. **CallMeBot must never be shipped**: personal-use-only by its own terms, routes your message text through an unaccountable third party in a URL query string, and automating WhatsApp through unofficial bridges risks a permanent ban on the user's number. WordPress.org Guideline 2 requires complying with third-party terms; this would fail on its face.

**Instead:** one signed webhook into an automation platform gives the user WhatsApp *and* thousands of other destinations, for one pasted URL. **Be precise about which platform, though — this needed a correction.** Zapier's own webhook-catching trigger ("Webhooks by Zapier") is **not on its free plan**; it requires Professional at $19.99/mo. The two genuinely free routes are **Make** (webhook triggers are free-tier) and **self-hosted n8n** (free under its Sustainable Use License — and notably, n8n has *no* WordPress trigger node at all, so for those users our webhook isn't a shortcut, it's the only door in). Document Make and n8n as the primary recipes; mention Zapier as an option with its cost stated plainly.

**One more free, near-zero-effort channel worth adding: [ntfy.sh](https://ntfy.sh/).** No account, no API key — the user picks a topic name and it doubles as the password: `POST https://ntfy.sh/<topic>` with the message body. Apache-2.0/GPLv2, self-hostable, one plugin in the entire wordpress.org directory currently supports it (published eight days before this research, zero installs). Cheap enough to ship in v1 alongside Discord.

### 3.2 What to build, ranked

This is also genuinely open ground, not just a nice-to-have: a competitor sweep found that **no AI content or autoblogging plugin in the wordpress.org directory ships any notification channel at all** — not AI Engine, not GetGenie, not AIomatic. And the two plugins that used to own free Slack and Discord notifications were both **closed by wordpress.org for guideline violations** (Slack integration, Oct 2024; Discord integration, Feb 2025); nothing above 400 installs has replaced either. Building this well is close to unopposed.

| Priority | Channel | Why |
|---|---|---|
| **1** | **Generic signed webhook** | Highest leverage. It *is* the WhatsApp answer, the Laravel answer, and the Make/n8n answer, in one piece of code. Also the one channel every serious competitor (Uncanny Automator) gives away free and unmetered — theirs is the pattern to match |
| **2** | **Discord** | Lowest friction of any chat channel — 5 clicks, free, no app, no caps. Its wordpress.org category leader is closed; open ground |
| **3** | **Email** | Expected by default. Ship with a **Send test email** button |
| **4** | **Slack** | Same mechanics as Discord, more setup. Note the free plan's 10-app cap. Its category leader is also closed |
| 5 | Telegram (v1.5) | Free, popular with this audience, and the one healthy incumbent (WP Telegram, 30k installs, 100% rating) — needs a "detect my chat ID" helper since bots can't initiate |
| 5 | ntfy.sh (v1.5) | No account, no key, one topic string. Nearly free to implement, essentially uncontested in the directory |
| 6 | In-dashboard bell (v1.5) | The pattern Yoast/Woo/Jetpack already taught users |
| — | **WhatsApp / SMS** | **Delegate via webhook**, into Make or self-hosted n8n specifically — not Zapier, whose webhook trigger is paid-only. Document the recipe |
| — | **Web Push** | **Skip.** The PHP library needs 8.2 (WP's floor is 7.4), service-worker scope is unsolvable cross-host, and the desktop grant rate is ~10% |
| — | **Microsoft Teams** | **Skip for now.** The classic incoming-webhook connector was retired by Microsoft in May 2026; the replacement (Power Automate Workflows) uses a different payload schema and no plugin has caught up to it yet |

**Webhook design** — follow the [Standard Webhooks](https://www.standardwebhooks.com/) convention (`webhook-id`, `webhook-timestamp`, `webhook-signature`), now adopted by OpenAI, Anthropic, Twilio and PagerDuty, so a Laravel app can use an off-the-shelf verifier. HMAC-SHA256 over the **raw body**, compared with `hash_equals()`. Emit `X-Hub-Signature-256` as well — one extra HMAC buys compatibility with every GitHub-shaped receiver.

**Three things that will bite if missed:**
- **`wp_remote_post()` does not validate URLs by default** — `reject_unsafe_urls` defaults to `false`. A user-supplied webhook URL is an SSRF vector straight at `169.254.169.254`. Validate at save time *and* pass the flag at send time, with `redirection => 0`.
- **Never send inline on publish.** A slow endpoint would hang the editor and hit `max_execution_time`. Queue it.
- **`Blogcraft_Http::post_json()` returns "Invalid JSON response" on any non-JSON 2xx** — and **Slack returns plain `ok`, Discord returns `204 No Content`**. Both would be misread as failures. Needs a `post_raw()` variant.

### 3.3 Email: what we must tell users honestly

`wp_mail()` returning `true` means "handed to the mail server", **not delivered** — core's own docblock says so. On shared hosting it commonly fails because WordPress sends as `wordpress@yourdomain.com` (usually not a real mailbox) from a server with no SPF or DKIM authority for that domain.

**Since February 2024, Gmail requires SPF *or* DKIM from every sender — not just bulk senders — and since November 2025 Gmail rejects non-compliant mail outright rather than spam-foldering it.**

So the plugin should: ship a **Send test email** button, hook `wp_mail_failed` to capture the real error, and say plainly — *"WordPress email often fails silently. Install a free SMTP plugin (FluentSMTP) and connect a transactional service (Brevo's free tier is 300/day). No plugin can fix this for you; it depends on DNS records only you or your host can set."*

We must **not** claim guaranteed delivery. Guideline 9 forbids implying a plugin can guarantee compliance.

---

## Part 4 — Your Laravel site, other platforms, and MCP

The research changed my answer here completely, in your favour.

### 4.1 Layer 1 — Abilities (do this regardless)

**WordPress core 6.9 shipped the Abilities API.** `wp_register_ability()` is a plain core function, PHP 7.4-clean, zero dependencies. Register Blogcraft's capabilities on `wp_abilities_api_init`, each with a real `permission_callback` mapped to a WordPress capability.

**Core already exposes them over REST at `/wp-json/wp-abilities/v1/abilities/{name}/run`.** So the moment we register abilities, a Laravel app can drive Blogcraft using an Application Password — **and we ship no MCP code, no REST controller, nothing.** This is the whole Laravel scenario, solved by core.

Guard with `function_exists()` — our floor is WP 6.0.

### 4.2 Layer 2 — A thin MCP endpoint

MCP got dramatically easier. Spec revision **2026-07-28** removed sessions (`Mcp-Session-Id` is gone) and the GET/SSE stream from the required surface. Both current and legacy revisions permit a **JSON-only server**: one route, POST, returns `application/json`.

That means **no SSE, no `flush()`, no long-lived connections** — nothing shared hosting, mod_security, or FastCGI buffering can break. It is a `register_rest_route` handler returning `WP_REST_Response`, roughly 300–500 lines.

| Decision | Choice |
|---|---|
| Route | one POST route; GET/DELETE return `405` explicitly |
| Streaming | none, ever. Don't declare `listChanged`, so no subscriptions are needed |
| Sessions | none |
| Auth | Application Password (Basic), or a plugin-minted bearer token. **No OAuth** — never ship an OAuth server in a free plugin |
| Versions | dual-era: `2025-06-18`/`2025-11-25` (what clients actually speak today) **and** `2026-07-28`. The spec blesses this |
| Default state | **off**; write tools off; per-tool toggles; audit log |
| SDK | **none.** All three PHP MCP SDKs require PHP 8.1 and drag in PSR/Symfony trees that collide inside WordPress |

Precedent is good: four MCP plugins are live on wordpress.org, three declaring `Requires PHP: 7.4`. `Automattic/wordpress-mcp` is **archived**; its successor `WordPress/mcp-adapter` targets PHP 7.4 but **is not on wordpress.org**, so we can't depend on it.

**Guideline 8 is the one to respect:** never expose a tool that evaluates PHP, writes plugin/theme files, or runs shell commands.

### 4.3 Layer 3 — Publishing elsewhere

On publish, POST the whole article package (blocks + HTML + meta + image URLs + schema) with a signature. The receiving site sets `rel="canonical"` back to the WordPress original — **mandatory**, or you have manufactured a duplicate-content problem for your own user. Ship a copy-paste Laravel controller + signature middleware in the docs.

---

## Part 5 — Keeping current with Google, and where I push back

You want the plugin to update itself when Google changes the rules. Half of that is right and half is a trap.

**Do this: extract the rules into a versioned data file.** Every Google-sensitive rule — banned phrases, scorecard weights, structure rules, schema emphasis, meta lengths — currently lives hardcoded across PHP classes. Move them to `data/rules.json`, exactly as `providers.json` already does for endpoints. Then a rule change is a one-file edit, testable against a fixture corpus. **This is unambiguously worth doing.**

**Don't do this: a remote channel that silently updates scoring rules.** The earlier plan proposed a weekly cron pulling a signed rules file. I recommend against it in v1:

- WordPress.org's Common Issues page states plainly: **"We do not permit plugins to phone home to other servers for updates."** Rules data isn't code, so this is arguable — but it is *arguable*, and a reviewer's judgement call is a bad thing to build a headline feature on.
- It needs opt-in consent under Guideline 7 anyway, so it can't be on by default.
- If our rules server is down, or wrong, or compromised, every install's scoring changes with no review.
- **The same value ships through normal plugin updates**, which the user already trusts, can read, and can roll back.

**What "we keep up with Google" should actually mean:** a documented commitment to ship a rules update within days of a material Search Central change, a visible `rules_version` in the dashboard, and a changelog entry saying what changed and why. That is a promise about **our release cadence**, not a background process — and it is the honest version of the claim.

**Bake the standing compliance in:** drip pacing with conservative daily caps, mandatory plan approval, a cross-post variety guard (consecutive posts must differ in opener and structure), answer-first intros (already enforced), and the author-entity pack (already shipped in 0.36.0).

---

## Part 6 — More scenarios

1. **Seasonal campaign** — seed with an event (Diwali, Black Friday); front-load preparation posts, skip dead days.
2. **Competitor-gap campaign** — paste 3 competitor domains, map what they cover, fill only the gaps.
3. **Refresh campaign** — schedule rewrites of stale posts instead of new ones. Runs on the rebuilt refresh pipeline from 0.36.0.
4. **Outline-approval mode** — strictest HITL: approve each outline before drafting.
5. **Multi-language** — one plan, N language variants, interlinked with `hreflang`.
6. **Budget guardian** — pre-flight estimate, "you're 80% through budget" alert, auto-pause at 100% *with a notification* rather than silent stop.
7. **Ideas inbox** — a private form where a client or teammate submits topics that land as unslotted calendar rows.
8. **Section-level regeneration** — "make this section longer", "add a table here", on a finished draft.
9. **Agency mode** — one site drives client blogs over abilities/MCP.
10. **AI-visibility check** — after publishing, does this post get cited in AI Overviews for its keyphrase? Genuinely green-field for a free plugin.

---

## Part 7 — Build order and honest effort

**Phase 0 — finish the foundation.** H12 parallelism guard. Per-stage timing + per-job cost instrumentation (**blocks the estimate feature**). A `post_raw()` HTTP variant. SSRF validation helper. *Small.*

**Phase 1 — the campaign engine.** New tables (`campaigns`, `campaign_items`). Suggest + Wikipedia expansion. Scoring. LLM-as-editor with source verification. Calendar UI with locks and regeneration. Lead-time drafting. Variety guard. P50/P90 estimates. **This is the product**, and it is a Blogcraft-scale effort on its own.

**Phase 2 — reach.** Signed webhooks + Discord + email + Slack, all queued. Abilities registration (small, high value — unlocks Laravel via core REST). `rules.json` extraction.

**Phase 3 — the agentic layer.** MCP endpoint. Telegram. In-dashboard bell. Laravel example package.

**Phase 4 — growth.** GSC feedback loop, refresh and competitor-gap campaigns, multi-language, AI-visibility.

**On job scheduling:** the current WP-Cron queue is adequate for 1–2 posts/day and marginal for campaigns. **Action Scheduler** (Automattic, GPL-3, PHP 7.2+, zero production deps, powers WooCommerce) is the right upgrade — it retries, logs failures, and gives users a Scheduled Actions screen they can self-diagnose from. One licensing note: it is GPL-3-or-later while we are GPL-2-or-later; bundling makes the combined work effectively GPL-3.

---

## Part 8 — What I recommend not building

| Not this | Because |
|---|---|
| Native WhatsApp | 15 steps, delete your personal WhatsApp, mandatory template approval. Delegate via webhook |
| CallMeBot or any unofficial WhatsApp bridge | Violates WhatsApp's terms; risks a permanent ban on the user's number; fails WP.org Guideline 2 |
| Web Push | Library needs PHP 8.2, WordPress floor is 7.4; service-worker scope unsolvable cross-host; ~10% grant rate |
| An OAuth server for MCP | Security-critical code shipped to shared hosting. Bearer tokens are spec-permitted |
| A vendored MCP SDK | All require PHP 8.1 and collide inside WordPress. Hand-roll ~400 lines |
| Remote rule-pack updates | Arguable against "no phoning home for updates"; ship rules through normal releases |
| Hard dependency on SerpApi | Google v. SerpApi is live and undecided. Keep it strictly optional |
| Reddit as a data source | Self-service app registration closed in late 2025; free tier is non-commercial only |
| Google Trends | Official API still application-gated alpha 13 months on; pytrends archived April 2025. Use Wikipedia pageviews |
| Claiming topical authority as a ranking mechanism | The evidence base is n=8 and uncontrolled. Build the feature; don't make the claim |

---

## Part 9 — What success looks like

> A user types *"beginner sourdough baking, 30 posts, Mon/Wed/Fri at 9am, hold everything for my review, max $20"* and presses **Plan**.
>
> Blogcraft harvests what people actually search for — from Google Suggest, Wikipedia's own structure, and their SerpApi key if they have one — scores it, and hands the model a real candidate list to *edit rather than invent*. A clustered 30-day calendar appears, every row carrying its type, its intent, and what it covers that the existing pages don't, with a completion range drawn from this site's own measured speed.
>
> They drag two posts, lock one, and press **Start**. Then they answer notifications: *"Draft ready for review"* in Discord, *"Published — here's the link and its score"* by email, while their Laravel app receives every published post over a signed webhook, and Claude can reschedule any day on request.
>
> When Google retires a rich-result type in November, a plugin update changes one data file, the dashboard shows what changed, and the tests prove nothing else moved.

Free, with the user holding every lever, and — the part none of the paid tools can say — **built on what people actually searched for, with the claims we can defend and none of the ones we can't.**
