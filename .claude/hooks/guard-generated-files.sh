#!/usr/bin/env bash
#
# PreToolUse hook: stop Claude from hand-editing generated or third-party files.
#
# Exit 2 blocks the edit and tells Claude which source file to change instead.

set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FILE="$(jq -r '.tool_input.file_path // empty')"

[ -n "$FILE" ] || exit 0

case "$FILE" in
	"$PLUGIN_DIR"/*) REL="${FILE#"$PLUGIN_DIR"/}" ;;
	*) exit 0 ;;
esac

block() {
	echo "Blocked edit to $REL: $1" >&2
	exit 2
}

case "$REL" in
	vendor/*) block "vendor/ is managed by Composer. Change composer.json and run composer update, or fix the behaviour in includes/." ;;
	node_modules/*) block "node_modules/ is managed by npm." ;;
	dist/*) block "dist/ is the release build. Change the source and run npm run dist." ;;
	assets/js/*.js | assets/js/*.asset.php) block "assets/js/ is compiled by webpack. Edit src/js/ and run npm run build." ;;
	assets/css/*.css) block "assets/css/ is compiled from SCSS. Edit src/scss/ and run npm run build." ;;
	languages/*.pot) block "The POT file is generated. Run npm run make-pot after changing strings." ;;
	.wordpress-org/source/*) block ".wordpress-org/source/ holds the original screenshots. Edit the screenshot-N.png copies instead." ;;
esac

exit 0
