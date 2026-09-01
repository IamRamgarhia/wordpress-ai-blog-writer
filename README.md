<div align="center">

# Dicecodes AI Blog Writer — free AI writer & SEO content generator for WordPress

### Write blog posts inside WordPress with your own API key — no subscription, no credits, no middleman.

[![License: GPLv2](https://img.shields.io/badge/License-GPLv2-green.svg)](LICENSE)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg?logo=wordpress)](#-install)
[![Version](https://img.shields.io/badge/Version-0.72.0-orange.svg)](https://github.com/IamRamgarhia/wordpress-ai-blog-writer/releases)
[![PHP](https://img.shields.io/badge/PHP-7.4%20to%208.5-777BB4.svg?logo=php)](#-install)
[![Tests](https://img.shields.io/badge/Tests-705%20passing-brightgreen.svg)](#-contributing)
[![GitHub Stars](https://img.shields.io/github/stars/IamRamgarhia/wordpress-ai-blog-writer?style=social)](https://github.com/IamRamgarhia/wordpress-ai-blog-writer)
[![Cost](https://img.shields.io/badge/Cost-100%25%20Free-brightgreen.svg)](#-why-choose-this-plugin)
[![Docs](https://img.shields.io/badge/Docs-dicecodes.com-3858e9.svg)](https://dicecodes.com/ai-blog-writer/)

**Research, draft, self-critique, rewrite and score — twelve steps inside your own WordPress, billed to your own AI account at your provider's own rates.**

It has no AI of its own and no server of its own. You paste a key from a provider you already use, and every request goes straight there. Nothing routes through anybody else, so there is no markup, no credit balance and no per-post fee.

It reads current sources before writing, drafts in a voice you describe, reads its own work back, rewrites what it found wrong, and measures the finished draft against twenty-five checks — which you see, with the score, before anything becomes a post.

**Looking for:** a free AI writing plugin with no monthly subscription · an AI content generator that uses your own OpenAI or Gemini key · a self-hosted alternative to credit-based AI writers · a way to run AI blogging on a local model with no key at all? That is what this is.

[Download Now](https://github.com/IamRamgarhia/wordpress-ai-blog-writer/releases/latest) &nbsp;|&nbsp; [Documentation](https://dicecodes.com/ai-blog-writer/) &nbsp;|&nbsp; [Quick Start](#-your-first-post-in-5-minutes) &nbsp;|&nbsp; [How It Works](#-how-it-writes) &nbsp;|&nbsp; [FAQ](#-frequently-asked-questions) &nbsp;|&nbsp; [Report Bug](https://github.com/IamRamgarhia/wordpress-ai-blog-writer/issues) &nbsp;|&nbsp; [Request Feature](https://github.com/IamRamgarhia/wordpress-ai-blog-writer/issues)

</div>

---

## 📦 Install

### From a release zip

1. Download the latest `dicecodes-ai-blog-writer-x.y.z.zip` from [Releases](https://github.com/IamRamgarhia/wordpress-ai-blog-writer/releases/latest).
2. In WordPress: **Plugins → Add Plugin → Upload Plugin**.
3. Choose the zip, install, activate.

> **Install through Upload Plugin, not by extracting the zip by hand.** WordPress reads the folder name from inside the archive, so uploading replaces a previous version cleanly. Extracting manually creates a second folder, and two copies of the same plugin is a fatal error on activation.

### From source

```bash
git clone https://github.com/IamRamgarhia/wordpress-ai-blog-writer.git
cd wordpress-ai-blog-writer
composer install --no-dev
```

Move the folder into `wp-content/plugins/` and activate it.

**Requires** WordPress 6.0+ and PHP 7.4+. Tested to WordPress 7.1 and PHP 8.5.

> Full documentation, with a setup walkthrough and every check explained: **[dicecodes.com/ai-blog-writer](https://dicecodes.com/ai-blog-writer/)**

---

## ⚡ Your first post in 5 minutes

Three things decide whether the first post is any good. The plugin opens a setup screen on activation and asks for them in order.

### 1. Connect a provider

**Settings → Connect a provider.** Choose who you have an account with, paste the key, save. The model list is then read from your own account, so you pick from what your key can actually use rather than typing an id from memory.

The list is grouped by what it costs, free first, because spending nothing is a supported way to use this plugin rather than a trial of it.

| Group | Who is in it | What you need |
|---|---|---|
| **Free — on your own machine** | Ollama · LM Studio · Jan · llama.cpp | Nothing. No account, no key, and nothing leaves the machine |
| **Free tier — a key, no card** | Google Gemini · Groq · Mistral · Hugging Face · OpenRouter's `:free` models | An account and a key |
| **Free credits, then paid** | Cerebras | An account |
| **Paid** | OpenAI · Anthropic · xAI · DeepSeek · Moonshot · Together · Fireworks | An account with billing |

Nothing is held back on a free provider. There is no paid tier here to unlock.

### 2. Say who you write for

**Settings → Describe your voice.** Two sentences on the subject and the reader. This is sent with every request and is the single biggest reason two blogs using the same model do not read the same. Already have posts published? It can read them and describe your voice for you.

### 3. Say what only you know

On the Write screen, one field asks what you know that nobody else does — a number you measured, a price you paid, what went wrong when you tried it. It is the heaviest check on the finished post and the only part of a page a model cannot produce.

Stuck? A button reads your topic and asks you four specific questions instead. It never answers them for you: invented facts are exactly what that field exists to avoid.

---

## 🎯 Why choose this plugin

| | This plugin | Typical paid AI plugin |
|---|---|---|
| **Cost of the plugin** | Free, GPLv2 | Subscription or credits |
| **Who bills you** | Your AI provider, directly | The plugin vendor, with a markup |
| **Where your content goes** | Your provider only | Through the vendor's servers |
| **Locked features** | None. There is no pro tier | Usually |
| **Runs on a local model** | Yes — Ollama, LM Studio, Jan, llama.cpp, no key | Rarely |
| **Source** | All of it, public | Usually closed |
| **Telemetry** | None | Varies |
| **Scores what it wrote** | 25 checks, shown before publishing | Varies |

Every line there is a fact about how the plugin works. **No plugin can promise you rankings, and this one does not try.**

---

## ⚙️ How it writes

Twelve steps, one provider call each, driven from your browser so it works on hosts where WP-Cron is unreliable.

```mermaid
flowchart LR
    A[Research] --> B[Outline]
    B --> C[Draft]
    C --> D[Sections]
    D --> E[Questions]
    E --> F[Extras]
    F --> G[Critique]
    G --> H[Revise]
    H --> I[Verify]
    I --> J{{You read it}}
    J --> K[Publish]
    K --> L[Pictures]
    L --> M[Linking]
```

| Step | What happens |
|------|--------------|
| **Research** | Reads current sources, if you switched any on |
| **Outline** | Plans the title, description, address and sections |
| **Draft** | Writes the opening |
| **Sections** | Writes each section, one call per section |
| **Questions** | Answers what readers actually ask |
| **Extras** | Any optional blocks you asked for |
| **Critique** | Reads its own draft back and measures it |
| **Revise** | Fixes what the critique and the measurements found |
| **Verify** | Checks every link resolves, scores the result |
| **Publish** | Creates the post |
| **Pictures** | One image per step, never all at once |
| **Linking** | Points older posts at this one, tells the crawlers |

You watch it happen, then read the finished draft and its score before anything becomes a post.

---

## ✅ What it checks

Twenty-five measurements on every finished draft, each reporting what it found against what you asked for.

<table>
<tr><td valign="top" width="50%">

**Shape**
- Length against target
- Section count
- Heading order, no skipped levels
- Paragraph and sentence length
- Reading ease

**Voice**
- Banned phrases
- Em dashes
- Passive voice
- Excluded terms

</td><td valign="top" width="50%">

**Search**
- Title length and subject placement
- Subject in the first 100 words
- Subject in the address and description
- Keyword density
- Internal and external links
- Image alt text

**Substance**
- Your own figures used
- Claims with something to check them against
- Whether it merely restates its sources

</td></tr>
</table>

Failures come with an instruction the rewrite can act on. Where a failure is about your site rather than the writing — too few internal links, say — it says so instead of pretending a rewrite would fix it.

---

## 🔌 Works with

**Providers:** OpenAI · Anthropic · Google Gemini · xAI · Groq · DeepSeek · Moonshot · Mistral · OpenRouter · Together · Fireworks · Cerebras · Hugging Face · Ollama · LM Studio · Jan · llama.cpp · any OpenAI-compatible endpoint · the WordPress AI Client

**SEO plugins:** Yoast SEO · Rank Math · SEOPress · All In One SEO — it fills their title and description fields automatically. With none installed it writes the description and sharing tags itself.

**Editors:** Real Gutenberg blocks, every paragraph and heading editable. On a Classic Editor site it writes plain HTML instead.

**Pictures:** Pollinations (no key) · Pexels · Pixabay · fal.ai · OpenAI · Gemini · Grok. Paid services are only used when you pick one, never as a fallback.

---

## 💰 What it costs

Nothing to the plugin. Your provider charges you at their rates, and the Write screen estimates the tokens before you spend them. A monthly token cap stops generation once reached.

---

## 🔒 Data privacy & security

- Your key is **encrypted in the database** and never written to logs, error messages or the screen.
- **Nothing is contacted until you switch it on.** Every research source is off by default.
- **No telemetry, no phone-home, no analytics.** The plugin never contacts us, because there is no us to contact.
- **Deleting the plugin leaves your settings and posts alone** unless you tick the box asking for them to be removed.
- Posts are ordinary WordPress posts from the moment they are created, and stay whatever happens to this plugin.

---

## 🤔 Frequently asked questions

<details>
<summary><strong>Is it really free?</strong></summary>

The plugin is, entirely, under GPLv2. There is no pro tier and nothing is locked. The AI is billed to you by whichever provider you choose, and several have free tiers large enough to write with.

</details>

<details>
<summary><strong>Do I need a paid API key?</strong></summary>

No. The provider list is grouped by cost with the free routes at the top.

**Nothing to pay, nothing to sign up for:** Ollama, LM Studio, Jan and llama.cpp each run a model on your own machine. Pick one, leave the key blank — the address is already filled in. A model of around seven billion parameters writes a readable post on an ordinary laptop.

**A key but no card:** Google Gemini, Groq, Mistral and Hugging Face give away usage at no cost. OpenRouter lists models that are free to call — on OpenRouter those are the ids ending in `:free`.

Every feature works the same on any of them. Allowances move on each provider's schedule, so the settings screen links to their own page for the current figure rather than repeating a number that would go stale.

</details>

<details>
<summary><strong>Will my posts rank on Google?</strong></summary>

Nobody can promise that, and this plugin does not. What it does is measure the things that are checkable — structure, substance, internal links, whether the page says anything only you could have written — and tell you plainly what is working against a post.

</details>

<details>
<summary><strong>Does it disclose that AI was involved?</strong></summary>

Yes, by default, in the byline. Google asks that automation be disclosed. You can change the wording or switch it off.

</details>

<details>
<summary><strong>Does it work with Yoast or Rank Math?</strong></summary>

Yes. It detects Yoast, Rank Math, SEOPress and All In One SEO and fills their title and description fields. With none installed it writes the description and sharing tags itself.

</details>

<details>
<summary><strong>Are the posts real Gutenberg blocks?</strong></summary>

Yes. Every paragraph, heading, list and table is a proper block, editable normally rather than arriving as one unopenable lump. On a Classic Editor site it writes plain HTML instead.

</details>

<details>
<summary><strong>Can it publish automatically?</strong></summary>

It can, on a schedule you set, with a daily cap. It is off by default, and posts are saved as drafts unless you say otherwise.

</details>

<details>
<summary><strong>What happens to my data if I delete the plugin?</strong></summary>

Nothing, by default. Your settings, writing rules and posts stay exactly where they are — install it again and everything is as you left it. There is a box in Settings if you do want it all removed, and ticking it is the only confirmation there will be.

</details>

---

## 🛠️ Contributing

Issues and pull requests are welcome.

```bash
composer install
composer lint          # PHPCS, WordPress Coding Standards
npx wp-env start
npx wp-env run tests-cli --env-cwd=wp-content/plugins/blogcraft -- vendor/bin/phpunit
```

Documentation for the plugin itself lives at [dicecodes.com/ai-blog-writer](https://dicecodes.com/ai-blog-writer/).

The test suite is the specification. Every fix here ships with a test that fails without it, and the commit message says what went wrong and why the fix is shaped the way it is.

---

## 💬 Support

- **Found a bug?** [Open an issue](https://github.com/IamRamgarhia/wordpress-ai-blog-writer/issues)
- **Documentation:** [dicecodes.com/ai-blog-writer](https://dicecodes.com/ai-blog-writer/)

---

<div align="center">

**Built by [DiceCodes](https://dicecodes.com)** · GPL-2.0-or-later · [LICENSE](LICENSE)

</div>
