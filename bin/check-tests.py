"""Decide whether a PHPUnit run actually passed.

The test job reported success over five failing tests, for two reasons that
compounded:

wp-env truncates the container's stdout, so PHPUnit's summary never reached
the log — the last thing visible was a progress line, and a progress line
looks like progress whether or not there is an E in it.

And a test was requiring uninstall.php, which had just been tightened to exit
unless WordPress is genuinely uninstalling. `exit` ends PHP with status 0. So
the run stopped two thirds of the way through, printed no summary, wrote no
report, and returned success.

An exit code is therefore not enough by itself. This reads the JUnit report,
which exists only if the run reached the end, and says how many tests ran and
what became of them.

Read with a regular expression rather than an XML parser: the only thing
needed is the counts on the first testsuite element, and the stdlib parsers
resolve external entities by default. The file is ours, but a parser that can
be talked into fetching a URL has no business being reached for when the job
is to read four integers.

    python bin/check-tests.py junit.xml
"""
import io
import os
import re
import sys

MINIMUM = 400

COUNTS = re.compile(
    r'<testsuite\b[^>]*\btests="(\d+)"[^>]*\berrors="(\d+)"[^>]*'
    r'\bfailures="(\d+)"[^>]*\bskipped="(\d+)"'
)

CASE = re.compile(
    r'<testcase\b[^>]*\bname="([^"]*)"[^>]*\bclass="([^"]*)"[^>]*?>\s*<(failure|error)\b[^>]*>',
    re.S,
)


def main():
    path = sys.argv[1] if len(sys.argv) > 1 else 'junit.xml'

    if not os.path.exists(path) or os.path.getsize(path) == 0:
        sys.exit(
            'No test report at ' + path + '. PHPUnit did not reach the end of the '
            'run — most often that means something under test called exit or die, '
            'which ends PHP with status 0 and looks like success.'
        )

    report = io.open(path, encoding='utf-8', errors='replace').read()
    found = COUNTS.search(report)

    if not found:
        sys.exit('The test report has no counts on it. It is not a JUnit report.')

    tests, errors, failures, skipped = (int(n) for n in found.groups())

    if not tests:
        sys.exit('The test report counts no tests at all.')

    # A report suddenly holding a fraction of the suite means the run was cut
    # short somewhere this cannot otherwise see.
    if tests < MINIMUM:
        sys.exit(
            'Only %d tests ran, and there should be at least %d. The run was cut short.'
            % (tests, MINIMUM)
        )

    print(
        '%d tests, %d failures, %d errors, %d skipped'
        % (tests, failures, errors, skipped)
    )

    if failures or errors:
        for name, class_name, kind in CASE.findall(report):
            print('  %s  %s::%s' % (kind, class_name, name))

        sys.exit('%d test(s) did not pass.' % (failures + errors))

    print('All tests passed.')


main()
