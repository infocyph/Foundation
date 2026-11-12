#!/usr/bin/env python3
"""Syntax-check complete PHP code-blocks in the Infbyte Sphinx manual.

Only blocks beginning with an explicit <?php are treated as standalone
PHP source. Contextual fragments remain examples, not runnable files.
"""

from __future__ import annotations

import subprocess
import sys
import tempfile
import textwrap
from pathlib import Path

DOCS = Path(__file__).resolve().parents[1]
DIRECTIVE = ".. code-block:: php"


def examples(path: Path):
    lines = path.read_text(encoding="utf-8").splitlines()
    for index, line in enumerate(lines):
        if line.strip() != DIRECTIVE:
            continue

        base_indent = len(line) - len(line.lstrip())
        body = []
        cursor = index + 1
        while cursor < len(lines):
            current = lines[cursor]
            indent = len(current) - len(current.lstrip())
            if current.strip() and indent <= base_indent:
                break
            body.append(current)
            cursor += 1

        # Sphinx directive options are not PHP program statements.
        while body and (not body[0].strip() or body[0].lstrip().startswith(":")):
            body.pop(0)

        php = textwrap.dedent("\n".join(body)).strip()
        if php.startswith("<?php"):
            yield index + 1, php + "\n"


def main() -> int:
    checked = 0
    errors = []
    with tempfile.TemporaryDirectory(prefix="infbyte-php-examples-") as directory:
        temporary = Path(directory) / "snippet.php"
        for page in sorted(DOCS.rglob("*.rst")):
            for line, php in examples(page):
                checked += 1
                temporary.write_text(php, encoding="utf-8")
                result = subprocess.run(
                    ["php", "-l", str(temporary)],
                    text=True,
                    capture_output=True,
                    check=False,
                )
                if result.returncode != 0:
                    errors.append(f"{page.relative_to(DOCS)}:{line}\n"
                                  f"{result.stdout}{result.stderr}")
    for error in errors:
        print(error, file=sys.stderr)
    print(f"PHP RST blocks syntax-checked: {checked}; failed: {len(errors)}")
    if checked == 0:
        print("No complete PHP blocks found; check documentation extraction.", file=sys.stderr)
        return 1
    return 1 if errors else 0


if __name__ == "__main__":
    raise SystemExit(main())
