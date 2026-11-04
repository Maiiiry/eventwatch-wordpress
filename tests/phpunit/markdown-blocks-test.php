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
