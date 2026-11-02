import * as interactivity from '@wordpress/interactivity';

const { store, getContext, getElement } = interactivity;

// 名前付きで import すると、この名前の無い WordPress 6.7 でモジュールごと読めなくなる。
// 新しい版は、これで包まないと preventDefault より先に本体の既定の動作が走る。
const withSyncEvent = interactivity.withSyncEvent ?? ( ( action ) => action );

function isPlainClick( event ) {
	return (
		event.button === 0 &&
		! event.metaKey &&
		! event.ctrlKey &&
		! event.altKey &&
		! event.shiftKey
	);
}

function* visit( href ) {
	const context = getContext();
	context.busy = true;
	try {
		const { actions } = yield import( '@wordpress/interactivity-router' );
		// 読み上げるのはサーバーが描いた件数だけにする。Router の既定の文は翻訳されない。
		yield actions.navigate( href, {
			force: true,
			loadingAnimation: false,
			screenReaderAnnouncement: false,
		} );
	} finally {
		context.busy = false;
	}
}

store( 'eventwatch/event-search', {
	actions: {
		submit: withSyncEvent( function* ( event ) {
			event.preventDefault();
			const form = event.target;
			// form.action は、name が action の欄があるとその欄を返す。
			const url = new URL(
				form.getAttribute( 'action' ),
				window.location.href
			);
			url.search = '';
			for ( const [ name, value ] of new window.FormData( form ) ) {
				if ( value !== '' ) {
					url.searchParams.append( name, value );
				}
			}
			yield* visit( url.href );
		} ),
		follow: withSyncEvent( function* ( event ) {
			const link = event.target.closest(
				'.eventwatch-events__pagination a, .eventwatch-search__clear'
			);
			if ( ! link || ! isPlainClick( event ) ) {
				return;
			}
			event.preventDefault();
			const root = getElement().ref;
			const clearing = link.matches( '.eventwatch-search__clear' );
			yield* visit( link.href );
			// 押した要素は描き直しで消えることがあるので、次に操作する場所へ移す。
			root.querySelector(
				clearing ? 'input[type="search"]' : '[role="status"]'
			)?.focus( { preventScroll: true } );
		} ),
	},
} );
