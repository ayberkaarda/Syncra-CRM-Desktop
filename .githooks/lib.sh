# Shared helpers for the commit-trace hooks. Sourced, not executed.
# POSIX sh; needs only grep, sed and git.

HOOK_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
TERMS_FILE="$HOOK_DIR/trace-terms"

# Contributor trailers that must never appear in a commit message.
TRAILER_RE='^[[:space:]]*(co-authored-by|co-developed-by|assisted-by|generated-by|helped-by)[[:space:]]*:'

# Print each term as a ready-to-use ERE, one per line.
trace_patterns() {
	grep -v -e '^[[:space:]]*#' -e '^[[:space:]]*$' "$TERMS_FILE" | sed 's/\r$//' |
	while IFS= read -r t; do
		case $t in
		w:*) printf '(^|[^[:alnum:]])(%s)([^[:alnum:]]|$)\n' "${t#w:}" ;;
		*) printf '(%s)\n' "$t" ;;
		esac
	done
}

# Print the raw terms (for reporting), aligned with trace_patterns.
trace_terms() {
	grep -v -e '^[[:space:]]*#' -e '^[[:space:]]*$' "$TERMS_FILE" | sed -e 's/\r$//' -e 's/^w://'
}

# Combined ERE of all terms.
trace_regex() {
	_r=
	while IFS= read -r _p; do
		_r="${_r:+$_r|}$_p"
	done <<EOT
$(trace_patterns)
EOT
	printf '%s\n' "$_r"
}

# Given one line of text in $1, print the terms it matches, comma separated.
terms_in_line() {
	_found=
	_i=0
	trace_patterns > "${TMPDIR:-/tmp}/trace-pat.$$"
	trace_terms > "${TMPDIR:-/tmp}/trace-term.$$"
	while IFS= read -r _p; do
		_i=$((_i + 1))
		if printf '%s\n' "$1" | grep -qiE -e "$_p"; then
			_t=$(sed -n "${_i}p" "${TMPDIR:-/tmp}/trace-term.$$")
			_found="${_found:+$_found, }$_t"
		fi
	done < "${TMPDIR:-/tmp}/trace-pat.$$"
	rm -f "${TMPDIR:-/tmp}/trace-pat.$$" "${TMPDIR:-/tmp}/trace-term.$$"
	printf '%s\n' "$_found"
}

# Check a commit message file. Prints problems, returns 1 if any.
# $1 = message file, $2 = label used in output.
check_message() {
	_msg=$1
	_label=$2
	_rc=0
	# Drop comment lines and anything below the verbose-commit scissors line.
	_clean="${TMPDIR:-/tmp}/trace-msg.$$"
	sed -e '/^# -\{24\} >8 -\{24\}$/,$d' -e '/^#/d' "$_msg" > "$_clean"
	if grep -iE -e "$TRAILER_RE" "$_clean" > /dev/null; then
		grep -inE -e "$TRAILER_RE" "$_clean" | sed "s|^|$_label: contributor trailer: |" >&2
		_rc=1
	fi
	_re=$(trace_regex)
	grep -inE -e "$_re" "$_clean" > "$_clean.hits"
	while IFS= read -r _hit; do
		_n=${_hit%%:*}
		_line=${_hit#*:}
		printf '%s: line %s: trace term [%s]: %s\n' "$_label" "$_n" "$(terms_in_line "$_line")" "$_line" >&2
		_rc=1
	done < "$_clean.hits"
	rm -f "$_clean" "$_clean.hits"
	return $_rc
}
