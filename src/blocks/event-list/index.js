import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';
import Edit from './edit';
import './style.css';

registerBlockType( metadata.name, {
	edit: Edit,
	// サーバーで描く動的ブロックなので、投稿の本文には属性だけを残す。
	save: () => null,
	variations: [
		{
			name: 'this-month',
			title: __( 'Events This Month', 'eventwatch' ),
			description: __(
				'Show the events of this month that have not ended.',
				'eventwatch'
			),
			attributes: { period: 'thisMonth' },
			isActive: [ 'period' ],
			scope: [ 'inserter', 'block', 'transform' ],
		},
		{
			name: 'coming-soon',
			title: __( 'Coming Soon', 'eventwatch' ),
			description: __(
				'Show the next events within 30 days.',
				'eventwatch'
			),
			attributes: { period: 'next30Days', perPage: 3 },
			isActive: [ 'period' ],
			scope: [ 'inserter', 'block', 'transform' ],
		},
	],
} );
