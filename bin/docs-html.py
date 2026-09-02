"""Build the published documentation page from docs/content.json.

The plugin used to carry its own copy of all this, on a Help screen and
behind a "How this works" control on every card. It now links here instead,
so this file is the only copy and the anchors have to keep matching: a help
link in the plugin is this page's URL plus the section id, and nothing warns
you when one of them moves.

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

TITLE = 'Dicecodes AI Blog Writer - Documentation'
LEAD = (
    'Everything the plugin does, why each control is there, and what it '
    'costs you. Free, bring your own key, nothing held back.'
)

CSS = """
:root {
  color-scheme: light dark;
  --ink: #16181d;
  --ink-soft: #4a5058;
  --muted: #6b7280;
  --bg: #ffffff;
  --bg-soft: #f7f8fa;
  --line: #e3e6ea;
  --accent: #3a5bd9;
  --accent-soft: #eef2fe;
  --measure: 68ch;
}

@media (prefers-color-scheme: dark) {
  :root {
    --ink: #e8eaed;
    --ink-soft: #b8bec7;
    --muted: #8b929c;
    --bg: #14161a;
    --bg-soft: #1b1e24;
    --line: #2b2f37;
    --accent: #8fa6ff;
    --accent-soft: #1e2436;
  }
}

* { box-sizing: border-box; }

body {
  margin: 0;
  background: var(--bg);
  color: var(--ink);
  font: 16px/1.65 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
        "Helvetica Neue", Arial, sans-serif;
  -webkit-font-smoothing: antialiased;
}

a { color: var(--accent); }

.wrap {
  max-width: 1080px;
  margin: 0 auto;
  padding: 0 24px;
}

header.top {
  border-bottom: 1px solid var(--line);
  background: var(--bg-soft);
  padding: 56px 0 40px;
}

header.top h1 {
  margin: 0 0 12px;
  font-size: 2.1rem;
  line-height: 1.2;
  letter-spacing: -0.02em;
}

header.top p {
  margin: 0;
  max-width: var(--measure);
  color: var(--ink-soft);
  font-size: 1.05rem;
}

.layout {
  display: grid;
  grid-template-columns: 240px minmax(0, 1fr);
  gap: 48px;
  padding: 40px 0 80px;
}

nav.toc {
  position: sticky;
  top: 24px;
  align-self: start;
  max-height: calc(100vh - 48px);
  overflow-y: auto;
  font-size: 0.9rem;
}

nav.toc h2 {
  margin: 0 0 10px;
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.09em;
  text-transform: uppercase;
  color: var(--muted);
}

nav.toc ol {
  margin: 0;
  padding: 0;
  list-style: none;
}

nav.toc li { margin: 0; }

nav.toc a {
  display: block;
  padding: 5px 10px;
  border-radius: 5px;
  color: var(--ink-soft);
  text-decoration: none;
}

nav.toc a:hover {
  background: var(--accent-soft);
  color: var(--accent);
}

main { min-width: 0; }

section {
  margin: 0 0 56px;
  scroll-margin-top: 20px;
}

section > h2 {
  margin: 0 0 8px;
  font-size: 1.5rem;
  letter-spacing: -0.015em;
}

.lead {
  margin: 0 0 20px;
  max-width: var(--measure);
  color: var(--ink-soft);
  font-size: 1.05rem;
}

main p {
  max-width: var(--measure);
}

ol.steps {
  margin: 0 0 22px;
  padding: 0;
  list-style: none;
  counter-reset: step;
}

ol.steps li {
  position: relative;
  margin: 0 0 12px;
  padding: 0 0 0 42px;
  max-width: var(--measure);
  counter-increment: step;
}

ol.steps li::before {
  content: counter(step);
  position: absolute;
  left: 0;
  top: 1px;
  width: 26px;
  height: 26px;
  border-radius: 50%;
  background: var(--accent-soft);
  color: var(--accent);
  font-size: 0.8rem;
  font-weight: 600;
  text-align: center;
  line-height: 26px;
}

