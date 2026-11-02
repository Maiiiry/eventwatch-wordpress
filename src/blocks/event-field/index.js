import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { _x, __ } from '@wordpress/i18n';

import metadata from './block.json';
import './style.css';

const FIELDS = [
	[ 'back', __( 'Back to events', 'eventwatch' ) ],
	[ 'category', __( 'Event category', 'eventwatch' ) ],
	[ 'dates', __( 'Event date & time', 'eventwatch' ) ],
	[ 'venue', __( 'Event venue', 'eventwatch' ) ],
	[ 'organizer', __( 'Event organizer', 'eventwatch' ) ],
	[ 'map', __( 'Event map', 'eventwatch' ) ],
	[ 'calendar', __( 'Add to calendar', 'eventwatch' ) ],
	[
		'interested',
		_x( 'Save', 'mark an event you are interested in', 'eventwatch' ),
	],
	[ 'share', __( 'Share', 'eventwatch' ) ],
	[ 'official', __( 'Visit official page', 'eventwatch' ) ],
];

const LABELS = Object.fromEntries( FIELDS );

// テンプレートの編集には投稿が無いので、値を描かずに欄の名前を出す。
function Edit( { attributes } ) {
	return (
		<div { ...useBlockProps( { className: 'is-editor-preview' } ) }>
			{ LABELS[ attributes.field ] ?? attributes.field }
		</div>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	// サーバーで描く動的ブロックなので、投稿の本文には属性だけを残す。
	save: () => null,
	variations: FIELDS.map( ( [ field, title ] ) => ( {
		name: field,
		title,
		attributes: { field },
		isActive: [ 'field' ],
		scope: [ 'inserter', 'transform' ],
	} ) ),
} );
