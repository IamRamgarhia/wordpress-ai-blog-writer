=== Dicecodes AI Blog Writer ===
Contributors: dicecodes
Tags: ai content generator, ai writer, autoblogging, seo content, blog automation
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.89.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI blog writer that researches first, writes in your voice, and checks its own work. Connect any provider with your own API key.

== Description ==

Dicecodes AI Blog Writer writes blog posts for your WordPress site using an AI provider you choose and connect with your own API key.

Every feature is included. Nothing is locked, nothing expires, and there are no credits or quotas. Your only cost is whatever your chosen provider charges, and several offer free tiers.

Full documentation, including a setup walkthrough and an explanation of every check it scores: https://dicecodes.com/

**How a post is written**

1. **Research.** Gathers current source material before writing a word, so the post is not assembled from memory alone.
2. **Outline.** Plans the structure, then writes the draft section by section.
3. **Critique.** Reads its own draft and lists what is vague, repetitive or padded.
4. **Revise.** Rewrites to fix what it found. If the critique finds nothing, this pass is skipped rather than run for the sake of it.
5. **Verify.** Checks that every link resolves and scores the draft. Anything below your threshold is held for review instead of published.

**It can match an article you admire**

Paste the address of any published post and the plugin reads it: how long it runs, how many sections, how long its sentences and paragraphs are, whether it uses tables and lists, how heavily it links out, how many concrete figures it states, whether it says "I" or "you". Those measurements become your writing rules.

Structure only. None of the wording is copied, kept, or shown to a model — what it takes is public form, which belongs to nobody. It is a truer answer than a preset named after a famous blog, because it stays right when that blog changes.

There are also eight ready-made shapes to start from: definitive guide, numbered list, step by step, this against that, data study, argued opinion, quick explainer, hands-on review.

**It measures what it was asked for**

Twenty-five checks run on the finished draft, and every one that fails is written back into the rewrite as an instruction rather than a number. Among them:

* Does the opening answer the question in its first two sentences, or clear its throat first?
* Does every section that states a figure carry a link beside it? (It checks the link is there, not that the number is on the page at the other end.)
* Does it say anything the sources it read do not already say?
* Is the subject in the title, in a heading, and in the opening?
* Are the title and meta description the length they need to be?

The loop is the whole point: a score you have to act on yourself is a report card, and a rewrite that never gets measured is a guess.

**It writes in your voice**

Describe your niche, your reader, your tone, your style rules, and the things you never write about. All of it is sent with every request. A list of common AI tells is blocked by default.

You can also store your own anecdotes and experience, which is the one thing AI writing structurally lacks.

**It looks after the rest of your site**

* Links each new post to your existing ones from inside the sentences, not just a list at the bottom, and goes back to link older posts to the new one
* Refuses a topic too similar to something you have already published
* Rewrites your older posts in place when they go stale, keeping the same URL
* Adds a featured image, alt text, structured data and a contents outline
* Publishes author, reviewer, organisation and breadcrumb markup, which is what search and answer engines read as an expertise signal
* Adds a byline readers can see, with the author's own bio and profile links from their WordPress profile — the same signal in a form a person can read, rather than only a machine
* Plans around what is already ranking: the pages covering a topic are opened, their section headings read, and the outline aimed at what they leave out
* Fills in Yoast, Rank Math or SEOPress fields when one of those is active, and writes the description and sharing tags into the page itself when none of them is

**What it does not do**

It does not find topics for you. Every tool in this category that does is reselling keyword-volume data, and that data cannot be had for free — so charging nothing and supplying topics are not both possible. You bring the subject; the plugin does the rest.

It does not invent evidence. There is a field for your own figures, results and prices, and they are used as fact and checked against the finished draft. Nothing fills that field for you, because nothing can.

**You stay in control**

Drafts are the default. Volume limits are conservative. A monthly token cap stops runaway spend. Nothing publishes without settings you chose.

== Development ==

Development happens in the open at https://github.com/IamRamgarhia/wordpress-ai-blog-writer — the full source, the test suite, and the build scripts that produce the release zip and the translation template. Issues and pull requests are welcome there.

Nothing in the plugin is minified, compiled or generated: every file shipped is the file that was written.

== External Services ==

Dicecodes AI Blog Writer contacts no servers of its own. It collects no analytics and sends nothing to the plugin author.

It contacts only the services you configure, and only when generating a post:

**The WordPress AI Client** — on WordPress 7.0 and later, if a provider plugin is installed, this plugin can route everything through WordPress instead. No key here and no signup: the credentials live in WordPress and the request goes wherever your site already sends AI requests. It is offered in the provider list only when it is genuinely available.

