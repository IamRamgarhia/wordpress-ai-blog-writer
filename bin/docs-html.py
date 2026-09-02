"""Build the published documentation page from docs/content.json.

The plugin used to carry its own copy of all this, on a Help screen and
behind a "How this works" control on every card. It links here instead, so
this file is the only copy and the anchors have to keep matching: a help
link in the plugin is this page's URL plus the section id, and nothing warns
you when one of them moves. The test suite checks them against each other.

    python bin/docs-html.py

Writes docs/index.html. Upload that as the page the plugin links to.
"""

import io
import json
import os
import sys
from html import escape

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CONTENT = os.path.join(HERE, 'docs', 'content.json')
TARGET = os.path.join(HERE, 'docs', 'index.html')

NAME = 'Dicecodes AI Blog Writer'
TITLE = NAME + ' - Documentation'
LEAD = (
    'Your site talks to your provider and nothing sits in between. This is '
    'how every part of that works, and what each of it costs you.'
)

# The three real ways in. Not the section list again — that is the rail's
# job — but the three questions somebody actually arrives with.
DOORS = [
    ('quickstart', 'Set it up', 'Five steps, about ten minutes.'),
    ('providers', 'Use your own API key', 'Your account, billed to you at their rates.'),
    ('clients', 'Use Claude or ChatGPT', 'The subscription you already pay for. No key.'),
]

