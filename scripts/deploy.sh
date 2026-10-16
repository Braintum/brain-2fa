#!/usr/bin/env bash
#
# Deploy Brain 2FA to the WordPress.org plugin directory (SVN).
#
# Builds the production package with `npm run dist`, syncs dist/brain-2fa/ to
# SVN trunk, syncs the listing assets from .wordpress-org/ to SVN assets, and
# tags the release.
#
# Usage:
#   bash scripts/deploy.sh [--dry-run] [--skip-build] [--assets-only] [--allow-dirty]
#
#   --dry-run      Prepare the SVN working copy and show the changes, but do not commit.
#   --skip-build   Reuse the existing dist/brain-2fa/ build.
#   --assets-only  Update only the listing assets (banners, icons, screenshots, blueprint).
#   --allow-dirty  Deploy even if the Git working tree has uncommitted changes.
#
# SVN asks for the password on commit. It is the SVN password from
# https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password,
# not the WordPress.org account password.

set -euo pipefail

PLUGIN_SLUG="brain-2fa"
MAIN_FILE="brain-2fa.php"
SVN_URL="https://plugins.svn.wordpress.org/${PLUGIN_SLUG}"
SVN_USER="${SVN_USER:-braintum}"

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${PLUGIN_DIR}/dist/${PLUGIN_SLUG}"
ASSETS_DIR="${PLUGIN_DIR}/.wordpress-org"

DRY_RUN=0
SKIP_BUILD=0
ASSETS_ONLY=0
ALLOW_DIRTY=0

for arg in "$@"; do
	case "$arg" in
		--dry-run) DRY_RUN=1 ;;
		--skip-build) SKIP_BUILD=1 ;;
		--assets-only) ASSETS_ONLY=1 ;;
		--allow-dirty) ALLOW_DIRTY=1 ;;
		-h | --help)
			sed -n '3,20p' "$0" | sed 's/^# \{0,1\}//'
			exit 0
			;;
		*)
			echo "Unknown option: $arg" >&2
			exit 1
			;;
	esac
done

# ---------------------------------------------------------------- helpers

