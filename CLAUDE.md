@AGENTS.md

## Claude Code

### Hooks (`.claude/settings.json`)

> [!IMPORTANT]
> Claude Code loads `.claude/settings.json` only from the directory a session is opened in. **Open Claude Code in this plugin directory** (`wp-content/plugins/brain-2fa`) so the hooks run. A session opened at the WordPress root still finds the skills below, but it runs **no hooks**. In that case, run the checks under "Validation" in AGENTS.md yourself before you report a change as done. To get the hooks in a WordPress-root session too, add them to that root's `.claude/settings.local.json` with `$CLAUDE_PROJECT_DIR/wp-content/plugins/brain-2fa/.claude/hooks/...` paths. The scripts ignore files outside this plugin.

- **PreToolUse `guard-generated-files.sh`** blocks edits to `vendor/`, `node_modules/`, `dist/`, compiled `assets/js` and `assets/css`, the POT file, and `.wordpress-org/source/`. The block message names the source file to edit instead.
- **PostToolUse `lint-changed-file.sh`** runs after every edit:
  - PHP in `brain-2fa.php` or `includes/` (views included): `php -l`, then `phpcbf` (auto-fix), then `phpcs`.
  - JS in `src/js/`: `eslint --fix`, then `eslint`.
  - SCSS in `src/scss/`: compiles `src/scss/admin.scss` with sass to catch errors (writes nothing; run `npm run build` afterwards).
  - `brain-2fa.php`, `readme.txt`, `package.json`: `scripts/check-versions.sh`. A version mismatch comes back as context, not an error, so a version bump can edit the files one at a time.

  Remaining violations come back to you as hook feedback; fix them before you move on. When the hook says it auto-fixed formatting, re-read the file before your next edit.

The scripts locate the plugin from their own path, so they work no matter which directory the session was opened in, as long as the session loads them. To check them by hand: `printf '{"tool_input":{"file_path":"%s"}}' "$PWD/includes/Utils.php" | .claude/hooks/lint-changed-file.sh; echo $?`.

### CI (`.github/workflows/ci.yml`)

Every push to `master` and every pull request runs the same checks on GitHub:
- PHP syntax on 8.1 through 8.4;
- `composer phpcs`;
- `npm run lint` and `npm run build`;
- `scripts/check-versions.sh`;
- an up-to-date POT;
- WordPress.org **Plugin Check** against the production package (`npm run package`, which builds `dist/brain-2fa`).

Keep it green: the plugin is live on WordPress.org.

### Skills (`.claude/skills/`)

- `wp-coding-standards`: WPCS rules and commands for this code base.
- `wp-security`: security invariants and the review checklist. Load it before touching anything auth-related, including emails.
- `release`: the WordPress.org release procedure. It is user-invoked only (`/release`).
