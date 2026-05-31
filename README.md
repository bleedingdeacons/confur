# Confur — Conference Question Collation

**Version:** 2.11.3

Automated collation of answers to conference questions submitted online by groups. Confur provides one place for groups to lodge their answers and one place for region representatives to read them back, organised and ready to discuss.

## What it does

Conference cycles are messy. Confur cleans the mess up by separating three things that used to be tangled together:

1. **Groups submit answers** through a public-facing form that creates an `Answer` custom post.
2. **Region reps review** in a centralised admin screen — Status (who's submitted, how complete), Results (the full text, by committee, ready to collate).
3. **AA programme content** (Steps, Traditions, Responsibility Pledge) is reusable via shortcodes wherever it's needed.

Every question/answer field is backed by Advanced Custom Fields, so the question set itself is editable from the WordPress admin without code changes.

## Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher
- [12 Step Meeting List](https://wordpress.org/plugins/12-step-meeting-list/) — meeting metadata
- [Advanced Custom Fields](https://wordpress.org/plugins/advanced-custom-fields/) — answer field group

Confur is a MIT-licensed plugin published by The Bleeding Deacons.

## Installation

1. Install and activate **12 Step Meeting List** and **Advanced Custom Fields**.
2. Upload the `confur` directory to `/wp-content/plugins/`.
3. Activate Confur through the **Plugins** menu in WordPress.
4. Import the bundled ACF configuration (`setup/`) — this registers the `Answer` custom post type and its field group.
5. Create a public registration page containing a form that creates the `Answer` post type.

## Administration

Under the **Conference for Question** sidebar menu:

| Screen | What it shows |
|---|---|
| **Status** | Submission progress per group/committee — who's filed, who hasn't. |
| **Results** | The latest answers grouped for collation. |

## Shortcodes

### Answer & question management

```text
[answer_field committee="1" question="1"]    Generate an answer input field
[question number="1" committee="1"]          Render a question with formatting
[committee number="1" name="Finance"]        Open a committee section
[header]                                     Conference meeting header
[progress_table]                             Per-committee progress table
[control position="top"]                     Save controls (Draft / Complete)
[status position="top"]                      Unsaved-changes notice
[days_remaining end_date="2024-12-31" extend_by="0"]   Countdown to deadline
```

### AA programme content

```text
[step number="1"]                            Display an AA Step + PDF link
[tradition number="1"]                       Display an AA Tradition + PDF link
[responsibility_pledge number="1"]           Display the Responsibility Pledge
```

### General utilities

```text
[open_blank href="url" class="…"]Link[/open_blank]      Link that opens in a new tab
[link_email address="x@y" subject="…"]Text[/link_email] Mailto link
[pdf_link url="…" name="file.pdf"]Link[/pdf_link]       Inline PDF download link
```

## Building a release

The plugin ships with a cross-platform `build.php` script that packages a distributable zip:

```bash
php build.php                       # Production zip (default)
php build.php build:production      # Production zip
php build.php build:dev             # Dev zip (keeps tests)
php build.php clean                 # Remove the build/ directory
php build.php --version=2.12.0      # Override version on the way out
php build.php --help                # Show all options
```

Output lands in `build/confur-production-<version>.zip` (or `-dev-`). On every build the script reads the version from the `Version:` header in `Confur.php`, syncs the `**Version:**` line in this README and the `Stable tag:` line in `readme.txt`, and stamps the build date into `readme.txt` immediately after the `Stable tag:` line.

## Testing

PHPUnit is wired up via `phpunit.xml`. Run the suite with:

```bash
# Unix-like
./run-tests.sh

# Windows
run-tests.cmd
```

Coverage reports drop into `coverage/`.

## Licence

MIT (Modified). See `LICENSE` for the full text.
