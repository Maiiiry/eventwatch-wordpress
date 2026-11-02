=== EventWatch Events ===
Contributors: eventwatch
Tags: events, calendar, event list
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish your events on your WordPress site and list them on EventWatch.

== Description ==

EventWatch Events lets you manage events on your own WordPress site. Add events from the block editor with their dates, venue, prefecture, and category. Drafts, publishing, scheduling, private posts, the trash, and revisions work the same way as for WordPress posts.

You can use the plugin without an EventWatch account. It does not connect to EventWatch in this version.

= Showing events on your site =

Add the Event List block to any page. Choose cards or a list, the number of events per page, the order, and filters by category, tag, prefecture, and dates. The block follows your theme's colors and fonts, and you can change them in the block settings. Only published events that have not ended are listed. Events with no dates and password-protected events are not listed. The order and the date filter use the range from an event's first date to its last date.

Classic themes can use the same list with a shortcode:

`[eventwatch_events layout="list" per_page="6" order="asc" category="slug" tag="slug" prefecture="13" from="2026-12-01" to="2026-12-31" empty_text="No events yet."]`

All attributes are optional. `layout` is `cards` or `list`, `order` is `asc` or `desc`, and `prefecture` is a number from 1 to 47 in the JIS order.

To change how one event is drawn, copy `templates/event-card.php` or `templates/event-row.php` from the plugin to an `eventwatch` folder in your theme.

= Scheduled events =

WordPress publishes scheduled events with WP-Cron, which only runs when someone visits your site. On a site with few visitors, a scheduled event may be published later than the time you set. To publish on time, run WP-Cron from your server's cron.

= Removing the plugin =

Deleting the plugin removes its settings and the capabilities it added to user roles. Your events stay in the database unless the option `eventwatch_delete_events_on_uninstall` is set.

= Translations =

The plugin includes a Japanese translation. Other languages come from translate.wordpress.org.

== Changelog ==

= 0.1.0 =
* Plugin foundation.
