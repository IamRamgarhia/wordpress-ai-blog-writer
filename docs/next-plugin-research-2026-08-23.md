# Next Plugin — Market Gap Research & Recommendations

**Date:** 2026-08-23
**Portfolio context:** Dice Codes — **Open Migration** (free unlimited transfer/backup) and **DiceStack** (free 170-module all-in-one suite) are live on wordpress.org; **Blogcraft/RankCraft** (free AI blog writer, BYO key) is in progress as plugin #3. Studio philosophy: *take what WordPress users pay for and make it genuinely free — no tiers, no upsells, no data collection.*

**Research method:** Two deep research passes — (1) real user pain from Reddit r/Wordpress, r/WordPressPlugins, r/woocommerce (top posts of the year), WordPress.org support forums and 1-star reviews, Hacker News, Dev.to; (2) a 20-category paid-vs-free wedge map with verified pricing and free-tier gates from wordpress.org and vendor pages. Name availability spot-checked against the live plugin directory.

---

## The Meta-Finding

The loudest 2025–2026 WordPress themes validate the studio's whole model:

1. **Update fear is the pain of the year.** r/WordPressPlugins' #1 discussion post of the year was an *emergency thread* for WordPress 6.9 breaking sites; "Is the WordPress Update button the scariest thing ever?" is a standing meme; the .org "Fixing WordPress" forum is a permanent conveyor of "update broke my site."
2. **Plugin trust collapsed in April 2026**: someone bought 31 plugins on Flippa and planted backdoors in all of them (1,194-point HN story). Cloudflare: "96% of security issues for WordPress sites originate in plugins"; the .org review queue is 800+ plugins deep. WordPress.org has **no mechanism to flag plugin ownership changes**.
3. **Upsell rage is peaking**: "Those ads in the backend are getting out of hand" (top r/Wordpress post, Dec 2025); "Why is finding free plugins so… infuriating?" (Jan 2026). Meanwhile, free-no-upsell launches get *front-page celebration* — a free Yoast alternative post (Aug 2026) and a free Metorik clone (Apr 2026) were both top-of-subreddit hits. **Launching genuinely free plugins on Reddit is proven distribution for exactly this model.**

---

## Recommendation #1 (build this next): SafeRollback — Safe Updates with Automatic Rollback

**The idea:** Before any auto-update (WP core, plugin, or theme): take an automatic snapshot → apply the update → run a health check (homepage HTTP status, expected-content check, optional checksums) → **if the site is broken, revert files AND database automatically** and email the admin a report of what happened.

**Why it's #1 — every signal points here:**
- **Loudest pain found:** update fear dominated every community studied (the WP 6.9 emergency thread; "How a single plugin update broke my entire site"; "Microsoft Clarity update broke site immediately"; "Stop testing WordPress 7 on production").
- **The free gap is total.** WP Rollback (300k+ installs) only *manually swaps versions* — no snapshot, no DB undo, no automation — and now nags for a Pro version. Total Upkeep does backups without the update-verify-revert loop. **Nobody free does the full loop.** (A tiny "<10 installs" plugin called "Guardian – Conflict Detector & Safe Updates" attempted this and went nowhere — the idea is proven, the execution absent.)
- **Perfect code synergy:** it IS Open Migration's engine (snapshot, restore, serialized-safe DB rollback) wrapped around a scheduler and a health checker. Perhaps 70% of the machinery is already written.
- **Universal audience:** every single WordPress site updates. Safety-critical plugins earn trust that lifts the whole portfolio.
- **Timing:** post-backdoor-incident 2026 is the year admins want a guardian.
- **Name verified:** "SafeRollback" returns **zero results** in the plugin directory. Display name: `SafeRollback – Safe Updates & Auto Rollback for WordPress`.

