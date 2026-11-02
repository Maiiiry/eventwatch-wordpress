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

		$upcoming_list = '<!-- wp:eventwatch/event-list {"layout":"list","perPage":2} /-->';
		$past_section  = self::section(
			__( 'Past events', 'eventwatch' ),
			__( 'Events that have ended, newest first.', 'eventwatch' ),
			'<!-- wp:eventwatch/past-events /-->'
		);

		$patterns = array(
			'this-month'        => array(
				'title'      => __( 'Events This Month', 'eventwatch' ),
				'blockTypes' => array( Event_List_Block::NAME ),
				'content'    => self::wrap(
					self::section(
						__( 'Events this month', 'eventwatch' ),
						'',
						'<!-- wp:eventwatch/event-list {"period":"thisMonth"} /-->'
					)
				),
			),
			'coming-soon'       => array(
				'title'      => __( 'Coming Soon', 'eventwatch' ),
				'blockTypes' => array( Event_List_Block::NAME ),
				'content'    => self::wrap(
					self::section(
						_x( 'Coming soon', 'heading of the next events', 'eventwatch' ),
						'',
						'<!-- wp:eventwatch/event-list {"perPage":3,"period":"next30Days"} /-->'
					)
				),
			),
			'past-events'       => array(
				'title'      => __( 'Past Events', 'eventwatch' ),
				'blockTypes' => array( Past_Events_Block::NAME ),
				'content'    => self::wrap( $past_section ),
			),
			'upcoming-and-past' => array(
				'title'      => __( 'Upcoming and Past Events', 'eventwatch' ),
				'blockTypes' => array( Event_List_Block::NAME, Past_Events_Block::NAME ),
				'content'    => self::wrap(
					self::section( __( 'Upcoming events', 'eventwatch' ), '', $upcoming_list ) . $past_section
				),
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
	 * パターン全体を包む。
	 *
	 * @param string $content ブロックの文。
	 * @return string
	 */
	private static function wrap( $content ) {
		return '<!-- wp:group {"align":"wide","layout":{"type":"default"}} --><div class="wp-block-group alignwide">' . $content . '</div><!-- /wp:group -->';
	}

	/**
	 * 見出しと説明とブロックの 1 区切り。
	 *
	 * @param string $heading 見出し。
	 * @param string $text    説明。空なら置かない。
	 * @param string $block   ブロックの文。
	 * @return string
	 */
	private static function section( $heading, $text, $block ) {
		$head = '<!-- wp:heading --><h2 class="wp-block-heading">' . esc_html( $heading ) . '</h2><!-- /wp:heading -->';
		if ( '' !== $text ) {
			$head .= '<!-- wp:paragraph {"fontSize":"small"} --><p class="has-small-font-size">' . esc_html( $text ) . '</p><!-- /wp:paragraph -->';
		}

		return '<!-- wp:group {"style":{"spacing":{"blockGap":"0.25rem"}},"layout":{"type":"default"}} --><div class="wp-block-group">' . $head . '</div><!-- /wp:group -->' . $block;
	}
}
