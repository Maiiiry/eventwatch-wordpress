<?php
/**
 * 送る内容と Markdown の変換のテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

use EventWatch\Event_Meta;
use EventWatch\Markdown_Converter;
use EventWatch\Post_Type;
use EventWatch\Sync_Payload;
use WP_UnitTestCase;

/**
 * Markdown_Converter と Sync_Payload のテスト。
 */
class Sync_Payload_Test extends WP_UnitTestCase {

	/**
	 * 基底クラスの tear_down がメタの登録を全部消すので、テストごとに登録する。
	 */
	public function set_up() {
		parent::set_up();
		Event_Meta::register();
	}

	/**
	 * 変換の表。
	 *
	 * @return array
	 */
	public function html_cases() {
		return array(
			'段落'             => array( '<p>一つ目</p><p>二つ目</p>', "一つ目\n\n二つ目" ),
			'見出し'            => array( '<h2>見出し</h2><h6>小</h6>', "## 見出し\n\n###### 小" ),
			'強調と太字'          => array( '<p><strong>太</strong>と<em>斜</em></p>', '**太**と*斜*' ),
			'強調の内側の空白は外へ'    => array( '<p>前<strong> 太 </strong>後</p>', '前 **太** 後' ),
			'括弧で始まる太字は印なし'   => array( '<p><strong>「重要」</strong>です</p>', '「重要」です' ),
			'括弧で終わる斜体は印なし'   => array( '<p><em>（注）</em>です</p>', '（注）です' ),
			'句読点で終わる太字は印なし'  => array( '<p><strong>重要。</strong>続き</p>', '重要。続き' ),
			'入れ子の強調'         => array( '<p><strong><em>両方</em></strong></p>', '***両方***' ),
			'隣り合う太字'         => array( '<p><strong>あ</strong><strong>い</strong></p>', '**あ**い' ),
			'隣り合う斜体'         => array( '<p><em>あ</em><em>い</em></p>', '*あ*い' ),
			'入れ子の後の斜体'       => array( '<p><strong><em>あ</em></strong><em>い</em></p>', '***あ***い' ),
			'斜体の後の入れ子'       => array( '<p><em>あ</em><strong><em>い</em></strong></p>', '*あ*い' ),
			'入れ子どうし'         => array( '<p><strong><em>あ</em></strong><strong><em>い</em></strong></p>', '***あ***い' ),
			'囲みを挟んだ隣'        => array( '<p><span><em>あ</em></span><span><em>い</em></span></p>', '*あ*い' ),
			'打ち消した星の後の強調'    => array( '<p>*<strong>あ</strong></p>', '\\***あ**' ),
			'全角空白で始まる太字'     => array( '<p><strong>　重要</strong>です</p>', '　重要です' ),
			'NBSP で終わる太字'    => array( "<p><strong>重要\u{A0}</strong>です</p>", "重要\u{A0}です" ),
			'宛先の星'           => array( '<a href="https://example.com/a****b">公式</a>', '[公式](https://example.com/a%2A%2A%2A%2Ab)' ),
			'! の後のリンク'       => array( '<p>見てね!<a href="https://example.com/">ここ</a></p>', '見てね\\![ここ](https://example.com/)' ),
			'囲みを挟んだ ! の後'    => array( '<p><span>見てね!</span><a href="https://example.com/">ここ</a></p>', '見てね\\![ここ](https://example.com/)' ),
			'打ち消した \\ の後の !' => array( '<p>\\!<a href="https://example.com/">ここ</a></p>', '\\\\\\![ここ](https://example.com/)' ),
			'宛先の末尾の逆斜線'      => array( '<a href="https://example.com/a\\">公式</a>', '[公式](https://example.com/a%5C)' ),
			'箇条書き'           => array( '<ul><li>あ</li><li>い</li></ul>', "- あ\n- い" ),
			'番号付き'           => array( '<ol start="3"><li>あ</li><li>い</li></ol>', "3. あ\n4. い" ),
			'入れ子'            => array( '<ul><li>あ<ol><li>子</li></ol></li><li>い</li></ul>', "- あ\n  1. 子\n- い" ),
			'引用'             => array( '<blockquote><p>一</p><p>二</p></blockquote>', "> 一\n>\n> 二" ),
			'改行'             => array( '<p>一<br>二</p>', "一  \n二" ),
			'水平線'            => array( '<hr>', '---' ),
			'リンク'            => array( '<p><a href="https://example.com/a">公式</a></p>', '[公式](https://example.com/a)' ),
			'大文字のスキーム'       => array( '<a href="HTTPS://example.com/">公式</a>', '[公式](https://example.com/)' ),
			'メールのリンク'        => array( '<a href="mailto:a@example.com">連絡</a>', '[連絡](mailto:a@example.com)' ),
			'宛先のかっこ'         => array( '<a href="https://example.com/a(b) c">公式</a>', '[公式](https://example.com/a%28b%29%20c)' ),
			'スクリプトのリンクは文字'   => array( '<a href="javascript:alert(1)">押す</a>', '押す' ),
			'data のリンクは文字'   => array( '<a href="data:text/html,x">押す</a>', '押す' ),
			'相対のリンクは文字'      => array( '<a href="/about">案内</a>', '案内' ),
			'画像を落とす'         => array( '<p>前<img src="https://example.com/a.png" alt="画像">後</p>', '前後' ),
			'図を説明ごと落とす'      => array( '<figure><img src="a.png"><figcaption>説明</figcaption></figure><p>本文</p>', '本文' ),
			'画像だけのリンクを落とす'   => array( '<p><a href="https://example.com/"><img src="a.png"></a></p>', '' ),
			'埋め込みを落とす'       => array( '<figure><iframe src="https://example.com/"></iframe></figure><video src="a.mp4"></video><audio src="a.mp3"></audio>', '' ),
			'スクリプトを落とす'      => array( '<script>alert(1)</script><style>a{}</style><p>本文</p>', '本文' ),
			'画像の記法を打ち消す'     => array( '<p>![a](https://example.com/a.png)</p>', '\\!\\[a\\](https://example.com/a.png)' ),
			'記号を打ち消す'        => array( '<p>a*b*_c_`d`</p>', 'a\\*b\\*\\_c\\_\\`d\\`' ),
			'タグの形を打ち消す'      => array( '<p>&lt;Roland TR-808&gt;</p>', '\\<Roland TR-808>' ),
			'実体参照の形を打ち消す'    => array( '<p>&amp;amp; と A &amp; B</p>', '\\&amp; と A & B' ),
			'行頭の見出しの形'       => array( '<p># 見出しではない</p>', '\\# 見出しではない' ),
			'行頭のリストの形'       => array( '<p>- 項目ではない</p><p>1. 項目ではない</p>', "\\- 項目ではない\n\n1\\. 項目ではない" ),
			'行頭の引用の形'        => array( '<p>&gt; 引用ではない</p>', '\\> 引用ではない' ),
			'囲みの中の文字を段落に'    => array( '<div>文字<p>段落</p>続き</div>', "文字\n\n段落\n\n続き" ),
			'表はセルごとに段落'      => array( '<table><tr><td>あ</td><td>い</td></tr></table>', "あ\n\nい" ),
			'全角の空白は残す'       => array( '<p>あ　い</p>', 'あ　い' ),
			'空'              => array( '', '' ),
		);
	}

