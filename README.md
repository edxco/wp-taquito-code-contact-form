# EBC Form

A lightweight WordPress contact form. It saves every submission to the
database, emails the lead to one or more recipients, and shows a customizable
thank-you message in place of the form. No page reload is needed.

## Features

- **Five-field form**: name, email, phone, company and message, all required.
- **Stored submissions**: every submission is saved and listed under
  **Contact Form → Submissions**. Every column can be sorted, each row
  expands to show the full submission, and rows can be deleted one at a time
  or in bulk.
- **Email notifications**: each lead goes to one or more recipients. You can
  set a custom From name and From email, and send a test email from the
  settings page.
- **Fast submits**: the visitor gets a reply as soon as the lead is saved.
  Notification emails go out in a separate request, with a WP-Cron fallback if
  that request never arrives.
- **Fast-submit mu-plugin**: while a submission is handled, WordPress loads
  only this plugin.
- **Spam protection**: a honeypot field, a time token (rejects submits faster
  than 3 s or older than 2 h), a per-IP rate limit and spam-content checks.
- **Thank-you message**: the title, message (basic HTML allowed) and image are
  revealed in place of the form, and the address bar switches to a thank-you
  URL for analytics.
- **WPML support**: the thank-you strings are registered for String
  Translation, and the thank-you URL keeps the language prefix.
- **Google Tag Manager**: a `scf_form_submit` event is pushed to `dataLayer`
  on every successful submission.

## Requirements

- WordPress with jQuery (it's bundled with WordPress)
- PHP 8.0 or later
- An SMTP plugin is recommended so notification emails reach the inbox

## Installation

1. Copy this folder into `wp-content/plugins/`.
2. Activate **EBC Form** under **Plugins**.

Activating the plugin creates the `{prefix}scf_submissions` table, sets the
default options and copies `mu-plugin/scf-fast-submit.php` into
`wp-content/mu-plugins/`. If that copy fails, an admin notice explains how to
copy the file by hand.

## Usage

Add the shortcode to any page or post:

```
[simple_contact_form]
```

To change the submit button's label on one form only:

```
[simple_contact_form btn_label="Send"]
```

## Settings

All settings are under **Contact Form → Settings**:

| Setting | Description | Default |
| --- | --- | --- |
| Button label | Submit button text | `Enviar` |
| Recipient emails | Who receives each lead | Site admin email |
| From name / From email | Sender of the notifications. Leave empty to let the SMTP plugin decide. | Empty |
| Rate limit | Max submissions per IP per hour | `5` |
| Thank-you URL | Path shown in the address bar after submitting | `thank-you` |
| Thank-you title / message / image | Content shown in place of the form | `¡Gracias!` / default message / none |

The **Send a Test Email** section sends a sample notification through the same
code path as a real lead. If sending fails, it shows the error.

## How a submission works

1. The browser posts the form to the REST route
   `/?rest_route=/scf/v1/submit`. If that returns a 4xx error (for example,
   because a security plugin blocks anonymous REST requests), the browser
   retries through `admin-ajax.php?action=scf_submit`.
2. The server runs the spam checks, saves the lead and returns a single-use
   notify key.
3. The browser shows the thank-you message, pushes the `dataLayer` event and
   sends the key to `scf_notify` with `sendBeacon()`. That request loads every
   plugin, so SMTP plugins handle the emails.
4. If the beacon never arrives, a WP-Cron event sends the emails about two
   minutes later.

Submit responses include a `Server-Timing` header (`wp-boot` and `scf`), which
you can see in DevTools → Network → Timing.

### Turning off fast submit

The mu-plugin is removed when the plugin is deactivated. To turn it off while
the plugin stays active, add this line to `wp-config.php`:

```php
define( 'SCF_FAST_SUBMIT', false );
```

## Project structure

```
simple-contact-form.php    Plugin bootstrap, activation hooks, asset loading, mu-plugin installer
includes/database.php      Submissions table and queries
includes/settings.php      Admin menu, Submissions and Settings pages, test email
includes/form-handler.php  Submit handler (REST + AJAX), spam checks, notifications, thank-you URL
includes/shortcode.php     [simple_contact_form] markup
assets/css/form.css        Form styles
assets/js/form.js          Async submit, fallback, thank-you reveal, notify beacon
mu-plugin/                 Fast-submit must-use plugin (copied into wp-content/mu-plugins/)
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).
