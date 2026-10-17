#!/usr/bin/env bash
#
# Check that the release metadata agrees everywhere WordPress.org and the plugin read it:
#
# - Version:       brain-2fa.php header = BRAIN_2FA_VERSION = readme.txt "Stable tag" = package.json
# - Requirements:  "Requires at least" and "Requires PHP" match between brain-2fa.php and readme.txt
# - Changelog:     readme.txt has a "= <version> =" changelog entry for the current version
#
# Usage: bash scripts/check-versions.sh
# Exits 1 and lists every mismatch when something disagrees. Used by CI and the Claude lint hook.

set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PLUGIN_DIR" || exit 1

header() { # header <file> <field>
	sed -n "s/^[ *]*$2:[[:space:]]*//p" "$1" | head -n 1 | tr -d '\r' | sed 's/[[:space:]]*$//'
}

plugin_version="$(header brain-2fa.php 'Version')"
constant_version="$(sed -n "s/.*define( 'BRAIN_2FA_VERSION', '\([^']*\)' );.*/\1/p" brain-2fa.php | head -n 1)"
stable_tag="$(header readme.txt 'Stable tag')"
package_version="$(sed -n 's/^[[:space:]]*"version":[[:space:]]*"\([^"]*\)".*/\1/p' package.json | head -n 1)"

errors=0
fail() {
	echo "✗ $1"
	errors=$((errors + 1))
}

[ -n "$plugin_version" ] || fail "brain-2fa.php has no Version header."
[ "$constant_version" = "$plugin_version" ] || fail "BRAIN_2FA_VERSION is '$constant_version' but the plugin header Version is '$plugin_version'."
[ "$stable_tag" = "$plugin_version" ] || fail "readme.txt Stable tag is '$stable_tag' but the plugin header Version is '$plugin_version'."
[ "$package_version" = "$plugin_version" ] || fail "package.json version is '$package_version' but the plugin header Version is '$plugin_version'."

for field in 'Requires at least' 'Requires PHP'; do
	in_plugin="$(header brain-2fa.php "$field")"
	in_readme="$(header readme.txt "$field")"
	[ "$in_plugin" = "$in_readme" ] || fail "'$field' is '$in_plugin' in brain-2fa.php but '$in_readme' in readme.txt."
done

[ -n "$(header readme.txt 'Tested up to')" ] || fail "readme.txt has no 'Tested up to' header."
grep -qF "= $plugin_version =" readme.txt || fail "readme.txt has no '= $plugin_version =' changelog entry."

if [ "$errors" -gt 0 ]; then
	exit 1
fi

echo "✓ Version $plugin_version is consistent (header, constant, Stable tag, package.json); requirements and changelog match."