**MVP scope:** snapshot before update (files+DB, incremental after first) · health check (HTTP + content signature, per-site configurable) · one-click AND automatic revert · update report email · update scheduling windows.
**Build size:** Medium. **Risks:** snapshot storage on shared hosting (reuse Open Migration's chunked AJAX approach); false-positive reverts (make auto-revert opt-in-in-opt-out-per-update).

---

## Recommendation #2: Open Staging — Staging with Free Push-to-Live

**The idea:** One-click staging clone, then **one-click push back to live** — the exact action every competitor paywalls. Later phases: selective push (posts/media only) and DB merge.

- **Pain evidence:** recurring all year in r/Wordpress — "How do you handle staging→production when most changes live in the database?", "What tools do you use to sync dev/staging/production?" (asked again as recently as Aug 18, 2026). HN: "WordPress has no concept of a staging site."
- **The gate is explicit:** WP Staging Lite (100k+ installs) lists *"Push staging changes to production"* as Pro-only; Pro costs €699 lifetime. BlogVault time-limits staging by tier. Its 1-star reviews literally say **"Bait and switch"** and **"CAREFUL: Restore may cost extra."** WPvivid gates the same thing.
- **Synergy:** again, this is Open Migration's engine with a diffing layer — clone exists already (that's what migration IS); push-to-live is restore-with-search-replace; the new work is content diffing and selective push.
- **Build size:** Large (DB merge is genuinely hard — but a full-push MVP is medium). **Risks:** hosts' native staging compete (but lock you to that host); big-site performance.
- **Directory state:** no plugin named "Open Staging"; the name matches the Open Migration brand family.

---

## Recommendation #3: FreeSplit — A/B Testing (the purest gap on the board)

**The idea:** Server-side split testing: create variant B of any page/title/CSS block, split traffic 50/50, track conversions (goal = page visit, form submit, WooCommerce order), declare winners with proper statistics.

- **Why:** since Google Optimize died (2023) there is **no credible free A/B testing for WordPress at all**. Nelio's free tier allows 500 tested pageviews/month — its own FAQ says "focus on one test only." The directory search holds just 53 A/B plugins, none dominant. Universal need (everyone selling anything), effectively zero free supply — the biggest need-vs-supply gap found in the entire research.
- **Build size:** Medium (URL/title/CSS tests are M; heatmaps would push M-L — skip heatmaps in v1).
- **Risks:** statistical correctness must be right (sample size / significance UX); low-traffic sites see slow results (set expectations in UI).
- **Synergy:** thinner — new engine, though it pairs with DiceStack's analytics modules and Blogcraft (test AI-generated titles against each other!). Directory has no general "FreeSplit"-style incumbent.

---

## Recommendation #4: Open Reports — Agency Client Reports & Uptime

**The idea:** Self-hosted agency layer: connect client sites (REST token, same pattern as MainWP), generate branded monthly PDF/HTML client reports (updates applied, uptime %, traffic from their GA/storage, security events), plus a white-label dashboard skin. Absorb uptime monitoring: one WP install watches all client sites.

- **Why:** both category leaders monetize *exactly this layer* — MainWP's own page says "unlock reporting and uptime tools when you're ready" (i.e., paid); ManageWP sells reporting per-site. The only notable free reporting plugin has ~6,000 installs — underserved. **A free Metorik clone got front-page celebration on r/WordPressPlugins (Apr 2026)** — proof this audience rewards free agency tooling loudly.
- **Synergy:** the audience IS Open Migration's audience (agencies migrate sites constantly) — the strongest cross-sell of any option.
- **Build size:** Medium (child-site aggregation + PDF generation). **Risks:** scope creep (dashboards, white-labeling is a rabbit hole — keep v1 to reports + uptime).

---

## Recommendation #5: Plugin Trust Monitor

**The idea:** A watchtower for your installed plugins: alerts when a plugin's **ownership changes** (the exact failure of April 2026 — dormant for 8 months before the backdoor fired), abandonment detection (no update in N months vs. its historical cadence), vulnerability feed integration, "last active" health scoring, and a dependency risk report before you install anything new.

