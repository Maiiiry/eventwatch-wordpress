import { registerPlugin } from '@wordpress/plugins';

import EventPanel, { PLUGIN_NAME } from './event-panel';
import SyncPanel from './sync-panel';
import './editor.css';

registerPlugin( PLUGIN_NAME, { render: EventPanel } );
registerPlugin( 'eventwatch-sync', { render: SyncPanel } );
