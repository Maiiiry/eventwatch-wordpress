<?php
/**
 * ブロックのパターンの登録。
 *
 * @package EventWatch
 */

namespace EventWatch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 見出しと説明は本体のブロックで置く。オーナーが直せて、色も書体もテーマから受け継ぐので、色の指定を書かない。
 */
class Event_Patterns {

	const CATEGORY = 'eventwatch';

	/**
	 * カテゴリとパターンを登録する。
	 *
	 * @return void
	 */
	public static function register() {
		register_block_pattern_category(
			self::CATEGORY,
			array( 'label' => __( 'EventWatch', 'eventwatch' ) )
		);

		$past_section = self::section(
			__( 'Past events', 'eventwatch' ),
			__( 'Events that have ended, newest first.', 'eventwatch' ),
			Past_Events_Block::NAME,
			array()
		);

		$patterns = array(
			'this-month'        => array(
				'title'      => __( 'Events This Month', 'eventwatch' ),
				'blockTypes' => array( Event_List_Block::NAME ),
				'content'    => self::section(
					__( 'Events this month', 'eventwatch' ),
					'',
					Event_List_Block::NAME,
					array( 'period' => Event_List::PERIOD_THIS_MONTH )
				),
			),
			'coming-soon'       => array(
				'title'      => __( 'Coming Soon', 'eventwatch' ),
				'blockTypes' => array( Event_List_Block::NAME ),
				'content'    => self::section(
					_x( 'Coming soon', 'heading of the next events', 'eventwatch' ),
					'',
					Event_List_Block::NAME,
					array(
						'perPage' => 3,
						'period'  => Event_List::PERIOD_NEXT_30_DAYS,
					)
				),
			),
			'past-events'       => array(
				'title'      => __( 'Past Events', 'eventwatch' ),
				'blockTypes' => array( Past_Events_Block::NAME ),
				'content'    => $past_section,
			),
			'upcoming-and-past' => array(
				'title'      => __( 'Upcoming and Past Events', 'eventwatch' ),
				'blockTypes' => array( Event_List_Block::NAME, Past_Events_Block::NAME ),
				'content'    => self::section(
					__( 'Upcoming events', 'eventwatch' ),
					'',
					Event_List_Block::NAME,
					array(
						'layout'  => Event_List::LAYOUT_LIST,
						'perPage' => 2,
					)
				) . '<!-- wp:spacer {"height":"3rem"} --><div style="height:3rem" aria-hidden="true" class="wp-block-spacer"></div><!-- /wp:spacer -->' . $past_section,
			),
		);

		foreach ( $patterns as $slug => $pattern ) {
			register_block_pattern(
				self::CATEGORY . '/' . $slug,
				array_merge(
					$pattern,
					array(
						'source'     => 'plugin',
						'categories' => array( self::CATEGORY ),
					)
				)
			);
		}
	}

	/**
	 * 見出しと説明とブロックの 1 区切り。
	 *
	 * 余白はブロックの margin に書く。blockGap はテーマが有効にしないと出力されず、preset の値はテーマが持つとは限らない。
	 *
	 * @param string $heading    見出し。
	 * @param string $text       説明。空なら置かない。
	 * @param string $block      ブロックの名前。
	 * @param array  $attributes ブロックの属性。
	 * @return string
	 */
	private static function section( $heading, $text, $block, array $attributes ) {
		$attributes['style'] = array( 'spacing' => array( 'margin' => array( 'top' => '1rem' ) ) );

		$html = '<!-- wp:heading {"style":{"spacing":{"margin":{"top":"0","bottom":"0"}}}} --><h2 class="wp-block-heading" style="margin-top:0;margin-bottom:0">' . esc_html( $heading ) . '</h2><!-- /wp:heading -->';
		if ( '' !== $text ) {
			$html .= '<!-- wp:paragraph {"style":{"spacing":{"margin":{"top":"0.25rem","bottom":"0"}}},"fontSize":"small"} --><p class="has-small-font-size" style="margin-top:0.25rem;margin-bottom:0">' . esc_html( $text ) . '</p><!-- /wp:paragraph -->';
		}
		$html .= '<!-- wp:' . $block . ' ' . wp_json_encode( $attributes ) . ' /-->';

		return '<!-- wp:group {"align":"wide","layout":{"type":"default"}} --><div class="wp-block-group alignwide">' . $html . '</div><!-- /wp:group -->';
	}
}