CSS = """
:root {
  color-scheme: light dark;

  /* Ink and verdigris: patina on a working instrument. The paper is cool
     rather than cream, so the serif does not read as a books-and-coffee
     page, and the accent is a green that has aged rather than a brand
     blue that has not. */
  --ink: #14201f;
  --ink-2: #33423d;
  --muted: #5b6a64;
  --paper: #f4f5f2;
  --surface: #ffffff;
  --rule: #d7ddd6;
  --rule-soft: #e6eae4;
  --verd: #1a6b58;
  --verd-ink: #10453a;
  --verd-wash: #e8f2ee;
  --shadow: 18px 40px 80px -32px rgba(20, 32, 31, .30);
  --shadow-lift: 0 2px 0 var(--rule-soft), 12px 22px 44px -24px rgba(20, 32, 31, .34);

  --sans: ui-sans-serif, system-ui, -apple-system, "Segoe UI Variable Text",
          "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  --serif: "Iowan Old Style", Charter, "Bitstream Charter", Cambria, Georgia,
           "Times New Roman", serif;

  --measure: 68ch;
}

@media (prefers-color-scheme: dark) {
  :root {
    --ink: #e7ece9;
    --ink-2: #b9c4bf;
    --muted: #8a9993;
    --paper: #0d1413;
    --surface: #151d1b;
    --rule: #2a3532;
    --rule-soft: #202927;
    --verd: #58c3a5;
    --verd-ink: #8fdcc3;
    --verd-wash: #16302a;
    --shadow: 18px 40px 90px -34px rgba(0, 0, 0, .8);
    --shadow-lift: 0 2px 0 var(--rule-soft), 12px 22px 44px -24px rgba(0, 0, 0, .65);
  }
}

* { box-sizing: border-box; }

html { scroll-behavior: smooth; }

body {
  margin: 0;
  background: var(--paper);
  color: var(--ink);
  font-family: var(--serif);
  font-size: 17px;
  line-height: 1.72;
  -webkit-font-smoothing: antialiased;
  text-rendering: optimizeLegibility;
}

a { color: var(--verd-ink); text-underline-offset: 3px; }
a:hover { color: var(--verd); }

:focus-visible {
  outline: 2px solid var(--verd);
  outline-offset: 3px;
  border-radius: 2px;
}

.wrap { max-width: 1140px; margin: 0 auto; padding: 0 28px; }

/* ---------------------------------------------------------------- hero. */

.hero {
  position: relative;
  padding: 88px 0 64px;
  background: var(--surface);
  border-bottom: 1px solid var(--rule);
  overflow: hidden;
}

/* One quiet piece of depth: light falling from the top left, so the sheet
   has an edge rather than being a flat fill. */
.hero::before {
  content: "";
  position: absolute;
  inset: -40% 40% 40% -20%;
  background: radial-gradient(closest-side, var(--verd-wash), transparent 70%);
  opacity: .8;
  pointer-events: none;
}

.hero > * { position: relative; }

.eyebrow {
  margin: 0 0 14px;
  font-family: var(--sans);
  font-size: .82rem;
  font-weight: 600;
  color: var(--verd);
}

h1 {
  margin: 0 0 18px;
  max-width: 20ch;
  font-family: var(--sans);
  font-size: clamp(2.3rem, 5.5vw, 3.4rem);
  font-weight: 800;
  line-height: 1.04;
  letter-spacing: -0.035em;
}

.hero p.lead-in {
  margin: 0;
  max-width: 54ch;
  font-size: 1.16rem;
  line-height: 1.6;
  color: var(--ink-2);
}

.doors {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px;
  margin: 44px 0 0;
  padding: 0;
  list-style: none;
}

.doors a {
  display: block;
  height: 100%;
  padding: 20px 22px 22px;
  background: var(--paper);
  border: 1px solid var(--rule);
  border-radius: 3px;
  text-decoration: none;
  color: inherit;
}

.doors a:hover {
  border-color: var(--verd);
  background: var(--verd-wash);
}

.doors strong {
  display: block;
  margin-bottom: 4px;
  font-family: var(--sans);
  font-size: 1.02rem;
  font-weight: 700;
  letter-spacing: -0.012em;
  color: var(--verd-ink);
}

.doors span {
  font-size: .95rem;
  line-height: 1.5;
  color: var(--muted);
}

/* -------------------------------------------------------------- layout. */

.layout {
  display: grid;
  grid-template-columns: 216px minmax(0, 1fr);
  gap: 64px;
  padding: 56px 0 96px;
}

/* The rail is a spine: one hairline down the page, with the section you are
   reading marked against it. */
nav.toc {
  position: sticky;
  top: 28px;
  align-self: start;
  max-height: calc(100vh - 56px);
  overflow-y: auto;
  padding-left: 14px;
  border-left: 1px solid var(--rule);
  font-family: var(--sans);
  font-size: .9rem;
}

nav.toc h2 {
  margin: 0 0 12px;
  font-size: .9rem;
  font-weight: 700;
  letter-spacing: -0.01em;
  color: var(--ink);
}

nav.toc ol { margin: 0; padding: 0; list-style: none; }

nav.toc a {
  position: relative;
  display: block;
  padding: 4px 0 4px 12px;
  color: var(--muted);
  text-decoration: none;
  line-height: 1.4;
}

nav.toc a::before {
  content: "";
  position: absolute;
  left: -15px;
  top: 50%;
  width: 2px;
  height: 0;
  background: var(--verd);
  transform: translateY(-50%);
  transition: height .18s ease;
}

nav.toc a:hover { color: var(--ink); }

nav.toc a.is-here {
  color: var(--verd-ink);
  font-weight: 600;
}

nav.toc a.is-here::before { height: 100%; }

main { min-width: 0; }

section { margin: 0 0 68px; scroll-margin-top: 28px; }

section > h2 {
  position: relative;
  margin: 0 0 10px;
  font-family: var(--sans);
  font-size: 1.72rem;
  font-weight: 750;
  line-height: 1.15;
  letter-spacing: -0.028em;
}

/* Hangs in the margin, so the eye finds the start of a section without a
   rule across the page. */
section > h2::before {
  content: "";
  position: absolute;
  left: -28px;
  top: .62em;
  width: 14px;
  height: 2px;
  background: var(--verd);
}

.lead {
  margin: 0 0 22px;
  max-width: var(--measure);
  font-size: 1.1rem;
  color: var(--ink-2);
}

main p { max-width: var(--measure); }

/* --------------------------------------------------------------- steps. */

ol.steps {
  margin: 0 0 26px;
  padding: 0;
  list-style: none;
  counter-reset: step;
}

ol.steps li {
  position: relative;
  margin: 0 0 16px;
  padding-left: 46px;
  max-width: var(--measure);
  counter-increment: step;
}

ol.steps li::before {
  content: counter(step);
  position: absolute;
  left: 0;
  top: 2px;
  width: 28px;
  height: 28px;
  border: 1px solid var(--rule);
  border-radius: 50%;
  background: var(--surface);
  color: var(--verd-ink);
  font-family: var(--sans);
  font-size: .82rem;
  font-weight: 700;
  text-align: center;
  line-height: 26px;
}

ol.steps strong {
  display: block;
  font-family: var(--sans);
  font-size: 1rem;
  font-weight: 700;
  letter-spacing: -0.012em;
}

ol.steps span { color: var(--ink-2); }

/* -------------------------------------------------------------- points. */

ul.points { margin: 0 0 24px; padding: 0; list-style: none; }

ul.points li {
  position: relative;
  margin: 0 0 11px;
  padding-left: 22px;
  max-width: var(--measure);
  color: var(--ink-2);
}

ul.points li::before {
  content: "";
  position: absolute;
  left: 2px;
  top: .82em;
  width: 7px;
  height: 1px;
  background: var(--verd);
}

/* --------------------------------------------------------------- table.
   The one loud thing on the page. The column that is yours is raised out of
   the sheet and the two that are not recede, because that difference is the
   whole argument the section is making. */

.table-wrap { margin: 8px 0 26px; overflow-x: auto; padding: 4px 4px 8px; }

table {
  border-collapse: separate;
  border-spacing: 0;
  width: 100%;
  min-width: 620px;
  font-family: var(--sans);
  font-size: .92rem;
  line-height: 1.5;
}

caption {
  margin-bottom: 14px;
  font-family: var(--sans);
  font-size: .95rem;
  font-weight: 700;
  letter-spacing: -0.012em;
  text-align: left;
  color: var(--ink);
}

th, td { padding: 12px 16px; text-align: left; vertical-align: top; }

thead th {
  font-size: .84rem;
  font-weight: 600;
  color: var(--muted);
  border-bottom: 1px solid var(--rule);
}

tbody th {
  font-weight: 600;
  color: var(--ink);
  border-bottom: 1px solid var(--rule-soft);
}

tbody td {
  color: var(--muted);
  border-bottom: 1px solid var(--rule-soft);
}

tbody tr:last-child th,
tbody tr:last-child td { border-bottom: 0; }

/* The raised column. */
thead th:nth-child(2) {
  background: var(--verd);
  color: #fff;
  font-weight: 700;
  border-bottom: 0;
  border-radius: 3px 3px 0 0;
}

tbody td:nth-of-type(1) {
  background: var(--surface);
  color: var(--ink);
  font-weight: 600;
  box-shadow: var(--shadow-lift);
}

tbody tr:last-child td:nth-of-type(1) { border-radius: 0 0 3px 3px; }

/* ----------------------------------------------------------------- faq. */

.faq h3 {
  margin: 30px 0 8px;
  max-width: var(--measure);
  font-family: var(--sans);
  font-size: 1.06rem;
  font-weight: 700;
  letter-spacing: -0.014em;
  scroll-margin-top: 28px;
}

.faq p { margin: 0 0 12px; color: var(--ink-2); }

/* -------------------------------------------------------------- footer. */

footer.foot {
  padding: 30px 0 72px;
  border-top: 1px solid var(--rule);
  font-family: var(--sans);
  font-size: .9rem;
  color: var(--muted);
}

footer.foot p { margin: 0 0 6px; max-width: var(--measure); }

/* ---------------------------------------------------------- responsive. */

@media (max-width: 900px) {
  .layout { grid-template-columns: 1fr; gap: 30px; padding-top: 36px; }

  nav.toc {
    position: static;
    max-height: none;
    padding: 0 0 22px;
    border-left: 0;
    border-bottom: 1px solid var(--rule);
  }

  nav.toc ol { display: flex; flex-wrap: wrap; gap: 4px 16px; }
  nav.toc a { padding-left: 0; }
  nav.toc a::before { display: none; }

  section > h2::before { display: none; }

  .doors { grid-template-columns: 1fr; }
  .hero { padding: 56px 0 44px; }
}

@media (prefers-reduced-motion: reduce) {
  html { scroll-behavior: auto; }
  * { transition: none !important; }
}
"""

