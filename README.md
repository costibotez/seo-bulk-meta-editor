# Yoast SEO Bulk Meta Editor

Yoast SEO Bulk Meta Editor is a WordPress plugin that adds a dedicated admin page for managing Yoast SEO metadata in bulk. It displays the title, meta description and focus keyword for each post, page and any public custom post type in a sortable table so you can update multiple entries quickly — and adds a live Google preview, per‑row SEO scoring, bulk find & replace and a full change history so you can work faster and safely.

## Features

### General Functionality
- Dedicated admin page listing all posts and pages in a sortable table so Yoast SEO metadata can be edited in bulk.

### Editing
- View all Yoast SEO meta titles, descriptions, keywords and other fields in one place.
- Edit fields inline with character counters and save via AJAX.
- Save changes or undo the most recent edit.
- Filter rows by search text, post type and category.
- Choose which post types and columns appear from the plugin's **Settings** page.
- Responsive table with a "Load More" button for large datasets.
- Set how many rows appear per page (defaults to 20).
- Detects WPML and lets you filter posts by language with flag icons in the table.

### SEO & CTR tools
- **Live Google (SERP) preview** — see a desktop or mobile snippet update in real time as you type the meta title or description, with truncation matching the search results.
- **Per‑row SEO health score** — a traffic‑light dot flags missing titles/descriptions/keywords and length problems, with details on hover.
- **"Show only problems" filter** — instantly narrow the table to rows that need attention (or just the critical ones).
- **Bulk find & replace** — search and replace across meta titles, descriptions or keywords for every matching post, with a preview of affected rows before you apply. Supports case sensitivity, regular expressions and template variables (`%%title%%`, `%%sitename%%`, `%%sep%%`).
- **Site-wide SEO audit** — a dashboard summarising your whole site: score distribution, missing/over/under-length fields by post type, and duplicate meta titles/descriptions across posts, with one-click jumps into the editor or straight to a post.
- **Google Search Console metrics** — connect a property and show clicks, impressions, CTR and average position per URL as optional editor columns, to prioritise the pages worth rewriting.
- **AI meta generation** — draft meta titles and descriptions from page content with your own Claude or OpenAI key, per row or in bulk, always as a preview you approve before saving.

### Safety & history
- **Change history / audit log** — every edit (single, undo, revert or bulk) is recorded to the database with the old value, new value, user and timestamp.
- **One‑click revert** — roll any logged change back to its previous value from the **History** page.
- **Draft & scheduled changes** — submit edits for review instead of saving directly; an administrator approves, rejects or schedules them, and scheduled changes apply automatically at the chosen time.

### Security
- All AJAX actions are protected with nonces and capability checks.
- Meta writes are restricted to an allow‑list of Yoast keys and per‑post `edit_post` checks.
- All values are escaped on output and sanitized per field on save.