**AI providers** — one of the following, whichever you set up. The topic, your style settings and any gathered research are sent so the post can be written.

* OpenAI — https://openai.com/policies/terms-of-use and https://openai.com/policies/privacy-policy
* Anthropic — https://www.anthropic.com/legal/consumer-terms and https://www.anthropic.com/legal/privacy
* Google Gemini — https://ai.google.dev/gemini-api/terms and https://policies.google.com/privacy
* xAI (Grok), Moonshot (Kimi), DeepSeek, Groq, OpenRouter, Mistral, Together, Fireworks, Cerebras and Hugging Face. Terms and privacy policy vary; see the one you choose.
* Ollama, LM Studio, Jan or llama.cpp running on your own machine, which sends nothing anywhere.
* A custom endpoint you define yourself.

**Research providers** — optional. The post topic is sent so relevant sources can be found.

* Tavily (api.tavily.com) — https://tavily.com/terms and https://tavily.com/privacy
* SerpApi (serpapi.com) — https://serpapi.com/legal#terms-of-service and https://serpapi.com/legal#privacy-policy
* A SearXNG instance you host yourself.
* Wikipedia (en.wikipedia.org) — the topic is sent to its public summary API to read the opening of a matching article. No account and no key. https://foundation.wikimedia.org/wiki/Policy:Terms_of_Use and https://foundation.wikimedia.org/wiki/Policy:Privacy_policy
* Hacker News search, via Algolia (hn.algolia.com) — the topic is sent as a search query. No account and no key. https://www.algolia.com/policies/terms and https://www.algolia.com/policies/privacy

Every one of these starts switched off, including the two that need no key. Nothing is contacted until you turn a source on, and only the post topic is ever sent. See Settings, Research.

**Image providers** — off until you switch pictures on, which is how you choose one. A short description of the wanted picture is then sent so an image can be found or generated. When "Describe the picture first" is on, that description is written by the AI provider above from the post's title and subject, and no post content is sent to the image service itself.

* Pollinations (image.pollinations.ai) — https://pollinations.ai (this service publishes no terms or privacy page; it is offered because it needs no account, and it is off until you choose it)
* fal.ai (fal.run) — this service refuses automated requests, so its terms and privacy pages cannot be linked here; both are reachable from fal.ai itself, and it is off until you choose it
* OpenAI — https://openai.com/policies/terms-of-use and https://openai.com/policies/privacy-policy
* Google Gemini — https://ai.google.dev/gemini-api/terms and https://policies.google.com/privacy
* xAI (Grok) — https://x.ai/legal/terms-of-service and https://x.ai/legal/privacy-policy
* Pexels (api.pexels.com) — https://www.pexels.com/terms-of-service/ and https://www.pexels.com/privacy-policy/
* Pixabay (pixabay.com) — https://pixabay.com/service/terms/ and https://pixabay.com/service/privacy/

fal.ai, OpenAI, Gemini and Grok charge for each image they generate. The plugin never falls back to them: they are used only when you have chosen one of them, so an image is never billed to you by accident.

**Search engine notification** — off unless you switch it on under Settings, Automation.

* IndexNow (api.indexnow.org) — the address of the post, and nothing else, is sent once as it goes live. The endpoint is shared: Bing, Yandex, Seznam and Naver all read from it. https://www.bing.com/indexnow and https://www.microsoft.com/privacy/privacystatement

When it is on, the address of each post is sent as it goes live, so Bing, Yandex, Seznam and Naver come and look rather than waiting to find it. Only the address is sent, never the post. Google has said it does not take part.

The plugin may also fetch any URL you explicitly add to its research list, to read it as source material. When a search provider is configured, it also opens the first few results for a topic to read how they are organised, which is used to plan a post that covers what they leave out.

== Frequently Asked Questions ==

= Where is the documentation? =

Two places. The plugin ships its own under Help, which is always accurate to the version you have installed. The longer guides and walkthroughs are at https://dicecodes.com/
= Do I need to pay for anything? =

The plugin is free and complete, and takes no cut of anything. The provider list is grouped by what it costs, free first, so the question is answered before you pick rather than after.

The first group runs a model on your own computer: Ollama, LM Studio, Jan and llama.cpp. No account, no key, no bill, and nothing leaves the machine. The second needs a key but not a card: Google Gemini, Groq, Mistral and Hugging Face give away usage at no cost, and OpenRouter lists a number of models that are free to call. Everything the plugin does works the same on either — there is no paid tier here to unlock.

Pictures work the same way: Pollinations needs no key, Pexels and Pixabay are free with a free key, and fal.ai, OpenAI, Gemini and Grok charge per image and are only ever used if you pick one of them.

