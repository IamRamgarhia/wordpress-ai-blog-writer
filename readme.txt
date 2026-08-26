=== Blogcraft ===
Contributors: dicecodes
Tags: ai content generator, ai writer, autoblogging, seo content, blog automation
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.44.0
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

**It can match an article you admire**

Paste the address of any published post and Blogcraft reads it: how long it runs, how many sections, how long its sentences and paragraphs are, whether it uses tables and lists, how heavily it links out, how many concrete figures it states, whether it says "I" or "you". Those measurements become your writing rules.

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

It does not find topics for you. Every tool in this category that does is reselling keyword-volume data, and that data cannot be had for free — so charging nothing and supplying topics are not both possible. You bring the subject; Blogcraft does the rest.

It does not invent evidence. There is a field for your own figures, results and prices, and they are used as fact and checked against the finished draft. Nothing fills that field for you, because nothing can.

**You stay in control**

Drafts are the default. Volume limits are conservative. A monthly token cap stops runaway spend. Nothing publishes without settings you chose.

== External Services ==

Blogcraft contacts no servers of its own. It collects no analytics and sends nothing to the plugin author.

It contacts only the services you configure, and only when generating a post:

**The WordPress AI Client** — on WordPress 7.0 and later, if a provider plugin is installed, Blogcraft can route everything through WordPress instead. No key here and no signup: the credentials live in WordPress and the request goes wherever your site already sends AI requests. It is offered in the provider list only when it is genuinely available.

Blogcraft still talks to providers directly as well, and will keep doing so. It supports WordPress 6.0, where the AI Client does not exist; the AI Client needs a separate provider plugin, so a 7.0 site can have it and still have nothing behind it; and naming fourteen providers including models running on your own machine is the point of a bring-your-own-key tool.

**AI providers** — one of the following, whichever you set up. The topic, your style settings and any gathered research are sent so the post can be written.

* OpenAI — https://openai.com/policies/terms-of-use and https://openai.com/policies/privacy-policy
* Anthropic — https://www.anthropic.com/legal/consumer-terms and https://www.anthropic.com/legal/privacy
* Google Gemini — https://ai.google.dev/gemini-api/terms and https://policies.google.com/privacy
* xAI (Grok), Moonshot (Kimi), DeepSeek, Groq, OpenRouter, Mistral, Together, Fireworks and Cerebras. Terms and privacy policy vary; see the one you choose.
* Ollama or LM Studio running on your own machine, which sends nothing anywhere.
* A custom endpoint you define yourself.

**Research providers** — optional. The post topic is sent so relevant sources can be found.

* Tavily — https://tavily.com/terms and https://tavily.com/privacy
* SerpApi — https://serpapi.com/legal and https://serpapi.com/privacy-policy
* A SearXNG instance you host yourself.
* Wikipedia — https://foundation.wikimedia.org/wiki/Policy:Terms_of_Use and https://foundation.wikimedia.org/wiki/Policy:Privacy_policy
* Hacker News search, via Algolia — https://www.algolia.com/policies/terms and https://www.algolia.com/policies/privacy

Every one of these starts switched off, including the two that need no key. Blogcraft contacts nothing until you turn a source on, and only the post topic is ever sent. Blogcraft, Settings, Research.

**Image providers** — off until you switch pictures on, which is how you choose one. A short description of the wanted picture is then sent so an image can be found or generated. When "Describe the picture first" is on, that description is written by the AI provider above from the post's title and subject, and no post content is sent to the image service itself.

* Pollinations — https://pollinations.ai (this service publishes no terms or privacy page; it is offered because it needs no account, and it is off until you choose it)
* fal.ai — https://fal.ai/terms and https://fal.ai/privacy
* OpenAI — https://openai.com/policies/terms-of-use and https://openai.com/policies/privacy-policy
* Google Gemini — https://ai.google.dev/gemini-api/terms and https://policies.google.com/privacy
* xAI (Grok) — https://x.ai/legal/terms-of-service and https://x.ai/legal/privacy-policy
* Pexels — https://www.pexels.com/terms-of-service/ and https://www.pexels.com/privacy-policy/
* Pixabay — https://pixabay.com/service/terms/ and https://pixabay.com/service/privacy/

If you already write with OpenAI, Google or xAI, choosing the same one for pictures uses the key you have already entered — one key, one bill. A key from a different company will not work, and the settings screen says which case you are in.

