#!/usr/bin/env bash
#
# Run the Brain 2FA WP-CLI checks in tests/wp-cli/ against the WordPress site this plugin
# is installed in. Development sites only: the checks create users and write options while
# they run, and restore everything afterwards (the uninstall check drops and recreates the
# passkeys table).
#
# Usage: npm run test:wp            (or: bash tests/run-wp-cli.sh [check-name ...])
# WP-CLI: $WP_CLI if set, else the wp-cli.phar in the WordPress root, else `wp` on the PATH.
# Exits 1 when any check fails.

set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PLUGIN_DIR" || exit 1

if [ -n "${WP_CLI:-}" ]; then
	read -r -a wp <<< "$WP_CLI"
elif [ -f ../../../wp-cli.phar ]; then
	wp=(php ../../../wp-cli.phar)
elif command -v wp > /dev/null; then
	wp=(wp)
else
	echo "WP-CLI not found. Set WP_CLI, e.g. WP_CLI='php /path/to/wp-cli.phar'." >&2
	exit 1
fi

# name:arguments. The uninstall check runs twice: keeping data, then removing it.
checks=(tab-save: login-security: method-impact: emails: hardening: trusted-devices: enrollment: reminders: recovery-warning: uninstall:keep uninstall:)
if [ "$#" -gt 0 ]; then
	checks=()
	for name in "$@"; do
		[ "$name" = uninstall ] && checks+=(uninstall:keep uninstall:) || checks+=("$name:")
	done
fi

failed=()
for check in "${checks[@]}"; do
	name="${check%%:*}"
	arg="${check#*:}"
	label="$name${arg:+ ($arg)}"
	file="tests/wp-cli/$name.php"
	[ -f "$file" ] || { echo "No such check: $file" >&2; failed+=("$label"); continue; }

	echo "== $label"
	output="$("${wp[@]}" eval-file "$file" ${arg:+"$arg"} 2>&1)"
	status=$?
	# Show results and problems; hide unrelated notices from other plugins.
	grep -E '^(PASS|FAIL|PHP ISSUE|Cleanup|Restore|Backed up|ALL PASSED|[0-9]+ FAILED)|Fatal|Error:' <<< "$output"
	if [ "$status" -ne 0 ] || grep -q '^FAIL' <<< "$output" || ! grep -q '^ALL PASSED' <<< "$output"; then
		failed+=("$label")
	fi
done

echo
if [ "${#failed[@]}" -gt 0 ]; then
	echo "✗ Failed: ${failed[*]}"
	exit 1
fi
echo "✓ All WP-CLI checks passed."
