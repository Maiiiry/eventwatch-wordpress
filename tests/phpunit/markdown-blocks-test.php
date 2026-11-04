<?php
/**
 * Markdown からブロックへの変換のテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

use EventWatch\Markdown_Blocks;
use EventWatch\Markdown_Converter;
use WP_UnitTestCase;

/**
 * Markdown_Blocks のテスト。
 */
class Markdown_Blocks_Test extends WP_UnitTestCase {

	/**
	 * ブロックの名前を、出てくる順に集める。
	 *
	 * @param array $blocks parse_blocks の戻り。
	 * @return string[]
	 */
	private function names( array $blocks ) {
		$names = array();
		foreach ( $blocks as $block ) {
			if ( null !== $block['blockName'] ) {
				$names[] = $block['blockName'];
				$names   = array_merge( $names, $this->names( $block['innerBlocks'] ) );
			}
		}
		return $names;
	}

	/**
	 * 記法ごとに、どのブロックになるか。
	 *
	 * @return array<string, array{0: string, 1: string[]}>
	 */
	public function block_cases() {
		return array(
			'段落'        => array( 'あいう', array( 'core/paragraph' ) ),
			'見出し'       => array( '### 会場', array( 'core/heading' ) ),
			'下線の見出し'    => array( "会場\n===", array( 'core/heading' ) ),
			'箇条書き'      => array( "- a\n- b", array( 'core/list', 'core/list-item', 'core/list-item' ) ),
			'番号付き'      => array( "3. a\n4. b", array( 'core/list', 'core/list-item', 'core/list-item' ) ),
			'入れ子'       => array( "- a\n  - b", array( 'core/list', 'core/list-item', 'core/list', 'core/list-item' ) ),
			'引用'        => array( "> a\n> b", array( 'core/quote', 'core/paragraph' ) ),
			'囲みのコード'    => array( "```\na\n```", array( 'core/code' ) ),
			'字下げのコード'   => array( '    a', array( 'core/code' ) ),
			'水平線'       => array( '---', array( 'core/separator' ) ),
			'表'         => array( "| a | b |\n| --- | --- |\n| 1 | 2 |", array( 'core/table' ) ),
			'並び'        => array( "# a\n\nb\n\n- c", array( 'core/heading', 'core/paragraph', 'core/list', 'core/list-item' ) ),
			'段落の直後のリスト' => array( "a\n- b", array( 'core/paragraph', 'core/list', 'core/list-item' ) ),
		);
	}

	/**
	 * 記法ごとに、対応するブロックになる。
	 *
	 * @dataProvider block_cases
	 *
	 * @param string   $markdown Markdown。
	 * @param string[] $expected ブロックの名前。
	 */
	public function test_becomes_blocks( $markdown, array $expected ) {
		$this->assertSame( $expected, $this->names( parse_blocks( Markdown_Blocks::from_markdown( $markdown ) ) ) );
	}

