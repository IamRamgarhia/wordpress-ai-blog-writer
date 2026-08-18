"""Generate languages/blogcraft.pot from the plugin source.

WP-CLI's i18n command is the usual tool for this, but it is not available on
every machine that builds this plugin, and a translation template that only
one person can regenerate goes stale. This scans for the gettext calls the
plugin actually uses, carries translators comments through, and reports any
call missing the text domain — which is the failure Plugin Check flags and
the one a template alone would hide.
"""

import io
import os
import re
import sys
from collections import OrderedDict

SRC = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOMAIN = 'blogcraft'
OUT = os.path.join(SRC, 'languages', 'blogcraft.pot')

# Each entry: function name -> how many leading string literals are messages,
# and whether the call carries a context string.
FUNCTIONS = {
    '__': (1, False),
    '_e': (1, False),
    'esc_html__': (1, False),
    'esc_html_e': (1, False),
    'esc_attr__': (1, False),
    'esc_attr_e': (1, False),
    'esc_textarea__': (1, False),
    '_n': (2, False),
    '_x': (1, True),
    '_ex': (1, True),
    'esc_html_x': (1, True),
    'esc_attr_x': (1, True),
    '_nx': (2, True),
}

CALL = re.compile(r'(?<![\w$>])(' + '|'.join(sorted(FUNCTIONS, key=len, reverse=True)) + r')\s*\(')
TRANSLATORS = re.compile(r'/\*\s*(translators:.*?)\*/', re.S | re.I)


def read_literal(text, pos):
    """Read one PHP single- or double-quoted literal starting at or after pos.

    Returns (value, end_index), or (None, pos) when the next non-space
    character does not begin a string.
    """
    while pos < len(text) and text[pos] in ' \t\r\n':
        pos += 1

    if pos >= len(text) or text[pos] not in ("'", '"'):
        return None, pos

    quote = text[pos]
    pos += 1
    out = []

    while pos < len(text):
        char = text[pos]

        if char == '\\' and pos + 1 < len(text):
            nxt = text[pos + 1]
            if quote == "'":
                # Only \' and \\ are escapes in a single-quoted PHP string.
                out.append(nxt if nxt in ("'", '\\') else char + nxt)
            else:
                out.append({'n': '\n', 't': '\t', 'r': '\r'}.get(nxt, nxt))
            pos += 2
            continue

        if char == quote:
            return ''.join(out), pos + 1

        out.append(char)
        pos += 1

    return None, pos


def skip_to_next_argument(text, pos):
    """Advance past one comma at the current nesting level."""
    depth = 0

    while pos < len(text):
        char = text[pos]

        if char in '([':
            depth += 1
        elif char in ')]':
            if depth == 0:
                return None
            depth -= 1
        elif char == ',' and depth == 0:
            return pos + 1
        elif char in ("'", '"'):
            _, pos = read_literal(text, pos)
            continue

        pos += 1

    return None


def escape(value):
    value = value.replace('\\', '\\\\').replace('"', '\\"')
    return value.replace('\n', '\\n').replace('\t', '\\t').replace('\r', '')


def php_files():
    for entry in ('blogcraft.php', 'uninstall.php'):
        path = os.path.join(SRC, entry)
        if os.path.isfile(path):
            yield path

    for folder, dirs, names in os.walk(os.path.join(SRC, 'includes')):
        dirs[:] = [d for d in dirs if d != 'vendor']
        for name in sorted(names):
            if name.endswith('.php'):
                yield os.path.join(folder, name)


def scan():
    entries = OrderedDict()
    missing = []

    for path in php_files():
        text = io.open(path, encoding='utf-8').read()
        rel = os.path.relpath(path, SRC).replace(os.sep, '/')
        line_starts = [0]
        for match in re.finditer('\n', text):
            line_starts.append(match.end())

        def line_of(offset):
            low, high = 0, len(line_starts) - 1
            while low < high:
                mid = (low + high + 1) // 2
                if line_starts[mid] <= offset:
                    low = mid
                else:
                    high = mid - 1
            return low + 1

        for match in CALL.finditer(text):
            name = match.group(1)
            count, has_context = FUNCTIONS[name]
            pos = match.end()

            messages = []
            for _ in range(count):
                value, pos = read_literal(text, pos)
                if value is None:
                    break
                messages.append(value)
                nxt = skip_to_next_argument(text, pos)
                if nxt is None:
                    break
                pos = nxt

            if len(messages) != count:
                continue

            context = None
            if has_context:
                # _n/_nx put the count between the plurals and the context.
                if name == '_nx':
                    nxt = skip_to_next_argument(text, pos)
                    if nxt is None:
                        continue
                    pos = nxt
                context, pos = read_literal(text, pos)
                if context is None:
                    continue
                nxt = skip_to_next_argument(text, pos)
                if nxt is None:
                    continue
                pos = nxt
            elif name == '_n':
                nxt = skip_to_next_argument(text, pos)
                if nxt is None:
                    continue
                pos = nxt

            domain, _ = read_literal(text, pos)
            line = line_of(match.start())

            if domain != DOMAIN:
                missing.append('%s:%d  %s( %r ) domain=%r' % (rel, line, name, messages[0], domain))
                continue

            comment = None
            window = text[max(0, match.start() - 400):match.start()]
            found = TRANSLATORS.findall(window)
            if found:
                comment = ' '.join(found[-1].split())

            key = (context, messages[0], messages[1] if count > 1 else None)
            entry = entries.setdefault(key, {'refs': [], 'comment': None})
            entry['refs'].append('%s:%d' % (rel, line))
            if comment and not entry['comment']:
                entry['comment'] = comment

    return entries, missing


def write(entries):
    folder = os.path.dirname(OUT)
    if not os.path.isdir(folder):
        os.makedirs(folder)

    out = [
        '# Copyright (C) Dicecodes',
        '# This file is distributed under the GPL-2.0-or-later license.',
        'msgid ""',
        'msgstr ""',
        '"Project-Id-Version: Blogcraft\\n"',
        '"Report-Msgid-Bugs-To: https://dicecodes.com/\\n"',
        '"MIME-Version: 1.0\\n"',
        '"Content-Type: text/plain; charset=UTF-8\\n"',
        '"Content-Transfer-Encoding: 8bit\\n"',
        '"Plural-Forms: nplurals=2; plural=(n != 1);\\n"',
        '"X-Domain: %s\\n"' % DOMAIN,
        '',
    ]

    for (context, singular, plural), entry in entries.items():
        if entry['comment']:
            out.append('#. %s' % entry['comment'])
        for ref in entry['refs']:
            out.append('#: %s' % ref)
        if context is not None:
            out.append('msgctxt "%s"' % escape(context))
        out.append('msgid "%s"' % escape(singular))
        if plural is not None:
            out.append('msgid_plural "%s"' % escape(plural))
            out.append('msgstr[0] ""')
            out.append('msgstr[1] ""')
        else:
            out.append('msgstr ""')
        out.append('')

    io.open(OUT, 'w', encoding='utf-8', newline='\n').write('\n'.join(out))


def main():
    entries, missing = scan()
    write(entries)

    print('%s  %d strings' % (os.path.relpath(OUT, SRC).replace(os.sep, '/'), len(entries)))

    if missing:
        print('\n%d call(s) without the "%s" text domain:' % (len(missing), DOMAIN))
        for item in missing:
            print('  ' + item)
        sys.exit(1)

    print('every translatable call carries the text domain')


main()
