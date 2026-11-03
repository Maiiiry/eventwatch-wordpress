import apiFetch from '@wordpress/api-fetch';
import { Button, ToggleControl } from '@wordpress/components';
import { useEntityProp } from '@wordpress/core-data';
import { useRegistry, useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { EVENT_PANEL_ID, SYNC_BLOCKS_EVENT } from './panel-names';

const POST_TYPE = 'eventwatch_event';
const SEND_FIELD = 'eventwatch_send';

// 保存の応答は送信の前に作られるので、送信待ちの間だけ読み直す。
const REFRESH_INTERVAL = 5000;
const REFRESH_MAX = 3;

const ICONS = {
	check: 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
	clock: 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
	minus: 'M15 12H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
	pause: 'M14.25 9v6m-4.5 0V9M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
	warning:
		'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z',
};

const STATE_LOOKS = {
	unsent: { icon: 'minus', tone: 'muted' },
	queued: { icon: 'clock', tone: 'muted' },
	review: { icon: 'clock', tone: 'info' },
	live: { icon: 'check', tone: 'success' },
	fix: { icon: 'warning', tone: 'warning' },
	stopped: { icon: 'pause', tone: 'warning' },
	withdrawn: { icon: 'minus', tone: 'muted' },
	failed: { icon: 'warning', tone: 'error' },
	off: { icon: 'minus', tone: 'muted' },
};

const Icon = ( { name, size = 20 } ) => (
	<svg
		width={ size }
		height={ size }
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.6"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<path d={ ICONS[ name ] } />
	</svg>
);

const useSyncStatus = ( postId ) => {
	const [ status, setStatus ] = useState( null );
	const [ resending, setResending ] = useState( false );
	const timer = useRef();
	const generation = useRef( 0 );

	const refresh = useCallback(
		( remaining = 0 ) => {
			window.clearTimeout( timer.current );
			generation.current += 1;
			const current = generation.current;

			apiFetch( { path: `/eventwatch/v1/events/${ postId }/sync` } )
				.then( ( next ) => {
					if ( current !== generation.current ) {
						return;
					}
					setStatus( next );
					if ( next.state === 'queued' && remaining > 0 ) {
						timer.current = window.setTimeout(
							() => refresh( remaining - 1 ),
							REFRESH_INTERVAL
						);
					}
				} )
				// 状態を読めなくても、編集を止めない。次の保存で読み直す。
				.catch( () => {} );
		},
		[ postId ]
	);

	useEffect( () => {
		if ( postId ) {
			refresh();
		}
		return () => window.clearTimeout( timer.current );
	}, [ postId, refresh ] );

	const saving = useSelect( ( select ) => {
		const editor = select( editorStore );
		return editor.isSavingPost() && ! editor.isAutosavingPost();
	}, [] );
	const wasSaving = useRef( false );
	useEffect( () => {
		if ( wasSaving.current && ! saving ) {
			refresh( REFRESH_MAX );
		}
		wasSaving.current = saving;
	}, [ saving, refresh ] );

	const resend = () => {
		setResending( true );
		generation.current += 1;
		apiFetch( {
			path: `/eventwatch/v1/events/${ postId }/resend`,
			method: 'POST',
		} )
			.then( setStatus )
			.catch( () => {} )
			.finally( () => setResending( false ) );
	};

	return { status, resend, resending };
};

const SendBlocks = ( { blocks, heading, description } ) => {
	const registry = useRegistry();

	// 欄はパネルの中にある。閉じていれば開いてから移す。Slot が遅れて描くので待つ。
	const focusField = ( id ) => {
		const editor = registry.select( editorStore );
		if ( ! editor.isEditorPanelOpened( EVENT_PANEL_ID ) ) {
			registry
				.dispatch( editorStore )
				.toggleEditorPanelOpened( EVENT_PANEL_ID );
		}
		window.requestAnimationFrame( () => {
			window.setTimeout( () => document.getElementById( id )?.focus() );
		} );
	};

	return (
		<div className="eventwatch-sync__blocks">
			<span className="eventwatch-sync__blocks-icon">
				<Icon name="warning" />
			</span>
			<div className="eventwatch-sync__blocks-body">
				<p className="eventwatch-sync__blocks-heading">{ heading }</p>
				<p className="eventwatch-sync__muted">{ description }</p>
				<ul>
					{ blocks.map( ( block ) => (
						<li key={ block.code }>
							<span>{ block.message }</span>
							{ block.field && (
								<>
									{ ' ' }
									<Button
										variant="link"
										onClick={ () =>
											focusField( block.field )
										}
									>
										{ block.action }
									</Button>
								</>
							) }
						</li>
					) ) }
				</ul>
			</div>
		</div>
	);
};

const SyncPanelContent = ( { unsavedText } ) => {
	const postId = useSelect(
		( select ) => select( editorStore ).getCurrentPostId(),
		[]
	);
	const [ send, setSend ] = useEntityProp(
		'postType',
		POST_TYPE,
		SEND_FIELD
	);
	const { status, resend, resending } = useSyncStatus( postId );

	const categoryBlocked = Boolean(
		send !== false &&
		status?.blocks.some( ( block ) => block.code === 'category' )
	);
	useEffect( () => {
		document.dispatchEvent(
			new window.CustomEvent( SYNC_BLOCKS_EVENT, {
				detail: { category: categoryBlocked },
			} )
		);
	}, [ categoryBlocked ] );

	if ( ! status ) {
		return null;
	}

	const sending = send !== false;
	let shown = status;
	if ( ! sending ) {
		shown = { ...status, ...unsavedText.off, state: 'off' };
	} else if ( status.state === 'off' ) {
		shown = { ...status, ...unsavedText.unsent, state: 'unsent' };
	}
	const look = STATE_LOOKS[ shown.state ] ?? STATE_LOOKS.unsent;
	const blocks = sending ? status.blocks : [];

	return (
		<PluginDocumentSettingPanel name="eventwatch-sync" title="EventWatch">
			<div className="eventwatch-sync">
				{ blocks.length > 0 && (
					<SendBlocks
						blocks={ blocks }
						heading={ __(
							'Can’t send to EventWatch',
							'eventwatch'
						) }
						description={ __(
							'Fix the points below, and the post is sent when you update it. Publishing on your site isn’t affected.',
							'eventwatch'
						) }
					/>
				) }
				<div
					className={ `eventwatch-sync__state eventwatch-sync__state--${ look.tone }` }
				>
					<Icon name={ look.icon } />
					<div className="eventwatch-sync__state-text">
						<span className="eventwatch-sync__label">
							{ shown.label }
						</span>
						<span className="eventwatch-sync__muted">
							{ shown.description }
						</span>
					</div>
				</div>
				{ sending && shown.reason.text && (
					<div className="eventwatch-sync__reason">
						<span className="eventwatch-sync__reason-label">
							{ shown.reason.label }
						</span>
						<p>{ shown.reason.text }</p>
					</div>
				) }
				{ sending && shown.meta.value && (
					<dl className="eventwatch-sync__meta">
						<dt>{ shown.meta.label }</dt>
						<dd>{ shown.meta.value }</dd>
					</dl>
				) }
				{ sending && shown.canResend && (
					<Button
						__next40pxDefaultSize
						variant="secondary"
						className="eventwatch-sync__resend"
						isBusy={ resending }
						disabled={ resending }
						accessibleWhenDisabled
						onClick={ resend }
					>
						{ __( 'Retry now', 'eventwatch' ) }
					</Button>
				) }
				<div className="eventwatch-sync__toggle">
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Send this post to EventWatch',
							'eventwatch'
						) }
						checked={ sending }
						onChange={ setSend }
					/>
				</div>
			</div>
		</PluginDocumentSettingPanel>
	);
};

const SyncPanel = () => {
	const { sync } = window.eventwatchEditor ?? {};
	const postType = useSelect(
		( select ) => select( editorStore ).getCurrentPostType(),
		[]
	);

	// キーなしのサイトでは騒がない。
	if ( postType !== POST_TYPE || ! sync?.hasKey ) {
		return null;
	}

	return <SyncPanelContent unsavedText={ sync.unsavedText } />;
};

export default SyncPanel;