bold() { printf '\033[1m%s\033[0m\n' "$*"; }
step() { printf '\n\033[1;34m==>\033[0m \033[1m%s\033[0m\n' "$*"; }
ok() { printf '    \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '    \033[33m!\033[0m %s\n' "$*"; }
die() {
	printf '\n\033[31mError:\033[0m %s\n' "$*" >&2
	exit 1
}

confirm() {
	local answer
	printf '%s [y/N] ' "$1"
	read -r answer
	[ "$answer" = "y" ] || [ "$answer" = "Y" ]
}

# Schedule unversioned files for addition and missing files for deletion.
svn_stage() {
	local dir="$1" path
	svn status "$dir" | { grep '^?' || true; } | cut -c9- | while IFS= read -r path; do
		svn add --quiet --parents "${path}@"
	done
	svn status "$dir" | { grep '^!' || true; } | cut -c9- | while IFS= read -r path; do
		svn delete --quiet --force "${path}@"
	done
}

# Copy WordPress.org listing assets (whitelisted names only) into a directory.
copy_listing_assets() {
	local target="$1" file
	find "$target" -mindepth 1 -maxdepth 1 ! -name '.svn' -exec rm -rf {} +
	for file in "$ASSETS_DIR"/icon-*.{png,jpg,gif,svg} "$ASSETS_DIR"/banner-*.{png,jpg} "$ASSETS_DIR"/screenshot-*.{png,jpg,gif}; do
		[ -f "$file" ] && cp "$file" "$target/"
	done
	if [ -d "$ASSETS_DIR/blueprints" ]; then
		mkdir -p "$target/blueprints"
		cp "$ASSETS_DIR"/blueprints/*.json "$target/blueprints/"
	fi
	return 0
}

# WordPress.org serves assets with their SVN mime type; without it, browsers download them.
set_asset_mime_types() {
	local target="$1" file
	for file in "$target"/*.png; do [ -f "$file" ] && svn propset --quiet svn:mime-type image/png "${file}@"; done
	for file in "$target"/*.jpg; do [ -f "$file" ] && svn propset --quiet svn:mime-type image/jpeg "${file}@"; done
	for file in "$target"/*.gif; do [ -f "$file" ] && svn propset --quiet svn:mime-type image/gif "${file}@"; done
	for file in "$target"/*.svg; do [ -f "$file" ] && svn propset --quiet svn:mime-type image/svg+xml "${file}@"; done
	return 0
}

# ------------------------------------------------------------ preflight

bold "Brain 2FA → WordPress.org deploy"
[ "$DRY_RUN" -eq 1 ] && warn "Dry run: nothing will be committed."

step "Checking tools"
for tool in svn php git; do
	command -v "$tool" >/dev/null 2>&1 || die "'$tool' is required but not installed."
done
if [ "$SKIP_BUILD" -eq 0 ] && [ "$ASSETS_ONLY" -eq 0 ]; then
	for tool in npm composer wp; do
		command -v "$tool" >/dev/null 2>&1 || die "'$tool' is required to build. Install it or pass --skip-build."
	done
fi
ok "All required tools found"

cd "$PLUGIN_DIR"

step "Checking versions"
HEADER_VERSION=$(grep -m1 -E '^[[:space:]]*\*[[:space:]]*Version:' "$MAIN_FILE" | awk '{print $NF}' | tr -d '\r')
CONST_VERSION=$(grep -m1 "BRAIN_2FA_VERSION'" "$MAIN_FILE" | sed -E "s/.*'BRAIN_2FA_VERSION',[[:space:]]*'([^']+)'.*/\1/")
STABLE_TAG=$(grep -m1 -i '^Stable tag:' readme.txt | awk '{print $NF}' | tr -d '\r')
PACKAGE_VERSION=$(php -r 'echo json_decode(file_get_contents("package.json"))->version;')
VERSION="$HEADER_VERSION"

printf '    %-24s %s\n' "Plugin header:" "$HEADER_VERSION" "BRAIN_2FA_VERSION:" "$CONST_VERSION" "readme.txt Stable tag:" "$STABLE_TAG" "package.json:" "$PACKAGE_VERSION"

[ -n "$VERSION" ] || die "Could not read the Version header from $MAIN_FILE."
if [ "$ASSETS_ONLY" -eq 0 ]; then
	[ "$CONST_VERSION" = "$VERSION" ] || die "BRAIN_2FA_VERSION ($CONST_VERSION) does not match the plugin header ($VERSION)."
	[ "$STABLE_TAG" = "$VERSION" ] || die "readme.txt Stable tag ($STABLE_TAG) does not match the plugin header ($VERSION)."
	[ "$PACKAGE_VERSION" = "$VERSION" ] || die "package.json version ($PACKAGE_VERSION) does not match the plugin header ($VERSION)."
	grep -q "^= ${VERSION} =" readme.txt || die "readme.txt has no changelog entry for ${VERSION} (expected a '= ${VERSION} =' heading)."
	ok "All versions are ${VERSION} and the changelog has an entry"
fi

if [ "$ASSETS_ONLY" -eq 0 ]; then
	step "Checking Git"
	if [ -n "$(git status --porcelain)" ]; then
		git status --short
		if [ "$ALLOW_DIRTY" -eq 1 ]; then
			warn "Uncommitted changes will be included in the build (--allow-dirty)."
		else
			die "The working tree has uncommitted changes. Commit them first or pass --allow-dirty."
		fi
	else
		ok "Working tree is clean ($(git rev-parse --short HEAD))"
	fi
fi

step "Checking the SVN repository"
svn info "$SVN_URL" >/dev/null 2>&1 || die "Cannot reach $SVN_URL. Check your network and SVN access."
if [ "$ASSETS_ONLY" -eq 0 ] && svn ls "${SVN_URL}/tags/${VERSION}" >/dev/null 2>&1; then
	die "Tag ${VERSION} already exists on WordPress.org. Bump the version before releasing."
fi
ok "$SVN_URL is reachable"

# ---------------------------------------------------------------- build

if [ "$ASSETS_ONLY" -eq 0 ]; then
	if [ "$SKIP_BUILD" -eq 1 ]; then
		step "Using existing build (--skip-build)"
	else
		step "Building the production package (npm run dist)"
		npm run dist
	fi

	step "Checking the build"
	[ -d "$BUILD_DIR" ] || die "Build directory $BUILD_DIR not found."
	for required in "$MAIN_FILE" readme.txt vendor/autoload.php assets/js/login.js assets/js/admin.js assets/css/admin.css languages/brain-2fa.pot; do
		[ -e "$BUILD_DIR/$required" ] || die "The build is missing $required."
	done
	for forbidden in node_modules src .git .wordpress-org scripts package.json webpack.config.js phpcs.xml.dist AGENTS.md CLAUDE.md .claude docs; do
		[ ! -e "$BUILD_DIR/$forbidden" ] || die "The build must not contain $forbidden."
	done
	if [ -d "$BUILD_DIR/vendor/squizlabs" ] || [ -d "$BUILD_DIR/vendor/wp-coding-standards" ]; then
		die "The build contains Composer dev dependencies. Run 'npm run package' to rebuild without them."
	fi
	cmp -s readme.txt "$BUILD_DIR/readme.txt" || die "dist readme.txt is out of date. Rebuild without --skip-build."
	if grep -q '^#: dist/' "$BUILD_DIR/languages/brain-2fa.pot"; then
		die "languages/brain-2fa.pot references dist/ files. Check the make-pot --exclude list and rebuild."
	fi
	BUILD_VERSION=$(grep -m1 -E '^[[:space:]]*\*[[:space:]]*Version:' "$BUILD_DIR/$MAIN_FILE" | awk '{print $NF}' | tr -d '\r')
	[ "$BUILD_VERSION" = "$VERSION" ] || die "The build is version ${BUILD_VERSION}, expected ${VERSION}. Rebuild without --skip-build."
	while IFS= read -r -d '' php_file; do
		php -l "$php_file" >/dev/null 2>&1 || die "PHP syntax error in ${php_file#"$BUILD_DIR"/}."
	done < <(find "$BUILD_DIR" -name '*.php' -not -path '*/vendor/*' -print0)
	ok "Build contents look correct and PHP files pass lint"
fi

# ---------------------------------------------------------- SVN checkout

SVN_DIR="$(mktemp -d "${TMPDIR:-/tmp}/${PLUGIN_SLUG}-svn.XXXXXX")"
step "Checking out SVN into $SVN_DIR"
svn checkout --quiet --depth immediates "$SVN_URL" "$SVN_DIR"
cd "$SVN_DIR"
# tags/ stays shallow: it holds a full copy of every release.
for dir in trunk assets tags; do
	if [ ! -d "$dir" ]; then
		mkdir "$dir"
		svn add --quiet --depth empty "$dir"
	elif [ "$dir" != "tags" ]; then
		svn update --quiet --set-depth infinity "$dir"
	fi
done
ok "Checked out trunk, assets and tags"

if [ "$ASSETS_ONLY" -eq 0 ]; then
	step "Syncing dist/${PLUGIN_SLUG}/ → trunk/"
	find trunk -mindepth 1 -maxdepth 1 ! -name '.svn' -exec rm -rf {} +
	cp -R "$BUILD_DIR"/. trunk/
	find trunk -name '.DS_Store' -not -path '*/.svn/*' -delete
	svn_stage trunk
	ok "trunk updated"
fi

step "Syncing .wordpress-org/ → assets/"
copy_listing_assets assets
svn_stage assets
set_asset_mime_types assets
ok "assets updated"

# --------------------------------------------------------------- review

step "Pending SVN changes"
CHANGES=$(svn status trunk assets)
if [ -z "$CHANGES" ]; then
	warn "No changes to trunk or assets."
else
	echo "$CHANGES" | awk '{print $1}' | sort | uniq -c | while read -r count code; do
		case "$code" in
			A) label="added" ;; D) label="deleted" ;; M) label="modified" ;; *) label="$code" ;;
		esac
		printf '    %4s %s\n' "$count" "$label"
	done
	echo "$CHANGES" | { grep -v '/vendor/' || true; } | head -40
	[ "$(echo "$CHANGES" | wc -l)" -gt 40 ] && echo "    ... (run 'svn status' in $SVN_DIR for the full list)"
