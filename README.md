# Brain 2FA

A modern, secure Two-Factor Authentication (2FA) plugin for WordPress with support for TOTP authenticator apps and email-based verification.

[![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://github.com/braintum/brain-2fa)
[![License](https://img.shields.io/badge/license-GPLv2+-green.svg)](LICENSE)
[![WordPress](https://img.shields.io/badge/wordpress-5.0+-blue.svg)](https://wordpress.org)

## Features

- 🔐 **TOTP Support** - Compatible with Google Authenticator, Authy, and other authenticator apps
- 📧 **Email-Based 2FA** - Alternative verification via email codes
- 🎨 **Modern UI** - Clean, user-friendly interface for settings and setup
- 🔄 **Backup Codes** - Generate emergency backup codes for account recovery
- 👥 **Role-Based Enforcement** - Require 2FA for specific user roles
- 📱 **QR Code Generation** - Easy setup with QR codes for authenticator apps
- 🌐 **Internationalization Ready** - Fully translatable (i18n)
- ⚡ **Performance Optimized** - Lightweight with modern JavaScript architecture
- 🎯 **WordPress Standards** - Follows WordPress coding standards and best practices

## Requirements

- **WordPress**: 5.0 or higher
- **PHP**: 7.4 or higher
- **Node.js**: 18.0 or higher (for development)
- **npm**: 9.0 or higher (for development)

## Installation

### Via WordPress Admin

1. Download the latest release
2. Navigate to **Plugins > Add New** in WordPress admin
3. Click **Upload Plugin** and select the downloaded zip file
4. Click **Install Now** and then **Activate**

### Manual Installation

1. Download and extract the plugin files
2. Upload the `brain-2fa` folder to `/wp-content/plugins/`
3. Activate the plugin through the **Plugins** menu in WordPress

### Composer Installation

```bash
composer require braintum/brain-2fa
```

## Configuration

### Basic Setup

1. Navigate to **Settings > Brain 2FA** in WordPress admin
2. Configure your preferred 2FA method (Authenticator App or Email)
3. Optionally enable force 2FA for specific user roles
4. Save settings

### User Setup

Users can set up 2FA from their profile page:

1. Go to **Users > Profile**
2. Scroll to the **Brain 2FA** section
3. Enable 2FA and follow the setup instructions
4. Save backup codes in a secure location

## Development

### Setup

```bash
# Clone the repository
git clone https://github.com/braintum/brain-2fa.git
cd brain-2fa

# Install PHP dependencies
composer install

# Install JavaScript dependencies
npm install
```

### Build Assets

```bash
# Development mode (watch for changes)
npm run dev

# Production build
npm run build

# Development build (unminified)
npm run build:dev

# Lint JavaScript
npm run lint
npm run lint:fix
```

### Project Structure

```
brain-2fa/
├── assets/              # Compiled assets (auto-generated)
│   ├── css/            # Compiled CSS
│   └── js/             # Compiled JavaScript
├── includes/           # PHP classes (PSR-4)
│   ├── Admin/         # Admin functionality
│   ├── Auth/          # Authentication logic
│   ├── TwoFactor/     # 2FA implementations
│   └── App.php        # Main application class
├── languages/         # Translation files
├── src/               # Source files (development)
│   ├── js/           # JavaScript source
│   │   ├── components/
│   │   └── utils/
│   └── scss/         # SCSS source
│       ├── _variables.scss
│       ├── _components.scss
│       └── ...
├── vendor/           # Composer dependencies
├── webpack.config.js # Webpack configuration
├── package.json      # NPM dependencies
└── composer.json     # Composer configuration
```

### JavaScript Architecture

The plugin uses a modern JavaScript build system:

- **Webpack 5** for bundling
- **Babel** for ES6+ transpilation
- **ESLint** for code quality
- **Sass** for styling
- **WordPress i18n** for translations
- **Functional programming** approach (no classes)

See [README-JAVASCRIPT.md](README-JAVASCRIPT.md) for detailed JavaScript development documentation.

### Coding Standards

The plugin follows:

- [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- [WordPress JavaScript Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/javascript/)
- PSR-4 autoloading for PHP classes

## Translation

### Generate POT file

```bash
wp i18n make-pot . languages/brain2fa.pot --domain=brain2fa
```

### Create translations

1. Use [Poedit](https://poedit.net/) or similar tool to create `.po` files
2. Compile to `.mo` files
3. For JavaScript translations, generate JSON files:

```bash
wp i18n make-json languages --no-purge
```

## Security

### Reporting Vulnerabilities

If you discover a security vulnerability, please email security@braintum.com. All security vulnerabilities will be promptly addressed.

### Security Features

- Secure TOTP implementation using industry-standard algorithms
- Rate limiting on login attempts
- Encrypted storage of secret keys
- Secure random generation for backup codes
- CSRF protection on all forms

## Contributing

We welcome contributions! Please follow these steps:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Make your changes following WordPress coding standards
4. Test thoroughly
5. Commit your changes (`git commit -m 'Add amazing feature'`)
6. Push to the branch (`git push origin feature/amazing-feature`)
7. Open a Pull Request

### Development Guidelines

- Write clean, documented code
- Follow WordPress and PSR-4 standards
- Add translations for all user-facing strings
- Test on multiple WordPress versions
- Update documentation as needed

## Testing

```bash
# Run PHP tests (if available)
composer test

# Run JavaScript linting
npm run lint

# Check for WordPress coding standards
composer phpcs
```

## Changelog

### Version 1.0.0
- Initial release
- TOTP authenticator app support
- Email-based 2FA
- QR code generation
- Backup codes
- Role-based enforcement
- Modern JavaScript architecture
- i18n support

## FAQ

### How do I reset 2FA for a user?

As an administrator, you can disable 2FA for any user from their profile page in WordPress admin.

### What happens if I lose my authenticator app?

Use one of your backup codes to log in, then set up 2FA again with a new device.

### Can I use this plugin on multisite?

Yes, Brain 2FA is compatible with WordPress multisite installations.

### Which authenticator apps are supported?

Any TOTP-compatible app works, including:
- Google Authenticator
- Microsoft Authenticator
- Authy
- 1Password
- Bitwarden

## Support

- **Documentation**: [README-JAVASCRIPT.md](README-JAVASCRIPT.md)
- **Issues**: [GitHub Issues](https://github.com/braintum/brain-2fa/issues)
- **Website**: [https://www.braintum.com/](https://www.braintum.com/)

## License

This plugin is licensed under the GPLv2 (or later).

```
Brain 2FA - Two Factor Authentication for WordPress
Copyright (C) 2026 Braintum

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```

## Credits

Built with ❤️ by [Braintum](https://www.braintum.com/)

### Dependencies

- [OTPHP](https://github.com/Spomky-Labs/otphp) - TOTP implementation
- [Endroid QR Code](https://github.com/endroid/qr-code) - QR code generation
- [WordPress i18n](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-i18n/) - Internationalization

---

**Made with modern JavaScript and WordPress best practices** 🚀