- **Why:** the #2 screamed-about theme; **no free tool exists for any of it.** WordPress.org has no ownership-change flagging — a free plugin polling the .org API for its own site's plugin list can provide it. The 31-plugin backdoor story is a ready-made launch narrative.
- **Build size:** Small-Medium (mostly reads public .org API data + cron + alerts). **Risks:** security reputation is unforgiving (never overstate detection); could later become a DiceStack module but standalone gets its own search traffic.
- **Bonus:** SafeRollback + Trust Monitor together form a coherent "safety" franchise.

---

## Honorable mentions (real gaps, ranked reasons not to do them *now*)

| Idea | The gap | Why not now |
|---|---|---|
| **Social auto-poster (free, self-hosted)** | ROP free = 1 post/share, 5-hour min, FB+X only; Blog2Social gates all scheduling. Direct Blogcraft synergy (auto-share generated posts) | LinkedIn/Instagram/Meta API policy treadmill — high maintenance risk for a small studio |
| **Membership with payments** | Paid Memberships Pro permanently closed on .org (Oct 2024); MemberPress $399/yr; free side is permission-only | Large build, payments/lifecycle complexity |
| **BYO-key AI translation for Polylang** | All auto-translate is per-word SaaS or paid add-on; perfect BYO-key fit | XL build, Polylang ecosystem coupling |
| **PDF/document automation** | The one good free tool (Gravity PDF) requires paid Gravity Forms | Medium-large build, weaker portfolio synergy |
| **Woo order-DB cleanup + card-testing firewall** | Real, loud r/woocommerce pain | Narrow audience; DiceStack module might be the better shape |

## Decidedly NOT recommended (checked, free side already strong or space dying)

Image optimization (EWWW free is genuinely strong + DiceStack covers it) · forms (Fluent Forms free) · media folders (FileBird free unlimited) · redirects (Redirection is the gold standard of free) · LMS (Tutor/Masteriyo free too good) · product feeds (AdTribes free unlimited) · booking (LatePoint free sets a high bar) · email SMTP (FluentSMTP free) · broken links (mediocre incumbent, but Redirection solves half and it's a background hum, not a scream).

---

## Decision matrix

| Rank | Plugin | Demand evidence | Free-gap strength | Code synergy | Build | Risk |
|---|---|---|---|---|---|---|
| 1 | **SafeRollback** (safe updates) | ★★★★★ loudest pain of the year | ★★★★★ nobody does the loop | ★★★★★ = Open Migration engine | M | Low |
| 2 | **Open Staging** (push-to-live) | ★★★★☆ recurring all year | ★★★★★ explicitly paywalled by every leader | ★★★★☆ clone exists, push is restore | L | Med |
| 3 | **FreeSplit** (A/B testing) | ★★★☆☆ quiet but universal | ★★★★★ zero credible free since Google Optimize died | ★★☆☆☆ new engine | M | Med |
| 4 | **Open Reports** (agency layer) | ★★★★☆ proven Reddit appetite | ★★★★☆ leaders monetize exactly this | ★★★☆☆ audience overlap | M | Low |
| 5 | **Plugin Trust Monitor** | ★★★★★ defining 2026 story | ★★★★★ nothing free exists | ★★☆☆☆ new (but small) | S-M | Med (security rep) |

## Recommended sequence

1. **Finish Blogcraft/RankCraft** (it's the flagship content play; the audit + competitive reports define its roadmap).
2. **Plugin #4 = SafeRollback.** Highest pain × cleanest wedge × direct engine reuse × medium build. Launch on r/WordPressPlugins with the "the Update button shouldn't be scary" narrative — that audience has front-paged free no-upsell launches twice this year.
3. **Plugin #5 = Open Staging** (the large build, now cheaper because SafeRollback hardened the snapshot/restore engine).
4. Then choose between FreeSplit (new market) and Open Reports (agency flywheel) based on where the first two plugins' user base concentrates.
