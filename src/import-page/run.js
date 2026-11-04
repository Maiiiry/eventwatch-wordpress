/**
 * 選んだ投稿を 1 件ずつ取り込む手順。画面と通信は引数で受ける。
 */

// 結び付けは書き込みで 60 回/分の対象。同じキーの送信の分を残す。
export const LINK_GAP_MS = 1200;

// 待ち続けて止まって見えないよう、同じ件で待つ回数を絞る。
export const MAX_WAITS = 5;

/**
 * @param {Object}                                               args
 * @param {string[]}                                             args.ids        投稿の ID。
 * @param {(id: string) => Promise<Object>}                      args.send       1 件を取り込む。Event_Import::run の戻りを返す。
 * @param {(id: string, state: string, result?: Object) => void} args.onChange   件の状態が変わったときに呼ぶ。（ID, 状態, 結果）
 * @param {() => boolean}                                        args.shouldStop 止める指示があるか。今の件が終わった後に見る。
 * @param {(ms: number) => Promise<void>}                        args.sleep      待つ。ミリ秒を受けて Promise を返す。
 * @return {Promise<{stopReason: string}>} キーやサイトの問題で止めたときは理由。
 */
export async function runImport( { ids, send, onChange, shouldStop, sleep } ) {
	for ( let index = 0; index < ids.length; index++ ) {
		const id = ids[ index ];

		if ( shouldStop() ) {
			ids.slice( index ).forEach( ( rest ) =>
				onChange( rest, 'cancelled' )
			);
			return { stopReason: '' };
		}

		onChange( id, 'doing' );
		const result = await sendWithWaits( { id, send, onChange, sleep } );
		onChange( id, result.status, result );

		if ( 'stop' === result.status ) {
			ids.slice( index + 1 ).forEach( ( rest ) =>
				onChange( rest, 'cancelled' )
			);
			return { stopReason: result.reason };
		}

		if ( index < ids.length - 1 ) {
			await sleep( LINK_GAP_MS );
		}
	}

	return { stopReason: '' };
}

/**
 * 待つように言われたら、その秒数だけ待って同じ件を送り直す。
 *
 * @param {Object}                                               args
 * @param {string}                                               args.id       投稿の ID。
 * @param {(id: string) => Promise<Object>}                      args.send     1 件を取り込む。
 * @param {(id: string, state: string, result?: Object) => void} args.onChange 件の状態が変わったときに呼ぶ。
 * @param {(ms: number) => Promise<void>}                        args.sleep    待つ。
 * @return {Promise<Object>} 結果。
 */
async function sendWithWaits( { id, send, onChange, sleep } ) {
	for ( let waits = 0; ; waits++ ) {
		let result;
		try {
			result = await send( id );
		} catch {
			// 応答を失っても、やり直しは 2 件目を作らない。
			return { status: 'failed', eventId: id, title: '', reason: '' };
		}

		if ( 'wait' !== result.status ) {
			return result;
		}
		if ( waits >= MAX_WAITS ) {
			return { status: 'failed', eventId: id, title: '', reason: '' };
		}

		onChange( id, 'waiting' );
		await sleep( Math.max( 1, result.retryAfter ) * 1000 );
		onChange( id, 'doing' );
	}
}
