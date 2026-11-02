/**
 * 編集画面の欄の判定。規則は includes/class-event-meta.php の REST の検証と揃える。
 */

export const MAX_RANGES = 100;
export const MAX_NOTE = 200;
export const MAX_VENUE_NAME = 256;
export const MAX_ADDRESS = 512;
export const MAX_URL = 1024;

export const ERROR_START_INCOMPLETE = 'startIncomplete';
export const ERROR_END_INCOMPLETE = 'endIncomplete';
export const ERROR_INVALID_DATE = 'invalidDate';
export const ERROR_END_BEFORE_START = 'endBeforeStart';
export const ERROR_OVERLAP = 'overlap';

export const emptyRow = () => ( {
	startDate: '',
	startTime: '',
	endDate: '',
	endTime: '',
} );

const splitDateTime = ( value ) => {
	const [ date = '', time = '' ] = ( value ?? '' ).split( ' ' );
	return [ date, time ];
};

/**
 * メタの日程を、欄の値にする。0 組なら空の組を 1 つ返す（メタには入れない）。
 *
 * @param {Array<{start: string, end: string}>} occurrences メタの値。
 * @return {Array<Object>} 欄の値。
 */
export const rowsFromOccurrences = ( occurrences ) => {
	if ( ! occurrences?.length ) {
		return [ emptyRow() ];
	}
	return occurrences.map( ( { start, end } ) => {
		const [ startDate, startTime ] = splitDateTime( start );
		const [ endDate, endTime ] = splitDateTime( end );
		return { startDate, startTime, endDate, endTime };
	} );
};

const isBlank = ( row ) =>
	! row.startDate && ! row.startTime && ! row.endDate && ! row.endTime;

const existsOnCalendar = ( date ) => {
	const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec( date );
	if ( ! match ) {
		return false;
	}
	const [ year, month, day ] = match.slice( 1 ).map( Number );
	const parsed = new Date( Date.UTC( year, month - 1, day ) );
	return (
		parsed.getUTCFullYear() === year &&
		parsed.getUTCMonth() === month - 1 &&
		parsed.getUTCDate() === day
	);
};

const isTime = ( time ) => /^([01]\d|2[0-3]):[0-5]\d$/.test( time );

const isValidMoment = ( date, time ) =>
	existsOnCalendar( date ) && isTime( time );

/**
 * 日程の欄を判定する。
 *
 * 文字列のまま比べる。`YYYY-MM-DD HH:mm` は文字列の順が時刻の順と一致する。
 *
 * @param {Array<Object>} rows 欄の値。
 * @return {{errors: Object<number, string>, occurrences: Array<{start: string, end: string}>}} 組ごとの誤りと、メタに書く値。
 */
export const evaluateRows = ( rows ) => {
	const errors = {};
	const complete = [];

	rows.forEach( ( row, index ) => {
		if ( isBlank( row ) ) {
			return;
		}
		if ( ! row.startDate || ! row.startTime ) {
			errors[ index ] = ERROR_START_INCOMPLETE;
			return;
		}
		if ( Boolean( row.endDate ) !== Boolean( row.endTime ) ) {
			errors[ index ] = ERROR_END_INCOMPLETE;
			return;
		}
		if (
			! isValidMoment( row.startDate, row.startTime ) ||
			( row.endDate && ! isValidMoment( row.endDate, row.endTime ) )
		) {
			errors[ index ] = ERROR_INVALID_DATE;
			return;
		}

		const start = `${ row.startDate } ${ row.startTime }`;
		const end = row.endDate ? `${ row.endDate } ${ row.endTime }` : '';
		if ( end && end < start ) {
			errors[ index ] = ERROR_END_BEFORE_START;
			return;
		}
		complete.push( { index, start, end } );
	} );

	const sorted = [ ...complete ].sort( ( a, b ) =>
		a.start.localeCompare( b.start )
	);
	let previousEnd = '';
	sorted.forEach( ( { index, start, end } ) => {
		// 終了が空の組は、開始と同じ時刻として比べる（EventWatch が同じ扱いをする）。
		if ( previousEnd && start <= previousEnd ) {
			errors[ index ] = ERROR_OVERLAP;
		}
		previousEnd = end || start;
	} );

	return {
		errors,
		occurrences: complete.map( ( { start, end } ) => ( { start, end } ) ),
	};
};

/**
 * 会場名と住所の片方だけが入っているか。
 *
 * @param {string} name    会場名。
 * @param {string} address 住所。
 * @return {boolean} 片方だけなら true。
 */
export const isVenueIncomplete = ( name, address ) =>
	Boolean( name?.trim() ) !== Boolean( address?.trim() );

/**
 * 別の公式ページの形が正しいか。空は正しい。
 *
 * @param {string} url URL。
 * @return {boolean} 正しければ true。
 */
export const isValidUrl = ( url ) => /^$|^https?:\/\/\S+$/.test( url ?? '' );

/**
 * サーバーの字数の数え方（コードポイント）で数える。
 *
 * @param {string} text 文字列。
 * @return {number} 字数。
 */
export const countCharacters = ( text ) => [ ...( text ?? '' ) ].length;
