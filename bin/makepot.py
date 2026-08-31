"""Regenerate languages/dicecodes-ai-blog-writer.pot from the source.

The .pot file had drifted badly: 313 of 802 translatable strings were absent,
because it was last written by hand-run tooling ten releases ago. A languages
folder that carries 61% of the plugin is a promise half kept, and nothing warns
you — a translator simply finds the screen half in English.

This is deliberately small and exact rather than clever. It understands the
five call shapes this plugin actually uses and nothing else, so its output is
predictable and a drift check in CI can compare byte for byte.

    python bin/makepot.py          # rewrite the file
    python bin/makepot.py --check  # exit 1 if it would change
"""
import io
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
POT = os.path.join(ROOT, 'languages', 'dicecodes-ai-blog-writer.pot')
DOMAIN = 'dicecodes-ai-blog-writer'

HEADER = '''# Copyright (C) Dicecodes
# This file is distributed under the GPL-2.0-or-later license.
msgid ""
msgstr ""
"Project-Id-Version: Blogcraft\\n"
"Report-Msgid-Bugs-To: https://dicecodes.com/\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Plural-Forms: nplurals=2; plural=(n != 1);\\n"
"X-Domain: blogcraft\\n"
'''

# A single-quoted PHP string, allowing escaped quotes and backslashes.
STR = r"'((?:[^'\\]|\\.)*)'"

SINGULAR = re.compile(
    r"\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*"
    + STR
    + r"\s*,\s*'" + DOMAIN + r"'\s*\)"
)

PLURAL = re.compile(
    r"\b_n\(\s*" + STR + r"\s*,\s*" + STR
    + r"\s*,\s*[^,]+,\s*'" + DOMAIN + r"'\s*\)"
)

# A translator note sits on the line or lines directly above the call.
NOTE = re.compile(r"/\*\s*(translators:.*?)\s*\*/", re.S)


def php_files():
    out = []
    for name in sorted(os.listdir(os.path.join(ROOT, 'includes'))):
        if name.endswith('.php'):
            out.append('includes/' + name)
    for name in ('blogcraft.php', 'uninstall.php'):
        if os.path.exists(os.path.join(ROOT, name)):
            out.append(name)
    return out


def unescape(text):
    """PHP single-quoted string to its real value."""
    return text.replace("\\\\", "\x00").replace("\\'", "'").replace("\x00", "\\")


def escape(text):
    """Real value to a .po double-quoted string."""
    return (
        text.replace("\\", "\\\\")
        .replace('"', '\\"')
        .replace("\t", "\\t")
        .replace("\n", "\\n")
    )


def note_above(body, at):
    """The translator comment immediately preceding an offset, if any."""
    window = body[max(0, at - 400):at]
    hits = NOTE.findall(window)

    if not hits:
        return ''

    # Only if nothing but whitespace and comment markers sit between them.
    tail = window[window.rfind('/*'):]
    after = tail[tail.find('*/') + 2:]

    if after.strip(' \t\r\n'):
        return ''

    return ' '.join(hits[-1].split())


def collect():
    """Every string, keyed by (singular, plural) with its references."""
    entries = {}

    for rel in php_files():
        body = io.open(os.path.join(ROOT, rel), encoding='utf-8').read()
        lines = body.count('\n') + 1
        starts = [0]
        for line in body.split('\n')[:-1]:
            starts.append(starts[-1] + len(line) + 1)

        def line_of(offset):
            low, high = 0, len(starts) - 1
            while low < high:
                mid = (low + high + 1) // 2
                if starts[mid] <= offset:
                    low = mid
                else:
                    high = mid - 1
            return low + 1

        for match in SINGULAR.finditer(body):
            key = (unescape(match.group(1)), None)
            entry = entries.setdefault(key, {'refs': [], 'note': ''})
            entry['refs'].append('%s:%d' % (rel, line_of(match.start())))
            entry['note'] = entry['note'] or note_above(body, match.start())

        for match in PLURAL.finditer(body):
            key = (unescape(match.group(1)), unescape(match.group(2)))
            entry = entries.setdefault(key, {'refs': [], 'note': ''})
            entry['refs'].append('%s:%d' % (rel, line_of(match.start())))
            entry['note'] = entry['note'] or note_above(body, match.start())

    return entries


def render(entries):
    out = [HEADER]

    for (single, plural) in sorted(entries, key=lambda k: (k[0], k[1] or '')):
        entry = entries[(single, plural)]
        block = []

        if entry['note']:
            block.append('#. ' + entry['note'])

        for ref in sorted(set(entry['refs'])):
            block.append('#: ' + ref)

        block.append('msgid "%s"' % escape(single))

        if plural is None:
            block.append('msgstr ""')
        else:
            block.append('msgid_plural "%s"' % escape(plural))
            block.append('msgstr[0] ""')
            block.append('msgstr[1] ""')

        out.append('\n'.join(block))

    return '\n\n'.join(out) + '\n'


def main():
    text = render(collect())
    check = '--check' in sys.argv

    existing = ''
    if os.path.exists(POT):
        existing = io.open(POT, encoding='utf-8', newline='').read()

    if check:
        if existing == text:
            print('languages/dicecodes-ai-blog-writer.pot is current.')
            return 0

        print('languages/dicecodes-ai-blog-writer.pot is out of date. Run: python bin/makepot.py')
        return 1

    io.open(POT, 'w', encoding='utf-8', newline='\n').write(text)
    print('wrote %d entries to languages/dicecodes-ai-blog-writer.pot' % text.count('\nmsgid "'))
    return 0


if __name__ == '__main__':
    sys.exit(main())
