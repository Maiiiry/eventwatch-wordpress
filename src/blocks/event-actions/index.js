import { registerBlockType } from '@wordpress/blocks';
import {
	InnerBlocks,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';

import metadata from './block.json';

const TEMPLATE = [
	[ 'eventwatch/event-field', { field: 'calendar' } ],
	[ 'eventwatch/event-field', { field: 'interested' } ],
	[ 'eventwatch/event-field', { field: 'share' } ],
];

function Edit() {
	const blockProps = useBlockProps();
	const innerBlocksProps = useInnerBlocksProps( blockProps, {
		template: TEMPLATE,
		orientation: 'horizontal',
	} );

	return <div { ...innerBlocksProps } />;
}

registerBlockType( metadata.name, {
	edit: Edit,
	// 外側はサーバーが描く。中のブロックだけを本文に残す。
	save: () => <InnerBlocks.Content />,
} );
