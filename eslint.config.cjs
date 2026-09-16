'use strict';

const { defineConfig, globalIgnores } = require( 'eslint/config' );
const { FlatCompat } = require( '@eslint/eslintrc' );
const js = require( '@eslint/js' );

const compat = new FlatCompat( {
	baseDirectory: __dirname,
	recommendedConfig: js.configs.recommended,
	allConfig: js.configs.all
} );

module.exports = defineConfig( [
	globalIgnores( [
		'**/node_modules/',
		'**/coverage/',
		'resources/dist/',
		'vendor/',
		'**/*.test.js'
	] ),

	...compat.extends(
		'wikimedia/client/common',
		'wikimedia/language/es2019'
	),

	{
		languageOptions: {
			ecmaVersion: 2022,
			globals: {
				mw: 'readonly',
				OO: 'readonly'
			}
		},
		rules: {
			camelcase: 'off',
			'no-use-before-define': 'off',
			'jsdoc/no-undefined-types': 'off',
			'max-statements-per-line': 'off',
			'brace-style': 'off',
			'no-unused-vars': [ 'warn', { args: 'none' } ],
			'linebreak-style': 'off',
			'preserve-caught-error': 'off'
		}
	},

	{
		files: [ 'eslint.config.cjs', '.stylelintrc.cjs' ],
		languageOptions: {
			sourceType: 'commonjs',
			globals: {
				__dirname: 'readonly',
				__filename: 'readonly',
				module: 'readonly',
				require: 'readonly',
				exports: 'readonly',
				process: 'readonly'
			}
		}
	}
] );
