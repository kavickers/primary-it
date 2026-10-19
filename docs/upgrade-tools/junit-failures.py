"""Group the failing tests in a PHPUnit JUnit report by their error message.

A few hundred failures after an upstream merge usually come from a handful of
causes; grouping by the (normalized) message shows which ones.
See docs/upgrading-from-upstream.md.

Usage:
    python junit-failures.py junit.xml            # grouped summary
    python junit-failures.py junit.xml --classes  # failing test classes, one per line
"""
import collections
import re
import sys
import xml.etree.ElementTree as ET

failures = []
for case in ET.parse(sys.argv[1]).getroot().iter('testcase'):
    for kind in ('failure', 'error'):
        element = case.find(kind)
        if element is None:
            continue
        lines = [line.strip() for line in (element.text or '').splitlines() if line.strip()]
        message = next((line for line in lines if 'SQLSTATE' in line or 'Exception' in line or 'Error' in line),
                       lines[0] if lines else '')
        failures.append((case.get('class') or case.get('classname') or '', case.get('name'), message[:400]))

if '--classes' in sys.argv:
    print('\n'.join(sorted({cls for cls, _, _ in failures})))
    sys.exit()


def normalize(message):
    return re.sub(r"'[^']*'", "'X'", re.sub(r'\d+', 'N', message))[:170]


groups = collections.defaultdict(list)
for cls, name, message in failures:
    groups[normalize(message)].append(cls.rsplit('\\', 1)[-1] + '::' + name)

print(f'{len(failures)} failing tests in {len(groups)} groups\n')
for message, tests in sorted(groups.items(), key=lambda item: -len(item[1])):
    print(len(tests), '|', message)
    print('     ', ', '.join(tests[:4]) + (' ...' if len(tests) > 4 else ''))
