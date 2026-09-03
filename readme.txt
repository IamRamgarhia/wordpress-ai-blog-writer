=== Dicecodes AI Blog Writer ===
Contributors: dicecodes
Tags: ai content generator, ai writer, autoblogging, seo content, blog automation
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.3
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

Dicecodes AI Blog Writer has no server of its own. It collects no analytics and sends nothing to the plugin author. It contacts only the services you configure, and only while writing a post.

**The WordPress AI Client** — on WordPress 7.0 and later, if a provider plugin is installed, the request can go through WordPress instead. No key here and no signup: the credentials live in WordPress. Offered only when genuinely available.

**AI providers** — whichever one you set up. The topic, your style settings and any gathered research are sent so the post can be written.

* OpenAI (api.openai.com) — https://openai.com/policies/terms-of-use and https://openai.com/policies/privacy-policy
* Anthropic (api.anthropic.com) — https://www.anthropic.com/legal/consumer-terms and https://www.anthropic.com/legal/privacy
* Google Gemini (generativelanguage.googleapis.com) — https://ai.google.dev/gemini-api/terms and https://policies.google.com/privacy
* xAI, for Grok (api.x.ai) — https://x.ai/legal/terms-of-service and https://x.ai/legal/privacy-policy
* Moonshot, for Kimi (api.moonshot.ai) — https://www.moonshot.ai/user-agreement and https://www.moonshot.ai/privacy-policy
* DeepSeek (api.deepseek.com) — https://cdn.deepseek.com/policies/en-US/deepseek-terms-of-use.html and https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html
* Groq (api.groq.com) — https://groq.com/terms-of-use/ and https://groq.com/privacy-policy/
* OpenRouter (openrouter.ai) — https://openrouter.ai/terms and https://openrouter.ai/privacy
* Mistral (api.mistral.ai) — https://mistral.ai/terms and https://mistral.ai/terms/#privacy-policy
* Together (api.together.xyz) — https://www.together.ai/terms-of-service and https://www.together.ai/privacy
* Fireworks (api.fireworks.ai) — https://fireworks.ai/terms-of-service and https://fireworks.ai/privacy-policy
* Cerebras (api.cerebras.ai) — https://www.cerebras.ai/terms-of-service and https://www.cerebras.ai/privacy-policy
* Hugging Face (router.huggingface.co) — https://huggingface.co/terms-of-service and https://huggingface.co/privacy
* Ollama, LM Studio, Jan or llama.cpp on your own machine, or a custom endpoint you define. The address is yours and the request reaches no third party, so there is no policy to link. https://ollama.com/, https://lmstudio.ai/ and https://jan.ai/

**Research providers** — optional, all off until switched on. Only the post topic is sent, and only when you write one. See Settings, Research.

* Tavily (api.tavily.com) — https://tavily.com/terms and https://tavily.com/privacy
* SerpApi (serpapi.com) — https://serpapi.com/legal#terms-of-service and https://serpapi.com/legal#privacy-policy
* Wikipedia (en.wikipedia.org) — the topic goes to its public summary API to read the opening of a matching article. No account or key. https://foundation.wikimedia.org/wiki/Policy:Terms_of_Use and https://foundation.wikimedia.org/wiki/Policy:Privacy_policy
* Hacker News search, via Algolia (hn.algolia.com) — the topic is sent as a search query. No account or key. https://www.algolia.com/policies/terms and https://www.algolia.com/policies/privacy
* A SearXNG instance you host yourself — the address is one you enter, so no third party is reached and there is no policy to link.

**Image providers** — off until you switch pictures on, which is how you choose one. A short description of the wanted picture is sent so one can be found or generated; the post itself never is. fal.ai, OpenAI, Gemini and Grok charge per image and are used only when chosen, so nothing is billed by accident.

* Pollinations (image.pollinations.ai) — https://pollinations.ai — publishes no terms or privacy page; offered because it needs no account.
* fal.ai (fal.run) — refuses automated requests, so its terms and privacy pages cannot be linked here; both are reachable from fal.ai itself.
* OpenAI, Google Gemini and xAI for Grok — the same three services, hosts and policies listed under AI providers above.
* Pexels (api.pexels.com) — https://www.pexels.com/terms-of-service/ and https://www.pexels.com/privacy-policy/ — served to people, but automated checks are refused with a 403.
* Pixabay (pixabay.com) — https://pixabay.com/service/terms/ and https://pixabay.com/service/privacy/ — rate limited, so an automated check may see 403 on one attempt and 200 on the next.

