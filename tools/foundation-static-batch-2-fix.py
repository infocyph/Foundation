from pathlib import Path

p = Path('tools/foundation-static-batch-2.py')
s = p.read_text()
s = s.replace("$configured = $definition['config'] ?? [];\\n        if (!is_array($configured) || $configured === []) {", "$configured = $definition['config'] ?? [];\\n        if (! is_array($configured) || $configured === []) {")
s = s.replace("            if (!is_string($filename) || $filename === '' || basename($filename) !== $filename) {", "            if (! is_string($filename) || $filename === '' || basename($filename) !== $filename) {")
p.write_text(s)
