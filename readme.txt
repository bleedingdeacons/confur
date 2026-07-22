=== Confur ===
Contributors: thebleedingdeacons
Tags: conference, questions, answers, groups, aa
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 2.11.3
Build date: 2026/07/22 02:16:05
Requires PHP: 7.4
License: MIT (Modified)

Automated collation of answers to questions for conference. Groups submit answers online; region representatives review and prepare responses.

== Description ==

Confur provides a complete solution for collecting, organising, and managing questions submitted by groups for conference. The plugin streamlines the process of gathering answers from multiple groups, making it easy for region representatives to review and prepare responses.

**Key features:**

* **Group answer submission** — groups submit answers to conference questions through an online form.
* **Centralised management** — single dashboard for reviewing and organising every submitted answer.
* **ACF-backed data model** — leverages Advanced Custom Fields for flexible answer storage.
* **Meeting integration** — works alongside the 12 Step Meeting List plugin for meeting-aware question contexts.
* **Shortcode toolkit** — a complete set of shortcodes for rendering questions, capturing answers, tracking committee progress, and surfacing AA programme content (Steps, Traditions, Responsibility Pledge).

== Installation ==

1. Install and activate the [12 Step Meeting List](https://wordpress.org/plugins/12-step-meeting-list/) plugin.
2. Install and activate the [Advanced Custom Fields](https://wordpress.org/plugins/advanced-custom-fields/) plugin.
3. Upload the `confur` directory to `/wp-content/plugins/`.
4. Activate Confur through the **Plugins** menu in WordPress.
5. Import the bundled ACF configuration to register the Answer custom post type and its field group.
6. Create a registration page containing a form that creates the Answer post type.

== Frequently Asked Questions ==

= Does Confur work without ACF? =

No. The Answer post type and its fields are defined via ACF, so Advanced Custom Fields must be installed and activated.

= Where do I see submitted answers? =

Under the **Conference for Question** sidebar menu. The Status screen shows submission progress; Results displays the latest answers in a collation-friendly layout.

= What shortcodes are available? =

`[answer_field]`, `[question]`, `[committee]`, `[header]`, `[progress_table]`, `[control]`, `[status]`, `[days_remaining]`, plus AA-programme shortcodes `[step]`, `[tradition]`, `[responsibility_pledge]`, and general utilities `[open_blank]`, `[link_email]`, `[pdf_link]`. See README.md for full parameters.

== Changelog ==

= 2.11.3 =
* Current stable release.
