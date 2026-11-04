<?php
/**
 * EventWatch の本文の Markdown を、投稿のブロックに変える。
 *
 * @package EventWatch
 */

namespace EventWatch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EventWatch の本文に書ける記法を読む。生の HTML は要素にせず文字として出す。
 *
 * 1 つの改行は `<br>` にする。EventWatch の表示は改行を行の分かれ目として描くので、つなぐと見た目が変わる。
 * 文字参照で書いた画像（`![a][ref]`）と、宛先の 2 段以上の括弧は読まない。
 */
class Markdown_Blocks {

	const HR       = '/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/';
	const HEADING  = '/^ {0,3}(#{1,6})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/';
	const FENCE    = '/^( {0,3})(`{3,}|~{3,})(.*)$/';
	const QUOTE    = '/^ {0,3}> ?/';
	const LIST     = '/^( {0,3})([-+*]|\d{1,9}[.)])( +|$)/';
	const DELIMITS = '/^ *\|? *:?-+:? *(?:\| *:?-+:? *)*\|? *$/';

	/**
	 * 読んだ画像の宛先。
	 *
	 * @var string[]
	 */
	private static $found = array();

	/**
	 * 画像の宛先と、手元の添付の対応。
	 *
	 * @var array<string, int>
	 */
	private static $images = array();

	/**
	 * 本文をブロックの文字列にする。対応に無い画像は説明の文字にする。
	 *
	 * @param string             $markdown Markdown。
	 * @param array<string, int> $images   画像の宛先と、手元の添付の ID。
	 * @return string
	 */
	public static function from_markdown( $markdown, array $images = array() ) {
		self::$images = $images;

		$blocks = self::render( self::parse( self::lines( $markdown ) ) );

		self::$images = array();

		// 本文の文字をショートコードとして動かさない。書いた人はサイトの権限を持つとは限らない。ブロックの属性に [ は出さない。
		return str_replace( '[', '&#91;', implode( "\n\n", $blocks ) );
	}

	/**
	 * 本文の画像の宛先。
	 *
	 * @param string $markdown Markdown。
	 * @return string[]
	 */
	public static function image_urls( $markdown ) {
		self::$found = array();
		self::from_markdown( $markdown );
		$found       = array_values( array_unique( self::$found ) );
		self::$found = array();

		return $found;
	}

	/**
	 * 行に分ける。
	 *
	 * @param string $markdown Markdown。
	 * @return string[]
	 */
	private static function lines( $markdown ) {
		return explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", (string) $markdown ) );
	}

	/**
	 * 行をブロックの木にする。
	 *
	 * @param string[] $lines 行。
	 * @return array<int, array>
	 */
	private static function parse( array $lines ) {
		$nodes = array();
		$count = count( $lines );
		$i     = 0;

		while ( $i < $count ) {
			$line = $lines[ $i ];

			if ( '' === trim( $line ) ) {
				++$i;
			} elseif ( preg_match( self::FENCE, $line, $fence ) && ! ( '`' === $fence[2][0] && false !== strpos( $fence[3], '`' ) ) ) {
				list( $node, $i ) = self::parse_fence( $lines, $i, $fence );
				$nodes[]          = $node;
			} elseif ( preg_match( '/^(?: {4}|\t)/', $line ) ) {
				list( $node, $i ) = self::parse_indented( $lines, $i );
				$nodes[]          = $node;
			} elseif ( preg_match( self::HEADING, $line, $heading ) ) {
				$nodes[] = array(
					'type'  => 'heading',
					'level' => strlen( $heading[1] ),
					'text'  => isset( $heading[2] ) ? $heading[2] : '',
				);
				++$i;
			} elseif ( preg_match( self::HR, $line ) ) {
				$nodes[] = array( 'type' => 'separator' );
				++$i;
			} elseif ( preg_match( self::QUOTE, $line ) ) {
				list( $node, $i ) = self::parse_quote( $lines, $i );
				$nodes[]          = $node;
			} elseif ( preg_match( self::LIST, $line ) ) {
				list( $node, $i ) = self::parse_list( $lines, $i );
				$nodes[]          = $node;
			} elseif ( $i + 1 < $count && self::is_table_start( $line, $lines[ $i + 1 ] ) ) {
				list( $node, $i ) = self::parse_table( $lines, $i );
				$nodes[]          = $node;
			} else {
				list( $node, $i ) = self::parse_paragraph( $lines, $i );
				$nodes[]          = $node;
			}
		}

		return $nodes;
	}

