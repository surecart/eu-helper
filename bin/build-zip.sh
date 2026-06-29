#!/usr/bin/env bash
#
# Build an installable surecart-eu-helper.zip locally.
#
# Produces the SAME artifact as the GitHub release workflow
# (.github/workflows/release.yml): it builds the block/admin assets, then stages
# the plugin into a slug-named folder honouring .distignore, and zips it. The
# resulting zip drops into wp-content/plugins/ and runs with no build toolchain.
#
# Usage (from anywhere):
#   bin/build-zip.sh            # install deps, build, and package
#   bin/build-zip.sh --no-build # skip npm install/build, just (re)package
#
set -euo pipefail

SLUG="surecart-eu-helper"
STAGE=".release"
ZIP="${SLUG}.zip"

# Resolve the plugin root (the parent of this script's directory) so the script
# works regardless of the current working directory.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

BUILD=1
if [ "${1:-}" = "--no-build" ]; then
	BUILD=0
fi

# --- Sanity checks -----------------------------------------------------------
for cmd in rsync zip; do
	command -v "$cmd" >/dev/null 2>&1 || {
		echo "✗ Required command '$cmd' not found in PATH." >&2
		exit 1
	}
done

# --- Build assets ------------------------------------------------------------
if [ "$BUILD" -eq 1 ]; then
	command -v npm >/dev/null 2>&1 || {
		echo "✗ npm not found. Install Node, or re-run with --no-build to package the existing build/." >&2
		exit 1
	}
	echo "→ Installing dependencies…"
	if [ -f package-lock.json ]; then
		npm ci
	else
		npm install
	fi
	echo "→ Building blocks + admin app (npm run build)…"
	npm run build
else
	echo "→ Skipping build (--no-build); packaging the existing build/ output."
fi

if [ ! -d build ]; then
	echo "✗ No build/ directory found. Run without --no-build, or run 'npm run build' first." >&2
	exit 1
fi

# --- Stage (honour .distignore) ---------------------------------------------
echo "→ Staging plugin files into ${STAGE}/${SLUG} (honouring .distignore)…"
rm -rf "$STAGE" "$ZIP"
mkdir -p "${STAGE}/${SLUG}"
rsync -a --exclude-from='.distignore' --exclude "$STAGE" ./ "${STAGE}/${SLUG}/"

# --- Zip ---------------------------------------------------------------------
echo "→ Creating ${ZIP}…"
( cd "$STAGE" && zip -rq "../${ZIP}" "$SLUG" )
rm -rf "$STAGE"

echo "✔ Built ${ROOT}/${ZIP}"
