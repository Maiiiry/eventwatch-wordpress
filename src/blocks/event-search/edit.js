import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Disabled,
	PanelBody,
	RangeControl,
	SelectControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

import metadata from './block.json';

export default function Edit( { attributes, setAttributes } ) {
	const { layout, perPage } = attributes;

	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Display', 'eventwatch' ) }>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Layout', 'eventwatch' ) }
						value={ layout }
						options={ [
							{
								value: 'cards',
								label: __( 'Cards', 'eventwatch' ),
							},
							{ value: 'list', label: __( 'List', 'eventwatch' ) },
						] }
						onChange={ ( value ) =>
							setAttributes( { layout: value } )
						}
					/>
					<RangeControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Events per page', 'eventwatch' ) }
						value={ perPage }
						min={ 1 }
						max={ 24 }
						onChange={ ( value ) =>
							setAttributes( { perPage: value ?? 6 } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			{ /* 公開の表示と同じ PHP を通す。編集中に検索やリンクで画面を離れないよう、操作は止める。 */ }
			<Disabled>
				<ServerSideRender
					block={ metadata.name }
					attributes={ attributes }
					skipBlockSupportAttributes
				/>
			</Disabled>
		</div>
	);
}
