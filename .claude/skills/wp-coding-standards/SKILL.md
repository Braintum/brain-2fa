---
name: wp-coding-standards
description: WordPress Coding Standards for Brain 2FA PHP and JavaScript. Use when writing or changing PHP in brain-2fa.php or includes/, JavaScript in src/js/, or when fixing PHPCS/ESLint violations reported by the lint hook.
---

# WordPress Coding Standards (Brain 2FA)

The PHP baseline is clean against `phpcs.xml.dist` (WordPress-Extra + WordPress-Docs, PHP 8.1+, WordPress 6.4+). The JavaScript baseline is clean against `eslint-config-wordpress`. Keep both clean: the PostToolUse hook runs `phpcbf`/`phpcs` and `eslint --fix`/`eslint` on every edited file and returns violations to you. **The hook only runs when the session was opened in the plugin directory** (see CLAUDE.md). If you got no hook feedback after an edit, assume it did not run: run `composer phpcs` and `npm run lint` yourself before reporting the work as done. CI runs the same checks on every push to `master`.

## Commands

```sh
composer phpcs                      # whole plugin
vendor/bin/phpcs --standard=phpcs.xml.dist includes/Auth/LoginController.php
composer phpcbf                     # auto-fix formatting
npm run lint                        # ESLint on src/js
npm run lint:fix
```

## PHP rules that matter here

- **Namespaces and prefixes.** Classes live under `Brain_2FA\` (PSR-4 from `includes/`). Global functions, hooks, options, user meta, transients and cookies use the `brain2fa_` prefix. The one existing exception is the `brain_2fa_loaded` action; do not rename it.
- **Text domain** is `brain-2fa` for every translatable string. Add a `/* translators: */` comment immediately before any string with placeholders, and use numbered placeholders (`%1$s`) when there is more than one.
- **Escape late, at output.** `esc_html()`, `esc_attr()`, `esc_url()`, `esc_html__()`, `esc_attr_e()`, `wp_kses()` with an explicit allow-list. Never echo a variable without escaping, even one you sanitized earlier.
- **Input.** `wp_unslash()` first, then sanitize (`sanitize_text_field()`, `absint()`, `sanitize_key()`), then validate against an allow-list where possible. Passwords are the exception: unslash but never sanitize.
- **Strict comparisons and Yoda conditions:** `if ( 'email' === $method_id )`. Pass `true` as the third argument to `in_array()`.
- **Long array syntax** `array( ... )`. Short `[]` is reported by WordPress-Core.
- **Spacing:** tabs for indentation, a space inside parentheses `foo( $bar )`, and aligned `=` in consecutive assignments (phpcbf fixes this).
- **Docblocks** on every file, class, method and property, with `@param`, `@return` and a `@package` tag. `@since` matches the version that introduced the code.
- **Type declarations** are welcome (the code base targets PHP 8.1), but keep public method signatures compatible with `TwoFactorMethodInterface`.
- **Every PHP file** starts with the `ABSPATH` guard used in the existing files.
- **Class files use PSR-4 names** (`includes/Emails/Mailer.php` holds `Brain_2FA\Emails\Mailer`), not WordPress `class-*.php` names. Start each class file with `<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName`, as the existing ones do. This is the only accepted file-level exception.
- **View templates** (`includes/Admin/views/`) run in the global scope. Prefix every variable a template creates with `brain2fa_` (PrefixAllGlobals sniff), list the variables it receives in a `@var` docblock, and never build an `include` path from request data. Use a fixed `if`/`elseif` map instead.
- **SQL:** write each query as one complete string literal with placeholders, using `%i` for table names, and pass it straight to `$wpdb->prepare()`. If the query has variants, write each variant out in full rather than building the string conditionally: the PreparedSQL sniff cannot verify built strings, and suppressing it is not allowed.
- **Settings and admin forms:**
  - Check the capability and nonce first.
  - Handle the POST on the page's `load-{hook}` action, before any output.
  - After a success, call `wp_safe_redirect()` then `exit`.
  - After a failure, render the form again with the submitted values and the error next to the field, rather than redirecting and losing the input.

## phpcs:ignore etiquette

Suppress only a specific sniff, on the line it applies to, with a reason:

```php
$password = wp_unslash( $_POST[ $password_key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be altered.
```

Never add a bare `// phpcs:ignore` or a file-wide `phpcs:disable` to silence the hook. If a security sniff fires (nonce, escaping, sanitizing), fix the code. Read `.claude/skills/wp-security/SKILL.md` before you decide a suppression is justified.

## JavaScript

- Source lives in `src/js/` (ES modules, bundled by webpack). Never edit `assets/js/`. Run `npm run build` after changes.
- Use `@wordpress/i18n` (`__`, `sprintf`) with the `brain-2fa` domain for user-facing text.
- `Brain2FA` (localized data) and `wp` are declared globals in `.eslintrc.js`. Do not add new globals without declaring them there.
- `src/js/examples.js` is excluded from linting and from the POT file. Do not import it.

## SCSS

Edit `src/scss/` partials and run `npm run build`. Never edit `assets/css/admin.css` by hand. The lint hook compiles `src/scss/admin.scss` after each SCSS edit to catch errors. `admin.css` also loads on the Users screens, so scope every rule under a plugin class (`.brain2fa-…`), never a bare element or attribute selector. Keep `_responsive.scss` last in `admin.scss`.

## Release metadata

The version lives in the `brain-2fa.php` header, `BRAIN_2FA_VERSION`, `readme.txt` `Stable tag`, and `package.json`. Run `bash scripts/check-versions.sh`; it also checks "Requires at least", "Requires PHP" and the changelog entry. CI fails when they disagree.
