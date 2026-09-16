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
		'vendor/'
	] ),

	...compat.extends( 'wikimedia/server' ),

	{
		rules: {
			camelcase: 'off',
			'linebreak-style': 'off'
		}
	},

	{
		files: [ 'resources/**/*.js' ],
		languageOptions: {
			ecmaVersion: 2019,
			sourceType: 'script',
			globals: {
				document: 'readonly',
				window: 'readonly',
				mw: 'readonly'
			}
		},
		rules: {
			'json-es/use-valid-json': 'off',
			camelcase: 'off',
			'linebreak-style': 'off'
		}
	}
] );
