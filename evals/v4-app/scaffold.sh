#!/usr/bin/env bash
# Copies the v4 fixture app into the run's empty workspace.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
src="$here/../../fixtures/v4-app"

if [ ! -d "$src" ]; then
  echo "scaffold: fixture not found at $src" >&2
  exit 1
fi

cp -R "$src/." .
find . -name .DS_Store -delete
