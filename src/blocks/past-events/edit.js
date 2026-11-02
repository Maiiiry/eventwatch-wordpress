import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Disabled,
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

import metadata from './block.json';

export default function Edit( { attributes, setAttributes } ) {
	const { layout, perPage, monthsBack, emptyText } = attributes;

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
							{
								value: 'list',
								label: __( 'List', 'eventwatch' ),
							},
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
							setAttributes( {
								perPage:
									value ??
									metadata.attributes.perPage.default,
							} )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'How far back', 'eventwatch' ) }
						value={ String( monthsBack ) }
						options={ [
							{
								value: '3',
								label: __( '3 months', 'eventwatch' ),
							},
							{
								value: '6',
								label: __( '6 months', 'eventwatch' ),
							},
							{
								value: '12',
								label: __( '1 year', 'eventwatch' ),
							},
							{
								value: '24',
								label: __( '2 years', 'eventwatch' ),
							},
							{
								value: '0',
								label: __( 'All past events', 'eventwatch' ),
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { monthsBack: Number( value ) } )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Text when there are no events',
							'eventwatch'
						) }
						placeholder={ __(
							'No past events yet.',
							'eventwatch'
						) }
						value={ emptyText }
						onChange={ ( value ) =>
							setAttributes( { emptyText: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			{ /* 公開の表示と同じ PHP を通す。編集中にリンクで画面を離れないよう、操作は止める。 */ }
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