	/**
	 * 囲みのコードブロックを読む。
	 *
	 * @param string[] $lines 行。
	 * @param int      $i     始まりの行。
	 * @param string[] $fence 始まりの行の照合。
	 * @return array{0: array, 1: int}
	 */
	private static function parse_fence( array $lines, $i, array $fence ) {
		$count  = count( $lines );
		$close  = '/^ {0,3}' . preg_quote( $fence[2][0], '/' ) . '{' . strlen( $fence[2] ) . ',}[ \t]*$/';
		$indent = strlen( $fence[1] );
		$code   = array();

		for ( ++$i; $i < $count; ++$i ) {
			if ( preg_match( $close, $lines[ $i ] ) ) {
				++$i;
				break;
			}
			$code[] = (string) preg_replace( '/^ {0,' . $indent . '}/', '', $lines[ $i ] );
		}

		return array(
			array(
				'type' => 'code',
				'text' => implode( "\n", $code ),
			),
			$i,
		);
	}

	/**
	 * 字下げのコードブロックを読む。
	 *
	 * @param string[] $lines 行。
	 * @param int      $i     始まりの行。
	 * @return array{0: array, 1: int}
	 */
	private static function parse_indented( array $lines, $i ) {
		$count = count( $lines );
		$code  = array();

		for ( ; $i < $count; ++$i ) {
			if ( '' !== trim( $lines[ $i ] ) && ! preg_match( '/^(?: {4}|\t)/', $lines[ $i ] ) ) {
				break;
			}
			$code[] = (string) preg_replace( '/^(?: {4}|\t)/', '', $lines[ $i ] );
		}

		return array(
			array(
				'type' => 'code',
				'text' => rtrim( implode( "\n", $code ) ),
			),
			$i,
		);
	}

	/**
	 * 引用を読む。段落の続きは `>` が無くても引用に含める。
	 *
	 * @param string[] $lines 行。
	 * @param int      $i     始まりの行。
	 * @return array{0: array, 1: int}
	 */
	private static function parse_quote( array $lines, $i ) {
		$count = count( $lines );
		$inner = array();

		for ( ; $i < $count; ++$i ) {
			$line = $lines[ $i ];
			if ( preg_match( self::QUOTE, $line ) ) {
				$inner[] = (string) preg_replace( self::QUOTE, '', $line );
			} elseif ( '' !== trim( $line ) && $inner && '' !== trim( end( $inner ) ) && ! self::starts_block( $line ) ) {
				$inner[] = $line;
			} else {
				break;
			}
		}

		return array(
			array(
				'type'     => 'quote',
				'children' => self::parse( $inner ),
			),
			$i,
		);
	}

	/**
	 * リストを読む。種類の違う印が来たら別のリストにする。
	 *
	 * @param string[] $lines 行。
	 * @param int      $i     始まりの行。
	 * @return array{0: array, 1: int}
	 */
	private static function parse_list( array $lines, $i ) {
		$count = count( $lines );
		preg_match( self::LIST, $lines[ $i ], $first );
		$ordered = ctype_digit( substr( $first[2], 0, 1 ) );
		$mark    = substr( $first[2], -1 );
		$items   = array();

		while ( $i < $count && preg_match( self::LIST, $lines[ $i ], $match ) && ! preg_match( self::HR, $lines[ $i ] ) && substr( $match[2], -1 ) === $mark ) {
			$width = strlen( $match[1] ) + strlen( $match[2] ) + max( 1, strlen( $match[3] ) );
			$body  = array( (string) substr( $lines[ $i ], $width ) );

			for ( ++$i; $i < $count; ++$i ) {
				$line  = $lines[ $i ];
				$blank = '' === trim( $line );

				if ( $blank ) {
					$next = self::next_filled( $lines, $i );
					if ( null === $next || strlen( $next ) - strlen( ltrim( $next, ' ' ) ) < $width ) {
						break;
					}
					$body[] = '';
				} elseif ( strlen( $line ) - strlen( ltrim( $line, ' ' ) ) >= $width ) {
					$body[] = (string) substr( $line, $width );
				} elseif ( '' !== trim( (string) end( $body ) ) && ! preg_match( self::LIST, $line ) && ! self::starts_block( $line ) ) {
					$body[] = ltrim( $line );
				} else {
					break;
				}
			}

			$items[] = self::parse( $body );

			while ( $i < $count && '' === trim( $lines[ $i ] ) ) {
				$next = self::next_filled( $lines, $i );
				if ( null === $next || ! preg_match( self::LIST, $next, $after ) || substr( $after[2], -1 ) !== $mark ) {
					break 2;
				}
				++$i;
			}
		}

		return array(
			array(
				'type'    => 'list',
				'ordered' => $ordered,
				'start'   => $ordered ? (int) $first[2] : 1,
				'items'   => $items,
			),
			$i,
		);
	}

