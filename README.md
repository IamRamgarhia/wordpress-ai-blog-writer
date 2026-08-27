<div align="center">

# Blogcraft

### Free AI blog writer for WordPress — with your own API key

[![CI](https://github.com/IamRamgarhia/blogcraft/actions/workflows/ci.yml/badge.svg)](https://github.com/IamRamgarhia/blogcraft/actions/workflows/ci.yml)
[![Licence](https://img.shields.io/badge/licence-GPL--2.0--or--later-blue.svg)](LICENSE)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%20to%208.5-777bb4.svg)](https://www.php.net/)
[![Tests](https://img.shields.io/badge/tests-652-brightgreen.svg)](#contributing)

**No subscription · No credits · No middleman · Nothing locked**

</div>

---

Blogcraft writes blog posts inside WordPress using an AI account you already
own. There are no credits, no subscription, no per-post fee and nothing locked
behind a paid tier. You paste your own provider key, and that provider bills
you directly at their own rates.

It researches before it writes, drafts in a voice you describe, reads its own
work back, rewrites what it found wrong, and measures the result against
twenty-odd checks before anything reaches your site.

**Requires WordPress 6.0+ and PHP 7.4+. Tested to WordPress 7.1 and PHP 8.5.
GPL-2.0-or-later.**

---

## Why use this instead of a paid AI writing tool

Every claim here is a fact about how the plugin works, not a promise about
results. No plugin can promise you rankings, and this one does not try.

- **You own the account.** Your key, your provider, your bill. Nothing routes
  through a middleman, so there is no markup and no credit system.
- **It runs on your server.** Your topics, drafts and settings stay in your
  WordPress database. Nothing is sent anywhere except the AI provider you
  chose, and the research sources you switched on.
- **Nothing is locked.** There is no pro version. Every feature in the source
  is the feature you get.
- **The source is public.** All of it is here, readable, GPL.
- **Free providers work.** Google and Groq have free tiers big enough to write
  with. Ollama and LM Studio run a model on your own machine for nothing at
  all, no key required.
- **It disagrees with you when the evidence says so.** The scorecard reports
  what it measured, not what you hoped. When a rewrite makes a post worse, it
  says the score went down.

### Free alternative to paid AI content plugins

If you are looking for an AI writing plugin without a monthly subscription,
without a credit balance, and without your content passing through somebody
else's service, that is what this is. The trade is that you set up a provider
account yourself, which takes about two minutes and is the same step that
removes the middleman.

---

## Install

### From a release zip

1. Download the latest `blogcraft-x.y.z.zip` from
   [Releases](../../releases).
2. In WordPress: **Plugins → Add Plugin → Upload Plugin**.
3. Choose the zip, install, activate.

Always install through **Upload Plugin** rather than extracting the zip by
hand. WordPress reads the folder name from inside the archive, so uploading
replaces the previous version cleanly. Extracting manually creates a second
folder, and two copies of a plugin is a fatal error on activation.

### From source

```bash
git clone https://github.com/IamRamgarhia/blogcraft.git
cd blogcraft
composer install --no-dev
```

Then move the folder into `wp-content/plugins/` and activate it.

---

## Getting started

On activation the plugin opens a short setup screen. Three things decide
whether the first post is any good.

### 1. Connect a provider

**Settings → Connect a provider.** Choose who you have an account with, paste
the key, save. The model list is then read from your own account, so you pick
from what your key can actually use rather than typing an id from memory.

Supported: OpenAI, Anthropic, Google Gemini, xAI, Groq, DeepSeek, Moonshot,
Mistral, OpenRouter, Together, Fireworks, Cerebras, Ollama, LM Studio, any
OpenAI-compatible endpoint, and the WordPress AI Client.

### 2. Say who you write for

**Settings → Describe your voice.** Two sentences on the subject and the
reader. This is sent with every request and is the single biggest reason two
blogs using the same model do not read the same. If you already have posts
published, Blogcraft can read them and describe your voice for you.

### 3. Say what only you know

On the Write screen there is a field asking what you know that nobody else
does. A number you measured, a price you paid, what went wrong when you tried
it. It is the heaviest check on the finished post and the only part of a page
a model cannot produce. One or two sentences is enough.

If nothing comes to mind, a button reads your topic and asks you four specific
questions instead. It never answers them for you.

---

## How it writes

Twelve steps, one provider call each, driven from your browser so it works on
hosts where WP-Cron is unreliable.

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

You watch it happen, then read the finished draft and its score before
anything becomes a post.

---

## What it checks

Around twenty-five measurements on every finished draft, each one reporting
what it found against what you asked for:

- **Shape** — length, section count, heading order, paragraph and sentence
  length, reading ease
- **Search** — title length, subject in the title and early in it, subject in
  the first hundred words, in the address, in the description, keyword
  density, internal and external links
- **Substance** — your own figures used, claims that have something to check
  them against, whether it merely restates its sources
- **Voice** — banned phrases, em dashes, passive voice, excluded terms

Failures come with an instruction the rewrite can act on. Where a failure is
about your site rather than the writing — too few internal links, for example
— it says so instead of pretending a rewrite would fix it.

---

## What it costs

Nothing to the plugin. Your provider charges you at their rates, and the Write
screen estimates the tokens before you spend them. A monthly token cap is
available and stops generation once reached.

Pictures are separate and optional. Pollinations needs no key. Pexels and
Pixabay search real photographs and their keys are free. Paid image services
are only ever used when you pick one, never as a fallback.

---

## Privacy

- Your key is encrypted in the database and never written to logs, errors or
  the screen.
- Nothing is contacted until you switch it on. Research sources are all off by
  default.
- No telemetry, no phone-home, no analytics.
- Deleting the plugin leaves your settings and posts alone unless you tick the
  box asking for them to be removed.

---

## Frequently asked questions

<details>
<summary><strong>Is it really free?</strong></summary>

The plugin is. The AI is billed to you by whichever provider you choose, and several have free tiers large enough to write with.

</details>

<details>
<summary><strong>Do I need a paid API key?</strong></summary>

No. Google and Groq have free tiers. Ollama and LM Studio run locally and need no key at all.

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

Yes. Every paragraph, heading, list and table is a proper block, editable normally. On a Classic Editor site it writes plain HTML instead.

</details>

<details>
<summary><strong>Can it publish automatically?</strong></summary>

It can, on a schedule you set, with a daily cap. It is off by default and posts are saved as drafts unless you say otherwise.

</details>

---
## Contributing

Issues and pull requests are welcome.

```bash
composer install
composer lint          # PHPCS, WordPress Coding Standards
npx wp-env start
npx wp-env run tests-cli --env-cwd=wp-content/plugins/blogcraft -- vendor/bin/phpunit
```

The test suite is the specification. Every fix in this repository ships with a
test that fails without it, and the commit message says what went wrong and
why the fix is shaped the way it is.

---

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
