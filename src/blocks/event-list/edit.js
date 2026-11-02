import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Disabled,
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

import metadata from './block.json';

const TERM_QUERY = { per_page: 100, context: 'view', _fields: 'id,name' };

const anyOption = () => ( { value: '0', label: __( 'All', 'eventwatch' ) } );

function termOptions( terms ) {
	return [
		anyOption(),
		...( terms ?? [] ).map( ( term ) => ( {
			value: String( term.id ),
			// 本体はタームの名前を実体参照にして保存し、REST もそのまま返す。
			label: decodeEntities( term.name ),
		} ) ),
	];
}

export default function Edit( { attributes, setAttributes } ) {
	const {
		layout,
		perPage,
		order,
		categoryId,
		tagId,
		prefecture,
		dateFrom,
		dateTo,
		emptyText,
	} = attributes;

	const { categories, tags } = useSelect( ( select ) => {
		const { getEntityRecords } = select( coreStore );
		return {
			categories: getEntityRecords(
				'taxonomy',
				'eventwatch_event_cat',
				TERM_QUERY
			),
			tags: getEntityRecords(
				'taxonomy',
				'eventwatch_event_tag',
				TERM_QUERY
			),
		};
	}, [] );

	const setNumber = ( key ) => ( value ) =>
		setAttributes( { [ key ]: Number( value ) } );

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
						label={ __( 'Order', 'eventwatch' ) }
						value={ order }
						options={ [
							{
								value: 'asc',
								label: __( 'Soonest first', 'eventwatch' ),
							},
							{
								value: 'desc',
								label: __( 'Latest first', 'eventwatch' ),
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { order: value } )
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
							'No upcoming events yet.',
							'eventwatch'
						) }
						value={ emptyText }
						onChange={ ( value ) =>
							setAttributes( { emptyText: value } )
						}
					/>
				</PanelBody>
				<PanelBody title={ __( 'Filters', 'eventwatch' ) }>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Category', 'eventwatch' ) }
						value={ String( categoryId ) }
						options={ termOptions( categories ) }
						onChange={ setNumber( 'categoryId' ) }
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Tag', 'eventwatch' ) }
						value={ String( tagId ) }
						options={ termOptions( tags ) }
						onChange={ setNumber( 'tagId' ) }
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Prefecture', 'eventwatch' ) }
						value={ String( prefecture ) }
						options={ [
							anyOption(),
							...window.eventwatchEventList.prefectures,
						] }
						onChange={ setNumber( 'prefecture' ) }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="date"
						label={ __( 'From', 'eventwatch' ) }
						value={ dateFrom }
						onChange={ ( value ) =>
							setAttributes( { dateFrom: value } )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="date"
						label={ __( 'To', 'eventwatch' ) }
						value={ dateTo }
						onChange={ ( value ) =>
							setAttributes( { dateTo: value } )
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