	/**
	 * この行より後で、最初の空でない行。
	 *
	 * @param string[] $lines 行。
	 * @param int      $i     いまの行。
	 * @return string|null
	 */
	private static function next_filled( array $lines, $i ) {
		$count = count( $lines );

		for ( ++$i; $i < $count; ++$i ) {
			if ( '' !== trim( $lines[ $i ] ) ) {
				return $lines[ $i ];
			}
		}

		return null;
	}

	/**
	 * 表の始まりか。
	 *
	 * @param string $line 行。
	 * @param string $next 次の行。
	 * @return bool
	 */
	private static function is_table_start( $line, $next ) {
		return false !== strpos( $line, '|' ) && false !== strpos( $next, '-' ) && false !== strpos( $next, '|' ) && preg_match( self::DELIMITS, $next );
	}

	/**
	 * 表を読む。
	 *
	 * @param string[] $lines 行。
	 * @param int      $i     始まりの行。
	 * @return array{0: array, 1: int}
	 */
	private static function parse_table( array $lines, $i ) {
		$count = count( $lines );
		$head  = self::table_cells( $lines[ $i ] );
		$rows  = array();

		for ( $i += 2; $i < $count; ++$i ) {
			if ( '' === trim( $lines[ $i ] ) || self::starts_block( $lines[ $i ] ) ) {
				break;
			}
			$rows[] = array_slice( array_pad( self::table_cells( $lines[ $i ] ), count( $head ), '' ), 0, count( $head ) );
		}

		return array(
			array(
				'type' => 'table',
				'head' => $head,
				'rows' => $rows,
			),
			$i,
		);
	}

	/**
	 * 表の行をセルに分ける。`\|` では分けない。
	 *
	 * @param string $line 行。
	 * @return string[]
	 */
	private static function table_cells( $line ) {
		$line  = trim( $line );
		$line  = (string) preg_replace( '/^\|/', '', $line );
		$line  = (string) preg_replace( '/(?<!\\\\)\|$/', '', $line );
		$cells = (array) preg_split( '/(?<!\\\\)\|/', $line );

		return array_map(
			static function ( $cell ) {
				return str_replace( '\\|', '|', trim( (string) $cell ) );
			},
			$cells
		);
	}

	/**
	 * 段落を読む。次の行が `===`・`---` なら見出しにする。
	 *
	 * @param string[] $lines 行。
	 * @param int      $i     始まりの行。
	 * @return array{0: array, 1: int}
	 */
	private static function parse_paragraph( array $lines, $i ) {
		$count = count( $lines );
		$text  = array( ltrim( $lines[ $i ] ) );

		for ( ++$i; $i < $count; ++$i ) {
			$line = $lines[ $i ];
			if ( preg_match( '/^ {0,3}(=+|-+)[ \t]*$/', $line, $under ) ) {
				return array(
					array(
						'type'  => 'heading',
						'level' => '=' === $under[1][0] ? 1 : 2,
						'text'  => implode( "\n", $text ),
					),
					$i + 1,
				);
			}
			if ( '' === trim( $line ) || self::starts_block( $line ) ) {
				break;
			}
			$text[] = ltrim( $line );
		}

		return array(
			array(
				'type' => 'paragraph',
				'text' => implode( "\n", $text ),
			),
			$i,
		);
	}

	/**
	 * 段落の途中でも新しいブロックを始める行か。番号付きのリストは 1 からでなければ段落の続きとして読む。
	 *
	 * @param string $line 行。
	 * @return bool
	 */
	private static function starts_block( $line ) {
		if ( preg_match( self::HEADING, $line ) || preg_match( self::HR, $line ) || preg_match( self::FENCE, $line ) || preg_match( self::QUOTE, $line ) ) {
			return true;
		}

		return preg_match( self::LIST, $line, $match ) && '' !== $match[3] && ( ! ctype_digit( substr( $match[2], 0, 1 ) ) || 1 === (int) $match[2] );
	}