	/**
	 * 行の中の記法ごとの HTML。
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function inline_cases() {
		return array(
			'太字'          => array( '**a**', '<strong>a</strong>' ),
			'強調'          => array( '*a*', '<em>a</em>' ),
			'下線の強調'       => array( '_a_', '<em>a</em>' ),
			'打ち消し'        => array( '~~a~~', '<del>a</del>' ),
			'コード'         => array( '`a*b*`', '<code>a*b*</code>' ),
			'リンク'         => array( '[a](https://example.com/?x=1&y=2)', '<a href="https://example.com/?x=1&#038;y=2">a</a>' ),
			'山括弧のリンク'     => array( '<https://example.com/>', '<a href="https://example.com/">https://example.com/</a>' ),
			'1 つの改行'      => array( "a\nb", 'a<br>b' ),
			'行末の空白の改行'    => array( "a  \nb", 'a<br>b' ),
			'バックスラッシュの改行' => array( "a\\\nb", 'a<br>b' ),
			'打ち消しの記号'     => array( '\\*a\\*', '*a*' ),
			'打ち消しの山括弧'    => array( '\\<Roland TR-808>', '&lt;Roland TR-808&gt;' ),
			'生の HTML'     => array( '<script>x</script>', '&lt;script&gt;x&lt;/script&gt;' ),
			'文字の &'       => array( 'A & B', 'A &amp; B' ),
			'文字参照'        => array( '&lt;a&gt;', '&lt;a&gt;' ),
			'単語の中の _'     => array( 'snake_case_name', 'snake_case_name' ),
			'空白の前の *'     => array( '2 * 3 * 4', '2 * 3 * 4' ),
			'閉じない記号'      => array( '**a', '**a' ),
			'日本語の中の太字'    => array( 'これは**大事**です', 'これは<strong>大事</strong>です' ),
			'太字の中の強調'     => array( '**a *b***', '<strong>a <em>b</em></strong>' ),
			'強調の中の太字'     => array( '*a **b***', '<em>a <strong>b</strong></em>' ),
			'先頭の太字の強調'    => array( '***a** b*', '<em><strong>a</strong> b</em>' ),
			'先頭の強調の太字'    => array( '***a* b**', '<strong><em>a</em> b</strong>' ),
			'余った星'        => array( '**a***', '<strong>a</strong>*' ),
			'句読点で閉じない太字'  => array( '**「重要」**です', '**「重要」**です' ),
			'太字の強調'       => array( '***a***', '<em><strong>a</strong></em>' ),
			'単語の中で閉じない _' => array( '_foo_bar_', '<em>foo_bar</em>' ),
			'単語の中で開かない _' => array( 'a_b_ c', 'a_b_ c' ),
			'閉じない _'      => array( '_private_name です', '_private_name です' ),
			'コードの文字参照'    => array( '`&amp;`', '<code>&amp;amp;</code>' ),
			'角括弧'         => array( '\\[a]', '&#91;a]' ),
		);
	}

	/**
	 * 行の中の記法。
	 *
	 * @dataProvider inline_cases
	 *
	 * @param string $markdown Markdown。
	 * @param string $expected 段落の中の HTML。
	 */
	public function test_inline( $markdown, $expected ) {
		$html = Markdown_Blocks::from_markdown( $markdown );

		$this->assertSame( "<!-- wp:paragraph -->\n<p>{$expected}</p>\n<!-- /wp:paragraph -->", $html );
	}

	/**
	 * 入れて送り返した本文。投稿の本文を経ても意味が変わらない。
	 *
	 * @return array<string, array{0: string, 1?: string}>
	 */
	public function round_trip_cases() {
		return array(
			'段落'        => array( "一行目\n\n二行目" ),
			'改行'        => array( "一行目\n二行目", "一行目  \n二行目" ),
			'見出し'       => array( "## 会場\n\n本文" ),
			'深い見出し'     => array( '#### 会場' ),
			'リスト'       => array( "- a\n- b" ),
			'番号付き'      => array( "1. a\n2. b" ),
			'途中からの番号'   => array( "3. a\n4. b" ),
			'入れ子'       => array( "- a\n  - b\n  - c\n- d" ),
			'引用'        => array( "> あいう\n>\n> えお" ),
			'強調'        => array( '**太字** と *強調* と ~~打ち消し~~' ),
			'コード'       => array( '使うのは `npm ci` です' ),
			'コードブロック'   => array( "```\nconst a = '*';\n\n<b>\n```" ),
			'リンク'       => array( '[案内](https://example.com/guide)' ),
			'水平線'       => array( "a\n\n---\n\nb" ),
			'表'         => array( "| 時間 | 内容 |\n| --- | --- |\n| 10:00 | **開場** |\n| 11:00 | a\\|b |" ),
			'打ち消した山括弧'  => array( '使用機材は \\<Roland TR-808> です' ),
			'打ち消した記号'   => array( '\\*\\*太字にしない\\*\\*' ),
			'文字の &'     => array( 'A & B' ),
			'タスクリストは文字' => array( "- [ ] a\n- [x] b", "- \\[ \\] a\n- \\[x\\] b" ),
			'太字の中の強調'   => array( '**a *b***' ),
			'強調の中の太字'   => array( '*a **b***' ),
			'先頭の太字の強調'  => array( '***a** b*' ),
			'先頭の強調の太字'  => array( '***a* b**' ),
			'太字の強調'     => array( '***a***' ),
			'コードの文字参照'  => array( "```\n&lt;div&gt; &amp; x\n```" ),
			'行の中の文字参照'  => array( '`&amp;` です' ),
			'コードの中の画像'  => array( '`![a](b)` です' ),
			'見出しだけの表'   => array( "| a | b |\n| --- | --- |" ),
		);
	}

