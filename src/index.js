import { registerPlugin } from '@wordpress/plugins';

import EventPanel, { PLUGIN_NAME } from './event-panel';
import './editor.css';

registerPlugin( PLUGIN_NAME, { render: EventPanel } );
