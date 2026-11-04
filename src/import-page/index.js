import apiFetch from '@wordpress/api-fetch';
import { createRoot, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { runImport } from './run';

const NAMESPACE = '/eventwatch/v1/import';

const form = () => document.getElementById( 'eventwatch-import-table' );
const sleep = ( ms ) =>
	new Promise( ( resolve ) => window.setTimeout( resolve, ms ) );

const selectedBoxes = () =>
	Array.from(
		form()?.querySelectorAll(
			'tbody input[name="eventwatch_import[]"]:checked:not(:disabled)'
		) ?? []
	);

const stateLabels = () => ( {
	imported: __( 'Import finished', 'eventwatch' ),
	doing: __( 'Importing', 'eventwatch' ),
	waiting: __( 'Waiting', 'eventwatch' ),
	failed: __( 'Couldn’t import', 'eventwatch' ),
	stop: __( 'Couldn’t import', 'eventwatch' ),
	cancelled: __( 'Stopped', 'eventwatch' ),
} );

const STATE_CLASSES = {
	imported: 'eventwatch-import__state--done',
	doing: 'eventwatch-import__state--doing',
	failed: 'eventwatch-import__state--failed',
	stop: 'eventwatch-import__state--failed',
};

/**
 * 表の上の、選んだ数とボタンを今の選択に合わせる。
 *
 * @param {boolean} canImport 取り込める状態か。
 */
function syncSelection( canImport ) {
	const count = selectedBoxes().length;
	const button = form()?.querySelector( '[data-eventwatch-import-start]' );
	const label = form()?.querySelector( '[data-eventwatch-import-count]' );

	if ( button ) {
		button.disabled = ! canImport || 0 === count;
	}
	if ( label ) {
		label.textContent = count
			? sprintf(
					/* translators: %d: the number of posts selected. */
					_n( '%d selected', '%d selected', count, 'eventwatch' ),
					count
				)
			: __( 'Select posts to import', 'eventwatch' );
	}
}

/**
 * 結果に合わせて行を書き換える。取り込んだ行は、結果を出している間だけ「取り込み済み」にして選べなくする。
 * 読み直すとサーバーが返さないので消える。
 *
 * @param {Object} result Event_Import::run の戻り。
 */
function updateRow( result ) {
	const row = form()?.querySelector(
		`tr[data-eventwatch-event="${ window.CSS.escape( result.eventId ) }"]`
	);
	if ( ! row ) {
		return;
	}

	const imported = 'imported' === result.status;
	const box = row.querySelector( 'input[type="checkbox"]' );
	if ( box ) {
		box.checked = false;
		box.disabled = imported;
	}

	const cell = row.querySelector( 'td.column-wordpress' );
	if ( cell && result.editUrl ) {
		cell.textContent = imported
			? `${ __( 'Imported', 'eventwatch' ) } `
			: '';
		const link = document.createElement( 'a' );
		link.href = result.editUrl;
		link.textContent = __( 'Edit post', 'eventwatch' );
		cell.append( link );
	}
}

function Progress( { items, stopping, onStop } ) {
	const labels = stateLabels();
	const finished = items.filter(
		( item ) => ! [ 'doing', 'waiting', 'queued' ].includes( item.state )
	).length;

	return (
		<section
			className="eventwatch-import__progress"
			aria-labelledby="eventwatch-import-heading"
			aria-busy="true"
		>
			<h2 id="eventwatch-import-heading">
				{ __( 'Importing events', 'eventwatch' ) }
			</h2>
			<p role="status" aria-live="polite">
				{ sprintf(
					/* translators: 1: posts finished, 2: posts selected. */
					__(
						'%1$d of %2$d done. Keep this page open until it finishes.',
						'eventwatch'
					),
					finished,
					items.length
				) }
			</p>
			<div
				className="eventwatch-import__bar"
				role="progressbar"
				aria-valuemin="0"
				aria-valuemax={ items.length }
				aria-valuenow={ finished }
				aria-labelledby="eventwatch-import-heading"
			>
				<div
					style={ {
						width: `${ ( finished / items.length ) * 100 }%`,
					} }
				/>
			</div>
			<ul className="eventwatch-import__items">
				{ items.map( ( item ) => (
					<li key={ item.id }>
						<span>{ item.title }</span>
						<span className={ STATE_CLASSES[ item.state ] }>
							{ labels[ item.state ] ??
								__( 'Waiting', 'eventwatch' ) }
						</span>
					</li>
				) ) }
			</ul>
			<div>
				<button
					type="button"
					className="button"
					onClick={ onStop }
					disabled={ stopping }
				>
					{ __( 'Stop', 'eventwatch' ) }
				</button>
			</div>
		</section>
	);
}

function Results( { imported, failed, stopReason, onRetry, canImport } ) {
	return (
		<div className="eventwatch-import__results">
			{ imported.length > 0 && (
				<div className="notice notice-success inline" role="status">
					<p>
						<strong>
							{ sprintf(
								/* translators: %d: the number of posts imported. */
								_n(
									'%d post imported.',
									'%d posts imported.',
									imported.length,
									'eventwatch'
								),
								imported.length
							) }
						</strong>
					</p>
					<p>
						{ __(
							'Posts listed on EventWatch were published; others were saved as drafts. Check them over.',
							'eventwatch'
						) }
					</p>
					<ul>
						{ imported.map( ( item ) => (
							<li key={ item.eventId }>
								{ item.title } —{ ' ' }
								<a href={ item.editUrl }>
									{ __( 'Edit post', 'eventwatch' ) }
								</a>
							</li>
						) ) }
					</ul>
				</div>
			) }
			{ '' !== stopReason && (
				<div className="notice notice-error inline" role="alert">
					<p>{ stopReason }</p>
				</div>
			) }
			{ failed.length > 0 && (
				<div className="notice notice-error inline" role="alert">
					<p>
						<strong>
							{ sprintf(
								/* translators: %d: the number of posts not imported. */
								_n(
									'%d post couldn’t be imported.',
									'%d posts couldn’t be imported.',
									failed.length,
									'eventwatch'
								),
								failed.length
							) }
						</strong>
					</p>
					<ul>
						{ failed.map( ( item ) => (
							<li key={ item.eventId }>
								<strong>{ item.title }</strong> —{ ' ' }
								{ item.reason ||
									__(
										'The import didn’t finish. Wait a moment, then try again.',
										'eventwatch'
									) }
								{ item.editUrl && (
									<>
										{ ' ' }
										<a href={ item.editUrl }>
											{ __( 'Edit draft', 'eventwatch' ) }
										</a>
									</>
								) }
							</li>
						) ) }
					</ul>
					<p>
						<button
							type="button"
							className="button"
							onClick={ onRetry }
							disabled={ ! canImport }
						>
							{ __( 'Try again', 'eventwatch' ) }
						</button>
					</p>
				</div>
			) }
		</div>
	);
}

function App( { unlinked, canImport } ) {
	const [ items, setItems ] = useState( null );
	const [ stopping, setStopping ] = useState( false );
	const [ imported, setImported ] = useState( [] );
	const [ failed, setFailed ] = useState(
		unlinked.map( ( draft ) => ( {
			eventId: draft.id,
			title: draft.title,
			editUrl: draft.editUrl,
			reason: __(
				'This draft isn’t connected to EventWatch yet.',
				'eventwatch'
			),
		} ) )
	);
	const [ stopReason, setStopReason ] = useState( '' );
	const stopRef = useRef( false );
	const running = null !== items;

	useEffect( () => {
		const warn = ( event ) => {
			event.preventDefault();
		};
		if ( running ) {
			window.addEventListener( 'beforeunload', warn );
		}
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ running ] );

	const start = async ( ids ) => {
		stopRef.current = false;
		setStopping( false );
		setStopReason( '' );
		if ( form() ) {
			form().hidden = true;
		}

		const finish = () => {
			setItems( null );
			if ( form() ) {
				form().hidden = false;
			}
			syncSelection( canImport );
		};

		setItems( ids.map( ( id ) => ( { id, title: '', state: 'queued' } ) ) );

		let prepared;
		try {
			prepared = await apiFetch( {
				path: `${ NAMESPACE }/prepare`,
				method: 'POST',
				data: { eventIds: ids },
			} );
		} catch {
			prepared = {
				status: 'failed',
				reason: __(
					'The list couldn’t be loaded from EventWatch. Wait a moment, then try again.',
					'eventwatch'
				),
			};
		}

		if ( 'ok' !== prepared.status ) {
			setStopReason( prepared.reason );
			finish();
			return;
		}

		const titles = Object.fromEntries(
			prepared.items.map( ( item ) => [ item.id, item.title ] )
		);
		setItems(
			ids.map( ( id ) => ( {
				id,
				title: titles[ id ] ?? '',
				state: 'queued',
			} ) )
		);

		const { stopReason: reason } = await runImport( {
			ids,
			sleep,
			shouldStop: () => stopRef.current,
			send: ( eventId ) =>
				apiFetch( {
					path: `${ NAMESPACE }/run`,
					method: 'POST',
					data: { eventId },
				} ),
			onChange: ( id, state, result ) => {
				setItems(
					( rows ) =>
						rows?.map( ( row ) =>
							row.id === id ? { ...row, state } : row
						) ?? rows
				);
				// 結果が出るまで前の失敗を消さない。止めた件と読めなかった件の「やり直す」を残すため。
				if ( 'imported' === state ) {
					setImported( ( rows ) => [ ...rows, result ] );
					setFailed( ( rows ) =>
						rows.filter( ( row ) => row.eventId !== id )
					);
					updateRow( result );
				} else if ( 'failed' === state ) {
					updateRow( result );
					setFailed( ( rows ) => [
						...rows.filter( ( row ) => row.eventId !== id ),
						{ ...result, title: result.title || titles[ id ] },
					] );
				}
			},
		} );

		setStopReason( reason );
		finish();
	};

	useEffect( () => {
		const table = form();
		if ( ! table ) {
			return undefined;
		}

		// 本体のすべて選ぶは change を出さないので、押した後の状態を読む。
		const onClick = ( event ) => {
			if (
				event.target.matches?.( 'input[type="checkbox"]' ) ||
				event.target.closest?.( 'label' )
			) {
				syncSelection( canImport );
			}
			if ( event.target.matches?.( '[data-eventwatch-import-start]' ) ) {
				start( selectedBoxes().map( ( box ) => box.value ) );
			}
		};
		table.addEventListener( 'click', onClick );
		syncSelection( canImport );

		return () => table.removeEventListener( 'click', onClick );
	} );

	if ( items ) {
		return (
			<Progress
				items={ items }
				stopping={ stopping }
				onStop={ () => {
					stopRef.current = true;
					setStopping( true );
				} }
			/>
		);
	}

	return (
		<Results
			imported={ imported }
			failed={ failed }
			stopReason={ stopReason }
			canImport={ canImport }
			onRetry={ () => start( failed.map( ( row ) => row.eventId ) ) }
		/>
	);
}

const mount = document.getElementById( 'eventwatch-import-progress' );
if ( mount ) {
	createRoot( mount ).render(
		<App
			unlinked={ window.eventwatchImport?.unlinked ?? [] }
			canImport={ window.eventwatchImport?.canImport ?? false }
		/>
	);
}
