# Releasing to WordPress.org

Brain 2FA is hosted at https://wordpress.org/plugins/brain-2fa/. The SVN repository is https://plugins.svn.wordpress.org/brain-2fa and the SVN username is `braintum`.

WordPress.org SVN is a release system, not a development repository. Only push versions that are ready to use.

## One-time setup

- Set an SVN password at https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password. It is separate from the WordPress.org account password.
- Required tools: `svn`, `php` (with the ZipArchive extension), `composer`, `node`/`npm` (see `.nvmrc`), and `wp` (WP-CLI, for POT generation).
- Add `plugins@wordpress.org` to your email allow-list.

## Repository layout

| Local | SVN | Notes |
| --- | --- | --- |
| `dist/brain-2fa/` (built by `npm run dist`) | `trunk/` | Production files only: `assets`, `includes`, `languages`, `vendor` (no dev deps), `brain-2fa.php`, `composer.json`, `composer.lock`, `readme.txt`. |
| — | `tags/x.y.z/` | Copy of trunk for each release. `Stable tag` in readme.txt points here. |
| `.wordpress-org/` | `assets/` | Listing assets only (see below). |

### Listing assets (`.wordpress-org/`)

| File | Size | Used for |
| --- | --- | --- |
| `icon-128x128.png`, `icon-256x256.png` | 128², 256² | Plugin icon (search results, plugin card). |
| `banner-772x250.png`, `banner-1544x500.png` | 772×250, 1544×500 | Header banner (normal and retina). |
| `screenshot-N.png` | any | Screenshots. The caption is line N of `== Screenshots ==` in readme.txt. |
| `blueprints/blueprint.json` | — | Live Preview in WordPress Playground. |

`.wordpress-org/source/` keeps the original, unedited screenshots. The deploy script uploads only the file names in the table above. Screenshot 4 is a redacted copy (a placeholder replaces the real email address); keep personal data out of every screenshot.

## Releasing a new version

1. **Bump the version** to the same value in all four places:
   - `brain-2fa.php`: the `Version:` header and `define( 'BRAIN_2FA_VERSION', ... )`
   - `readme.txt`: `Stable tag:`
   - `package.json`: `"version"`
2. **Update `readme.txt`:** add a changelog entry `= x.y.z =` at the top of `== Changelog ==` and an `== Upgrade Notice ==` entry. Bump `Tested up to:` when you have tested a newer WordPress version.
3. **Check:** `composer phpcs` and `npm run lint`.
4. **Commit** everything (the deploy script refuses a dirty working tree unless you pass `--allow-dirty`).
5. **Dry run:** `npm run deploy:dry`. This builds, checks out SVN into a temporary directory, syncs trunk and assets, and prints the pending changes without committing.
6. **Deploy:** `npm run deploy`. Confirm the prompt and enter the SVN password when SVN asks. The script commits trunk and assets, creates `tags/x.y.z`, and offers to create a matching Git tag.
7. Check https://wordpress.org/plugins/brain-2fa/ after a few minutes.

## Updating only the listing

Banner, icon, screenshot or blueprint changes do not need a release:

```sh
npm run deploy:assets
```

Screenshot captions come from readme.txt, so a caption change needs the readme in trunk and in the current stable tag. Either do a release, or edit `trunk/readme.txt` and `tags/<stable>/readme.txt` directly in SVN.

## What the deploy script checks

`scripts/deploy.sh` stops with an error when:

- versions differ between the plugin header, the constant, `Stable tag` and package.json;
- readme.txt has no changelog entry for the version;
- the Git working tree is dirty;
- `tags/<version>` already exists on SVN (released versions are never overwritten);
- the build is missing required files, contains development files or Composer dev dependencies, or has a PHP syntax error;
- `dist/` is stale (its readme.txt or version does not match).

It sets `svn:mime-type` on images in `assets/` so WordPress.org serves them inline.

Options: `--dry-run`, `--skip-build`, `--assets-only`, `--allow-dirty`. Set `SVN_USER` to override the username.
