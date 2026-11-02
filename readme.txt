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

All attributes are optional. `layout` is `cards` or `list`, `order` is `asc` or `desc`, and `prefecture` is a number from 1 to 47 in the JIS order. `period` is `thisMonth` or `next30Days`. It is worked out from today's date each time the list is shown, and it replaces `from` and `to`.

To change how one event is drawn, copy `templates/event-card.php` or `templates/event-row.php` from the plugin to an `eventwatch` folder in your theme. The `ended` value in `$args` is true for an event that has ended.

Add the Past Events block to show the events that have ended, newest first. An event moves here the day after it ends. Choose cards or a list, the number of events per page, and how far back to go. The default is one year.

Classic themes can use the same list with a shortcode:

`[eventwatch_past_events layout="list" per_page="6" months="12" empty_text="No past events yet."]`

`months` is `3`, `6`, `12`, `24`, or `0` for all past events.

The EventWatch pattern category has ready-made sections: Events This Month, Coming Soon, Past Events, and Upcoming and Past Events. The headings use your theme's colors and fonts, and you can edit them. The Event List block also has the variations Events This Month and Coming Soon.

Add the Event Search block to let visitors search your upcoming events by keyword, dates, category, and prefecture. The results replace the old ones without reloading the page, and the search also works without JavaScript. The search is kept in the page URL, so visitors can share it and use the back button. Keywords match event titles, event content, and venue names.

Classic themes can use the same search with a shortcode:

`[eventwatch_search layout="list" per_page="6"]`

= Event pages =

Each published event has its own page at `/event/your-event/`. The page shows the title, featured image, content, dates, venue, organizer (your site name), map links, and buttons to add the event to a calendar and to share it. You can change the `event` part of the URL in Settings > EventWatch. Old URLs redirect to the new ones. Password-protected events show only the title and the password form until the visitor enters the password.

Block themes can rearrange the page in the Site Editor: edit the Single Event template. The Event Detail block has a variation for each part of the page, and the Event Actions block holds the buttons. A template named `single-eventwatch_event.html` in your theme takes priority.

Classic themes use `templates/single-event.php`. To change it, copy it to an `eventwatch` folder in your theme, or add a `single-eventwatch_event.php` template to your theme.

The page also outputs schema.org `Event` data, a description, and Open Graph tags. If Yoast SEO, Rank Math, All in One SEO, SEOPress, or The SEO Framework is active, the description and Open Graph tags are left to that plugin. Use the filters `eventwatch_output_meta_tags` and `eventwatch_output_json_ld` to change this.

= Maps =

By default, the event page only links to Google Maps and OpenStreetMap, and opening the page does not connect to any outside service. If you enter a Google Maps API key in Settings > EventWatch, the page embeds a Google map of the venue, and **your visitors' browsers connect to Google when they open the page**. Google's terms and pricing apply to the key, and you are responsible for reviewing them: https://cloud.google.com/maps-platform/terms . The privacy policy is at https://policies.google.com/privacy .

The links under "Share" and "Add to calendar" open Google Calendar, X, LINE, or Facebook only when a visitor chooses them.

= Scheduled events =

WordPress publishes scheduled events with WP-Cron, which only runs when someone visits your site. On a site with few visitors, a scheduled event may be published later than the time you set. To publish on time, run WP-Cron from your server's cron.

= Removing the plugin =

Deleting the plugin removes its settings and the capabilities it added to user roles. Your events stay in the database unless the option `eventwatch_delete_events_on_uninstall` is set.

= Translations =

The plugin includes a Japanese translation. Other languages come from translate.wordpress.org.

== Changelog ==

= 0.1.0 =
* Plugin foundation.
