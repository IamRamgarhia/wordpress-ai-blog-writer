=== Blogcraft ===
Contributors: dicecodes
Tags: ai content generator, ai writer, autoblogging, content generator, seo content
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI blog writer and content generator. Connect any AI provider with your own API key. Every feature included, free.

== Description ==

Blogcraft writes blog posts for your WordPress site using an AI provider you choose and connect with your own API key.

Source code and issue tracker: https://github.com/IamRamgarhia/blogcraft

Every feature is included. Nothing is locked, nothing expires, and there are no credits or quotas. Your only cost is whatever your chosen AI provider charges — several offer free tiers.

**How it works**

1. Tell Blogcraft about your site: niche, audience, tone, and style rules.
2. Connect an AI provider using your own API key.
3. Choose whether posts are saved as drafts for review or published automatically.

Blogcraft researches a topic before writing, drafts the post, critiques its own draft, and revises it. Posts are saved as native block content so they remain fully editable in the block editor.

**You stay in control**

Blogcraft defaults to saving drafts for your review. Volume limits are set conservatively. Nothing is published without settings you choose.

== External Services ==

Blogcraft does not contact any servers of its own, and it collects no analytics or telemetry.

When you configure an AI provider, the topic, your style settings, and any research material are sent to that provider's API so it can generate the post. This happens only when a post is generated, and only after you have supplied your own API key for that provider.

No AI providers are connected in this release. As each provider integration ships in a later release, this section will be updated with that provider's name, the purpose of the integration, exactly what data is sent, and links to that provider's terms of service and privacy policy.

== Frequently Asked Questions ==

= Do I need to pay for anything? =

The plugin is free and complete. You need an API key from an AI provider, and several providers offer free tiers.

= Will posts publish without my review? =

Only if you turn that on. Blogcraft saves drafts by default.

= Scheduled posts are not being created. Why? =

WordPress only runs scheduled tasks when someone visits your site. On a low-traffic site you may need a real system cron job. Blogcraft shows a notice in your dashboard when it detects this.

== Changelog ==

= 0.1.0 =
* Initial foundation release: settings, encrypted key storage, job queue, scheduling, and admin dashboard.