Free allowances move on each provider's schedule, so the settings screen links to every provider's own pricing page rather than repeating a number here that would go stale.

= Do I need an SEO plugin? =

No. If Yoast, Rank Math or SEOPress is active, this plugin writes the title and description into that plugin's own fields and leaves the rest to it. If none is active, it prints the description and the Facebook and X sharing tags for its own posts, so the description it wrote is actually used rather than sitting unread in the database.

It stops there deliberately. Filling in head tags for pages it did not write is what an SEO plugin is for, and doing it anyway would mean two plugins fighting over the same tags on any site that later installs one.

= Will posts publish without my review? =

Only if you turn that on. Drafts are the default, and any post scoring below your quality threshold is held for review even when you asked for immediate publication.

= Scheduled posts are not appearing. Why? =

WordPress only runs scheduled tasks when someone visits your site, so a quiet site may need a real system cron job. The plugin shows a notice when it detects this. You can also run `wp dicecodes run` from the command line.

= Can I use a local model? =

Yes, and it is the first group in the provider list. Ollama, LM Studio, Jan and llama.cpp each have an entry with the right address already filled in — pick one and leave the API key blank. Anything else that speaks the OpenAI protocol, vLLM included, works through the custom endpoint.

= What happens to my API keys? =

They are encrypted before being stored, shown only as a mask, and never written to logs or error messages.

== Changelog ==

= Changelog ==

The complete history. The most recent releases are also in readme.txt;
everything is here, oldest at the bottom.

= 0.89.0 =
* The connected apps list says whether each one is Active, Idle or Never used, with a dot beside the word. A date told you when something last called and left you to work out whether that meant it was still working

= 0.88.0 =
* You can pick the model for one post without changing the setting for every other one. It sits with the category and the publishing date, and offers whatever your site already knows about rather than asking you to type a name from memory
* The strip at the top of Settings says what is actually in use — which provider and model, what it may read, where pictures come from, how often it writes — rather than only that something is set up
* Removed three controls from Write a post that could not do anything when an AI client does the writing: asking the provider what to write when there is no provider, choosing whether to publish when nothing here publishes, and an estimate of a bill that goes to a subscription instead of to you
* A test now holds the rule in both directions, so a control cannot go missing from the path it belongs to either

= 0.87.0 =
* Connected apps are listed one row each, saying whether the app signed itself in or is using a token you issued, when it connected, and when it was last heard from
* Fixed: signing an app in stores two credentials, one to call with and one to renew with, and the old list showed both — so a single app appeared twice, the second row claiming it had never been used. Revoking from that list was worse than useless: take the first and the app renews itself straight back in, take the second and nothing appears to happen
* Disconnect now ends the connection and everything behind it
* The list of apps is a list rather than four buttons across the card, each row naming what that app needs from you

= 0.86.0 =
* Every point raised by the WordPress plugin review, fixed against the current code. The security checks were always being made, but through a helper one call away, so each one carried a note telling the code checker to ignore it — and the review read those notes and called it a fault. Thirty-one places now use WordPress’s own check, with the permission test beside it, and seventy-three of those notes are gone because nothing needs excusing
* Two of them were not false alarms: a pair of internal functions read submitted data on the strength of a check made by whatever called them, with nothing at those lines saying so. Both check for themselves now
* Submitted data is cleaned where it arrives rather than several steps later, with the right function for each kind — including the one that keeps a draft’s formatting intact
* Fixed two addresses that did not answer: the plugin’s own page, which is not written yet, and one image service that refuses automated requests on every page it has

= 0.85.0 =
* The sentence to say in your app has moved to where it is useful. It was a standing panel at the top of Write a post explaining what to do once you had saved a brief, sitting above the form that saves it — backwards, and unchanging, so after the second visit it was five lines of furniture. It appears once there is a brief to say it about
* The warning that no app is connected stays at the top, and only when it is true

= 0.84.0 =
* Housekeeping after the voice fix: one step layout was built from a different element than the two beside it, and the note explaining a list of duplicate style rules described deliberate work as something still to be sorted out. Neither changed how anything behaves

= 0.83.0 =
* Fixed: your voice was described in two places and the model was told both. Settings had a Describe your voice card, How it writes had a Voice tab, and they were not two views of one thing — they were two stores, and every request carried two versions of the tone, the reader, the point of view, the reading level, the banned words and the subjects to avoid. Fill in one and the model got two answers to the same question
* How it writes now owns all of it. What the blog is about, your style rules and your own experience have moved onto its Voice tab, along with the Learn from my posts button. Nothing you had typed is lost — it is carried over the first time you open the screen
* The Settings card is now a line pointing at where the voice lives
Older releases are listed in changelog.txt, which ships with the plugin.

