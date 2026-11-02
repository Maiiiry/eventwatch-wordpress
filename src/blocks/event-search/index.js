import { registerBlockType } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';
import './style.css';

registerBlockType( metadata.name, {
	edit: Edit,
	// サーバーで描く動的ブロックなので、投稿の本文には属性だけを残す。
	save: () => null,
} );