fi

if [ "$DRY_RUN" -eq 1 ]; then
	echo
	bold "Dry run complete. The SVN working copy is kept at:"
	echo "    $SVN_DIR"
	exit 0
fi

echo
if [ "$ASSETS_ONLY" -eq 1 ]; then
	confirm "Commit asset changes to $SVN_URL as ${SVN_USER}?" || die "Aborted. Working copy kept at $SVN_DIR."
else
	confirm "Commit ${VERSION} to trunk and create tags/${VERSION} on $SVN_URL as ${SVN_USER}?" || die "Aborted. Working copy kept at $SVN_DIR."
fi

# --------------------------------------------------------------- commit

if [ -n "$CHANGES" ]; then
	step "Committing trunk and assets"
	if [ "$ASSETS_ONLY" -eq 1 ]; then
		svn commit --username "$SVN_USER" -m "Update plugin assets" assets
	else
		svn commit --username "$SVN_USER" -m "Release ${VERSION}" trunk assets
	fi
fi

if [ "$ASSETS_ONLY" -eq 0 ]; then
	step "Tagging ${VERSION}"
	svn update --quiet
	svn copy --quiet trunk "tags/${VERSION}"
	svn commit --username "$SVN_USER" -m "Tag ${VERSION}" "tags/${VERSION}"
	ok "Created ${SVN_URL}/tags/${VERSION}"
fi

cd "$PLUGIN_DIR"
rm -rf "$SVN_DIR"

step "Done"
echo "    Plugin page: https://wordpress.org/plugins/${PLUGIN_SLUG}/"
echo "    The directory can take a few minutes to update. New plugins can take up to 72 hours to appear in search."

if [ "$ASSETS_ONLY" -eq 0 ] && ! git rev-parse -q --verify "refs/tags/${VERSION}" >/dev/null; then
	echo
	if confirm "Create Git tag ${VERSION} for this release?"; then
		git tag -a "$VERSION" -m "Release ${VERSION}"
		ok "Tagged ${VERSION}. Push it with: git push origin ${VERSION}"
	fi
fi
