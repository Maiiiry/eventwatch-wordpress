import { describe, expect, it } from 'vitest';

import {
	ERROR_END_BEFORE_START,
	ERROR_END_INCOMPLETE,
	ERROR_INVALID_DATE,
	ERROR_OVERLAP,
	ERROR_START_INCOMPLETE,
	countCharacters,
	emptyRow,
	evaluateRows,
	isValidUrl,
	isVenueIncomplete,
	rowsFromOccurrences,
} from './validate';

const row = ( startDate, startTime, endDate = '', endTime = '' ) => ( {
	startDate,
	startTime,
	endDate,
	endTime,
} );

describe( 'rowsFromOccurrences', () => {
	it( 'shows one empty row when there are no dates', () => {
		expect( rowsFromOccurrences( [] ) ).toEqual( [ emptyRow() ] );
		expect( rowsFromOccurrences( undefined ) ).toEqual( [ emptyRow() ] );
	} );

	it( 'splits a date and a time, and keeps an empty end', () => {
		expect(
			rowsFromOccurrences( [
				{ start: '2026-11-03 10:00', end: '2026-11-03 12:30' },
				{ start: '2026-11-04 10:00', end: '' },
			] )
		).toEqual( [
			row( '2026-11-03', '10:00', '2026-11-03', '12:30' ),
			row( '2026-11-04', '10:00' ),
		] );
	} );
} );

describe( 'evaluateRows', () => {
	it( 'writes nothing for a blank row and reports no error', () => {
		expect( evaluateRows( [ emptyRow() ] ) ).toEqual( {
			errors: {},
			occurrences: [],
		} );
	} );

	it( 'accepts an event without an end', () => {
		expect( evaluateRows( [ row( '2026-11-03', '10:00' ) ] ) ).toEqual( {
			errors: {},
			occurrences: [ { start: '2026-11-03 10:00', end: '' } ],
		} );
	} );

	it( 'accepts an end equal to the start', () => {
		expect(
			evaluateRows( [
				row( '2026-11-03', '10:00', '2026-11-03', '10:00' ),
			] ).errors
		).toEqual( {} );
	} );

	it.each( [
		[ 'no time', row( '2026-11-03', '' ) ],
		[ 'no date', row( '', '10:00' ) ],
		[ 'only an end', row( '', '', '2026-11-03', '10:00' ) ],
	] )( 'reports an incomplete start: %s', ( _name, input ) => {
		expect( evaluateRows( [ input ] ).errors ).toEqual( {
			0: ERROR_START_INCOMPLETE,
		} );
	} );

	it.each( [
		[ 'no end time', row( '2026-11-03', '10:00', '2026-11-03', '' ) ],
		[ 'no end date', row( '2026-11-03', '10:00', '', '12:00' ) ],
	] )( 'reports an incomplete end: %s', ( _name, input ) => {
		expect( evaluateRows( [ input ] ).errors ).toEqual( {
			0: ERROR_END_INCOMPLETE,
		} );
	} );

	it.each( [
		[ 'February 30', row( '2026-02-30', '10:00' ) ],
		[ 'hour 24', row( '2026-02-01', '24:00' ) ],
		[ 'a bad end', row( '2026-02-01', '10:00', '2026-13-01', '10:00' ) ],
	] )( 'reports a date that does not exist: %s', ( _name, input ) => {
		expect( evaluateRows( [ input ] ).errors ).toEqual( {
			0: ERROR_INVALID_DATE,
		} );
	} );

	it( 'reports an end before the start', () => {
		expect(
			evaluateRows( [
				row( '2026-11-03', '10:00', '2026-11-03', '09:59' ),
			] ).errors
		).toEqual( { 0: ERROR_END_BEFORE_START } );
	} );

	it( 'reports overlapping and touching dates on the later one, whatever the order', () => {
		const first = row( '2026-11-03', '10:00', '2026-11-03', '12:00' );
		const touching = row( '2026-11-03', '12:00', '2026-11-03', '13:00' );
		const overlapping = row( '2026-11-03', '11:00', '2026-11-03', '13:00' );

		expect( evaluateRows( [ first, touching ] ).errors ).toEqual( {
			1: ERROR_OVERLAP,
		} );
		expect( evaluateRows( [ overlapping, first ] ).errors ).toEqual( {
			0: ERROR_OVERLAP,
		} );
	} );

	it( 'treats an empty end as the start when comparing', () => {
		expect(
			evaluateRows( [
				row( '2026-11-03', '10:00' ),
				row( '2026-11-03', '10:00', '2026-11-03', '11:00' ),
			] ).errors
		).toEqual( { 1: ERROR_OVERLAP } );

		expect(
			evaluateRows( [
				row( '2026-11-03', '10:00' ),
				row( '2026-11-03', '10:01' ),
			] ).errors
		).toEqual( {} );
	} );

	it( 'skips a blank row between filled rows', () => {
		expect(
			evaluateRows( [
				row( '2026-11-03', '10:00' ),
				emptyRow(),
				row( '2026-11-04', '10:00' ),
			] )
		).toEqual( {
			errors: {},
			occurrences: [
				{ start: '2026-11-03 10:00', end: '' },
				{ start: '2026-11-04 10:00', end: '' },
			],
		} );
	} );
} );

describe( 'isVenueIncomplete', () => {
	it.each( [
		[ '', '', false ],
		[ 'Hall', 'Address', false ],
		[ 'Hall', '', true ],
		[ '', 'Address', true ],
		[ 'Hall', '   ', true ],
		[ '\u3000', '', false ],
		[ '\u3000', 'Address', true ],
	] )( '%j and %j -> %j', ( name, address, expected ) => {
		expect( isVenueIncomplete( name, address ) ).toBe( expected );
	} );
} );

describe( 'isValidUrl', () => {
	it.each( [
		[ '', true ],
		[ 'https://example.com/a', true ],
		[ 'http://example.com', true ],
		[ 'ftp://example.com', false ],
		[ 'example.com', false ],
		[ 'https://example.com/a b', false ],
	] )( '%j -> %j', ( url, expected ) => {
		expect( isValidUrl( url ) ).toBe( expected );
	} );
} );

describe( 'countCharacters', () => {
	it( 'counts an emoji as one character, like the server', () => {
		expect( countCharacters( '日程😀' ) ).toBe( 3 );
		expect( countCharacters( undefined ) ).toBe( 0 );
	} );
} );
