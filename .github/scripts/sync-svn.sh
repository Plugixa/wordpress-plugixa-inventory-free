#!/usr/bin/env bash
# Mirror a WordPress.org plugin's SVN repository into this Git repository.
#
#   - every SVN tags/<version> that has no Git tag yet becomes ONE commit whose tree is exactly
#     that SVN tag (no README.md, no .github), tagged <version>;
#   - main then gets SVN trunk on top, keeping the GitHub-only README.md and .github/.
#
# Usage: SLUG=plugixa-inventory bash .github/scripts/sync-svn.sh
# Env:   SLUG (required), BRANCH (default main), PUSH (default 1; 0 = commit locally only)
set -euo pipefail

SLUG="${SLUG:?set SLUG to the wordpress.org plugin slug}"
BRANCH="${BRANCH:-main}"
PUSH="${PUSH:-1}"
SVN="https://plugins.svn.wordpress.org/${SLUG}"
ROOT="$(git rev-parse --show-toplevel)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
cd "$ROOT"

# Byte-for-byte: some SVN files are CRLF. A machine with core.autocrlf=true (Git for Windows'
# default) would silently rewrite them to LF and every tree hash would drift.
export GIT_CONFIG_COUNT=2
export GIT_CONFIG_KEY_0=core.autocrlf GIT_CONFIG_VALUE_0=false
export GIT_CONFIG_KEY_1=core.safecrlf GIT_CONFIG_VALUE_1=false

changed=0

# Export one SVN path and refuse anything that does not look like the plugin: a half-failed
# fetch must never be allowed to empty the repository.
export_svn() {
  svn export --quiet --force "${SVN}/$1" "$2"
  if [ ! -f "$2/${SLUG}.php" ]; then
    echo "::error::SVN $1 has no ${SLUG}.php - refusing to mirror it" >&2
    exit 1
  fi
}

last_rev() { svn info --show-item last-changed-revision "${SVN}/$1" | tr -d '[:space:]'; }

# ---- 1. new tags, oldest first ---------------------------------------------------------------
mapfile -t tags < <(svn ls "${SVN}/tags" | sed 's#/$##' | grep -E '^[0-9]+(\.[0-9]+)*$' | sort -V || true)

for t in "${tags[@]}"; do
  if git rev-parse -q --verify "refs/tags/${t}" >/dev/null; then
    continue
  fi
  dir="${WORK}/tag-${t}"
  export_svn "tags/${t}" "$dir"
  rev="$(last_rev "tags/${t}")"

  # Build the tree from the export alone, in a throwaway index, so the tagged commit carries
  # nothing the SVN tag does not.
  idx="${WORK}/index-${t}"
  ( cd "$dir" && GIT_INDEX_FILE="$idx" git --git-dir="${ROOT}/.git" --work-tree="$dir" add -A . )
  tree="$(GIT_INDEX_FILE="$idx" git write-tree)"
  commit="$(git commit-tree "$tree" -p HEAD -m "Mirror SVN tag ${t} (r${rev})")"

  git tag "$t" "$commit"
  # Move the branch only; the work tree and index stay on the previous main, so README.md and
  # .github/ are still on disk for the trunk commit below.
  git update-ref "refs/heads/${BRANCH}" "$commit"
  echo "tag ${t}: ${commit} (SVN r${rev})"
  changed=1
done

# ---- 2. trunk on main ---------------------------------------------------------------------------
trunk="${WORK}/trunk"
export_svn "trunk" "$trunk"
rev="$(last_rev "trunk")"
stable="$(grep -i -m1 '^Stable tag:' "${trunk}/readme.txt" | sed 's/^[^:]*:[[:space:]]*//' | tr -d '\r' || true)"

find "$ROOT" -mindepth 1 -maxdepth 1 ! -name .git ! -name .github ! -name README.md -exec rm -rf {} +
cp -a "${trunk}/." "$ROOT/"

# Drop the index and re-hash every file: an index whose cached stat (same size, same second)
# happens to match a changed file would otherwise keep the old blob.
git rm -r -q -f --cached . >/dev/null
git add -A .
if ! git diff --cached --quiet; then
  git commit -q -m "Mirror SVN trunk r${rev} (Stable tag ${stable:-unknown})"
  echo "trunk: $(git rev-parse --short HEAD) (SVN r${rev})"
  changed=1
else
  echo "trunk: already at SVN r${rev}"
fi

# ---- 3. publish ---------------------------------------------------------------------------------
if [ "$changed" = 1 ] && [ "$PUSH" = 1 ]; then
  git push origin "${BRANCH}" --tags
elif [ "$changed" = 0 ]; then
  echo "nothing to mirror"
fi