	/**
	 * HTML を Markdown に変える。
	 *
	 * @dataProvider html_cases
	 *
	 * @param string $html     入力。
	 * @param string $expected 期待。
	 */
	public function test_converts_html( $html, $expected ) {
		$this->assertSame( $expected, Markdown_Converter::from_html( $html ) );
	}

	/**
	 * ブロックの本文は、保存された HTML だけを読む。動的なブロックは描画しない。
	 */
	public function test_reads_saved_html_of_blocks_without_rendering() {
		$rendered = false;
		$render   = static function ( $content ) use ( &$rendered ) {
			$rendered = true;
			return $content;
		};
		add_filter( 'render_block', $render );

		$content = '<!-- wp:heading --><h2 class="wp-block-heading">題</h2><!-- /wp:heading -->'
			. '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>段落</p><!-- /wp:paragraph -->'
			. '<!-- wp:list --><ul><!-- wp:list-item --><li>一</li><!-- /wp:list-item --><!-- wp:list-item --><li>二</li><!-- /wp:list-item --></ul><!-- /wp:list --></div><!-- /wp:group -->'
			. '<!-- wp:image --><figure class="wp-block-image"><img src="a.png"/></figure><!-- /wp:image -->'
			. '<!-- wp:eventwatch/event-list /-->'
			. '<!-- wp:latest-posts /-->';

		$this->assertSame( "## 題\n\n段落\n\n- 一\n- 二", Markdown_Converter::from_post_content( $content ) );
		$this->assertFalse( $rendered );

		remove_filter( 'render_block', $render );
	}

