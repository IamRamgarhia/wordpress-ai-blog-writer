=== Blogcraft AI Writer ===
Contributors: dicecodes
Tags: ai content generator, ai writer, autoblogging, seo content, blog automation
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.65.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI blog writer that researches first, writes in your voice, and checks its own work. Connect any provider with your own API key.

== Description ==

Blogcraft writes blog posts for your WordPress site using an AI provider you choose and connect with your own API key.

Every feature is included. Nothing is locked, nothing expires, and there are no credits or quotas. Your only cost is whatever your chosen provider charges, and several offer free tiers.

Full documentation, including a setup walkthrough and an explanation of every check it scores: https://dicecodes.com/blogcraft/

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

Blogcraft still talks to providers directly as well, and will keep doing so. It supports WordPress 6.0, where the AI Client does not exist; the AI Client needs a separate provider plugin, so a 7.0 site can have it and still have nothing behind it; and naming every provider individually, including models running on your own machine, is the point of a bring-your-own-key tool.

**AI providers** — one of the following, whichever you set up. The topic, your style settings and any gathered research are sent so the post can be written.

* OpenAI — https://openai.com/policies/terms-of-use and https://openai.com/policies/privacy-policy
* Anthropic — https://www.anthropic.com/legal/consumer-terms and https://www.anthropic.com/legal/privacy
* Google Gemini — https://ai.google.dev/gemini-api/terms and https://policies.google.com/privacy
* xAI (Grok), Moonshot (Kimi), DeepSeek, Groq, OpenRouter, Mistral, Together, Fireworks, Cerebras and Hugging Face. Terms and privacy policy vary; see the one you choose.
* Ollama, LM Studio, Jan or llama.cpp running on your own machine, which sends nothing anywhere.
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

= Where is the documentation? =

Two places. The plugin ships its own under Blogcraft, Help, which is always accurate to the version you have installed. The longer guides and walkthroughs are at https://dicecodes.com/blogcraft/
= Do I need to pay for anything? =

The plugin is free and complete, and takes no cut of anything. The provider list is grouped by what it costs, free first, so the question is answered before you pick rather than after.

The first group runs a model on your own computer: Ollama, LM Studio, Jan and llama.cpp. No account, no key, no bill, and nothing leaves the machine. The second needs a key but not a card: Google Gemini, Groq, Mistral and Hugging Face give away usage at no cost, and OpenRouter lists a number of models that are free to call. Everything Blogcraft does works the same on either — there is no paid tier here to unlock.

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

Yes, and it is the first group in the provider list. Ollama, LM Studio, Jan and llama.cpp each have an entry with the right address already filled in — pick one and leave the API key blank. Anything else that speaks the OpenAI protocol, vLLM included, works through the custom endpoint.

= What happens to my API keys? =

They are encrypted before being stored, shown only as a mask, and never written to logs or error messages.

== Changelog ==

= Changelog ==

The complete history. The most recent releases are also in readme.txt;
everything is here, oldest at the bottom.

= 0.65.0 =
* The plugin is now called Blogcraft AI Writer, and its text domain is blogcraft-ai-writer to match. wordpress.org generates the directory slug from the plugin name and will not change it afterwards, and the text domain has to equal that slug or the translations the directory builds never load — which is the same failure as shipping no translations at all
* Nothing that identifies your data moved. The option names, the capability, the admin addresses and the class names are all unchanged, so an existing install keeps every setting, blueprint and record of what it has written

= 0.64.0 =
* No provider is chosen for you. The setting defaulted to OpenAI, so a plugin whose whole point is that you bring your own key opened with somebody else's company already selected — a paid, card-first one, sitting above every route that costs nothing. The list now starts on "Choose a provider" and waits
* The Help screen is written to be scanned rather than read. It was twelve sections of four to seven full paragraphs, which is an essay about the plugin rather than instructions for using it. Each section now opens with one sentence and breaks into numbered steps or short lines, and a "Start here" section at the top gives the five steps in order

= 0.63.0 =
* The provider list is grouped by what it costs, free routes first. Every label already said free or paid, but in a flat list of nineteen that only helped somebody who read all nineteen — and the two at the top of it both want a card before they will answer anything
* Three more ways to spend nothing: Jan and llama.cpp join Ollama and LM Studio as models that run on your own machine with no key, and Hugging Face joins Google, Groq and Mistral as a hosted free tier. All three were reachable before through the custom endpoint, which is no use to anybody who does not already know their runtime speaks that protocol
* The Help screen's contents moved to a rail beside the writing, and now marks the section you are reading. It was a stack of twelve bare links between the heading and the first section, because the screen loaded neither the stylesheet nor the script the rest of the plugin uses

= 0.62.0 =
* The documentation link now appears on the overview, the last screen of the introduction, the wordpress.org listing and its FAQ, and three more places in the README
* Every one of those addresses comes from one place now. The plugins row wrote it out itself, so there were two copies of one URL in two files — which is how a link comes to 404 while its twin still works
* The plugin header pointed at the same page without a trailing slash, so the one address WordPress displays was a redirect rather than the address

= 0.61.0 =
* A documentation site at https://dicecodes.com/blogcraft/ — one self-contained HTML file with no CDN, no web fonts and no external request of any kind, built on the plugin's own palette and type scale
* Its section anchors are the same slugs the plugin already uses, so every "How this works" panel offers the shipped documentation and the online guide side by side, each deep-linked to the matching section
* That page was then audited against the same on-page rules the plugin applies to posts, and six of fourteen failed. The title was 82 characters and opened with the word "documentation"; the description never contained "AI writer" at all; and a page carrying fifteen questions had no structured data on it. It has three schema blocks now, with the FAQ generated from the visible answers rather than written a second time

= 0.60.0 =
* The README and the branding inside WordPress brought into line with the house style used by Open WP Migration, so somebody arriving at either plugin recognises the second
* The plugins row now offers Docs beside Set up and Settings, and the Help screen links out to the guides and to the issue tracker — kept clearly separate from the shipped documentation above them, which is always true of the version you have installed in a way an online page cannot be
Older releases are listed in changelog.txt, which ships with the plugin.

