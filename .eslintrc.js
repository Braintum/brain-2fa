module.exports = {
	extends: ['wordpress'],
	ignorePatterns: ['src/js/examples.js'],
	env: {
		browser: true,
		es2021: true,
		jquery: true,
	},
	parserOptions: {
		ecmaVersion: 2021,
		sourceType: 'module',
	},
	rules: {
		'camelcase': 'off',
	},
	globals: {
		wp: 'readonly',
		Brain2FA: 'readonly',
	},
};
