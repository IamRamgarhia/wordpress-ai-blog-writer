=== Dicecodes AI Blog Writer ===
Contributors: dicecodes
Tags: ai content generator, ai writer, autoblogging, seo content, blog automation
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.72.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI blog writer that researches first, writes in your voice, and checks its own work. Connect any provider with your own API key.

== Description ==

Dicecodes AI Blog Writer writes blog posts for your WordPress site using an AI provider you choose and connect with your own API key.

Every feature is included. Nothing is locked, nothing expires, and there are no credits or quotas. Your only cost is whatever your chosen provider charges, and several offer free tiers.

Full documentation, including a setup walkthrough and an explanation of every check it scores: https://dicecodes.com/ai-blog-writer/

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
* fal.ai — https://fal.ai/terms and https://fal.ai/privacy
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

Two places. The plugin ships its own under Help, which is always accurate to the version you have installed. The longer guides and walkthroughs are at https://dicecodes.com/ai-blog-writer/
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

= 0.72.0 =
* Connecting an app is one button now. It used to be: tick a box, save the page, come back, find the controls that had appeared, then issue a token. Everything is on the card from the first visit, and issuing a token is what switches connections on — pressing that button is not an ambiguous statement of intent
* Issuing a token also tests it. The site calls its own address exactly as an app will and reports what came back, so a server that strips the Authorization header or blocks the REST API says so here rather than leaving you with an app that only says it could not connect
* The card names the exact options each app needs. Claude Desktop offers four ways to authenticate and three OAuth arrangements, and the one it picks by itself fails with a message about registering an OAuth client that does not say what to choose instead. Set Authentication to None and add the Authorization header; the card says so, and warns about the option that fails
* Step by step rather than three paragraphs, with the address to copy in the step that asks you to copy it, and "How this works" going to the instructions rather than unfolding a summary of them

= 0.71.0 =
* A second way to supply a model, for anyone already paying for Claude, ChatGPT or a similar app. Instead of this site calling a provider with your key, the app connects to this site and does the writing, while the writing rules, the twenty-five checks and the publishing stay here. No API key, and nothing leaves your site: the connection comes in
* Settings, Connect an AI client. Switch it on, issue a token, and paste the address and the token into whatever you use. It speaks the Model Context Protocol, so it is not tied to one app
* A connected client can read your rules, score a draft against every check, create and revise drafts, and publish — but only above the quality threshold you set, exactly as a post written here would have to be. It cannot write on a schedule, touch a post it did not create, or reach your research and picture services. Scheduled writing still needs a provider key, because something has to be running when nobody is watching
* Tokens are shown once and stored only as a fingerprint. One stops working the moment the person it was issued to loses permission to write here, so a token cannot outlive the account behind it

= 0.70.0 =
* The plugin puts nothing in the dashboard notice area at all. The one notice it had — a warning that the queue has stalled — was already capability-gated, shown only on this plugin's own screens, dismissible and switchable off, which guideline 11 permits. But that space belongs to the whole dashboard, and the surest way not to misuse it is not to occupy it. The warning is drawn as part of our own page instead, under the navigation
* It also says how to fix the thing it is warning about, which the guideline asks for and a dismiss link on its own does not do: the first link now goes to the automation instructions, and dismissing is the second

= 0.69.0 =
* The admin menu says "AI Blog Writer" rather than the full plugin name. The sidebar column is about 160px wide, so the whole name wrapped onto two lines and knocked every item below it out of alignment. add_menu_page takes the browser title and the sidebar label separately, and only the readme heading and the Plugin Name header are required to agree

= 0.68.0 =
* The old name is out of the interface as well as the header. Renaming the plugin left the previous name on every screen, in the WP-CLI commands, and in the User-Agent sent to every research service — which is where somebody would actually have read it. Thirty-four strings, four commands and the request header now carry the real name
* The documentation address no longer contains the old term either. The review named URLs specifically, and a link on the author's own domain still spells out a name that belongs to somebody else
* readme.txt names where development happens, which guideline 4 asks for: the source ships in full, but the build scripts do not, so the repository is linked
* Trimmed two paragraphs of argument out of External Services. That section had crossed the 5,000-character ceiling and would have been truncated on the listing — the same fault as the changelog, one section along, and the test only covered the changelog. It covers every section now
Older releases are listed in changelog.txt, which ships with the plugin.

