import { describe, expect, it, vi } from 'vitest';
import { LINK_GAP_MS, MAX_WAITS, runImport } from './run';

function setup( answers, { stopAfter = Infinity } = {} ) {
	const events = [];
	const sleeps = [];
	let sent = 0;

	return {
		events,
		sleeps,
		args: {
			send: vi.fn( async ( id ) => {
				sent++;
				const answer = answers[ id ];
				const next = Array.isArray( answer ) ? answer.shift() : answer;
				if ( next instanceof Error ) {
					throw next;
				}
				return next ?? { status: 'imported', eventId: id };
			} ),
			onChange: ( id, state ) => events.push( `${ id }:${ state }` ),
			shouldStop: () => sent >= stopAfter,
			sleep: async ( ms ) => {
				sleeps.push( ms );
			},
		},
	};
}

describe( 'runImport', () => {
	it( '1 件ずつ送り、件の間だけ間を空ける', async () => {
		const { args, events, sleeps } = setup( {} );

		await runImport( { ids: [ 'a', 'b' ], ...args } );

		expect( events ).toEqual( [
			'a:doing',
			'a:imported',
			'b:doing',
			'b:imported',
		] );
		expect( sleeps ).toEqual( [ LINK_GAP_MS ] );
	} );

	it( '失敗しても次の件へ進む', async () => {
		const { args, events } = setup( {
			a: { status: 'failed', eventId: 'a', reason: 'x' },
		} );

		await runImport( { ids: [ 'a', 'b' ], ...args } );

		expect( events ).toContain( 'a:failed' );
		expect( events ).toContain( 'b:imported' );
	} );

	it( '待つように言われたら、その秒数の後に同じ件を送り直す', async () => {
		const { args, events, sleeps } = setup( {
			a: [
				{ status: 'wait', retryAfter: 7 },
				{ status: 'imported', eventId: 'a' },
			],
		} );

		await runImport( { ids: [ 'a' ], ...args } );

		expect( sleeps ).toEqual( [ 7000 ] );
		expect( args.send ).toHaveBeenCalledTimes( 2 );
		expect( events ).toEqual( [
			'a:doing',
			'a:waiting',
			'a:doing',
			'a:imported',
		] );
	} );

	it( '待つ回数には上限がある', async () => {
		const { args, events } = setup( {
			a: { status: 'wait', retryAfter: 1 },
		} );

		await runImport( { ids: [ 'a' ], ...args } );

		expect( args.send ).toHaveBeenCalledTimes( MAX_WAITS + 1 );
		expect( events[ events.length - 1 ] ).toBe( 'a:failed' );
	} );

	it( '止める指示は今の件が終わった後に効き、残りは取り消しになる', async () => {
		const { args, events } = setup( {}, { stopAfter: 1 } );

		await runImport( { ids: [ 'a', 'b', 'c' ], ...args } );

		expect( events ).toEqual( [
			'a:doing',
			'a:imported',
			'b:cancelled',
			'c:cancelled',
		] );
	} );

	it( 'キーを断られたら残りを送らず、理由を返す', async () => {
		const { args, events } = setup( {
			a: { status: 'stop', eventId: 'a', reason: 'キー' },
		} );

		const { stopReason } = await runImport( {
			ids: [ 'a', 'b' ],
			...args,
		} );

		expect( stopReason ).toBe( 'キー' );
		expect( args.send ).toHaveBeenCalledTimes( 1 );
		expect( events ).toContain( 'b:cancelled' );
	} );

	it( '通信の失敗はその件の失敗にする', async () => {
		const { args, events } = setup( { a: new Error( 'network' ) } );

		await runImport( { ids: [ 'a', 'b' ], ...args } );

		expect( events ).toContain( 'a:failed' );
		expect( events ).toContain( 'b:imported' );
	} );
} );
