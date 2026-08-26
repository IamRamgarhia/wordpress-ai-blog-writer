Write the complete audit report to a single new file — **no code changes whatsoever**.

**Single action:** create `docs/blogcraft-audit-2026-08-23.md` containing the full BlogCraft v0.31.0 audit, already fully written and verified:

1. **Executive summary** — verdict + counts by severity (5 critical, 15 high, 20 medium, 9 unwired, 7 over-engineering, 18 missing-for-"best-free").
2. **Critical bugs (C1–C5)** — dead `quality_threshold`/`refresh_after_days` inputs; TOC toggle ignored at publish; external-links check unsatisfiable (prompts forbid HTML, scorecard counts anchors); internal-links check fails (woven after verify measures); per-post zero overrides discarded — all with file:line references.
3. **High-priority bugs (H1–H15)** — non-idempotent publish + duplicate-post risk, reclaim-window theft, deactivation/autopilot cron, UTC vs site-time day rollover, `per_day=0` = unlimited, false cron-health warning on CLI, no runtime DB migration, verify measuring a different document, second-class refresh pipeline, string-matched rate limits, `strip_dead_links` corruption, concurrency, uninstall option gaps.
4. **Medium bugs (M1–M20)** — byte-length checks, curly-apostrophe evasion, `load_plugin_textdomain` missing, silent sodium key-save failure, wrong anchors, Gemini key in query string, etc.
5. **Unwired things (U1–U9)** and **over-engineering (O1–O7)**.
6. **Google-ranking / AI-writing assessment** — what's already strong (banned tells, answer-first, measured loop, honest E-E-A-T, real internal links, schema) and the ranking gaps (no fallback head meta, no PAA/SERP FAQs, no competitor H2 outlines, JSON-LD-only authorship, missing HowTo/ItemList schema, non-navigable TOC).
7. **Categories/archetypes** — the 8 existing shapes, the missing per-post archetype picker, and the missing shapes (business/press-release post, roundup, case study, pillar page, glossary, FAQ-only, checklist, interview, buyer's guide).
8. **UX walkthrough** of where the user writes + gaps.
9. **Security assessment.**
10. **Prioritized recommendations** in 4 tiers (surgical fixes → reliability → ranking features → simplification).
11. **Appendix** — verified end-to-end pipeline flow.

Nothing else in the repository is touched.