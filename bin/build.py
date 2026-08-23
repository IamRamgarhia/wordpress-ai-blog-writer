"""Build the distributable plugin zip.

PowerShell's Compress-Archive writes Windows path separators into the archive,
which the zip format does not permit. WordPress then finds no top-level folder
and installs the plugin under a second directory instead of updating the first
one. zipfile writes them correctly; this verifies that before finishing.
"""

import io
import os
import re
import sys
import zipfile

SRC = 'D:/calude/Wordpress plugin - blog writing'
ROOTS = ['blogcraft.php', 'uninstall.php', 'readme.txt', 'includes', 'assets', 'languages', 'data']


def version():
    header = io.open(os.path.join(SRC, 'blogcraft.php'), encoding='utf-8').read()
    match = re.search(r'^\s*\*\s*Version:\s*(\S+)', header, re.M)
    if not match:
        sys.exit('no version header in blogcraft.php')
    return match.group(1)


def files():
    for entry in ROOTS:
        path = os.path.join(SRC, entry)
        if os.path.isfile(path):
            yield path, 'blogcraft/' + entry
            continue
        if not os.path.isdir(path):
            continue
        for folder, _, names in os.walk(path):
            for name in sorted(names):
                full = os.path.join(folder, name)
                rel = os.path.relpath(full, SRC).replace(os.sep, '/')
                yield full, 'blogcraft/' + rel


def main():
    out = os.path.join(SRC, 'blogcraft-%s.zip' % version())

    if os.path.exists(out):
        os.remove(out)

    added = 0
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as archive:
        for full, arcname in files():
            archive.write(full, arcname)
            added += 1

    with zipfile.ZipFile(out) as archive:
        names = archive.namelist()
        for name in names:
            if '\\' in name:
                sys.exit('backslash in: ' + name)
            if not name.startswith('blogcraft/'):
                sys.exit('outside the plugin folder: ' + name)
        bad = archive.testzip()
        if bad:
            sys.exit('corrupt entry: ' + bad)

    print('%s  %d files  %d bytes' % (os.path.basename(out), added, os.path.getsize(out)))
    print('first entry: ' + names[0])


main()
