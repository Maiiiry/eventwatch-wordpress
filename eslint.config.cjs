const defaults = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaults,
	{
		// ブラウザーでの確認の手順の置き場。git で追わない。
		ignores: [ '.playwright-mcp/**' ],
	},
	{
		// @wordpress の部品は WordPress 本体が実行時に渡す（ビルドで外部のままにする）ので、依存に入れない。
		files: [ 'src/**/*.js' ],
		rules: {
			'import/no-extraneous-dependencies': 'off',
			'import/no-unresolved': [ 'error', { ignore: [ '^@wordpress/' ] } ],
		},
	},
];
