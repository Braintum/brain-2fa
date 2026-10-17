---
name: release
description: Prepare and publish a Brain 2FA release to the WordPress.org plugin directory (version bump, changelog, build, SVN deploy), or update only the listing assets.
disable-model-invocation: true
---

# Release Brain 2FA to WordPress.org

Follow `docs/releasing.md`. Summary:

1. **Ask for the new version number** and the user-facing changes if they were not given. Use semantic versioning.
2. **Bump the version in all four places:**
   - `brain-2fa.php`: the `Version:` header and the `BRAIN_2FA_VERSION` constant
   - `readme.txt`: `Stable tag:`
   - `package.json`: `version`
3. **Update `readme.txt`:** add a `= x.y.z =` entry at the top of `== Changelog ==` and a matching `== Upgrade Notice ==` entry. Bump `Tested up to:` if the plugin was tested on a newer WordPress.
4. **Validate the readme** with the WordPress.org MCP `validate-readme` tool if it is available.
5. **Run the checks:** `bash scripts/check-versions.sh`, `composer phpcs`, `npm run lint`, `npm run build`, `npm run make-pot`, `npm run test:wp`. After pushing, confirm that the GitHub CI run on `master` is green, including **Plugin Check**: `gh run list --workflow ci.yml --branch master --limit 1`. Do not deploy a build whose CI failed.
6. **Ask the user to commit the changes.** The deploy script refuses a dirty working tree.
7. **Dry run:** `bash scripts/deploy.sh --dry-run` and show the user the summary.
8. **Publish:** the user runs `npm run deploy` (or `bash scripts/deploy.sh`) themselves, because SVN prompts for their WordPress.org SVN password. Do not run the real deploy yourself unless the user explicitly asks you to in this conversation.

For listing-only changes (banner, icon, screenshots, blueprint): `bash scripts/deploy.sh --assets-only`.

Never reuse a version that already exists in `tags/` on SVN. The script refuses to.