	/**
	 * 木をブロックの文字列にする。
	 *
	 * @param array<int, array> $nodes 木。
	 * @return string[]
	 */
	private static function render( array $nodes ) {
		return array_map( array( self::class, 'render_node' ), $nodes );
	}

	/**
	 * 1 つのブロックを文字列にする。
	 *
	 * @param array $node 節。
	 * @return string
	 */
	private static function render_node( array $node ) {
		switch ( $node['type'] ) {
			case 'heading':
				return self::heading( $node );
			case 'separator':
				return "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";
			case 'code':
				return "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>" . self::code_html( $node['text'] ) . "</code></pre>\n<!-- /wp:code -->";
			case 'quote':
				return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">" . implode( "\n\n", self::render( $node['children'] ) ) . "</blockquote>\n<!-- /wp:quote -->";
			case 'list':
				return self::list_block( $node );
			case 'table':
				return self::table( $node );
		}

		return self::paragraph( $node['text'] );
	}

	/**
	 * 見出し。
	 *
	 * @param array $node 節。
	 * @return string
	 */
	private static function heading( array $node ) {
		$level = (int) $node['level'];
		$attrs = 2 === $level ? '' : ' {"level":' . $level . '}';

		return "<!-- wp:heading{$attrs} -->\n<h{$level} class=\"wp-block-heading\">" . self::inline( $node['text'] ) . "</h{$level}>\n<!-- /wp:heading -->";
	}