# The only script on the page, and it does one thing: mark the section you
# are reading against the rail. Everything else is static.
#
# A scroll handler rather than an IntersectionObserver. The observer is the
# tidier tool and was the first attempt, but its callbacks are delivered at
# the browser's discretion and are throttled in a backgrounded or automated
# tab, which makes the behaviour untestable — and something that cannot be
# checked is something that quietly stops working. This reads positions when
# asked, so it can be called directly and its answer looked at.
JS = """
(function () {
  var rail = [].slice.call(document.querySelectorAll('nav.toc a[href^="#"]'));

  if (!rail.length) { return; }

  var items = rail.map(function (a) {
    return { link: a, target: document.getElementById(a.getAttribute('href').slice(1)) };
  }).filter(function (item) { return item.target; });

  var here = null;

  function mark() {
    // The last section that has passed a line a fifth of the way down: the
    // one being read, rather than the one nearest the top of the window.
    var line = window.innerHeight * 0.2;
    var found = null;

    for (var i = 0; i < items.length; i++) {
      if (items[i].target.getBoundingClientRect().top <= line) { found = items[i]; }
    }

    if (found === here) { return; }

    if (here) { here.link.classList.remove('is-here'); }

    if (found) { found.link.classList.add('is-here'); }

    here = found;
  }

  var waiting = false;

  function onScroll() {
    if (waiting) { return; }

    waiting = true;

    window.requestAnimationFrame(function () {
      mark();
      waiting = false;
    });
  }

  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll, { passive: true });

  // Exposed so the behaviour can be driven and checked rather than assumed.
  window.bcMarkSection = mark;

  mark();
}());
"""


def para(text):
    return '<p>' + escape(text).replace('`', '') + '</p>'