ol.steps strong { display: block; }

ol.steps span { color: var(--ink-soft); }

ul.points {
  margin: 0 0 20px;
  padding: 0;
  list-style: none;
}

ul.points li {
  position: relative;
  margin: 0 0 9px;
  padding: 0 0 0 20px;
  max-width: var(--measure);
  color: var(--ink-soft);
}

ul.points li::before {
  content: "";
  position: absolute;
  left: 2px;
  top: 10px;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--line);
}

.table-wrap {
  margin: 0 0 20px;
  overflow-x: auto;
}

table {
  border-collapse: collapse;
  width: 100%;
  min-width: 560px;
  font-size: 0.92rem;
}

caption {
  margin-bottom: 10px;
  color: var(--muted);
  font-size: 0.8rem;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  text-align: left;
}

th, td {
  padding: 9px 12px;
  border-bottom: 1px solid var(--line);
  text-align: left;
  vertical-align: top;
}

thead th {
  border-bottom: 2px solid var(--line);
  font-size: 0.82rem;
  color: var(--muted);
}

thead th:nth-child(2) { color: var(--accent); }

tbody th {
  font-weight: 600;
  color: var(--ink);
}

tbody td { color: var(--ink-soft); }

tbody td:first-of-type { color: var(--ink); }

.faq h3 {
  margin: 26px 0 6px;
  font-size: 1.05rem;
  scroll-margin-top: 20px;
}

.faq p {
  margin: 0 0 10px;
  color: var(--ink-soft);
}

footer.foot {
  border-top: 1px solid var(--line);
  padding: 28px 0 60px;
  color: var(--muted);
  font-size: 0.9rem;
}

footer.foot p { margin: 0 0 6px; }

code {
  padding: 1px 5px;
  border-radius: 4px;
  background: var(--bg-soft);
  border: 1px solid var(--line);
  font-size: 0.9em;
}

@media (max-width: 860px) {
  .layout {
    grid-template-columns: 1fr;
    gap: 28px;
  }

  nav.toc {
    position: static;
    max-height: none;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--line);
  }

  nav.toc ol {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
  }

  header.top { padding: 40px 0 32px; }
  header.top h1 { font-size: 1.7rem; }
}
"""


def para(text):
    """One paragraph, with the plugin's own inline code left readable."""
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
    out.append('<style>' + CSS + '</style>')

    # Describes the page for anything reading it as data. Deliberately one
    # small graph rather than a pile of types: this is an article about how
    # a piece of software works, and saying so once is enough.
    schema = {
        '@context': 'https://schema.org',
        '@type': 'TechArticle',
        'headline': TITLE,
        'description': LEAD,
        'about': {'@type': 'SoftwareApplication', 'name': 'Dicecodes AI Blog Writer',
                  'applicationCategory': 'BrowserApplication',
                  'operatingSystem': 'WordPress'},
    }
    out.append('<script type="application/ld+json">'
               + json.dumps(schema, ensure_ascii=False) + '</script>')
    out.append('</head>')
    out.append('<body>')

    out.append('<header class="top"><div class="wrap">')
    out.append('<h1>' + escape(TITLE) + '</h1>')
    out.append('<p>' + escape(LEAD) + '</p>')
    out.append('</div></header>')

    out.append('<div class="wrap"><div class="layout">')

    # Contents.
    out.append('<nav class="toc" aria-label="Contents">')
    out.append('<h2>On this page</h2><ol>')

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
            # Wrapped, because a four-column comparison does not fit a phone
            # and a table that widens the page breaks every section above it.
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

    # Questions.
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
    out.append('<p>Dicecodes AI Blog Writer is free software, released under the GPL.</p>')
    out.append('<p>This page is the documentation the plugin links to. '
               'Section addresses are used by the plugin itself, so they do not change.</p>')
    out.append('</div></footer>')

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