fal.ai, OpenAI, Gemini and Grok charge for each image they generate. Blogcraft never falls back to them: they are used only when you have chosen one of them, so an image is never billed to you by accident.

**Search engine notification** — off unless you switch it on under Blogcraft, Settings, Automation.

* IndexNow — https://www.bing.com/indexnow and https://www.microsoft.com/privacy/privacystatement

When it is on, the address of each post is sent as it goes live, so Bing, Yandex, Seznam and Naver come and look rather than waiting to find it. Only the address is sent, never the post. Google has said it does not take part.

Blogcraft may also fetch any URL you explicitly add to its research list, to read it as source material. When a search provider is configured, it also opens the first few results for a topic to read how they are organised, which is used to plan a post that covers what they leave out.

== Frequently Asked Questions ==

= Do I need to pay for anything? =

The plugin is free and complete, and takes no cut of anything. You need an account with an AI provider, and every provider in the list is marked either free, "free tier", or paid, so you can see before you pick.

Three routes cost nothing at all. Ollama and LM Studio run a model on your own computer, need no key, and send nothing anywhere. Google Gemini, Groq and Mistral each give away some usage at no cost, and OpenRouter lists a number of free models. The rest bill you directly at their own rates.

Pictures work the same way: Pollinations needs no key, Pexels and Pixabay are free with a free key, and fal.ai, OpenAI, Gemini and Grok charge per image and are only ever used if you pick one of them.

Free allowances move on each provider's schedule, so the settings screen links to every provider's own pricing page rather than repeating a number here that would go stale.

= Do I need an SEO plugin? =

No. If Yoast, Rank Math or SEOPress is active, Blogcraft writes the title and description into that plugin's own fields and leaves the rest to it. If none is active, Blogcraft prints the description and the Facebook and X sharing tags for its own posts, so the description it wrote is actually used rather than sitting unread in the database.

It stops there deliberately. Filling in head tags for pages Blogcraft did not write is what an SEO plugin is for, and doing it anyway would mean two plugins fighting over the same tags on any site that later installs one.

= Will posts publish without my review? =

Only if you turn that on. Drafts are the default, and any post scoring below your quality threshold is held for review even when you asked for immediate publication.

= Scheduled posts are not appearing. Why? =

WordPress only runs scheduled tasks when someone visits your site, so a quiet site may need a real system cron job. Blogcraft shows a notice when it detects this. You can also run `wp blogcraft run` from the command line.

= Can I use a local model? =

Yes. Point the OpenAI-compatible provider at Ollama, LM Studio or vLLM and leave the API key blank.

= What happens to my API keys? =

They are encrypted before being stored, shown only as a mask, and never written to logs or error messages.

== Changelog ==

= 0.37.0 =
* Translations are now loaded. Every string in the plugin was written to be translatable and the translation file is rebuilt on every release, but nothing ever loaded it, so all of it stayed in English no matter what language the site was in
* A provider asking you to slow down is recognised by its status code rather than by reading its error message. The message is itself translated, so on a site in any other language a rate limit was mistaken for a failure and the post lost one of its three attempts to something that only needed a wait
* Title and description lengths are counted in characters, not bytes. An accent or an em dash cost extra, and a title in Greek, Hindi, Japanese or Arabic hit a sixty-character ceiling at about twenty letters
* Stock opening phrases are caught with a curly apostrophe as well as a straight one — the curly one being what actually gets written
* A banned word is matched as a whole word. "Delve" was flagged inside any longer word that happened to contain it
* Removing a dead link no longer damages a longer live link on the same domain, and if the removal cannot be done safely the log says so instead of reporting success
* API keys can be removed. A blank field means "keep what is saved", correctly, but that left no way to take a key out short of editing the database
* If a key cannot be encrypted — which happens on hosts without PHP's sodium extension — the screen now says so instead of reporting "Settings saved" over a key that was not stored
* The Gemini key travels as a header rather than in the address. In the address it was written into any proxy or server log along the way; the picture route already did this correctly
* Posts are no longer told they are missing an FAQ or key takeaways when the blueprint deliberately switched those off
* Scheduled work re-arms itself if something clears it. Schedules were set once at activation, so a migration, a staging copy or a security plugin could stop the queue with nothing on any screen saying why
* Removed two methods nothing called

