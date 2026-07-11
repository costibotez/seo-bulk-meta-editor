=== Yoast SEO Bulk Meta Editor ===
Contributors: costibotez
Donate link: https://www.buymeacoffee.com/costinbotez
Tags: seo, yoast, meta description, bulk edit, serp preview
Requires at least: 5.0
Tested up to: 6.5
Requires PHP: 7.2
Stable tag: 1.6.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Edit all your Yoast SEO meta titles, descriptions and keywords from one dashboard, with a live Google preview, SEO scoring, bulk find & replace and change history.

== Description ==

Yoast SEO Bulk Meta Editor adds a dedicated admin page for managing Yoast SEO metadata in bulk. It lists the meta title, meta description and focus keyword for every post, page and public custom post type in a sortable table so you can review and update many entries in a single place.

On top of fast inline editing, the plugin helps you write snippets that actually get clicked and keeps your changes safe:

**Editing**

* View all Yoast SEO meta titles, descriptions, keywords and other fields in one place.
* Edit fields inline with character counters and save via AJAX.
* Save changes or undo the most recent edit.
* Filter rows by search text, post type and category.
* Choose which post types and columns appear from the Settings page.
* Responsive table with a "Load More" button for large datasets.
* Set how many rows appear per page (defaults to 20).
* Detects WPML and lets you filter posts by language with flag icons.

**SEO & CTR tools**

* Live Google (SERP) preview that updates in real time as you type, in desktop or mobile layout, with truncation matching the search results.
* Per-row SEO health score: a traffic-light dot flags missing titles/descriptions/keywords and length problems, with details on hover.
* "Show only problems" filter to instantly narrow the table to rows that need attention.
* Bulk find & replace across meta titles, descriptions or keywords for every matching post, with a preview of affected rows before you apply. Supports case sensitivity, regular expressions and template variables (%%title%%, %%sitename%%, %%sep%%).

**Safety & history**

* Change history / audit log: every edit (single, undo, revert or bulk) is recorded to the database with the old value, new value, user and timestamp.
* One-click revert from the History page.

**Security**

* All AJAX actions are protected with nonces and capability checks.
* Meta writes are restricted to an allow-list of Yoast keys, with per-post edit permission checks.
* All values are escaped on output and sanitized per field on save.

Requires the [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/) plugin to be installed and active.

== Installation ==

1. Upload the `seo-bulk-meta-editor` directory to your site's `wp-content/plugins/` folder.
2. Activate **Yoast SEO Bulk Meta Editor** from the Plugins screen.
3. Ensure the Yoast SEO plugin is also active.
4. Open **Yoast Bulk Meta Editor** from the main admin menu.

== Usage ==

1. Open **Yoast Bulk Meta Editor** from the main menu.
2. Click a table cell to edit the meta title, description or keyword. The Google preview and SEO score update live as you type.
3. Click **Save Changes** to store your updates.
4. Use **Bulk Find & Replace** to update text across many posts at once (preview first, then apply).
5. Use **Yoast Bulk Meta Editor → History** to review or revert past changes.
6. Use **Yoast Bulk Meta Editor → Settings** to choose which post types and columns appear.

== Frequently Asked Questions ==

= Does this require Yoast SEO? =

Yes. The plugin reads and writes Yoast SEO meta keys, so Yoast SEO must be installed and active.

= Who can edit metadata? =

Access is controlled by a dedicated capability. By default only administrators have it, but you can grant it to other roles on the Settings page.

= Can I undo a bulk find & replace? =

Every change made through the plugin — including bulk replacements — is logged. You can revert individual changes from the History page.

= Does it work with WPML? =

Yes. When WPML is active, a language column with flags is shown and you can filter posts by language.

== Screenshots ==

1. The bulk editor table with per-row SEO score dots and the live Google preview.
2. Bulk find & replace with a preview of affected posts.
3. The change history / audit log with one-click revert.

== Changelog ==

= 1.6.0 =
* New: Live Google (SERP) preview with desktop/mobile layouts that updates while you type.
* New: Per-row SEO health score with a "show only problems" filter.
* New: Bulk find & replace across meta fields, with preview, regex and template variables.
* New: Change history / audit log stored in the database, with one-click revert from a new History page.
* Security: Added nonce verification, capability and per-post edit checks to all AJAX handlers.
* Security: Restricted meta writes to an allow-list of Yoast keys and escaped all table output.
* Security: Removed a dead, unsafe legacy script and hardened value sanitization per field.
* Improvement: Refactored row rendering into a single shared helper for consistent output.

= 1.5.0 =
* Removed CSV import/export tools and updated the documentation.
* Fixed a WPML issue where pages were not shown.
* Show pages before posts in the table.

= 1.4.0 =
* Added WPML language support with a flag column.
* Fixed the focus keyword not appearing by reading the correct Yoast meta key.
* Added configurable rows per page, selectable columns and allowed roles.

== Upgrade Notice ==

= 1.6.0 =
Adds a live Google preview, SEO scoring, bulk find & replace and a change-history/audit log, plus important security hardening. Recommended for all users.
