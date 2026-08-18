# Blogcraft — the all-in-one plan

Three things this document settles: where a finished post actually goes, what
"follows Google's rules" can honestly mean in code, and the order the remaining
features get built.

---

## 1. Where a finished post goes

This is already built and worth stating plainly, because it decides how much of
the rest matters.

A finished job calls `wp_insert_post()` and creates a **normal WordPress post**.
Not a custom post type, not a separate table. It appears under Posts, opens in
Gutenberg, and every plugin you already run (Yoast, Rank Math, your theme)
treats it as an ordinary post.

The status it lands in:

| Situation | Status | Where it shows |
|---|---|---|
| You chose "Save as a draft" | `draft` | Posts → Drafts |
| You chose "Publish it" and it passed the quality bar | `publish` | Live on the site |
| You chose "Publish it" but it scored below the threshold | `pending` | Blogcraft → Needs review |

That third row is the important one. A post that fails its own measurements is
**never published automatically**, whatever was asked for. Publishing unreviewed
content at volume is the exact behaviour Google's scaled content abuse policy
targets, so the safe path is the default and the unsafe one is not reachable by
accident.

Content is written as Gutenberg block markup, so paragraphs, headings, lists,
tables and images are all real editable blocks rather than one HTML blob.

Also attached on publish: featured image, `_blogcraft_generated` meta (so the
plugin only ever touches its own posts), the quality score, and the full
scorecard.

---

## 2. Writing to what Google actually publishes

### The honest framing

Nobody outside Google knows the ranking algorithm, and any plugin claiming to
"guarantee rankings" is either guessing or lying — and WordPress.org Guideline 9
forbids saying it either way. What can be done honestly is narrower and more
useful: Google publishes Search Essentials, the spam policies, the guidance on
generative AI content, and the structured data documentation. Those are written
down. We encode **what is written down** as measurable checks.

So the rule for this section: every item below is either something Google has
published, or a proxy we can measure. Nothing is included because it is
"believed to help".

### What the published guidance actually says

The 2026 guidance is consistent on three points:

1. **AI-assisted content is allowed.** Google's own documentation says so. The
   production method is not what is judged.
2. **What is penalised is unoriginal content produced at scale to manipulate
   rankings** — the scaled content abuse policy. Volume without value.
3. **E-E-A-T is the bar**: Experience, Expertise, Authoritativeness, Trust. And
   the part a model structurally cannot fake is the first E — first-hand
   experience.

The practical dividing line is human oversight. That is a design constraint, not
a feature: it is why review exists and why publishing is gated on a score.

### New blueprint group: "What Google looks for"

A new pane, with every field either sent to the model or measured — the same
rule the rest of the blueprint follows.

**Intent and coverage**
- `search_intent` — informational / commercial / transactional / navigational.
  Changes the outline prompt: a commercial-intent post gets comparison and
  criteria sections, an informational one gets definition and mechanism.
- `answer_first` (bool, measured) — the opening must answer the title's implicit
  question within the first paragraph. Measured by checking the primary phrase
  and a direct statement appear before the first heading.
- `questions_covered` (list, measured) — People Also Ask style questions the
  post must answer. Reuses the existing required-terms machinery.

**Originality — the scaled-content defence**
- `require_experience` (exists) — first-hand specifics.
- `experience_bank` (new, textarea) — your own anecdotes, data, opinions and
  test results, stored once and drawn on per post. This is the single highest
  value field in the plugin for E-E-A-T, because it is the only source of
  something the model cannot invent.
- `originality_floor` (measured) — reject a draft whose sections are largely
  restatements of the reference material. Implemented as similarity between
  each section and the research excerpts; too high means it rehashed.
- Duplicate check (exists) — already refuses near-identical topics.

**Trust**
- `author_byline` (post author, exists in WP) — surface it, and warn when the
  site has no author bio, because that is a published E-E-A-T signal.
