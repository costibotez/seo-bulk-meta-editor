=== Yoast SEO Bulk Meta Editor ===
Contributors: costibotez
Donate link: https://www.buymeacoffee.com/costinbotez
Tags: seo, yoast, rank math, seopress, bulk edit
Requires at least: 5.0
Tested up to: 6.5
Requires PHP: 7.2
Stable tag: 1.12.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Edit all your Yoast SEO meta titles, descriptions and keywords from one dashboard, with a live Google preview, SEO scoring, bulk find & replace and change history.

== Description ==

Yoast SEO Bulk Meta Editor adds a dedicated admin page for managing Yoast SEO metadata in bulk. It lists the meta title, meta description and focus keyword for every post, page and public custom post type in a sortable table so you can review and update many entries in a single place.

Learn more on the plugin homepage: https://www.nomad-developer.co.uk/plugins/yoast-seo-bulk-meta-editor

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
* Site-wide SEO audit dashboard: score distribution, missing/over/under-length fields by post type, and duplicate meta title/description detection across posts, with one-click jumps into the editor.
* Google Search Console integration: connect a property over OAuth and show clicks, impressions, CTR and average position per URL as optional editor columns.
* AI meta generation: draft meta titles and descriptions from page content with your own Claude or OpenAI key, per row or in bulk, always as a preview you approve before saving.

**Safety & history**

* Change history / audit log: every edit (single, undo, revert or bulk) is recorded to the database with the old value, new value, user and timestamp.
* One-click revert from the History page.
* Draft & scheduled changes with an approval workflow: submit edits for review, and let an administrator approve, reject or schedule them for automatic application.

**Security**

* All AJAX actions are protected with nonces and capability checks.
* Meta writes are restricted to an allow-list of Yoast keys, with per-post edit permission checks.
* All values are escaped on output and sanitized per field on save.

