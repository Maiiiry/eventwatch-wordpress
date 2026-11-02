<?php
/**
 * カテゴリと都道府県の固定の選択肢。
 *
 * @package EventWatch
 */

namespace EventWatch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 値は EventWatch の投稿の値に揃える。あとで同期するときに、EventWatch の検証で弾かれないため。
 */
class Event_Options {

	/**
	 * オンラインのイベントだけが持てる都道府県の値。
	 */
	const PREFECTURE_NONE = 0;

	/**
	 * カテゴリの値と語。
	 *
	 * @return array<int, string>
	 */
	public static function categories() {
		return array(
			1   => __( 'Competitions & contests', 'eventwatch' ),
			2   => __( 'Performances & stage', 'eventwatch' ),
			3   => __( 'Seminars & study groups', 'eventwatch' ),
			4   => __( 'Volunteering & charity', 'eventwatch' ),
			5   => __( 'Festivals & markets', 'eventwatch' ),
			6   => __( 'Parties & meetups', 'eventwatch' ),
			7   => __( 'Exhibitions & viewings', 'eventwatch' ),
			8   => __( 'Hands-on & play', 'eventwatch' ),
			255 => __( 'Other', 'eventwatch' ),
		);
	}

	/**
	 * 都道府県の値（JIS の順）と語。
	 *
	 * @return array<int, string>
	 */
	public static function prefectures() {
		return array(
			1  => __( 'Hokkaido', 'eventwatch' ),
			2  => __( 'Aomori', 'eventwatch' ),
			3  => __( 'Iwate', 'eventwatch' ),
			4  => __( 'Miyagi', 'eventwatch' ),
			5  => __( 'Akita', 'eventwatch' ),
			6  => __( 'Yamagata', 'eventwatch' ),
			7  => __( 'Fukushima', 'eventwatch' ),
			8  => __( 'Ibaraki', 'eventwatch' ),
			9  => __( 'Tochigi', 'eventwatch' ),
			10 => __( 'Gunma', 'eventwatch' ),
			11 => __( 'Saitama', 'eventwatch' ),
			12 => __( 'Chiba', 'eventwatch' ),
			13 => __( 'Tokyo', 'eventwatch' ),
			14 => __( 'Kanagawa', 'eventwatch' ),
			15 => __( 'Niigata', 'eventwatch' ),
			16 => __( 'Toyama', 'eventwatch' ),
			17 => __( 'Ishikawa', 'eventwatch' ),
			18 => __( 'Fukui', 'eventwatch' ),
			19 => __( 'Yamanashi', 'eventwatch' ),
			20 => __( 'Nagano', 'eventwatch' ),
			21 => __( 'Gifu', 'eventwatch' ),
			22 => __( 'Shizuoka', 'eventwatch' ),
			23 => __( 'Aichi', 'eventwatch' ),
			24 => __( 'Mie', 'eventwatch' ),
			25 => __( 'Shiga', 'eventwatch' ),
			26 => __( 'Kyoto', 'eventwatch' ),
			27 => __( 'Osaka', 'eventwatch' ),
			28 => __( 'Hyogo', 'eventwatch' ),
			29 => __( 'Nara', 'eventwatch' ),
			30 => __( 'Wakayama', 'eventwatch' ),
			31 => __( 'Tottori', 'eventwatch' ),
			32 => __( 'Shimane', 'eventwatch' ),
			33 => __( 'Okayama', 'eventwatch' ),
			34 => __( 'Hiroshima', 'eventwatch' ),
			35 => __( 'Yamaguchi', 'eventwatch' ),
			36 => __( 'Tokushima', 'eventwatch' ),
			37 => __( 'Kagawa', 'eventwatch' ),
			38 => __( 'Ehime', 'eventwatch' ),
			39 => __( 'Kochi', 'eventwatch' ),
			40 => __( 'Fukuoka', 'eventwatch' ),
			41 => __( 'Saga', 'eventwatch' ),
			42 => __( 'Nagasaki', 'eventwatch' ),
			43 => __( 'Kumamoto', 'eventwatch' ),
			44 => __( 'Oita', 'eventwatch' ),
			45 => __( 'Miyazaki', 'eventwatch' ),
			46 => __( 'Kagoshima', 'eventwatch' ),
			47 => __( 'Okinawa', 'eventwatch' ),
		);
	}
}
