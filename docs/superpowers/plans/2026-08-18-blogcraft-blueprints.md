# Blogcraft — Content Blueprints and the measurement loop

## Why

Two complaints, one root cause.

**"It should have all parameters and metrics which tell the AI how to write."**
Today the only levers on *how* a post is written are five free-text voice fields
and a pass/fail quality threshold. Every competitor exposes far more: Koala has
tone presets, four points of view and length bands; ZimmWriter has per-section
custom prompts and toggles for lists, tables, key takeaways and FAQs; Rank Math
sets tone, audience, region, language and an optimal word count; Surfer and
NEURONwriter score a draft against word count, heading structure, paragraph
length, image count and NLP term coverage.

**"The design is very basic."**
It is stock `form-table` markup. A hundred controls rendered that way would be
worse, not better, so the parameter work and the design work cannot be
separated — the design has to earn the density.

The differentiator nobody in that table has: Blogcraft already *measures* a
draft and then throws the number away. `Blogcraft_Verify::score()` returns a
number that decides publish-or-hold and is never shown to the model. Measuring,
then handing the model a specific list of what missed, is the loop that makes
every parameter below actually bite instead of being decoration.

## The idea

A **Blueprint** is a named, reusable set of writing rules. One is the site
default; a post can override any field. Blueprints are what the settings screen
edits, what the prompt builder reads, and what the scorecard measures against.

Three rules keep this from becoming a wall of switches:

1. **Every parameter must reach the prompt or the scorer.** A control that
   changes nothing is worse than no control. Each field below names where it
   lands.
2. **Everything has a sane default.** The plugin must still work end to end
   without anyone opening the blueprint editor.
3. **Measured, not asserted.** Anything the scorer can check (length, density,
   reading level, structure) is checked and fed back, not merely requested.

## Data model

New option `blogcraft_blueprints`: `array<slug, blueprint>`. Setting
`active_blueprint` names the default. Jobs carry a resolved blueprint snapshot
in their payload, so editing a blueprint never changes a post already mid-write.

### Fields

**Voice** — into the system prompt.
`tone` (preset|custom), `tone_custom`, `point_of_view`, `audience`,
`audience_custom`, `formality` (1–5), `reading_level`
(simple|general|informed|expert), `language`, `locale_spelling` (us|uk),
`brand_terms`, `banned_phrases`.

**Structure** — into the outline prompt; checked by the scorer.
`word_target`, `word_tolerance` (±%), `sections_min`, `sections_max`,
`allow_h3`, `para_max_sentences`, `sentence_max_words`, `intro_style`
(hook|direct|story|statistic), `conclusion_style`
(summary|action|next_steps|none), `takeaways`, `takeaways_count`, `faq`,
`faq_count`, `tables`, `lists`, `bold_key_phrases`, `toc`.

**SEO** — into prompts; checked by the scorer.
`primary_keyword`, `secondary_keywords`, `density_min`, `density_max`,
`required_terms`, `meta_title_max`, `meta_desc_max`, `internal_links_target`,
`external_links_target`, `images_target`.

**Authenticity** — into the system prompt; partly checked.
`literary_devices` (multi), `allow_contractions`, `allow_em_dash`,
`require_experience`, `require_citations`, `require_statistics`,
`sentence_variety`.

**Per-section prompts** — into the relevant stage.
`prompt_intro`, `prompt_section`, `prompt_conclusion`, `prompt_faq`.

## The measurement loop

`Blogcraft_Metrics` computes, from rendered article text:

- word count; Flesch Reading Ease and Flesch–Kincaid grade (syllable counting,
  no library)
- average and longest sentence; average paragraph length
- primary keyword density; secondary keyword presence
- required-term coverage (n of m)
- H2/H3 counts and depth
- internal and external link counts; image count
- banned-phrase hits; em dash count; passive-voice estimate

`Blogcraft_Scorecard` compares those against the blueprint and returns
`{ score, checks[] }`, each check carrying `label`, `pass`, `actual`, `target`
and, when failed, **a repair instruction written for the model**.

Pipeline change: the `critique` stage gets the failed checks appended, so
`revise` receives "the intro is 340 words against a 180 target; cut it" rather
than a bare score. The scorecard also renders on the review screen so a human
sees the same list.

## Screens

- **Blueprint editor** — new screen. Left rail of groups, right pane of
  controls, sticky save bar, live summary panel that reads back the blueprint
  as a sentence.
- **Write a post** — collapsible override panel for the fields worth changing
  per post (keyword, word target, tone, extra instructions).
- **Needs review** — scorecard with the failed checks, replacing the bare
  score and reason list.

## Design

Constraints: no build step, no CDN (Guideline 8), must stay Plugin Check clean,
must respect `prefers-reduced-motion`, must keep every control labelled.

- Design tokens already exist in `admin.css`; extend with elevation, radius and
  a muted accent scale.
- Card grid rather than `form-table` rows: label, control, hint in a
  three-column grid that collapses to one on narrow screens.
- Segmented controls for small enumerations (point of view, intro style),
  range sliders with live value read-out for numbers, chip multi-selects for
  literary devices and required terms.
- Score ring (inline SVG) on the review screen and dashboard.
- Sticky save bar so a long screen never hides its own save button.

## Order of work

1. Blueprint schema, storage, resolution, defaults, migration from the current
   voice settings.
2. `Blogcraft_Metrics` + `Blogcraft_Scorecard`, with the repair instructions.
3. Prompt builder rewritten to consume every blueprint field.
4. Feed failed checks into critique/revise.
5. Blueprint editor screen and the new CSS system.
6. Per-post overrides; scorecard on review; score ring.

Each step ships working: the plugin must generate a post correctly at every
point, not only at the end.
