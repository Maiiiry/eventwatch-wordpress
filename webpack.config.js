const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// src に block.json があると、既定の入口は block.json が指すものだけになる。編集画面のパネルの入口を足す。
module.exports = {
	...defaultConfig,
	entry: () => ( {
		...defaultConfig.entry(),
		index: './src/index.js',
	} ),
};
