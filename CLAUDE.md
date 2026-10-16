@AGENTS.md

## Claude Code

### Hooks (`.claude/settings.json`)

- **PreToolUse `guard-generated-files.sh`** blocks edits to `vendor/`, `node_modules/`, `dist/`, compiled `assets/js` and `assets/css`, the POT file, and `.wordpress-org/source/`. The block message names the source file to edit instead.
- **PostToolUse `lint-changed-file.sh`** runs after every edit:
  - PHP in `brain-2fa.php` or `includes/`: `php -l`, then `phpcbf` (auto-fix), then `phpcs`.
  - JS in `src/js/`: `eslint --fix`, then `eslint`.

  Remaining violations come back to you as hook feedback; fix them before you move on. When the hook says it auto-fixed formatting, re-read the file before your next edit.

The hooks run only when Claude Code is opened in this plugin directory, where `$CLAUDE_PROJECT_DIR` is the plugin root.

### Skills (`.claude/skills/`)

- `wp-coding-standards`: WPCS rules and commands for this code base.
- `wp-security`: security invariants and the review checklist. Load it before touching anything auth-related.
- `release`: the WordPress.org release procedure. It is user-invoked only (`/release`).
