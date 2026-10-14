#!/usr/bin/env bash
set -euo pipefail
git fetch --depth=1 --no-tags origin refs/tags/2.1.1
git ls-tree -r --name-only FETCH_HEAD -- src | grep "[.]php" | LC_ALL=C sort > "$RUNNER_TEMP/foundation-old-php.txt"
git ls-tree -r --name-only HEAD -- src | grep "[.]php" | LC_ALL=C sort > "$RUNNER_TEMP/foundation-new-php.txt"
comm -23 "$RUNNER_TEMP/foundation-old-php.txt" "$RUNNER_TEMP/foundation-new-php.txt" > "$RUNNER_TEMP/foundation-removed-php.txt"
count="$(wc -l < "$RUNNER_TEMP/foundation-removed-php.txt")"
if [ "$count" -ne 28 ]; then
  echo "Unexpected 2.1.1 removed API count: $count (expected 28)" >&2
  exit 1
fi
while IFS= read -r path; do
  symbol="${path#src/}"
  symbol="${symbol%.php}"
  if ! grep -Fq "$symbol" docs/foundation-4-api-map.md; then
    echo "Missing 2.1.1 API mapping for: $path" >&2
    exit 1
  fi
done < "$RUNNER_TEMP/foundation-removed-php.txt"
echo "Verified all 28 published Foundation 2.1.1 removed PHP source paths."
