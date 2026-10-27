#!/usr/bin/env bash
# Screenshots every rendered page in a directory with headless Chromium, at 1440 and 1920 wide.
#   tools/preview/screenshot.sh <dir> [glob, default *.html] [height, default 1400]
set -euo pipefail
dir=${1:?usage: screenshot.sh <dir> [glob] [height]}
glob=${2:-*.html}
height=${3:-1400}
browser=${CHROMIUM:-/usr/bin/chromium}
shopt -s nullglob
for page in "$dir"/$glob; do
  base=${page%.html}
  for width in 1440 1920; do
    "$browser" --headless=new --no-sandbox --disable-gpu --hide-scrollbars=false \
      --screenshot="$base-$width.png" --window-size="$width,$height" "file://$(realpath "$page")" >/dev/null 2>&1
    echo "$base-$width.png"
  done
done
