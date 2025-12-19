# Confur

A WordPress plugin for collating and managing conference questions submitted by groups online.

## Description

Confur provides a complete solution for collecting, organizing, and managing questions submitted by groups for conference. The plugin streamlines the process of gathering questions from multiple groups, making it easy for region representative's to review and prepare responses.

## Features

- **Group Question Submission** - Allow groups to submit questions online
- **Question Management** - Centralized dashboard for reviewing and organizing submitted answers
- **Custom Fields Integration** - Leverages Advanced Custom Fields for flexible question data structure
- **Meeting Integration** - Works seamlessly with the 12 Meeting List Plugin for meeting management

## Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher
- [12 Meeting List Plugin](https://wordpress.org/plugins/12-step-meeting-list/) (required)
- [Advanced Custom Fields Plugin](https://wordpress.org/plugins/advanced-custom-fields/) (required)

## Installation

1. **Install Dependencies First**
   - Install and activate the [12 Meeting List Plugin](https://wordpress.org/plugins/12-step-meeting-list/)
   - Install and activate the [Advanced Custom Fields Plugin](https://wordpress.org/plugins/advanced-custom-fields/)

2. **Install Confur**
   - Upload the `confur` folder to the `/wp-content/plugins/` directory
   - Alternatively, install directly from the WordPress plugin repository
   - Activate the plugin through the 'Plugins' menu in WordPress

## Usage

### Setup of Plugin

1. Import the ACF Configuration for Custom Type and Field Group
2. Create a Registration page containing a form that creates the Answer Custom Post Type

### Administration

**Conference for Question (side menu)**

1. Status shows all the relevant information regarding answer submission
2. Results displays the latest answers submitted in a collation friendly structure

## Shortcodes

Confur provides several shortcodes to display conference content and manage question submissions:

### Answer & Question Management

```
[answer_field committee="1" question="1"] - Generate an answer input field
[question number="1" committee="1"] - Display a question with formatting
[committee number="1" name="Finance"] - Create a committee section
[header] - Display conference meeting header
[progress_table] - Show progress tracking table for all committees
[control position="top"] - Display save controls (Draft/Complete buttons)
[status position="top"] - Show unsaved changes notification
[days_remaining end_date="2024-12-31" extend_by="0"] - Display deadline countdown
```

### AA Program Content

```
[step number="1"] - Display an AA Step with text and PDF link
[tradition number="1"] - Display an AA Tradition with text and PDF link
[responsibility_pledge number="1"] - Display the AA Responsibility Pledge
```

### General Utilities

```
[open_blank href="url" class="css-class"]Link Text[/open_blank] - Open link in new tab
[link_email address="email@example.com" subject="Subject"]Text[/link_email] - Create email link
[pdf_link url="url" name="filename.pdf"]Link Text[/pdf_link] - Generate PDF download link
```

### Form Configuration

```
[custom_form action="save_answers"] - Configure custom form submission
```

## Support

For support, bug reports, or feature requests, please contact your ELCO (Electronic Literature and Communications Officer).

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Changelog

### Version 2.1
- Initial release
- Basic question submission functionality
- Integration with 12 Meeting List Plugin
- Advanced Custom Fields support

## Credits

Developed by The Bleeding Deacons

---

**Note**: This plugin requires both the 12 Meeting List Plugin and Advanced Custom Fields Plugin to function properly. Please ensure both dependencies are installed and activated before using Confur.