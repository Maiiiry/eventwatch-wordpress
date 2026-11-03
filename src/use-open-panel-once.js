import { useRegistry, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useEffect } from '@wordpress/element';
import { store as preferencesStore } from '@wordpress/preferences';

const PREFERENCE_SCOPE = 'eventwatch';

// 既定では閉じて出る。最初の 1 度だけ開き、あとは利用者の開閉を覚えさせる。
const useOpenPanelOnce = ( panelId, preference ) => {
	const opened = useSelect(
		( select ) =>
			select( preferencesStore ).get( PREFERENCE_SCOPE, preference ),
		[ preference ]
	);
	const registry = useRegistry();

	useEffect( () => {
		if ( opened ) {
			return;
		}
		// toggle が 2 回走ると閉じてしまうので、先に印を立て、開閉はその時点の値を読む。
		registry
			.dispatch( preferencesStore )
			.set( PREFERENCE_SCOPE, preference, true );
		if ( ! registry.select( editorStore ).isEditorPanelOpened( panelId ) ) {
			registry.dispatch( editorStore ).toggleEditorPanelOpened( panelId );
		}
	}, [ opened, registry, panelId, preference ] );
};

export default useOpenPanelOnce;