- `require_citations` (exists, measured via outbound links).
- `cite_recency_days` (new) — warn when every cited source is older than N days
  on a topic where freshness matters.
- `disclose_ai` (bool) — optionally append a short, honest disclosure line.
  Google does not require it; some publishers and jurisdictions do.

**Structure Google can parse**
- Structured data: `Article` (exists), `FAQPage` (exists), plus `HowTo`,
  `Product` and `BreadcrumbList` with the archetypes below.
- `meta_title_max` / `meta_desc_max` (exist, measured).
- Heading order (measured) — no H3 before an H2, no skipped levels.
- `alt_text_required` (measured) — every image has real alt text.
- Internal links (measured) — moving to in-text anchors, see §3.

**Readability, which is a proxy not a rule**
Flesch band, sentence and paragraph ceilings, passive share — all already
measured. Labelled honestly in the UI as readability, not as "SEO score",
because Google has never published a readability threshold.

### The SEO scorecard

Same mechanism as the writing scorecard: measure, then hand the model a repair
instruction. A second scored panel on Needs review, so a person sees the same
list. **No overall "SEO score out of 100" presented as a ranking prediction** —
each check states what it measured and what was asked for.

---

## 3. Feature roadmap

Ordered by value per hour of work, not by ambition. Every item names what
already exists, because most of these are wiring rather than new machinery.

### Phase A — close the loop on what exists

**A1. SERP-derived required terms.**
The research provider already fetches top-ranking pages. Extract the terms that
recur across them, populate `required_terms`, measure coverage, feed misses back
for rewriting. Every part of that exists except the extraction. This is the core
of what Surfer and NEURONwriter charge for.

**A2. Topic engine.**
Seed keyword → related queries → clustered topics → straight into the calendar.
Removes the "what do I write about" wall, which is the real reason the paid
tools feel complete and this does not.

**A3. AI image generation.**
DALL-E and Imagen through the key already entered. Today a paid OpenAI or Gemini
key sits half-used while images come from Pollinations.

**A4. In-text contextual links.**
Pass the site's relevant posts into the drafting context and let the model
anchor them in the prose, instead of appending a "Related reading" list.

**A5. Fix the fragile image injection.**
Inject image blocks into the article structure before rendering, rather than
string-matching rendered heading markup, which fails silently.

### Phase B — the Google pane

Everything in §2. Depends on A1 for the term work.

### Phase C — archetypes

The blueprint store already supports named blueprints; there is no UI to create
or switch them. So this is prompt and schema work on existing foundations:
product review, X vs Y comparison, how-to, listicle, pillar guide. Each carries
its own outline shape, its own structured data, and its own checks.

Highest perceived value of anything here.

### Phase D — cost and control

- Cost in currency, not tokens, with a per-model price table and a spend cap.
- Per-stage model routing: cheap model for outline, critique and metadata;
  quality model for sections. Sixty to eighty percent cheaper with no loss where
  it counts.
- Edit and regenerate one section from Needs review, rather than approve-or-bin.

### Phase E — the moat

- Google Search Console: indexing submission, striking-distance keywords,
  decay detection feeding the existing refresh pipeline.
- Post-publish tracking: did high-scoring posts actually perform? That answer
  is what should tune the blueprint, and nobody in this market does it.

### Deferred, pending a decision

**Gutenberg sidebar copilot.** Every paid competitor has one. It needs a
JavaScript build step, which breaks the no-build-tooling constraint held
throughout and enlarges the WordPress.org review surface. Worth doing — as a
deliberate choice, not a slipped-in one.

---

## 4. What this does not do

Stated so the plan cannot be read as promising it:

- It does not guarantee rankings, and the UI will never say it does.
- It does not detect whether text "reads as AI". Detectors are unreliable and
  building against them would be building against noise.
- It does not remove the need for a person to read the post. That is the
  published dividing line, and designing around it would be designing toward
  the exact behaviour that gets sites demoted.