	/**
	 * 取り込んだ本文は、最初の更新で崩れない。
	 *
	 * @dataProvider round_trip_cases
	 *
	 * @param string      $markdown Markdown。
	 * @param string|null $expected 送り返す本文。無ければ同じ。
	 */
	public function test_round_trip( $markdown, $expected = null ) {
		$content = Markdown_Blocks::from_markdown( $markdown );

		$this->assertSame( null === $expected ? $markdown : $expected, Markdown_Converter::from_post_content( $content ) );
	}

	/**
	 * 2 回目の往復で変わらない。送った内容の同一性が揺れない。
	 *
	 * @dataProvider round_trip_cases
	 *
	 * @param string $markdown Markdown。
	 */
	public function test_round_trip_settles( $markdown ) {
		$once  = Markdown_Converter::from_post_content( Markdown_Blocks::from_markdown( $markdown ) );
		$twice = Markdown_Converter::from_post_content( Markdown_Blocks::from_markdown( $once ) );

		$this->assertSame( $once, $twice );
	}

	/**
	 * リストの項目の中の引用と表の文字を失わない。
	 */
	public function test_list_item_keeps_nested_text() {
		$sent = Markdown_Converter::from_post_content( Markdown_Blocks::from_markdown( "- a\n\n  > 引用\n\n  | x | y |\n  | --- | --- |\n  | 1 | 2 |\n- > 先頭" ) );

		foreach ( array( '引用', 'x / y', '1 / 2', '先頭' ) as $text ) {
			$this->assertStringContainsString( $text, $sent );
		}
	}

	/**
	 * 見出しだけの表に、本体が出さない空の区画を出さない。
	 */
	public function test_table_without_rows_has_no_body() {
		$this->assertStringNotContainsString( '<tbody', Markdown_Blocks::from_markdown( "| a |\n| --- |" ) );
	}

	/**
	 * 整形済みの br は改行。
	 */
	public function test_preformatted_br_is_newline() {
		$this->assertSame( "```\na\nb\n```", Markdown_Converter::from_html( '<pre>a<br>b</pre>' ) );
	}

	/**
	 * 表のセルの中のブロックが混ざらない。入れ子の表の行を外の行に数えない。
	 */
	public function test_table_cells_with_blocks() {
		$this->assertSame(
			"題\n\n| a b | x y |\n| --- | --- |\n| 1 | c |",
			Markdown_Converter::from_html( '<table><caption>題</caption><tr><td><p>a</p><p>b</p></td><td><p>x</p><p>y</p></td></tr><tr><td>1</td><td>c</td></tr></table>' )
		);
		$this->assertStringStartsWith( '| ', Markdown_Converter::from_html( '<table><tr><td><table><tr><td>in</td></tr></table></td></tr></table>' ) );
		$this->assertSame( 2, count( explode( "\n", Markdown_Converter::from_html( '<table><tr><td>a<table><tr><td>in</td></tr></table></td></tr></table>' ) ) ) );
	}

	/**
	 * 説明に角括弧があっても画像として読む。
	 */
	public function test_image_alt_with_brackets() {
		$url = 'https://cdn.example.com/a.jpg';
		$this->assertSame( array( $url ), Markdown_Blocks::image_urls( "![説明 [x]]({$url})" ) );
		$this->assertSame( array( $url ), Markdown_Blocks::image_urls( "前 ![説明 [x]]({$url})" ) );
	}

	/**
	 * 整形済みの中のコメントを送らない。
	 */
	public function test_preformatted_skips_comments() {
		$this->assertSame( "```\nab\ncd\n```", Markdown_Converter::from_html( '<pre>a<!-- secret -->b<br>c<span>d</span></pre>' ) );
	}

	/**
	 * セルの中にブロックの記号を出さない。
	 */
	public function test_table_cell_has_no_block_marks() {
		$html = '<table><tr><td><h2>H</h2></td><td><ul><li>a</li><li>b</li></ul></td><td><blockquote>q</blockquote></td></tr></table>';

		$this->assertSame( "| H | a b | q |\n| --- | --- | --- |", Markdown_Converter::from_html( $html ) );
	}

