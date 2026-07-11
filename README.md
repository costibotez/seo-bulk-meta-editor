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

### Safety & history
- **Change history / audit log** — every edit (single, undo, revert or bulk) is recorded to the database with the old value, new value, user and timestamp.
- **One‑click revert** — roll any logged change back to its previous value from the **History** page.

### Security
- All AJAX actions are protected with nonces and capability checks.
- Meta writes are restricted to an allow‑list of Yoast keys and per‑post `edit_post` checks.
- All values are escaped on output and sanitized per field on save.

### Requirements
- Requires the [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/) plugin to be active.

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

## Contributing

Pull requests and issues are welcome. Feel free to submit improvements or report problems on GitHub.

## Author

Costin Botez - [Buy me a coffee](https://www.buymeacoffee.com/costinbotez)

## License

Released under the GPLv2 or later.