= 0.36.0 =
* A byline readers can actually see. The author, reviewer and organisation markup was real and correct and entirely invisible: it spoke to parsers and said nothing to the person reading. Generated posts now end with the author's name, their bio and their profile links, taken from their own WordPress profile rather than from yet another settings field
* Posts are planned around what is already ranking. The pages covering a topic are opened, their section headings read, and the outline aimed at what they leave out — rather than arriving independently at the same shape as everybody else
* Optional IndexNow notification, off unless you switch it on. Bing, Yandex, Seznam and Naver come and look rather than waiting to find the post. Only the address is sent, never the post, and Google has said it does not take part
* Refreshing an existing post is now judged by the same rules that published it. A rewrite was scored by a superseded, older system, so the bar an existing post had to clear to be improved was a different bar, measuring different things — and nobody could tell, because both produced a number out of 100
* A refresh now weaves internal links into the sentences before scoring, as a new post does, instead of only appending a list underneath afterwards
* A post too long to show the model in full is now refused rather than trimmed. The rewrite replaces the whole post, so showing half of one and saving what came back silently deleted the half it never saw — on any post over roughly a thousand words, which is most of what this plugin writes
* The review screen's checks are rewritten after a refresh, so they describe the post as it now stands rather than as it was first written

= 0.35.0 =
* The meta description now reaches the page on sites with no SEO plugin. It was being written for every post, stored, and measured by the quality checks, and then never emitted, because WordPress prints no description tag of its own — so on a bare theme the most carefully checked sentence in the post did nothing at all. Facebook and X sharing tags come with it
* Only for posts Blogcraft wrote, and only when Yoast, Rank Math, SEOPress or All in One SEO is absent. Filling in head tags for the rest of the site is what an SEO plugin is for, and a theme that prints its own can switch these off with a filter
* Questions now come from what people actually search for. SerpApi returns "people also ask" in the same response the research sources arrive in, and it was being discarded while the model was asked to imagine questions instead. Tavily and a self-hosted SearXNG do not return them, so nothing changes there

= 0.34.0 =
* A post is never published twice. Publishing inserts the post and then spends minutes fetching pictures, which was long enough to cross the stale-job cutoff and be reclaimed while still running — and the re-run wrote a second copy. The post is now claimed the moment it exists, in two places that fail differently, and a resumed job finishes the one it already made
* Pictures are no longer re-fetched on a resumed job. Every generating service bills per image, so an interrupted attempt used to be charged for twice
* A job is no longer treated as dead after ten minutes. A single stage can legitimately take longer than that on a slow host — three provider attempts alone can reach four minutes — so reclaiming that early started a second copy of a live job rather than rescuing a stuck one. The window is now an hour, which is past every timeout the plugin permits
* A daily maximum of zero now writes nothing. It used to skip the cap entirely and allow a post every hour, while the calendar read the same zero as one and drew a schedule that would never happen
* The daily counter rolls over in your timezone, not UTC. On a site far from UTC the allowance used to reset in the middle of the working day
* Switching the plugin off now stops the automatic-writing schedule too. Only the queue schedule was being cleared, and WordPress keeps re-arming a recurring event whose plugin is gone
* "Blogcraft has not processed its queue recently" no longer shows forever on sites driven by a real system cron or WP-CLI — the arrangement the plugin's own documentation recommends. Only the WordPress-cron route recorded a heartbeat
* Plugin updates now bring the database up to date. Schema changes previously arrived only through the activation hook, which a one-click update never fires
* Deleting the plugin now removes the blueprint store and three other options it had been leaving behind, despite promising to remove every trace. Reinstalling handed the next owner the previous one's writing rules

= 0.33.0 =
* Every provider now says whether it costs money. The writing providers are marked free, "free tier" or paid, and the picture services the same way, so the first question anyone has about a list of fifteen names is answered before they pick one instead of after the first bill
* No label names a specific free allowance. Quotas move on each provider's schedule, so a number written into a plugin goes stale silently; the settings screen links to each provider's own pricing page instead