	/**
	 * 段落。画像だけの段落は画像のブロックにする。
	 *
	 * @param string $text 文字列。
	 * @return string
	 */
	private static function paragraph( $text ) {
		if ( preg_match( '/^!\[((?:[^\[\]]|\[[^\]]*\])*)\]\(([^()\s<>]+)\)$/', trim( $text ), $image ) ) {
			self::$found[] = $image[2];
			if ( isset( self::$images[ $image[2] ] ) ) {
				$id = (int) self::$images[ $image[2] ];
				return "<!-- wp:image {\"id\":{$id},\"sizeSlug\":\"full\",\"linkDestination\":\"none\"} -->\n<figure class=\"wp-block-image size-full\">" . self::image_tag( $id, self::plain_alt( $image[1] ) ) . "</figure>\n<!-- /wp:image -->";
			}
		}

		return "<!-- wp:paragraph -->\n<p>" . self::inline( $text ) . "</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * コードの文字。文字参照に見える形も文字のまま残す。
	 *
	 * @param string $code コード。
	 * @return string
	 */
	private static function code_html( $code ) {
		return htmlspecialchars( $code, ENT_QUOTES, 'UTF-8', true );
	}

	/**
	 * リスト。本体のリストの項目が持てる子はリストだけなので、ほかのブロックは行の中の文字にする。
	 *
	 * @param array $node 節。
	 * @return string
	 */
	private static function list_block( array $node ) {
		$attrs = array();
		if ( $node['ordered'] ) {
			$attrs['ordered'] = true;
			if ( 1 !== $node['start'] ) {
				$attrs['start'] = $node['start'];
			}
		}
		$comment = $attrs ? ' ' . wp_json_encode( $attrs ) : '';
		$tag     = $node['ordered'] ? 'ol' : 'ul';
		$start   = $node['ordered'] && 1 !== $node['start'] ? ' start="' . (int) $node['start'] . '"' : '';
		$items   = array();

		foreach ( $node['items'] as $children ) {
			$items[] = "<!-- wp:list-item -->\n<li>" . self::list_item( $children ) . "</li>\n<!-- /wp:list-item -->";
		}

		return "<!-- wp:list{$comment} -->\n<{$tag}{$start} class=\"wp-block-list\">" . implode( "\n\n", $items ) . "</{$tag}>\n<!-- /wp:list -->";
	}

	/**
	 * リストの項目の中身。
	 *
	 * @param array<int, array> $children 子の節。
	 * @return string
	 */
	private static function list_item( array $children ) {
		$text   = array();
		$nested = '';

		foreach ( $children as $child ) {
			if ( 'list' === $child['type'] ) {
				$nested .= self::list_block( $child );
			} else {
				$text = array_merge( $text, self::flat_lines( $child ) );
			}
		}

		return implode( '<br><br>', $text ) . $nested;
	}

	/**
	 * ブロックを行の中の文字にする。
	 *
	 * @param array $node 節。
	 * @return string[]
	 */
	private static function flat_lines( array $node ) {
		switch ( $node['type'] ) {
			case 'code':
				return array( '<code>' . self::code_html( $node['text'] ) . '</code>' );
			case 'quote':
				return array_merge( array(), ...array_map( array( self::class, 'flat_lines' ), $node['children'] ) );
			case 'list':
				$lines = array( array() );
				foreach ( $node['items'] as $children ) {
					$lines[] = array_merge( array(), ...array_map( array( self::class, 'flat_lines' ), $children ) );
				}
				return array_merge( ...$lines );
			case 'table':
				$lines = array();
				foreach ( array_merge( array( $node['head'] ), $node['rows'] ) as $row ) {
					$lines[] = implode( ' / ', array_map( array( self::class, 'inline' ), $row ) );
				}
				return $lines;
			case 'separator':
				return array();
		}

		return array( self::inline( $node['text'] ) );
	}

	/**
	 * 表。
	 *
	 * @param array $node 節。
	 * @return string
	 */
	private static function table( array $node ) {
		$head = '';
		foreach ( $node['head'] as $cell ) {
			$head .= '<th>' . self::inline( $cell ) . '</th>';
		}

		$body = '';
		foreach ( $node['rows'] as $row ) {
			$body .= '<tr>';
			foreach ( $row as $cell ) {
				$body .= '<td>' . self::inline( $cell ) . '</td>';
			}
			$body .= '</tr>';
		}

		// 本体の表のブロックは、行の無い区画を出さない。
		$body = '' === $body ? '' : "<tbody>{$body}</tbody>";

		return "<!-- wp:table -->\n<figure class=\"wp-block-table\"><table class=\"has-fixed-layout\"><thead><tr>{$head}</tr></thead>{$body}</table></figure>\n<!-- /wp:table -->";
	}

	/**
	 * 行の中を HTML にする。
	 *
	 * @param string $text 文字列。
	 * @return string
	 */
	private static function inline( $text ) {
		$parts  = array();
		$length = strlen( $text );
		$i      = 0;

		while ( $i < $length ) {
			$char = $text[ $i ];
			$next = $i + 1 < $length ? $text[ $i + 1 ] : '';

			if ( '\\' === $char ) {
				if ( "\n" === $next ) {
					$parts[] = '<br>';
					$i      += 2;
				} elseif ( '' !== $next && ctype_punct( $next ) ) {
					$parts[] = esc_html( $next );
					$i      += 2;
				} else {
					$parts[] = '\\';
					++$i;
				}
				continue;
			}

			if ( "\n" === $char ) {
				$last = count( $parts ) - 1;
				if ( $last >= 0 && is_string( $parts[ $last ] ) ) {
					$parts[ $last ] = rtrim( $parts[ $last ], ' ' );
				}
				$parts[] = '<br>';
				++$i;
				continue;
			}

			if ( ( '*' === $char || '_' === $char ) && preg_match( '/^(?:\*+|_+)/', substr( $text, $i ), $run ) ) {
				$parts[] = self::delimiter( $text, $i, $run[0] );
				$i      += strlen( $run[0] );
				continue;
			}

			$matched = self::inline_at( $text, $i );
			if ( null !== $matched ) {
				$parts[] = $matched[0];
				$i       = $matched[1];
				continue;
			}

			// esc_html は不正な UTF-8 を空にするので、1 バイトずつ渡さない。記号の無い間をまとめて渡す。
			if ( preg_match( '/^[^\\\\\n`!\[<&*_~]+/', substr( $text, $i ), $plain ) ) {
				$parts[] = esc_html( $plain[0] );
				$i      += strlen( $plain[0] );
				continue;
			}

			$parts[] = '&' === $char ? '&amp;' : esc_html( $char );
			++$i;
		}

		return self::join_parts( self::match_delimiters( $parts ) );
	}

	/**
	 * 強調の記号の連なりを、開けるか閉じられるかとともに読む。規則は CommonMark と同じ。
	 *
	 * 区切りの判定が EventWatch の表示とずれると、同じ本文の強調の範囲が変わる。
	 *
	 * @param string $text 文字列。
	 * @param int    $i    位置。
	 * @param string $run  記号の連なり。
	 * @return array
	 */
	private static function delimiter( $text, $i, $run ) {
		$prev = preg_match( '/.\z/su', substr( $text, 0, $i ), $before ) ? $before[0] : ' ';
		$next = preg_match( '/\A./su', (string) substr( $text, $i + strlen( $run ) ), $after ) ? $after[0] : ' ';

		$prev_space = (bool) preg_match( '/^[\s\p{Z}]$/u', $prev );
		$next_space = (bool) preg_match( '/^[\s\p{Z}]$/u', $next );
		$prev_punct = (bool) preg_match( '/^[\p{P}\p{S}]$/u', $prev );
		$next_punct = (bool) preg_match( '/^[\p{P}\p{S}]$/u', $next );

		$left  = ! $next_space && ( ! $next_punct || $prev_space || $prev_punct );
		$right = ! $prev_space && ( ! $prev_punct || $next_space || $next_punct );

		if ( '_' === $run[0] ) {
			$can_open  = $left && ( ! $right || $prev_punct );
			$can_close = $right && ( ! $left || $next_punct );
		} else {
			$can_open  = $left;
			$can_close = $right;
		}

		return array(
			'char'      => $run[0],
			'length'    => strlen( $run ),
			'count'     => strlen( $run ),
			'can_open'  => $can_open,
			'can_close' => $can_close,
			'open'      => '',
			'close'     => '',
		);
	}

	/**
	 * 閉じる記号から順に、一番近い開く記号と組にする。間に残った記号は文字にする。
	 *
	 * @param array $parts 文字列と記号の並び。
	 * @return array
	 */
	private static function match_delimiters( array $parts ) {
		foreach ( array_keys( $parts ) as $closer ) {
			if ( ! is_array( $parts[ $closer ] ) || ! $parts[ $closer ]['can_close'] ) {
				continue;
			}

			while ( $parts[ $closer ]['count'] > 0 ) {
				$opener = self::find_opener( $parts, $closer );
				if ( null === $opener ) {
					break;
				}

				$use = $parts[ $opener ]['count'] >= 2 && $parts[ $closer ]['count'] >= 2 ? 2 : 1;
				$tag = 2 === $use ? 'strong' : 'em';

				$parts[ $opener ]['count'] -= $use;
				$parts[ $closer ]['count'] -= $use;
				$parts[ $opener ]['open']   = "<{$tag}>" . $parts[ $opener ]['open'];
				$parts[ $closer ]['close'] .= "</{$tag}>";

				for ( $k = $opener + 1; $k < $closer; ++$k ) {
					if ( is_array( $parts[ $k ] ) ) {
						$parts[ $k ]['can_open']  = false;
						$parts[ $k ]['can_close'] = false;
					}
				}
			}
		}

		return $parts;
	}

	/**
	 * 閉じる記号と組になる、一番近い開く記号。
	 *
	 * @param array $parts  文字列と記号の並び。
	 * @param int   $closer 閉じる記号の位置。
	 * @return int|null
	 */
	private static function find_opener( array $parts, $closer ) {
		$close = $parts[ $closer ];

		for ( $k = $closer - 1; $k >= 0; --$k ) {
			$open = $parts[ $k ];
			if ( ! is_array( $open ) || $open['char'] !== $close['char'] || 0 === $open['count'] || ! $open['can_open'] ) {
				continue;
			}

			// CommonMark の 3 の倍数の規則。どちらかが開閉の両方になれるときの組み方を決める。
			$both = $open['can_close'] || $close['can_open'];
			if ( $both && 0 === ( $open['length'] + $close['length'] ) % 3 && ( 0 !== $open['length'] % 3 || 0 !== $close['length'] % 3 ) ) {
				continue;
			}

			return $k;
		}

		return null;
	}

	/**
	 * 文字列と記号の並びをつなぐ。組にならなかった記号は文字として残す。
	 *
	 * @param array $parts 文字列と記号の並び。
	 * @return string
	 */
	private static function join_parts( array $parts ) {
		$html = '';
		foreach ( $parts as $part ) {
			$html .= is_string( $part ) ? $part : $part['close'] . str_repeat( $part['char'], $part['count'] ) . $part['open'];
		}

		return $html;
	}

	/**
	 * この位置から始まる行の中の記法を読む。
	 *
	 * @param string $text 文字列。
	 * @param int    $i    位置。
	 * @return array{0: string, 1: int}|null HTML と次の位置。
	 */
	private static function inline_at( $text, $i ) {
		$rest = substr( $text, $i );
		$char = $rest[0];

		if ( '`' === $char && preg_match( '/^(`+)(.+?)(?<!`)\1(?!`)/s', $rest, $code ) ) {
			return array( '<code>' . self::code_html( trim( str_replace( "\n", ' ', $code[2] ) ) ) . '</code>', $i + strlen( $code[0] ) );
		}

		if ( '!' === $char && preg_match( '/^!\[((?:[^\[\]\\\\]|\\\\.|\[[^\]]*\])*)\]\(\s*(?:<([^<>\s]+)>|([^()\s<>]+))(?:\s+(?:"[^"]*"|\'[^\']*\'))?\s*\)/', $rest, $image ) ) {
			// 山括弧は揃ったときだけ宛先。揃わない形は EventWatch では文字で、投入記録と照らされていない。
			$url           = '' !== $image[2] ? $image[2] : $image[3];
			self::$found[] = $url;
			$alt           = self::plain_alt( $image[1] );
			$tag           = isset( self::$images[ $url ] ) ? self::image_tag( (int) self::$images[ $url ], $alt ) : esc_html( $alt );
			return array( $tag, $i + strlen( $image[0] ) );
		}

		if ( '[' === $char && preg_match( '/^\[((?:[^\[\]\\\\]|\\\\.|!\[[^\]]*\]\([^)]*\)|\[[^\[\]]*\])*)\]\(\s*(?:<([^<>\n]*)>|((?:[^()\s<>]|\([^()\s]*\))*))(?:\s+(?:"[^"]*"|\'[^\']*\'))?\s*\)/', $rest, $link ) ) {
			$url = '' !== $link[2] ? $link[2] : ( isset( $link[3] ) ? $link[3] : '' );
			return array( '<a href="' . esc_url( $url ) . '">' . self::inline( $link[1] ) . '</a>', $i + strlen( $link[0] ) );
		}

		if ( '<' === $char && preg_match( '/^<((?:https?:\/\/|mailto:)[^\s<>]+)>/i', $rest, $auto ) ) {
			return array( '<a href="' . esc_url( $auto[1] ) . '">' . esc_html( $auto[1] ) . '</a>', $i + strlen( $auto[0] ) );
		}

		if ( '&' === $char && preg_match( '/^&(?:#\d{1,7}|#[xX][0-9a-fA-F]{1,6}|[a-zA-Z][a-zA-Z0-9]{1,31});/', $rest, $entity ) ) {
			return array( esc_html( html_entity_decode( $entity[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), $i + strlen( $entity[0] ) );
		}

		return self::strikethrough( $text, $i );
	}

	/**
	 * 打ち消しを読む。記号の内側に空白があるものは打ち消しにしない。
	 *
	 * @param string $text 文字列。
	 * @param int    $i    位置。
	 * @return array{0: string, 1: int}|null
	 */
	private static function strikethrough( $text, $i ) {
		if ( '~~' !== substr( $text, $i, 2 ) ) {
			return null;
		}

		$after = substr( $text, $i + 2, 1 );
		if ( '' === $after || ctype_space( $after ) || '~' === $after ) {
			return null;
		}

		$from = $i + 2;
		while ( false !== ( $end = strpos( $text, '~~', $from ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition,Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- 次の閉じる記号を順に探す。
			if ( '\\' !== $text[ $end - 1 ] && ! ctype_space( $text[ $end - 1 ] ) ) {
				return array( '<del>' . self::inline( substr( $text, $i + 2, $end - $i - 2 ) ) . '</del>', $end + 2 );
			}
			$from = $end + 2;
		}

		return null;
	}

	/**
	 * 画像の要素。編集画面の画像のブロックと同じ形にする。送るときは class の添付の ID で宛先へ戻す。
	 *
	 * @param int    $id  添付の ID。
	 * @param string $alt 説明。
	 * @return string
	 */
	private static function image_tag( $id, $alt ) {
		return '<img src="' . esc_url( (string) wp_get_attachment_url( $id ) ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . (int) $id . '"/>';
	}

	/**
	 * 画像の説明の打ち消しを外す。
	 *
	 * @param string $alt 説明。
	 * @return string
	 */
	private static function plain_alt( $alt ) {
		return (string) preg_replace( '/\\\\([[:punct:]])/', '$1', $alt );
	}
}
