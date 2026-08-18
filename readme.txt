=== Blogcraft ===
Contributors: dicecodes
Tags: ai content generator, ai writer, autoblogging, content generator, seo content
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.9.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI blog writer that researches first, writes in your voice, and checks its own work. Connect any provider with your own API key.

== Description ==

Blogcraft writes blog posts for your WordPress site using an AI provider you choose and connect with your own API key.

Every feature is included. Nothing is locked, nothing expires, and there are no credits or quotas. Your only cost is whatever your chosen provider charges, and several offer free tiers.

**How a post is written**

1. **Research.** Gathers current source material before writing a word, so the post is not assembled from memory alone.
2. **Outline.** Plans the structure, then writes the draft section by section.
3. **Critique.** Reads its own draft and lists what is vague, repetitive or padded.
4. **Revise.** Rewrites to fix what it found. If the critique finds nothing, this pass is skipped rather than run for the sake of it.
5. **Verify.** Checks that every link resolves and scores the draft. Anything below your threshold is held for review instead of published.

**It writes in your voice**

Describe your niche, your reader, your tone, your style rules, and the things you never write about. All of it is sent with every request. A list of common AI tells is blocked by default.

You can also store your own anecdotes and experience, which is the one thing AI writing structurally lacks.

**It looks after the rest of your site**

* Links each new post to your existing ones, and goes back to link older posts to the new one
* Refuses a topic too similar to something you have already published
* Rewrites your older posts in place when they go stale, keeping the same URL
* Adds a featured image, alt text, structured data and a contents outline
* Fills in Yoast, Rank Math or SEOPress fields when one of those is active

**You stay in control**

Drafts are the default. Volume limits are conservative. A monthly token cap stops runaway spend. Nothing publishes without settings you chose.

== External Services ==

Blogcraft contacts no servers of its own. It collects no analytics and sends nothing to the plugin author.

It contacts only the services you configure, and only when generating a post:

**AI providers** — one of the following, whichever you set up. The topic, your style settings and any gathered research are sent so the post can be written.

* OpenAI-compatible endpoints, including Groq, OpenRouter, DeepSeek, Together, Mistral, Cerebras and local models. Terms and privacy policy vary by provider; see the one you choose.
* Google Gemini — https://ai.google.dev/gemini-api/terms and https://policies.google.com/privacy
* Anthropic — https://www.anthropic.com/legal/consumer-terms and https://www.anthropic.com/legal/privacy
* A custom endpoint you define yourself.

**Research providers** — optional. The post topic is sent so relevant sources can be found.

* Tavily — https://tavily.com/terms and https://tavily.com/privacy
* SerpApi — https://serpapi.com/legal and https://serpapi.com/privacy-policy
* A SearXNG instance you host yourself.

**Image providers** — optional. The post title is sent so an image can be found or generated.

* Pollinations — https://pollinations.ai
* Pexels — https://www.pexels.com/terms-of-service/ and https://www.pexels.com/privacy-policy/
* Pixabay — https://pixabay.com/service/terms/ and https://pixabay.com/service/privacy/

Blogcraft may also fetch any URL you explicitly add to its research list, to read it as source material.

== Frequently Asked Questions ==

= Do I need to pay for anything? =

The plugin is free and complete. You need an API key from an AI provider, and several offer free tiers.

= Will posts publish without my review? =

Only if you turn that on. Drafts are the default, and any post scoring below your quality threshold is held for review even when you asked for immediate publication.

= Scheduled posts are not appearing. Why? =

WordPress only runs scheduled tasks when someone visits your site, so a quiet site may need a real system cron job. Blogcraft shows a notice when it detects this. You can also run `wp blogcraft run` from the command line.

= Can I use a local model? =

Yes. Point the OpenAI-compatible provider at Ollama, LM Studio or vLLM and leave the API key blank.

= What happens to my API keys? =

They are encrypted before being stored, shown only as a mask, and never written to logs or error messages.

== Changelog ==

= 0.9.0 =
* Optional image beneath each section
* Consistent card layout across every screen

= 0.8.0 =
* FAQPage structured data
* Bulk topic import and batch rollback

= 0.7.0 =
* Research step gathers sources before writing
* Quality scoring and a review queue for posts that fall short
* Link verification before publishing
* Content refresh rewrites stale posts in place
* Image provider fallback chain
* WP-CLI commands
* Per-topic instructions

= 0.6.1 =
* Redesigned settings screen
* Fixed fields that browsers autofilled incorrectly

= 0.5.0 =
* Backward internal linking and duplicate-topic detection

= 0.4.0 =
* Internal linking, structured data, featured images, scheduled generation

= 0.3.0 =
* Brand voice applied to every request

= 0.2.0 =
* Post generation pipeline

= 0.1.0 =
* Initial foundation release