Requires one supported SEO plugin to be installed and active: [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/), [Rank Math](https://wordpress.org/plugins/seo-by-rank-math/) or [SEOPress](https://wordpress.org/plugins/wp-seopress/). All in One SEO stores its data in a custom table rather than post meta and is not supported.

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

= Which SEO plugins are supported? =

Yoast SEO, Rank Math and SEOPress. One of them must be installed and active. The plugin auto-detects which is in use, or you can choose explicitly on the Settings page. All in One SEO is not supported because it stores its data in a custom database table rather than in post meta.

= Who can edit metadata? =

Access is controlled by a dedicated capability. By default only administrators have it, but you can grant it to other roles on the Settings page.

= Can I undo a bulk find & replace? =

Every change made through the plugin — including bulk replacements — is logged. You can revert individual changes from the History page.

= How does the AI generation work, and is my content sent anywhere? =

It is optional and off until you add your own Claude (Anthropic) or OpenAI API key on the AI Assistant page. When you click Generate, the post's title and a content excerpt are sent to the provider you selected, and the suggested title and description are shown as unsaved edits for you to review. Nothing is written or sent automatically.

= Does it work with WPML? =

Yes. When WPML is active, a language column with flags is shown and you can filter posts by language.

== Screenshots ==

1. The bulk editor table with per-row SEO score dots and the live Google preview.
2. Bulk find & replace with a preview of affected posts.
3. The change history / audit log with one-click revert.

== Changelog ==

= 1.12.0 =
* New: AI-assisted meta generation. With your own Claude (Anthropic) or OpenAI API key, a per-row "Generate" button drafts a meta title and description from the post's content, and an "AI: fill problem rows" button does the same in bulk for visible rows that need attention.
* New: Generated values are inserted as unsaved, highlighted edits so you always review them before saving or submitting for review — nothing is written automatically.
* New: An "AI Assistant" settings page to choose the provider, enter the API key and optionally set the model.

= 1.11.0 =
* New: Draft & scheduled changes with an approval workflow. A "Submit for Review" button queues edits as drafts (with an optional future apply time) instead of saving directly.
* New: A "Pending Changes" admin page where administrators approve, reject, apply-now or cancel drafts. Approved drafts with a future time are applied automatically by WP-Cron, validated and logged to the change history like a direct save.
* Dev: Added ybme_install_tables, ybme_editor_actions and ybme_editor_js_vars extension hooks.

= 1.10.0 =
* New: Google Search Console integration. Connect a property over OAuth and add optional Clicks / Impressions / CTR / Position columns (last ~28 days) to the editor, to prioritise rewriting metas on high-impression, low-CTR pages.
* New: A Search Console admin page to enter credentials, connect/disconnect, choose the property and refresh data. Metrics also refresh daily via WP-Cron and are cached.
* Dev: Introduced an includes/ module layer and two extension filters (ybme_available_columns, ybme_render_column_cell) for adding read-only columns.

= 1.9.0 =
* New: Multi-plugin support. The editor, audit and find & replace now work with Rank Math and SEOPress in addition to Yoast SEO, via a provider abstraction over each plugin's meta keys.
* New: The active SEO plugin is auto-detected (Yoast, then Rank Math, then SEOPress), or you can pick one explicitly on the Settings page.
* Change: The plugin now requires any one of the supported SEO plugins to be active (previously Yoast specifically). Admin notices, the activation check and all write guards were updated accordingly.
* Note: All in One SEO stores its data in a custom table rather than post meta, so it is not supported by this release.

= 1.8.0 =
* New: Site-wide SEO Audit dashboard (new Audit submenu) with a good/needs-work/critical score distribution, a per-issue breakdown (missing and over/under-length titles and descriptions, missing keywords), a per-post-type breakdown, and cross-post duplicate meta title/description detection with direct edit links.
* New: Audit cards deep-link into the editor with the SEO filter pre-applied. Results are cached for 10 minutes and refresh automatically whenever a meta value changes, or on demand via the Refresh button.

= 1.7.0 =
* Improvement: "Save Changes" now saves every edit in a single AJAX request with one summary notification, instead of one request (and one toast) per field.
* Improvement: The edit queue is cleared after a successful save, so pressing Save twice no longer re-submits the same edits.
* New: Unsaved-changes guard — the browser warns before navigating away with pending edits, and edited cells are highlighted until saved.
* Fix: Settings registration is now hooked on admin_init at the top level rather than from inside the admin_menu callback.
* Dev: Added Composer config, PHP_CodeSniffer (WordPress Coding Standards) ruleset and a GitHub Actions lint workflow.

= 1.6.1 =
* Security: All settings (post types, columns, roles, rows-per-page, languages, license key) are now sanitized and validated on save against known-good values.
* Security: Rows-per-page is clamped (1–200) so the editor can no longer be made to query thousands of posts in one request.
* Security: Bulk find & replace rejects over-long find/replace input to limit exposure to catastrophic-backtracking (ReDoS) patterns.
* Security: Added directory-listing guards (index.php) to the plugin folders.
* New: Uninstall cleanup removes the plugin's options, custom capability and history table when the plugin is deleted.
* New: Runtime Yoast SEO check — shows an admin notice and blocks writes when Yoast is inactive, instead of silently writing orphaned meta.

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

= 1.12.0 =
Adds AI meta generation with your own Claude or OpenAI key: draft titles and descriptions per row or in bulk, always previewed before saving.

= 1.11.0 =
Adds a draft & scheduled changes approval workflow: submit edits for review and let an admin approve, reject or schedule them.

= 1.10.0 =
Adds Google Search Console integration: connect a property and show clicks, impressions, CTR and position per URL as optional editor columns.

= 1.9.0 =
Now works with Rank Math and SEOPress in addition to Yoast SEO. The active SEO plugin is auto-detected, or selectable on the Settings page.

= 1.8.0 =
Adds a site-wide SEO Audit dashboard: score distribution, issue and post-type breakdowns, and duplicate meta detection, with deep links into the editor.

= 1.7.0 =
Faster, safer saving: all edits save in one request, the queue clears on success, and you're warned before leaving with unsaved changes.

= 1.6.1 =
Security and robustness release: settings validation, rows-per-page clamping, find & replace input limits, uninstall cleanup and a runtime Yoast check. Recommended for all users.

= 1.6.0 =
Adds a live Google preview, SEO scoring, bulk find & replace and a change-history/audit log, plus important security hardening. Recommended for all users.
