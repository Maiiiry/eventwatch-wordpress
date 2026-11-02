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

= Scheduled events =

WordPress publishes scheduled events with WP-Cron, which only runs when someone visits your site. On a site with few visitors, a scheduled event may be published later than the time you set. To publish on time, run WP-Cron from your server's cron.

= Removing the plugin =

Deleting the plugin removes its settings and the capabilities it added to user roles. Your events stay in the database unless the option `eventwatch_delete_events_on_uninstall` is set.

== Changelog ==

= 0.1.0 =
* Plugin foundation.