= 0.32.0 =
* The quality threshold and refresh-after-days settings are now actually saved. Both were rendered on the settings screen and read everywhere the pipeline needs them, but nothing in between wrote them to the database — the number typed in was shown back as "Settings saved" and then discarded, so every post was gated against 60 regardless of what was chosen
* The table of contents toggle is now honoured. It previously had no effect on a published post; every article with four or more sections got one whether the setting was on or off
* Sources cited now defaults on. It is the only way a real citation link can appear at all, since every prompt in this plugin forbids the model from writing markdown or HTML, so with it off the "Sources cited" check could never pass no matter what a post said
* Fixed a second bug hiding behind that one: five per-post extras, including sources, had no control on the Write screen but were still in the list the composer reads to decide what was switched off — so every single post written through the composer forced sources off regardless of the blueprint, even after the default above was fixed
* Internal links are now woven into an article before it is scored, not after. The check that counts them previously always measured a draft with none yet, so it could never pass; the review screen showed 0 internal links on every post no matter how many the published version actually carried
* A per-post picture or citation count deliberately set to zero is no longer silently discarded and replaced with the blueprint default
* Two "Finish it" links pointed at the wrong settings card

= 0.31.0 =
* Research sources and pictures now start switched off. Blogcraft contacts nothing until you turn a source on; turning it on is how you say yes to it
* Reddit is no longer read. It refuses anonymous automated requests from most shared hosting, so it returned nothing for a large share of the people who had it on, and its terms want a registered application either way
* Hacker News, Wikipedia and every paid source are unchanged, and still send only the post topic

= 0.30.0 =
* A queued post can be stopped from Activity. Queueing twenty topics and changing your mind previously left no way out but the database

= 0.29.1 =
* Fixed: 313 of the plugin's 802 translatable strings were missing from the translation template, so nearly two fifths of the interface could not be translated at all

= 0.29.0 =
* Pictures now have their own settings card next to the one for writing, instead of being buried three cards down under Automation
* Fixed: the switch for putting a picture under each section heading was read by the pipeline and rendered nowhere, so it could not be turned on
* Overview explains how the plugin is used in four steps, and keeps explaining it after the setup checklist has gone

= 0.28.0 =
* Google Gemini and xAI Grok can now draw the pictures. If you already write with either, the same key does both
* Images that arrive as data rather than a link are handled, which is how Gemini answers
* A generated image is saved with an extension matching what it actually is, so a PNG is no longer rejected for being named .jpg
* Anything that comes back not being an image is refused rather than written to your media library

= 0.27.0 =
* The documentation now ships with the plugin, under Blogcraft, Help. The help panels used to link to a page that did not exist
* Provider addresses moved into a data file, and other plugins can now add a provider with the blogcraft_providers filter
* Word count for structured data is stored against the post instead of being recalculated with regular expressions on every single page view
* Uninstall now removes the nine post meta keys it always claimed to

= 0.26.0 =
* Tested against WordPress 7.1
* On WordPress 7.0 and later, Blogcraft can route through the WordPress AI Client: no key, no signup, no model id. Offered only when a provider plugin is actually installed behind it
* Related posts no longer exclude the current post in the database query, which got slower as a site grew

= 0.25.0 =
* Five optional extra sections, written from the finished article in one extra request: who it is for and who it is not, what works and what does not, a table of the figures with their sources, mistakes worth avoiding, and the sources it was written from
* Tables and numbered lists are now rendered as real blocks. The "use tables" switch has existed since the beginning and nothing could draw one
* Fixed: six custom-endpoint settings were saved and never passed to the adapter that reads them, so a custom provider always used Authorization, Bearer and a default response path whatever you typed
* Settings sections say whether they are required or optional
* Write a post says that the topic is the only field you have to fill in

= 0.24.0 =
* Start from a shape: eight ready-made sets of rules for guides, listicles, tutorials, comparisons, data studies, opinion, explainers and reviews
* Match an article: paste any published post and Blogcraft measures how it is built, then sets the rules to match. Structure only, never wording
* The base URL hint now changes when you change provider instead of describing whichever one was saved

= 0.23.3 =
* Fixed: Pexels and Pixabay were being sent the whole image prompt as a search query, so they matched nothing and every post quietly fell back to a free generator

= 0.23.2 =
* Fixed: picture settings reported as ready when they were not, so every image quietly fell back to a free service
* The picture controls now name the service drawing them and link straight to it
* The OpenAI image key field says whether your writing key already covers it

= 0.23.1 =
* The brief tabs on Write a post now say what they are and stay in view, so Pictures and Publishing can actually be found
* Screens use the width of the window instead of a 900px column
* The help control on each settings section is a labelled button rather than a small question mark
* Queueing is blocked, with a link to fix it, when no provider is connected

