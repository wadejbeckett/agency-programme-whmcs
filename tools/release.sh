#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Builds the release archive for a tagged version: agency-programme-whmcs-VERSION.zip with
# modules/addons/agencydiscount/ plus LICENSE, README.md and CHANGELOG.md, and a .sha256 beside it.
# Usage: tools/release.sh VERSION [OUTDIR]      (OUTDIR defaults to dist/)
# Refuses when tag vVERSION is missing, the working tree has uncommitted changes, or
# Module::VERSION at the tag differs from VERSION. The archive is built from the tag, not the tree.
set -euo pipefail
version=${1:?usage: tools/release.sh VERSION [OUTDIR]}
outdir=${2:-dist}
tag="v$version"
root=$(git rev-parse --show-toplevel)
cd "$root"
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "release.sh: VERSION must look like 1.2.3 (got \"$version\")" >&2; exit 2; }
git rev-parse -q --verify "refs/tags/$tag" >/dev/null || { echo "release.sh: tag $tag does not exist" >&2; exit 2; }
git diff --quiet && git diff --cached --quiet || { echo "release.sh: working tree has uncommitted changes; commit or stash them first" >&2; exit 2; }
module_version=$(git show "$tag:modules/addons/agencydiscount/lib/Module.php" | sed -n "s/.*public const VERSION = '\([^']*\)'.*/\1/p")
[[ "$module_version" == "$version" ]] || { echo "release.sh: Module::VERSION at $tag is \"$module_version\", not \"$version\"" >&2; exit 2; }
name="agency-programme-whmcs-$version"
mkdir -p "$outdir"; outdir=$(cd "$outdir" && pwd)
work=$(mktemp -d); trap 'rm -rf "$work"' EXIT
git archive --format=tar --prefix="$name/" "$tag" modules/addons/agencydiscount LICENSE README.md CHANGELOG.md | tar -xf - -C "$work"
zip="$outdir/$name.zip"
rm -f "$zip" "$zip.sha256"
(cd "$work" && find "$name" -type f | LC_ALL=C sort | TZ=UTC zip -X -q -D "$zip" -@)
# The archive must hold exactly the module files tracked at the tag plus the three documents.
expected=$( { git ls-tree -r --name-only "$tag" -- modules/addons/agencydiscount; printf 'LICENSE\nREADME.md\nCHANGELOG.md\n'; } | sed "s#^#$name/#" | LC_ALL=C sort)
actual=$(unzip -Z1 "$zip" | LC_ALL=C sort)
if [[ "$expected" != "$actual" ]]; then
  echo "release.sh: archive contents differ from the files tracked at $tag:" >&2
  diff <(echo "$expected") <(echo "$actual") >&2 || true
  exit 1
fi
(cd "$outdir" && sha256sum "$name.zip" > "$name.zip.sha256")
echo "$zip ($(unzip -Z1 "$zip" | wc -l) files)"; cat "$zip.sha256"
