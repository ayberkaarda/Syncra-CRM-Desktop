#!/bin/sh
# Self-test for the commit-trace hooks. Builds throwaway repos in a temp dir.
# Usage: sh .githooks/test.sh
HOOKS=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
BASE=$(mktemp -d 2> /dev/null || { d="${TMPDIR:-/tmp}/trace-test.$$"; mkdir -p "$d"; echo "$d"; })
trap 'rm -rf "$BASE"' EXIT INT TERM
OWNER=owner@example.com
pass=0
fail=0

g() { git -c core.hooksPath="$HOOKS" -c user.name=Owner -c user.email="$OWNER" -c commit.gpgsign=false -c core.autocrlf=false "$@"; }

ok() {
	if "$@" > "$BASE/out" 2>&1; then pass=$((pass + 1)); echo "PASS: accepted: $DESC"
	else fail=$((fail + 1)); echo "FAIL: expected accept: $DESC"; sed 's/^/    /' "$BASE/out"; fi
}
no() {
	if "$@" > "$BASE/out" 2>&1; then fail=$((fail + 1)); echo "FAIL: expected reject: $DESC"
	else pass=$((pass + 1)); echo "PASS: rejected: $DESC"; grep -m1 -e "trace" -e "trailer" -e "email" -e "ref name" "$BASE/out" | sed "s/^/    /"; fi
}

# Term spelled in pieces so this file never contains it literally.
T="cla""ude"

git init -q --bare "$BASE/remote.git"
git init -q "$BASE/w"
cd "$BASE/w" || exit 1
g config user.email "$OWNER"
git remote add origin "$BASE/remote.git"

echo hello > a.txt; g add a.txt
DESC="clean commit"; ok g commit -q -m "Add greeting"

echo x >> a.txt; g add a.txt
DESC="Co-authored-by trailer"; no g commit -q -m "Change" -m "co-authored-BY: Someone <s@e.com>"
DESC="trace term in message"; no g commit -q -m "Change written by $T"
DESC="'Generated with' in message"; no g commit -q -m "Change" -m "Generated with a tool"
g reset -q --hard

echo "// made by $T" > b.js; g add b.js
DESC="trace term in added line"; no g commit -q -m "Add b"
g reset -q; rm -f b.js

echo "fastrand opuscule" > c.txt; g add c.txt
DESC="word-bounded terms inside longer words"; ok g commit -q -m "Add c"

mkdir -p sub; echo "\"$T-sdk\": \"1.0\"" > sub/package-lock.json; g add sub/package-lock.json
DESC="lockfile line"; ok g commit -q -m "Add lockfile"

echo "The $T integration is documented here. trace-ok" > doc.md; g add doc.md
DESC="trace-ok line"; ok g commit -q -m "Add doc"

g rm -q doc.md
DESC="removed line with term"; ok g commit -q -m "Remove doc"

DESC="clean push"; ok g push -q origin HEAD:refs/heads/main

g checkout -q -b "feature/$T-work"
echo y >> a.txt; g add a.txt; g commit -q -m "More"
DESC="trace branch name"; no g push -q origin "feature/$T-work"
g checkout -q main 2> /dev/null || g checkout -q -b main origin/main 2> /dev/null

g checkout -q -b feature/ok
echo z >> a.txt; g add a.txt
git -c core.hooksPath="$HOOKS" -c user.name=Owner -c user.email="$OWNER" -c committer.email=bot@example.com \
	commit -q -m "Other committer" 2> /dev/null ||
	GIT_COMMITTER_EMAIL=bot@example.com g commit -q -m "Other committer"
DESC="push-time wrong committer email ($(git log -1 --format=%ce))"; no g push -q origin feature/ok

g reset -q --hard HEAD~1
echo w >> a.txt; g add a.txt
g commit -q -m "Bad" -m "Co-authored-by: X <x@e.com>" --no-verify
DESC="push-time Co-authored-by trailer"; no g push -q origin feature/ok

g reset -q --hard HEAD~1
echo v >> a.txt; g add a.txt
GIT_AUTHOR_EMAIL=other@example.com g commit -q -m "Foreign author"
DESC="push-time wrong author email"; no g push -q origin feature/ok

g reset -q --hard HEAD~1
echo u >> a.txt; g add a.txt; g commit -q -m "Fine"
DESC="clean branch push"; ok g push -q origin feature/ok

echo
echo "passed: $pass, failed: $fail"
[ $fail -eq 0 ]