= 0.23.0 =
* Fourteen named providers instead of four, including Grok, Kimi, DeepSeek, Groq, OpenRouter, Mistral and local models through Ollama or LM Studio
* Wikipedia, Reddit and Hacker News are now read for every post, free and with no key
* "Learn from my posts" fills the voice settings in from what you have already published
* Every settings section has a help control explaining what it is for
* Pictures and Publishing tabs on Write a post: art direction per post, plus category, tags, author and a publish time
* Needs review only appears when something is actually waiting
* New look throughout: translucent panels over a soft wash, with a solid fallback for anyone who has switched transparency off

= 0.22.0 =
* A field for your own figures, results and prices, used as fact and checked against the finished draft
* Internal links are now placed inside sentences where the wording matches, with the rest still listed at the end
* Overview says when posts have gone stale and refreshing is switched off

= 0.21.0 =
* Nine new checks, including answer-first openings, figures without a source, and how much of a draft merely repeats what its sources said
* Statistics, citations and first-hand experience are now measured, not just requested
* Title and meta description are checked, and the rewrite can now fix either
* Title and description length are taken from your settings instead of being hardcoded
* Publishes author, reviewer, organisation and breadcrumb structured data
* Section images no longer depend on exact block markup, so a theme or editor change cannot silently stop them appearing

= 0.20.0 =
* Pictures are now described by the model that wrote the article, instead of the headline being handed to an image model
* Added fal.ai and OpenAI as image sources, alongside the free ones
* New Pictures controls: treatment, mood, what the picture shows, shape, colours, and what it must never contain
* Text is kept out of generated images unless you ask for it
* The image prompt is shown on screen as you change the controls

= 0.19.0 =
* Overview now answers what needs doing, what is waiting, and how the last few posts scored

= 0.18.0 =
* Drafts are checked for missing alt text, skipped heading levels, and sections too thin to be worth a heading

= 0.17.0 =
* Works out which terms a subject is expected to cover, from the pages already covering it, and checks the draft against them

= 0.16.1 =
* Removed output token caps that starved reasoning models and broke drafting

= 0.16.0 =
* Articles are now written one section at a time, so long posts no longer fail part way through
* Saving a key checks it works, including on the save that completes setup

= 0.15.0 =
* Saving a key now checks it works and says so, instead of only saying "saved"
* Settings tells you which single thing is missing rather than "no provider yet"
* Save moved into the sticky rail, which now highlights the section you are in

= 0.14.0 =
* Terms that must never appear, and subjects to steer clear of, on both the brief and each post
* Settings gained jump links to each section

= 0.13.0 =
* Write a post rebuilt as a composer: the full brief for one post, on one screen
* A panel showing the shape of the post, its word budget and rough token cost, before you queue it
* Warns while you type when a topic repeats something already written or queued
* Every screen carries links to the others
* Settings links straight to the page that issues a key for the chosen provider

= 0.12.0 =
* Change the brief for one post without touching your defaults
* Needs review now shows what was measured and what was asked for, not just a number

= 0.11.0 =
* New "How it writes" screen: 48 controls over voice, structure, search, and sounding human
* A live Brief panel showing exactly what the model is told, updating as you change anything
* Per-section instructions for the opening, sections, ending and questions

= 0.10.0 =
* Every writing control now reaches the model, and measured faults are fed back for rewriting

= 0.9.7 =
* Weekday checkboxes are no longer squashed together

= 0.9.6 =
* Repeated topics in one pasted list are no longer queued twice
* Automation switched on with no days chosen now says so instead of doing nothing quietly
* The connection test asks for a key rather than spending a request to be told there isn't one
* Activity lists each job's topic, and the dashboard links a failure to its reason

= 0.9.5 =
* Accessibility: the bulk topic field is labelled, and repeated row buttons now name the topic or job they act on

= 0.9.4 =
* Schedule by weekday and start hour, in your site timezone
* New Calendar screen projecting when each queued topic will be written, with reordering
* Translation template shipped

= 0.9.3 =
* Every provider now has a default API address, so a key and a model are enough
* An empty setup says so instead of failing on a request that could not work

= 0.9.2 =
* New Activity screen showing recent jobs, why any of them stopped, and the event log
* Failed jobs can be tried again from there
* Running the queue by hand reports a failure as a failure

= 0.9.1 =
* The setup warning now appears when no provider is connected

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