def build(content):
    sections = content['sections']
    faq = content['faq']

    out = []
    out.append('<!doctype html>')
    out.append('<html lang="en">')
    out.append('<head>')
    out.append('<meta charset="utf-8">')
    out.append('<meta name="viewport" content="width=device-width, initial-scale=1">')
    out.append('<title>' + escape(TITLE) + '</title>')
    out.append('<meta name="description" content="' + escape(LEAD) + '">')
    out.append('<meta name="color-scheme" content="light dark">')
    out.append('<style>' + CSS + '</style>')

    schema = {
        '@context': 'https://schema.org',
        '@type': 'TechArticle',
        'headline': TITLE,
        'description': LEAD,
        'about': {
            '@type': 'SoftwareApplication',
            'name': NAME,
            'applicationCategory': 'BrowserApplication',
            'operatingSystem': 'WordPress',
        },
    }
    out.append('<script type="application/ld+json">'
               + json.dumps(schema, ensure_ascii=False) + '</script>')
    out.append('</head>')
    out.append('<body>')

    # Hero: what this is, and the three doors somebody actually comes in by.
    out.append('<header class="hero"><div class="wrap">')
    out.append('<p class="eyebrow">' + escape(NAME) + '</p>')
    out.append('<h1>Documentation</h1>')
    out.append('<p class="lead-in">' + escape(LEAD) + '</p>')

    out.append('<ul class="doors">')

    for anchor, title, line in DOORS:
        out.append('<li><a href="#' + anchor + '">'
                   + '<strong>' + escape(title) + '</strong>'
                   + '<span>' + escape(line) + '</span></a></li>')

    out.append('</ul>')
    out.append('</div></header>')

    out.append('<div class="wrap"><div class="layout">')

    out.append('<nav class="toc" aria-label="Contents">')
    out.append('<h2>Contents</h2><ol>')

    for one in sections:
        out.append('<li><a href="#' + one['id'] + '">' + escape(one['title']) + '</a></li>')

    out.append('<li><a href="#faq">Common questions</a></li>')
    out.append('</ol></nav>')

    out.append('<main>')

    for one in sections:
        out.append('<section id="' + one['id'] + '">')
        out.append('<h2>' + escape(one['title']) + '</h2>')

        if one['lead']:
            out.append('<p class="lead">' + escape(one['lead']) + '</p>')

        for text in one['prose']:
            out.append(para(text))

        if one['steps']:
            out.append('<ol class="steps">')

            for step in one['steps']:
                out.append('<li><strong>' + escape(step['title']) + '</strong>'
                           + '<span>' + escape(step['text']) + '</span></li>')

            out.append('</ol>')

        if one['points']:
            out.append('<ul class="points">')

            for point in one['points']:
                out.append('<li>' + escape(point) + '</li>')

            out.append('</ul>')

        table = one.get('table')

        if table:
            out.append('<div class="table-wrap"><table>')
            out.append('<caption>' + escape(table['caption']) + '</caption>')
            out.append('<thead><tr>')

            for cell in table['head']:
                out.append('<th scope="col">' + escape(cell) + '</th>')

            out.append('</tr></thead><tbody>')

            for row in table['rows']:
                out.append('<tr><th scope="row">' + escape(row[0]) + '</th>')

                for cell in row[1:]:
                    out.append('<td>' + escape(cell) + '</td>')

                out.append('</tr>')

            out.append('</tbody></table></div>')

        out.append('</section>')

    out.append('<section id="faq" class="faq">')
    out.append('<h2>Common questions</h2>')

    for item in faq:
        anchor = 'q-' + ''.join(
            c if c.isalnum() else '-' for c in item['q'].lower()
        ).strip('-')

        while '--' in anchor:
            anchor = anchor.replace('--', '-')

        out.append('<h3 id="' + anchor + '">' + escape(item['q']) + '</h3>')

        for text in item['a']:
            out.append(para(text))

    out.append('</section>')
    out.append('</main>')
    out.append('</div></div>')

    out.append('<footer class="foot"><div class="wrap">')
    out.append('<p>' + escape(NAME) + ' is free software, released under the GPL.</p>')
    out.append('<p>The plugin links into this page by section, so these '
               'addresses stay where they are.</p>')
    out.append('</div></footer>')

    out.append('<script>' + JS + '</script>')
    out.append('</body></html>')

    return '\n'.join(out)


def main():
    if not os.path.exists(CONTENT):
        sys.exit('no ' + CONTENT)

    content = json.load(io.open(CONTENT, encoding='utf-8'))
    html = build(content)

    io.open(TARGET, 'w', encoding='utf-8', newline='\n').write(html)

    ids = [s['id'] for s in content['sections']] + ['faq']

    print('wrote %s' % os.path.relpath(TARGET, HERE))
    print('%d sections, %d questions, %d bytes'
          % (len(content['sections']), len(content['faq']), len(html)))
    print('anchors: ' + ' '.join(ids))


if __name__ == '__main__':
    main()