	/**
	 * 山括弧が揃わないリンクの宛先もリンクにしない。
	 */
	public function test_unpaired_angle_is_not_link() {
		$this->assertStringNotContainsString( '<a ', Markdown_Blocks::from_markdown( '[a](<https://evil.example/)' ) );
		$this->assertStringContainsString( 'href="https://example.com/"', Markdown_Blocks::from_markdown( '[a](<https://example.com/>)' ) );
	}

	/**
	 * 山括弧が揃わない形は EventWatch では文字なので、画像として読まない。
	 */
	public function test_unpaired_angle_is_not_image() {
		$this->assertSame( array(), Markdown_Blocks::image_urls( '前 ![a](<https://evil.example/a.jpg)' ) );
		$this->assertSame( array(), Markdown_Blocks::image_urls( '![a](<https://evil.example/a.jpg)' ) );
		$this->assertSame( array( 'https://cdn.example.com/a.jpg' ), Markdown_Blocks::image_urls( '前 ![a](<https://cdn.example.com/a.jpg>)' ) );
	}

	/**
	 * 本文の画像の宛先を集める。
	 */
	public function test_image_urls() {
		$markdown = "前 ![a](https://cdn.example.com/1.jpg) 後\n\n![b](https://cdn.example.com/2.jpg)\n\n![c](https://cdn.example.com/1.jpg)\n\n[![d](https://cdn.example.com/3.jpg)](https://example.com/)";

		$this->assertSame(
			array( 'https://cdn.example.com/1.jpg', 'https://cdn.example.com/2.jpg', 'https://cdn.example.com/3.jpg' ),
			Markdown_Blocks::image_urls( $markdown )
		);
	}

	/**
	 * 画像は手元の添付に置き換わり、送るときに元の宛先へ戻る。
	 */
	public function test_images_round_trip() {
		$attachment = self::factory()->attachment->create( array( 'file' => 'poster.jpg' ) );
		$remote     = 'https://cdn.example.com/poster.jpg';
		$markdown   = "前\n\n![ポスター]({$remote})\n\n途中 ![小さい]({$remote}) の文";

		$content = Markdown_Blocks::from_markdown( $markdown, array( $remote => $attachment ) );

		$this->assertStringNotContainsString( $remote, $content );
		$this->assertStringContainsString( 'wp-image-' . $attachment, $content );
		$this->assertSame( array( 'core/paragraph', 'core/image', 'core/paragraph' ), $this->names( parse_blocks( $content ) ) );
		$this->assertSame( $markdown, Markdown_Converter::from_post_content( $content, array( $attachment => $remote ) ) );
	}

	/**
	 * 対応に無い画像は、説明の文字になる。送り返すときに、取り込めなかった宛先を作らない。
	 */
	public function test_image_without_attachment_becomes_text() {
		$content = Markdown_Blocks::from_markdown( '![ポスター](https://cdn.example.com/poster.jpg)' );

		$this->assertStringNotContainsString( '<img', $content );
		$this->assertStringNotContainsString( 'cdn.example.com', $content );
	}

	/**
	 * オーナーが足した画像は、対応に無いので送らない。文字の ! とリンクを画像にしない。
	 */
	public function test_converter_drops_other_images() {
		$content = '<p>a<img src="https://example.com/x.jpg" class="wp-image-999" alt="x">b</p><p>![c](https://example.com/y.jpg)</p><p>!<a href="https://example.com/">d</a></p>';

		$this->assertSame(
			"ab\n\n\\!\\[c\\](https://example.com/y.jpg)\n\n\\![d](https://example.com/)",
			Markdown_Converter::from_post_content( $content, array( 1 => 'https://cdn.example.com/1.jpg' ) )
		);
	}

	/**
	 * 本文の文字に印の制御文字があっても、画像にならない。
	 */
	public function test_image_mark_in_text_is_removed() {
		$this->assertSame( 'a\\[b\\](https://example.com/)', Markdown_Converter::from_html( "<p>a\x01[b](https://example.com/)</p>" ) );
	}
}
