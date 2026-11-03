import { registerPlugin } from '@wordpress/plugins';

import EventPanel, { PLUGIN_NAME } from './event-panel';
import SyncPanel, { SYNC_PLUGIN_NAME } from './sync-panel';
import './editor.css';

registerPlugin( PLUGIN_NAME, { render: EventPanel } );
registerPlugin( SYNC_PLUGIN_NAME, { render: SyncPanel } );