	/**
	 * 埋め込みのブロックは、保存された HTML の URL ごと落とす。
	 */
	public function test_embed_blocks_are_dropped() {
		$content = '<!-- wp:paragraph --><p>前</p><!-- /wp:paragraph -->'
			. '<!-- wp:embed {"url":"https://www.youtube.com/watch?v=abc","type":"video","providerNameSlug":"youtube"} --><figure class="wp-block-embed is-type-video"><div class="wp-block-embed__wrapper">' . "\nhttps://www.youtube.com/watch?v=abc\n" . '</div></figure><!-- /wp:embed -->'
			. '<!-- wp:core-embed/youtube {"url":"https://www.youtube.com/watch?v=old"} --><figure><div>https://www.youtube.com/watch?v=old</div></figure><!-- /wp:core-embed/youtube -->'
			. '<!-- wp:paragraph --><p>後</p><!-- /wp:paragraph -->';

		$this->assertSame( "前\n\n後", Markdown_Converter::from_post_content( $content ) );
	}

	/**
	 * クラシックの本文は、埋め込みの提供元の URL だけの行を落とす。それ以外の URL は文字として残す。
	 */
	public function test_classic_embed_lines_are_dropped() {
		$content = "前\n\nhttps://www.youtube.com/watch?v=abc\n\nhttps://example.com/tickets\n\n後";

		$this->assertSame( "前\n\nhttps://example.com/tickets\n\n後", Markdown_Converter::from_post_content( $content ) );
	}

	/**
	 * ブロックの無い本文は段落を補う。ショートコードは落とす。
	 */
	public function test_classic_content() {
		add_shortcode( 'eventwatch_test_code', '__return_empty_string' );

		$this->assertSame( "一\n\n二", Markdown_Converter::from_post_content( "一\n\n[eventwatch_test_code]二" ) );

		remove_shortcode( 'eventwatch_test_code' );
	}