**Search engine notification** — off unless switched on under Settings, Automation.

* IndexNow (api.indexnow.org) — the address of the post, and nothing else, is sent once as it goes live, so Bing, Yandex, Seznam and Naver come and look rather than waiting. Google does not take part. https://www.bing.com/indexnow and https://www.microsoft.com/privacy/privacystatement

The plugin also fetches any URL you add to its research list, to read as source material, and with a search provider configured it opens the first few results for a topic to see how they are organised.

== Frequently Asked Questions ==

= Where is the documentation? =

At https://dicecodes.com/ai-blog-writer/ — every help link in the plugin opens the section for the control you were looking at. It is one page rather than a copy inside the plugin and another on the web, so there is only ever one to be right.
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

= 1.3.3 =
* A body sent by a connected app is narrowed before it becomes a post. The helper that turns HTML into blocks returns anything already carrying a block delimiter exactly as it arrived — right for markup this plugin wrote and reads back, wrong for markup that came from somewhere else, because a body merely beginning with one went into the post whole. The same helper was already being given narrowed input everywhere else it is used
* This is not a way in that was otherwise shut: the account behind a connection token belongs to somebody who can already publish. It matters because of where the words come from. They are written by a language model, and this plugin hands that model pages it fetched off the open web — so trusting the connection is not the same as trusting the text arriving over it, and the page that renders it is every reader's
* A test sends a script tag, an event attribute, a javascript: link and an iframe behind a block delimiter, and requires all four to be gone and the writing around them to survive

= 1.3.2 =
* Addresses the plugin did not choose are now fetched the careful way. Two were not: the research list typed into a settings field, and the results a search service hands back, which nobody on this site picked at all. Both went through the call that follows whatever it is handed — including to this server's own network, or to a cloud provider's metadata address. The voice reader had used the careful call for the same kind of input all along
* A page fetched from outside is now read only up to two megabytes. All that is wanted from one is its headings and an excerpt, both of which arrive early, and without a cap a page answering with a gigabyte is read into memory in full — and the request that dies of it is somebody's post
* The provider call deliberately keeps the plain fetch, because Ollama, LM Studio, Jan and llama.cpp all answer on localhost and the careful call refuses loopback. A test records that reason alongside the three other exemptions, so the next person to tidy this does not quietly break every local model
* The translation catalogue still announced itself as "Blogcraft" and declared the old text domain. The rename updated the filename and the scanner but not the header inside it, which is the part a translation tool actually reads. Both now come from the same constant as everything else

= 1.3.1 =
* Every AI provider now carries its own terms and privacy links. Ten of them — xAI, Moonshot, DeepSeek, Groq, OpenRouter, Mistral, Together, Fireworks, Cerebras and Hugging Face — were listed on one shared line saying the policies vary and to check whichever you pick. The directory asks for links per service, and that line had none
* Each entry also names the address it actually contacts, so what the plugin reaches and what the readme claims can be compared without reading the code
* Two picture services publish policy pages that are served to people but refuse automated readers. Both are now marked as such, the way fal.ai already was, because an automated link check reports a failure for a page that is genuinely there
* The self-hosted options — SearXNG, Ollama, LM Studio, Jan, llama.cpp and a custom endpoint — say plainly that the address is yours and reaches no third party, rather than being listed with nothing beside them
* Giving every service its own links pushed the section past the 5,000 characters the readme parser keeps, which would have cut the last services back off. It is written tighter and fits, and a test holds it there
* A test compares the readme against the code and the provider list: an address the plugin can reach and does not disclose fails, and so does a service named without either a policy link or a stated reason there is none
* Said why the two base64 calls in the key encryption are there. Both were suppressed warnings with no explanation beside them, which reads worse than the thing it was hiding
Older releases are listed in changelog.txt, which ships with the plugin.

