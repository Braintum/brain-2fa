module.exports = {
	extends: ['wordpress'],
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
		'no-console': 'warn',
		'camelcase': 'off',
	},
	globals: {
		wp: 'readonly',
		Brain2FA: 'readonly',
	},
};