### Requirements
- Requires one supported SEO plugin to be active: [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/), [Rank Math](https://wordpress.org/plugins/seo-by-rank-math/) or [SEOPress](https://wordpress.org/plugins/wp-seopress/). (All in One SEO is not supported — it stores data in a custom table rather than post meta.)

## Installation

1. Upload the `seo-bulk-meta-editor` directory to your WordPress site's `wp-content/plugins/` folder
2. Activate **Yoast SEO Bulk Meta Editor** from the Plugins screen
3. Ensure the Yoast SEO plugin is also active

## Usage

1. In the WordPress admin, open **Yoast Bulk Meta Editor** from the main menu
2. Edit the meta title, description or keyword by clicking a table cell — the Google preview and SEO score update live as you type
3. After editing, click **Save Changes** to store the updates
4. Use **Bulk Find & Replace** to update text across many posts at once (preview first, then apply)
5. Use **Yoast Bulk Meta Editor → History** to review or revert past changes
6. Use **Yoast Bulk Meta Editor → Settings** to choose which post types and columns are shown in the table

## Changelog

### 1.12.0
- **New:** AI-assisted meta generation. With your own Claude (Anthropic) or OpenAI API key, a per-row **Generate** button drafts a meta title and description from the post's content; an **AI: fill problem rows** button does the same in bulk for visible rows that need attention.
- **New:** Generated values are inserted as unsaved, highlighted edits so you always review them before saving (or submitting for review) — nothing is written automatically.
- **New:** An **AI Assistant** settings page to choose the provider, enter the API key and optionally set the model.

### 1.11.0
- **New:** Draft & scheduled changes with an approval workflow. A **Submit for Review** button queues your edits as drafts (with an optional future apply time) instead of saving directly.
- **New:** A **Pending Changes** admin page where administrators approve, reject, apply-now or cancel drafts; approved drafts with a future time are applied automatically by WP-Cron. Every applied draft is validated and logged to the change history like a direct save.
- **Dev:** Added `ybme_install_tables`, `ybme_editor_actions` and `ybme_editor_js_vars` extension hooks.

### 1.10.0
- **New:** Google Search Console integration. Connect a property over OAuth and add optional **Clicks / Impressions / CTR / Position** columns (last ~28 days) to the editor, so you can prioritise rewriting metas on high-impression, low-CTR pages.
- **New:** A **Search Console** admin page to enter credentials, connect/disconnect, choose the property and refresh data; metrics also refresh daily via WP-Cron and are cached.
- **Dev:** Introduced an `includes/` module layer and two extension filters (`ybme_available_columns`, `ybme_render_column_cell`) so read-only columns can be added without touching the core editor.

### 1.9.0
- **New:** Multi-plugin support. The editor, audit and find & replace now work with **Rank Math** and **SEOPress** in addition to **Yoast SEO**, via a provider abstraction over each plugin's meta keys.
- **New:** The active SEO plugin is auto-detected (Yoast → Rank Math → SEOPress priority), or you can pick one explicitly on the **Settings** page.
- **Change:** The plugin now requires *any* one of the supported SEO plugins to be active (previously Yoast specifically). Admin notices, the activation check and all write guards were updated accordingly.
- **Note:** All in One SEO stores its data in a custom table rather than post meta, so it is not supported by this release.

### 1.8.0
- **New:** Site-wide **SEO Audit** dashboard (new **Audit** submenu). Shows a good/needs-work/critical score distribution, a per-issue breakdown (missing and over/under-length titles and descriptions, missing keywords), a per-post-type breakdown, and cross-post **duplicate meta title/description** detection with direct edit links.
- **New:** Audit cards deep-link into the editor with the SEO filter pre-applied; results are cached for 10 minutes and refresh automatically whenever a meta value changes (or on demand via the **Refresh** button).

### 1.7.0
- **Improvement:** "Save Changes" now saves every edit in a single AJAX request with one summary notification, instead of one request (and one toast) per field.
- **Improvement:** The edit queue is cleared after a successful save, so pressing Save twice no longer re-submits the same edits.
- **New:** Unsaved-changes guard — the browser warns before you navigate away with pending edits, and edited cells are highlighted until saved.
- **Fix:** Settings registration is now hooked on `admin_init` at the top level rather than from inside the `admin_menu` callback.
- **Dev:** Added Composer config, PHP_CodeSniffer (WordPress Coding Standards) ruleset and a GitHub Actions lint workflow.

### 1.6.1
- **Security:** All settings (post types, columns, roles, rows-per-page, languages, license key) are now sanitized and validated on save against known-good values.
- **Security:** Rows-per-page is clamped (1–200) so the editor can no longer be made to query thousands of posts in a single request.
- **Security:** Bulk find & replace rejects over-long find/replace input to limit exposure to catastrophic-backtracking (ReDoS) patterns.
- **Security:** Added directory-listing guards (`index.php`) to the plugin folders.
- **New:** Uninstall cleanup (`uninstall.php`) removes the plugin's options, custom capability and history table when the plugin is deleted.
- **New:** Runtime Yoast SEO check — shows an admin notice and blocks writes when Yoast is inactive, instead of silently writing orphaned meta.

### 1.6.0
- **New:** Live Google (SERP) preview with desktop/mobile layouts that updates while you type.
- **New:** Per-row SEO health score with a "show only problems" filter.
- **New:** Bulk find & replace across meta fields, with preview, regex and template variables (`%%title%%`, `%%sitename%%`, `%%sep%%`).
- **New:** Change history / audit log stored in the database, with one-click revert from a new **History** page.
- **Security:** Added nonce verification, capability and per-post edit checks to all AJAX handlers.
- **Security:** Restricted meta writes to an allow-list of Yoast keys and escaped all table output.
- **Security:** Removed a dead, unsafe legacy script and hardened value sanitization per field.
- **Improvement:** Refactored row rendering into a single shared helper for consistent output.

### 1.5.0
- Removed CSV import/export tools and updated the documentation.
- Fixed a WPML issue where pages were not shown; show pages before posts.

### 1.4.0
- Added WPML language support with a flag column.
- Fixed the focus keyword not appearing by reading the correct Yoast meta key.
- Added configurable rows per page, selectable columns and allowed roles.

## Contributing

Pull requests and issues are welcome. Feel free to submit improvements or report problems on GitHub.

## Author

Costin Botez - [Buy me a coffee](https://www.buymeacoffee.com/costinbotez)

## License

Released under the GPLv2 or later.
