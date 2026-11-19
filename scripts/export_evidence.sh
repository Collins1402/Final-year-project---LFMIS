#!/usr/bin/env bash
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT_DIR="$ROOT_DIR/docs/test-evidence"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKEND_OUTPUT="$OUTPUT_DIR/composer-test-$STAMP.txt"
PYTEST_OUTPUT="$OUTPUT_DIR/pytest-$STAMP.txt"

mkdir -p "$OUTPUT_DIR"

(
  cd "$ROOT_DIR/backend" || exit 1
  composer test
) 2>&1 | tee "$BACKEND_OUTPUT"
BACKEND_STATUS=${PIPESTATUS[0]}

(
  cd "$ROOT_DIR" || exit 1
  python -m pytest ml/tests -v
) 2>&1 | tee "$PYTEST_OUTPUT"
PYTEST_STATUS=${PIPESTATUS[0]}

printf '\nBackend evidence: %s\nPytest evidence: %s\n' "$BACKEND_OUTPUT" "$PYTEST_OUTPUT"
if [[ "$BACKEND_STATUS" -ne 0 || "$PYTEST_STATUS" -ne 0 ]]; then
  exit 1
fi
