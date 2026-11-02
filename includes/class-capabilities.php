<?php
/**
 * イベントの投稿タイプの権限。
 *
 * @package EventWatch
 */

namespace EventWatch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 役割への付与と取り外しを持つ。
 *
 * capability は role の行に保存されるので、プラグインを消しても自動では消えない。
 */
class Capabilities {

	/**
	 * 投稿タイプの capability_type から作られる、役割に持たせる権限。
	 *
	 * @var string[]
	 */
	const PRIMITIVES = array(
		'edit_eventwatch_events',
		'edit_others_eventwatch_events',
		'edit_private_eventwatch_events',
		'edit_published_eventwatch_events',
		'publish_eventwatch_events',
		'read_private_eventwatch_events',
		'delete_eventwatch_events',
		'delete_others_eventwatch_events',
		'delete_private_eventwatch_events',
		'delete_published_eventwatch_events',
	);

	/**
	 * 有効化のときに付ける役割。
	 *
	 * @var string[]
	 */
	const ROLES = array( 'administrator', 'editor' );

	/**
	 * いまのサイトの役割に付ける。
	 *
	 * @return void
	 */
	public static function grant() {
		foreach ( self::ROLES as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::PRIMITIVES as $capability ) {
				$role->add_cap( $capability );
			}
		}
	}

	/**
	 * いまのサイトのすべての役割から外す。あとから付け足した役割にも残さない。
	 *
	 * @return void
	 */
	public static function remove_from_all_roles() {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::PRIMITIVES as $capability ) {
				$role->remove_cap( $capability );
			}
		}
	}
}