	/**
	 * 欄がそろっているイベント。
	 *
	 * @param array $meta 上書きするメタ。
	 * @param array $post 上書きする投稿の値。
	 * @return \WP_Post
	 */
	private function event( array $meta = array(), array $post = array() ) {
		$id = self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => Post_Type::POST_TYPE,
					'post_status'  => 'publish',
					'post_title'   => '題',
					'post_content' => '<p>本文</p>',
					'post_name'    => 'my-event',
				),
				$post
			)
		);

		$values = array_merge(
			array(
				Event_Meta::OCCURRENCES   => array(
					array(
						'start' => '2026-12-01 10:00',
						'end'   => '2026-12-01 12:00',
					),
					array(
						'start' => '2026-12-02 10:00',
						'end'   => '',
					),
				),
				Event_Meta::ZONE          => 'Asia/Tokyo',
				Event_Meta::SCHEDULE_NOTE => '雨天中止',
				Event_Meta::CATEGORY      => 3,
				Event_Meta::PREFECTURE    => 13,
				Event_Meta::VENUE_NAME    => '会場',
				Event_Meta::VENUE_ADDRESS => '住所',
			),
			$meta
		);
		foreach ( $values as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		return get_post( $id );
	}

	/**
	 * 欄の対応。
	 */
	public function test_builds_body() {
		$post  = $this->event();
		$built = Sync_Payload::build( $post );

		$this->assertSame( array(), $built['blocks'] );
		$this->assertSame(
			array(
				'title'                 => '題',
				'description'           => '本文',
				'occurrenceRanges'      => array(
					array(
						'startAt' => '2026-12-01 10:00',
						'closeAt' => '2026-12-01 12:00',
					),
					array(
						'startAt' => '2026-12-02 10:00',
						'closeAt' => '',
					),
				),
				'holdingPeriodNote'     => '雨天中止',
				'zoneId'                => 'Asia/Tokyo',
				'category'              => 3,
				'prefectureCode'        => 13,
				'online'                => false,
				'venueName'             => '会場',
				'venueAddress'          => '住所',
				'publisherEventPageUrl' => get_permalink( $post ),
				'sourceUpdatedAt'       => gmdate( 'Y-m-d\TH:i:s', strtotime( $post->post_modified_gmt . ' UTC' ) ) . '+00:00',
			),
			$built['body']
		);
		$this->assertArrayNotHasKey( 'imageUploadId', $built['body'] );
		$this->assertArrayNotHasKey( 'tagIds', $built['body'] );
	}

	/**
	 * 別の公式ページがあれば、そちらを送る。
	 */
	public function test_separate_official_page_wins() {
		$built = Sync_Payload::build( $this->event( array( Event_Meta::URL => 'https://example.com/official' ) ) );

		$this->assertSame( 'https://example.com/official', $built['body']['publisherEventPageUrl'] );
	}

	/**
	 * 題の実体参照を戻し、タグを落とす。
	 */
	public function test_title_is_plain() {
		$built = Sync_Payload::build( $this->event( array(), array( 'post_title' => 'A &amp; <b>B</b>' ) ) );

		$this->assertSame( 'A & B', $built['body']['title'] );
	}

	/**
	 * 予約の公開の時刻が更新日時より新しければ、そちらを送る。
	 */
	public function test_source_updated_at_uses_later_publish_time() {
		$post      = $this->event();
		$published = strtotime( $post->post_modified_gmt . ' UTC' ) + 100;
		update_post_meta( $post->ID, Sync_Payload::PUBLISHED_AT_META, $published );

		$this->assertSame( gmdate( 'Y-m-d\TH:i:s', $published ) . '+00:00', Sync_Payload::source_updated_at( $post ) );

		update_post_meta( $post->ID, Sync_Payload::PUBLISHED_AT_META, $published - 200 );

		$this->assertSame( gmdate( 'Y-m-d\TH:i:s', $published - 100 ) . '+00:00', Sync_Payload::source_updated_at( $post ) );
	}

	/**
	 * 送れない理由のコード。
	 *
	 * @param array $built build の戻り。
	 * @return string[]
	 */
	private function codes( array $built ) {
		return array_column( $built['blocks'], 'code' );
	}

	/**
	 * 送れない理由を全部並べる。
	 */
	public function test_collects_all_blocks() {
		$built = Sync_Payload::build(
			$this->event(
				array(
					Event_Meta::CATEGORY    => 0,
					Event_Meta::PREFECTURE  => 0,
					Event_Meta::OCCURRENCES => array(),
				)
			)
		);

		$this->assertSame(
			array( Sync_Payload::BLOCK_OCCURRENCES, Sync_Payload::BLOCK_CATEGORY, Sync_Payload::BLOCK_PREFECTURE ),
			$this->codes( $built )
		);
	}

	/**
	 * オンラインなら都道府県は無くてよい。場所未定でもオンラインでなければ要る。
	 */
	public function test_prefecture_is_required_unless_online() {
		$online = Sync_Payload::build(
			$this->event(
				array(
					Event_Meta::PREFECTURE => 0,
					Event_Meta::ONLINE     => true,
				)
			)
		);
		$this->assertSame( array(), $online['blocks'] );

		$undecided = Sync_Payload::build(
			$this->event(
				array(
					Event_Meta::PREFECTURE    => 0,
					Event_Meta::VENUE_NAME    => '',
					Event_Meta::VENUE_ADDRESS => '',
				)
			)
		);
		$this->assertSame( array( Sync_Payload::BLOCK_PREFECTURE ), $this->codes( $undecided ) );
	}

	/**
	 * 題と本文の上限の境目。絵文字は 2 と数える。
	 */
	public function test_length_limits() {
		$title_ok   = Sync_Payload::build( $this->event( array(), array( 'post_title' => str_repeat( 'あ', 256 ) ) ) );
		$title_over = Sync_Payload::build( $this->event( array(), array( 'post_title' => str_repeat( 'あ', 255 ) . "\u{1F600}" ) ) );
		$no_title   = Sync_Payload::build( $this->event( array(), array( 'post_title' => '   ' ) ) );

		$this->assertSame( array(), $title_ok['blocks'] );
		$this->assertSame( array( Sync_Payload::BLOCK_TITLE_TOO_LONG ), $this->codes( $title_over ) );
		$this->assertSame( array( Sync_Payload::BLOCK_TITLE_EMPTY ), $this->codes( $no_title ) );

		$body_ok   = Sync_Payload::build( $this->event( array(), array( 'post_content' => '<p>' . str_repeat( 'あ', 10000 ) . '</p>' ) ) );
		$body_over = Sync_Payload::build( $this->event( array(), array( 'post_content' => '<p>' . str_repeat( 'あ', 9999 ) . "\u{1F600}" . '</p>' ) ) );

		$this->assertSame( array(), $body_ok['blocks'] );
		$this->assertSame(
			array(
				array(
					'code'   => Sync_Payload::BLOCK_DESCRIPTION_LONG,
					'length' => 10001,
				),
			),
			$body_over['blocks']
		);
	}

	/**
	 * 会場の組と、EventWatch の数え方での欄の上限。
	 */
	public function test_other_fields_that_the_server_rejects() {
		$address_only = Sync_Payload::build( $this->event( array( Event_Meta::VENUE_NAME => '' ) ) );
		$this->assertSame( array( Sync_Payload::BLOCK_VENUE_INCOMPLETE ), $this->codes( $address_only ) );

		$emoji     = "\u{1F600}";
		$note_ok   = Sync_Payload::build( $this->event( array( Event_Meta::SCHEDULE_NOTE => str_repeat( 'あ', 200 ) ) ) );
		$note_over = Sync_Payload::build( $this->event( array( Event_Meta::SCHEDULE_NOTE => str_repeat( 'あ', 199 ) . $emoji ) ) );
		$this->assertSame( array(), $note_ok['blocks'] );
		$this->assertSame( array( Sync_Payload::BLOCK_NOTE_TOO_LONG ), $this->codes( $note_over ) );

		$name_over    = Sync_Payload::build( $this->event( array( Event_Meta::VENUE_NAME => str_repeat( 'あ', 255 ) . $emoji ) ) );
		$address_over = Sync_Payload::build( $this->event( array( Event_Meta::VENUE_ADDRESS => str_repeat( 'あ', 511 ) . $emoji ) ) );
		$this->assertSame( array( Sync_Payload::BLOCK_VENUE_TOO_LONG ), $this->codes( $name_over ) );
		$this->assertSame( array( Sync_Payload::BLOCK_VENUE_TOO_LONG ), $this->codes( $address_over ) );
	}

	/**
	 * 読めない文字列は、上限の判定を甘くしない。
	 */
	public function test_server_length_of_invalid_utf8() {
		$this->assertSame( 3, Sync_Payload::server_length( "a\xFFb" ) );
		$this->assertSame( 3, Sync_Payload::server_length( "あ\u{1F600}" ) );
	}
}
