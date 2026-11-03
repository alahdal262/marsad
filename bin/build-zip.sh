#!/usr/bin/env bash
# Builds dist/marsad.zip with a top-level marsad/ folder, the layout WordPress
# and the GitHub updater expect. Dev-only files are left out.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
out="$root/dist"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT

mkdir -p "$stage/marsad" "$out"
rsync -a \
	--exclude '.git*' --exclude '.github' --exclude 'tests' --exclude 'bin' --exclude 'server' --exclude 'languages/source' \
	--exclude 'dist' --exclude '.DS_Store' --exclude 'phpunit*' \
	"$root/" "$stage/marsad/"

rm -f "$out/marsad.zip"
(cd "$stage" && zip -qr "$out/marsad.zip" marsad)
echo "$out/marsad.zip"
