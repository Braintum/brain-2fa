# JavaScript Development Setup for Brain 2FA

This document explains the JavaScript development environment for the Brain 2FA WordPress plugin.

## Overview

The plugin now uses a modern JavaScript build system with:
- **Webpack 5** for bundling
- **Babel** for ES6+ transpilation
- **WordPress i18n** for internationalization
- **ESLint** for code quality
- **Modular architecture** for maintainability

## Directory Structure

```
brain-2fa/
├── src/                          # Source files (development)
│   └── js/
│       ├── login.js              # Login entry point
│       ├── admin.js              # Admin entry point
│       ├── components/           # Reusable components
│       │   └── LoginForm.js      # Login form handler
│       └── utils/                # Utility functions
│           ├── ajax.js           # AJAX helpers
│           ├── dom.js            # DOM manipulation
│           └── notification.js   # Notifications
├── assets/                       # Compiled files (production)
│   ├── js/
│   │   ├── login.js              # Compiled login bundle
│   │   ├── login.asset.php       # Auto-generated dependencies
│   │   ├── admin.js              # Compiled admin bundle
│   │   └── admin.asset.php       # Auto-generated dependencies
│   └── css/
├── webpack.config.js             # Webpack configuration
├── package.json                  # NPM dependencies
├── .babelrc.js                   # Babel configuration
└── .eslintrc.js                  # ESLint configuration
```

## Getting Started

### 1. Install Dependencies

```bash
npm install
```

### 2. Development Mode

Watch for changes and automatically rebuild:

```bash
npm run dev
```

### 3. Production Build

Build optimized files for production:

```bash
npm run build
```

### 4. Linting

Check code quality:

```bash
npm run lint
```

Auto-fix issues:

```bash
npm run lint:fix
```

## Features

### 1. Internationalization (i18n)

All text strings use WordPress i18n functions:

```javascript
import { __ } from '@wordpress/i18n';

const message = __('Verification Code', 'brain2fa');
const formatted = sprintf(__('Welcome, %s!', 'brain2fa'), userName);
```

### 2. Automatic Dependency Management

Webpack automatically generates `.asset.php` files with dependencies:

```php
// login.asset.php (auto-generated)
return array(
    'dependencies' => array('wp-i18n'),
    'version' => '1.0.0'
);
```

### 3. Modular Architecture

Components are split into logical modules:

```javascript
// Import utilities
import { sendAjaxRequest } from '@/utils/ajax';
import { showError } from '@/utils/notification';

// Use in your code
const response = await sendAjaxRequest(data);
showError(message);
```

### 4. Modern JavaScript

Write modern ES6+ code:

```javascript
// Async/await
async function validateCredentials() {
    try {
        const response = await sendAjaxRequest(data);
        // Handle response
    } catch (error) {
        // Handle error
    }
}

// Arrow functions
const handleSubmit = (event) => {
    event.preventDefault();
    // Handle submit
};

// Destructuring
const { username, password } = formData;

// Template literals
const message = `Welcome, ${userName}!`;
```

## Translation Workflow

### 1. Extract Translatable Strings

Generate POT file for translators:

```bash
wp i18n make-pot . languages/brain2fa.pot --domain=brain2fa
```

### 2. Translate

Translators create `.po` files from the `.pot` file.

### 3. Compile

Compile `.po` files to `.mo`:

```bash
msgfmt languages/brain2fa-fr_FR.po -o languages/brain2fa-fr_FR.mo
```

### 4. JavaScript Translations

Convert `.po` to `.json` for JavaScript:

```bash
wp i18n make-json languages --no-purge
```

This creates `.json` files that WordPress uses for JavaScript translations.

## Extending the Setup

### Adding New Entry Points

1. Create new file in `src/js/`:

```javascript
// src/js/settings.js
import { __ } from '@wordpress/i18n';

console.log(__('Settings loaded', 'brain2fa'));
```

2. Add to webpack config:

```javascript
entry: {
    'login': './src/js/login.js',
    'admin': './src/js/admin.js',
    'settings': './src/js/settings.js', // New entry
},
```

3. Enqueue in PHP:

```php
$asset_file = BRAIN_2FA_PLUGIN_DIR . 'assets/js/settings.asset.php';
$asset_data = file_exists( $asset_file ) ? require $asset_file : array(
    'dependencies' => array(),
    'version'      => BRAIN_2FA_VERSION,
);

wp_enqueue_script(
    'brain-2fa-settings',
    BRAIN_2FA_PLUGIN_URL . 'assets/js/settings.js',
    $asset_data['dependencies'],
    $asset_data['version'],
    true
);

wp_set_script_translations(
    'brain-2fa-settings',
    'brain2fa',
    BRAIN_2FA_PLUGIN_DIR . 'languages'
);
```

### Adding New Components

Create reusable components in `src/js/components/`:

```javascript
// src/js/components/Modal.js
import { __ } from '@wordpress/i18n';

class Modal {
    constructor(title, content) {
        this.title = title;
        this.content = content;
        this.render();
    }

    render() {
        // Modal rendering logic
    }

    show() {
        // Show modal
    }

    hide() {
        // Hide modal
    }
}

export default Modal;
```

Use in your code:

```javascript
import Modal from '@/components/Modal';

const modal = new Modal(
    __('Confirmation', 'brain2fa'),
    __('Are you sure?', 'brain2fa')
);
modal.show();
```

### Adding Utilities

Create utility functions in `src/js/utils/`:

```javascript
// src/js/utils/validation.js
import { __ } from '@wordpress/i18n';

export function isValidEmail(email) {
    const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return regex.test(email);
}

export function validateForm(data) {
    const errors = [];
    
    if (!data.email || !isValidEmail(data.email)) {
        errors.push(__('Invalid email address', 'brain2fa'));
    }
    
    return errors;
}
```

## Best Practices

1. **Always use i18n functions** for user-facing text
2. **Keep components small and focused** on a single responsibility
3. **Use async/await** instead of callbacks for cleaner code
4. **Leverage webpack aliases** (`@/`) for cleaner imports
5. **Run linting** before committing code
6. **Build for production** before releases
7. **Test translations** in multiple languages

## Troubleshooting

### Build Fails

- Check Node.js version: `node --version` (requires 18+)
- Clear `node_modules` and reinstall: `rm -rf node_modules && npm install`
- Check for syntax errors in source files

### Translations Not Working

- Ensure `.json` files are generated: `wp i18n make-json languages`
- Check text domain matches: `'brain2fa'`
- Verify `wp_set_script_translations()` is called in PHP

### Dependencies Not Loading

- Check `.asset.php` files are generated
- Verify WordPress dependencies are available
- Check browser console for errors

## Resources

- [WordPress Handbook - JavaScript](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-i18n/)
- [Webpack Documentation](https://webpack.js.org/)
- [Babel Documentation](https://babeljs.io/)
- [@wordpress/i18n Package](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-i18n/)
