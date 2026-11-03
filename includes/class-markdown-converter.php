<?php
/**
 * 投稿の本文を、EventWatch へ送る Markdown に変える。
 *
 * @package EventWatch
 */

namespace EventWatch;

use DOMDocument;
use DOMElement;
use DOMNode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 見出し・段落・リスト・引用・リンク・強調を残し、画像と埋め込みを落とす。
 *
 * EventWatch は本文の画像記法と許可外のリンクを 400 にするので、ここで作らない。
 * ブロックは描画せず、保存された HTML だけを読む。cron の中でほかのプラグインの描画を動かさないため。
 */
class Markdown_Converter {

	/**
	 * 中身ごと落とす要素。
	 */
	const DROPPED = array( 'img', 'picture', 'svg', 'iframe', 'video', 'audio', 'source', 'track', 'canvas', 'script', 'style', 'object', 'embed', 'noscript', 'template', 'figcaption', 'form', 'button', 'input', 'select', 'textarea' );

	/**
	 * 段落を区切る要素。ここに無い要素は、行の中の要素として読む。
	 */
	const BLOCKS = array( 'address', 'article', 'aside', 'blockquote', 'body', 'center', 'dd', 'details', 'dir', 'div', 'dl', 'dt', 'figure', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'menu', 'nav', 'ol', 'p', 'pre', 'section', 'summary', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul' );

	/**
	 * リンクとして残すスキーム。大文字のスキームを EventWatch は 400 にするので、小文字にして比べる。
	 */
	const SCHEMES = array( 'http', 'https', 'mailto' );

	/**
	 * 投稿の本文を変換する。
	 *
	 * @param string $content 投稿の本文。
	 * @return string
	 */
	public static function from_post_content( $content ) {
		$html = has_blocks( $content ) ? self::static_html( parse_blocks( $content ) ) : wpautop( $content );

		return self::from_html( strip_shortcodes( $html ) );
	}

	/**
	 * HTML を変換する。
	 *
	 * @param string $html HTML。
	 * @return string
	 */
	public static function from_html( $html ) {
		if ( '' === trim( $html ) ) {
			return '';
		}

		if ( ! class_exists( DOMDocument::class ) ) {
			return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
		}

		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="UTF-8"><!doctype html><html><body>' . $html . '</body></html>', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$body = $document->getElementsByTagName( 'body' )->item( 0 );

		return $body ? trim( implode( "\n\n", self::blocks( $body ) ) ) : '';
	}

	/**
	 * ブロックの保存された HTML を、入れ子の順につなぐ。動的なブロックは保存された HTML を持たないので消える。
	 *
	 * @param array $blocks parse_blocks の戻り。
	 * @return string
	 */
	private static function static_html( array $blocks ) {
		$html = '';

		foreach ( $blocks as $block ) {
			$inner = 0;
			foreach ( $block['innerContent'] as $piece ) {
				if ( is_string( $piece ) ) {
					$html .= $piece;
				} elseif ( isset( $block['innerBlocks'][ $inner ] ) ) {
					$html .= self::static_html( array( $block['innerBlocks'][ $inner ] ) );
					++$inner;
				}
			}
		}

		return $html;
	}

	/**
	 * 子の要素を、ブロックの文字列の配列にする。
	 *
	 * @param DOMNode $parent_node 親。
	 * @return string[]
	 */
	private static function blocks( DOMNode $parent_node ) {
		$blocks = array();
		$inline = '';

		foreach ( $parent_node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM の名前。
			if ( ! self::is_block( $child ) ) {
				$inline .= self::inline( $child );
				continue;
			}

			$blocks = self::push_paragraph( $blocks, $inline );
			$inline = '';
			$blocks = array_merge( $blocks, self::block( $child ) );
		}

		return self::push_paragraph( $blocks, $inline );
	}

	/**
	 * ブロックの要素か。
	 *
	 * @param DOMNode $node 要素。
	 * @return bool
	 */
	private static function is_block( DOMNode $node ) {
		return $node instanceof DOMElement && in_array( strtolower( $node->tagName ), self::BLOCKS, true ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM の名前。
	}

	/**
	 * ブロックの要素を文字列にする。
	 *
	 * @param DOMNode $node 要素。
	 * @return string[]
	 */
	private static function block( DOMNode $node ) {
		$tag = strtolower( $node->nodeName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM の名前。

		if ( preg_match( '/^h([1-6])$/', $tag, $heading ) ) {
			$text = self::escape_inline_block( self::inline_children( $node ) );
			return '' === $text ? array() : array( str_repeat( '#', (int) $heading[1] ) . ' ' . preg_replace( '/ *\n */', ' ', $text ) );
		}

		switch ( $tag ) {
			case 'hr':
				return array( '---' );
			case 'ul':
			case 'ol':
			case 'menu':
			case 'dir':
				$list = self::list_block( $node, 'ol' === $tag );
				return '' === $list ? array() : array( $list );
			case 'blockquote':
				$inner = self::blocks( $node );
				return $inner ? array( self::prefix_lines( implode( "\n\n", $inner ), '> ', '> ' ) ) : array();
		}

		return self::blocks( $node );
	}

	/**
	 * リストを文字列にする。入れ子は本文の位置まで字下げする。
	 *
	 * @param DOMNode $list_node リスト。
	 * @param bool    $ordered   番号付きか。
	 * @return string
	 */
	private static function list_block( DOMNode $list_node, $ordered ) {
		$number = $ordered && $list_node instanceof DOMElement && ctype_digit( $list_node->getAttribute( 'start' ) )
			? (int) $list_node->getAttribute( 'start' )
			: 1;
		$items  = array();

		foreach ( $list_node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM の名前。
			if ( 'li' !== strtolower( $child->nodeName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM の名前。
				continue;
			}

			$blocks = self::blocks( $child );
			if ( ! $blocks ) {
				continue;
			}

			$marker  = $ordered ? $number . '. ' : '- ';
			$indent  = str_repeat( ' ', strlen( $marker ) );
			$items[] = self::prefix_lines( implode( "\n", $blocks ), $marker, $indent );
			++$number;
		}

		return implode( "\n", $items );
	}

	/**
	 * 1 行目と 2 行目以降に別々の接頭辞を付ける。
	 *
	 * @param string $text  文字列。
	 * @param string $first 1 行目の接頭辞。
	 * @param string $rest  2 行目以降の接頭辞。
	 * @return string
	 */
	private static function prefix_lines( $text, $first, $rest ) {
		$lines = explode( "\n", $text );

		foreach ( $lines as $index => $line ) {
			$prefix          = 0 === $index ? $first : $rest;
			$lines[ $index ] = '' === $line ? rtrim( $prefix ) : $prefix . $line;
		}

		return implode( "\n", $lines );
	}

	/**
	 * 行の中の文字列を段落として足す。
	 *
	 * @param string[] $blocks これまでのブロック。
	 * @param string   $inline 行の中の文字列。
	 * @return string[]
	 */
	private static function push_paragraph( array $blocks, $inline ) {
		$text = self::escape_inline_block( $inline );
		if ( '' !== $text ) {
			$blocks[] = $text;
		}

		return $blocks;
	}

	/**
	 * 行の中の文字列を整え、行頭でブロックの記法に読まれる文字を打ち消す。
	 *
	 * @param string $inline 行の中の文字列。
	 * @return string
	 */
	private static function escape_inline_block( $inline ) {
		$lines = array_map( 'trim', explode( "\n", $inline ) );
		$lines = array_values( array_filter( $lines, 'strlen' ) );

		foreach ( $lines as $index => $line ) {
			$line            = preg_replace( '/^(#{1,6})(?=\s|$)/', '\\\\$1', $line );
			$line            = preg_replace( '/^([-+=>])/', '\\\\$1', $line );
			$lines[ $index ] = preg_replace( '/^(\d+)([.)])(?=\s|$)/', '$1\\\\$2', $line );
		}

		return implode( "  \n", $lines );
	}

	/**
	 * 子を行の中の文字列にする。
	 *
	 * @param DOMNode $node 親。
	 * @return string
	 */
	private static function inline_children( DOMNode $node ) {
		$text = '';
		foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM の名前。
			$text .= self::inline( $child );
		}

		return $text;
	}

	/**
	 * 行の中の 1 つを文字列にする。
	 *
	 * @param DOMNode $node 要素か文字。
	 * @return string
	 */
	private static function inline( DOMNode $node ) {
		if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM の名前。
			return self::escape_text( (string) preg_replace( '/[ \t\r\n]+/', ' ', $node->nodeValue ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM の名前。
		}

		if ( ! $node instanceof DOMElement ) {
			return '';
		}

		$tag = strtolower( $node->tagName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM の名前。

		if ( in_array( $tag, self::DROPPED, true ) ) {
			return '';
		}

		switch ( $tag ) {
			case 'br':
				return "\n";
			case 'strong':
			case 'b':
				return self::wrap( self::inline_children( $node ), '**' );
			case 'em':
			case 'i':
				return self::wrap( self::inline_children( $node ), '*' );
			case 'a':
				return self::link( $node );
		}

		return self::inline_children( $node );
	}

	/**
	 * 強調の記号で囲む。記号の内側に空白があると強調にならないので、外へ出す。
	 *
	 * @param string $text 文字列。
	 * @param string $mark 記号。
	 * @return string
	 */
	private static function wrap( $text, $mark ) {
		$trimmed = trim( $text );
		if ( '' === $trimmed ) {
			return $text;
		}

		$start = strlen( $text ) - strlen( ltrim( $text ) );
		$end   = strlen( $text ) - strlen( rtrim( $text ) );

		return substr( $text, 0, $start ) . $mark . $trimmed . $mark . ( $end > 0 ? substr( $text, -$end ) : '' );
	}

	/**
	 * リンクにする。残せない宛先は文字だけにする。
	 *
	 * @param DOMElement $node a 要素。
	 * @return string
	 */
	private static function link( DOMElement $node ) {
		$text = self::inline_children( $node );
		$url  = self::link_url( $node->getAttribute( 'href' ) );

		if ( '' === trim( $text ) || '' === $url ) {
			return $text;
		}

		return '[' . trim( preg_replace( '/\s*\n\s*/', ' ', $text ) ) . '](' . $url . ')';
	}

	/**
	 * リンクの宛先を整える。絶対の URL だけを残す。
	 *
	 * @param string $href 宛先。
	 * @return string 残せないときは空。
	 */
	private static function link_url( $href ) {
		$url = trim( $href );

		if ( ! preg_match( '/^([a-zA-Z][a-zA-Z0-9+.-]*):/', $url, $match ) ) {
			return '';
		}

		$scheme = strtolower( $match[1] );
		if ( ! in_array( $scheme, self::SCHEMES, true ) ) {
			return '';
		}

		return strtr(
			$scheme . substr( $url, strlen( $scheme ) ),
			array(
				' '  => '%20',
				"\t" => '%09',
				"\n" => '',
				"\r" => '',
				'('  => '%28',
				')'  => '%29',
				'<'  => '%3C',
				'>'  => '%3E',
			)
		);
	}

	/**
	 * 文字の中で Markdown の記法に読まれるものを打ち消す。
	 *
	 * @param string $text 文字。
	 * @return string
	 */
	private static function escape_text( $text ) {
		$text = (string) preg_replace( '/([\\\\`*_\[\]<~])/', '\\\\$1', $text );
		$text = (string) preg_replace( '/!(?=\\\\\[)/', '\\\\!', $text );

		// 実体参照に読まれる形だけ。ただの & はそのまま。
		return (string) preg_replace( '/&(?=#?[a-zA-Z0-9]+;)/', '\\\\&', $text );
	}
}
