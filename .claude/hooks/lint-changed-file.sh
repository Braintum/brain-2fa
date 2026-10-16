#!/usr/bin/env bash
#
# PostToolUse hook: auto-fix and lint a file right after Claude edits it.
#
# - PHP in brain-2fa.php or includes/: php -l, phpcbf (auto-fix), then phpcs (WordPress-Extra + Docs).
# - JavaScript in src/js/: eslint --fix, then eslint.
#
# Exit 2 sends the remaining violations back to Claude so it fixes them.
# Formatting that phpcbf/eslint fixed on their own is reported as context.

set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FILE="$(jq -r '.tool_input.file_path // empty')"

[ -n "$FILE" ] && [ -f "$FILE" ] || exit 0

case "$FILE" in
	"$PLUGIN_DIR"/*) REL="${FILE#"$PLUGIN_DIR"/}" ;;
	*) exit 0 ;;
esac

case "$REL" in
	vendor/* | node_modules/* | dist/* | assets/*) exit 0 ;;
esac

cd "$PLUGIN_DIR" || exit 0

report_fixed() {
	jq -n --arg msg "$1" '{hookSpecificOutput: {hookEventName: "PostToolUse", additionalContext: $msg}}'
}

case "$REL" in
	brain-2fa.php | includes/*.php)
		if ! LINT="$(php -l "$FILE" 2>&1)"; then
			printf 'PHP syntax error in %s:\n%s\n' "$REL" "$LINT" >&2
			exit 2
		fi

		[ -x vendor/bin/phpcs ] || exit 0

		BEFORE="$(shasum "$FILE")"
		vendor/bin/phpcbf --standard=phpcs.xml.dist -q "$FILE" >/dev/null 2>&1
		FIXED=0
		[ "$BEFORE" != "$(shasum "$FILE")" ] && FIXED=1

		if ! OUT="$(vendor/bin/phpcs --standard=phpcs.xml.dist -q --report=emacs "$FILE" 2>&1)"; then
			[ "$FIXED" -eq 1 ] && echo "phpcbf auto-fixed some formatting in $REL; re-read the file before editing it again." >&2
			printf 'WordPress Coding Standards violations in %s (fix these; see .claude/skills/wp-coding-standards):\n%s\n' "$REL" "$OUT" >&2
			exit 2
		fi

		[ "$FIXED" -eq 1 ] && report_fixed "phpcbf auto-fixed WordPress Coding Standards formatting in $REL. Re-read the file before editing it again."
		;;

	src/js/*.js)
		[ "$REL" = "src/js/examples.js" ] && exit 0
		[ -x node_modules/.bin/eslint ] || exit 0

		BEFORE="$(shasum "$FILE")"
		node_modules/.bin/eslint --fix "$FILE" >/dev/null 2>&1
		FIXED=0
		[ "$BEFORE" != "$(shasum "$FILE")" ] && FIXED=1

		if ! OUT="$(node_modules/.bin/eslint --format unix "$FILE" 2>&1)"; then
			[ "$FIXED" -eq 1 ] && echo "eslint --fix auto-fixed some issues in $REL; re-read the file before editing it again." >&2
			printf 'ESLint (eslint-config-wordpress) violations in %s:\n%s\n' "$REL" "$OUT" >&2
			exit 2
		fi

		[ "$FIXED" -eq 1 ] && report_fixed "eslint --fix auto-fixed formatting in $REL. Re-read the file before editing it again. Run npm run build so assets/js/ matches."
		;;
esac

exit 0
