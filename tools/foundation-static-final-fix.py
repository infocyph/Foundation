from pathlib import Path

p = Path('tools/foundation-static-final.py')
s = p.read_text()
s = s.replace(
    '    /** @param array<array-key, mixed> $entry @return array<string, mixed> */',
    '    /**\n     * @param array<array-key, mixed> $entry\n     * @return array<string, mixed>\n     */',
)
p.write_text(s)
