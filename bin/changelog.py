"""Keep readme.txt's changelog in step with changelog.txt.

changelog.txt holds every release. readme.txt holds as many of the newest as
fit under the readme parser's 5000-character section ceiling, which is a real
limit rather than a style preference: over it, wordpress.org truncates the
section and shows a changelog cut off mid-sentence.

Splitting it by hand is how it drifts, so the split is computed. changelog.txt
is the file you edit; this copies the top of it across.

    python bin/changelog.py          # rewrite the readme section
    python bin/changelog.py --check  # exit 1 if it would change
"""
import io
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FULL = os.path.join(ROOT, 'changelog.txt')
README = os.path.join(ROOT, 'readme.txt')

# The parser's ceiling, less room for the pointer line and a margin. Landing
# exactly on 5000 is a warning, not a pass.
CEILING = 5000
MARGIN = 200

POINTER = 'Older releases are listed in changelog.txt, which ships with the plugin.'


def read(path):
    body = io.open(path, encoding='utf-8', newline='').read()

    return body, ('\r\n' if '\r\n' in body else '\n')


def releases(body):
    """Every release block in a changelog, newest first."""
    at = body.find('= ')
    parts = re.split(r'(?m)^(?== \d+\.\d+\.\d+ =$)', body[at:] if at >= 0 else body)

    return [p for p in parts if p.strip().startswith('= ')]


def build():
    full, eol = read(FULL)
    readme, _ = read(README)

    blocks = releases(full)

    if not blocks:
        sys.exit('changelog.txt has no releases in it')

    head = '== Changelog ==' + eol + eol
    tail = eol + POINTER + eol + eol
    kept, used = [], len(head) + len(tail)

    for block in blocks:
        if used + len(block) > CEILING - MARGIN:
            break

        kept.append(block)
        used += len(block)

    if not kept:
        sys.exit('the newest release alone is over the ceiling; shorten it')

    section = head + ''.join(kept).rstrip(eol) + tail

    match = re.search(
        r'^== Changelog ==$.*?(?=^== |\Z)',
        readme,
        re.S | re.M,
    )

    if not match:
        sys.exit('readme.txt has no changelog section')

    return readme[:match.start()] + section + readme[match.end():], len(kept), used


def main():
    wanted, kept, used = build()
    current, _ = read(README)

    if '--check' in sys.argv:
        if wanted != current:
            print('readme.txt changelog is out of step. Run: python bin/changelog.py')
            sys.exit(1)

        print('readme.txt changelog is current.')
        return

    io.open(README, 'w', encoding='utf-8', newline='').write(wanted)
    print('readme.txt carries %d releases, %d characters of %d' % (kept, used, CEILING))


main()
