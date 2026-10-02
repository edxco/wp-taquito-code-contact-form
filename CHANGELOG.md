# Changelog

All notable changes to this plugin are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.7.0] - 2026-10-02

### Added
- **Submissions**: bulk delete. Tick rows (or the header checkbox to select
  the whole page, shift-click for a range), choose **Bulk actions → Delete**
  and **Apply**; a confirmation shows how many will be deleted.

### Changed
- **Submissions**: the ID column (and Date) shrinks to fit its content
  instead of taking an equal share of the width.
- **Submissions**: the Message column shows the full message on a single,
  truncated line, and expands to the whole message while the row is hovered
  (or focused with the keyboard). It used to show only the first 12 words.

## [1.6.0] - 2026-10-02

### Added
- **Settings → Email Sender**: a From name and From email for the lead
  notifications. Left empty, the SMTP plugin (or WordPress's default) decides,
  as before. Without an SMTP plugin, the envelope sender (Return-Path) is set
  to the From address so SPF is checked against the site's domain instead of
  the server's.
- **Settings → Send a Test Email**: sends a sample notification through the
  same code as a real lead and shows the error if sending fails. Useful with
  mail-tester.com.
- An inbox checklist (SMTP plugin, SPF/DKIM/DMARC) on the settings page.

### Fixed
- A visitor name containing a comma, quote or `<>` no longer breaks the
  Reply-To header.
- HTML entities in the site name (e.g. `&amp;`) no longer show in the subject.
- When a lead can't be saved to the database, its notification is now queued
  like any other instead of being sent during the fast-submit request, where
  the SMTP plugin isn't loaded.

## [1.5.0] - 2026-10-02

### Changed
- The form now submits to a REST route (`scf/v1/submit`, sent as
  `?rest_route=` so it works without pretty permalinks or a WPML language
  prefix) instead of `admin-ajax.php`, which skips the admin-side code other
  plugins hook into. If the route answers 4xx (e.g. a security plugin blocking
  anonymous REST requests), the browser retries through `admin-ajax.php`, which
  still works as before.

### Added
- A must-use plugin, `scf-fast-submit.php`, that makes WordPress load only this
  plugin while a submission is handled, instead of every active plugin. It's
  copied into `wp-content/mu-plugins/` on activation and after updates, and
  removed on deactivation. If it can't be copied, an admin notice explains how
  to copy it by hand. Sending the emails (`scf_notify` and the WP-Cron
  fallback) still loads every plugin, so SMTP plugins keep working. Turn it
  off with `define( 'SCF_FAST_SUBMIT', false );` in `wp-config.php`.

### Fixed
- Submitted fields are unslashed before they're saved, so an apostrophe in a
  name or message (e.g. "O'Brien") is no longer stored as `O\'Brien`.

## [1.4.2] - 2026-10-02

### Fixed
- The thank-you image max width sets to 300px and also is moved on top of title

## [1.4.1] - 2026-10-02

### Fixed
- The thank-you image no longer breaks when the setting holds an attachment
  ID instead of a URL (it was output as `src="http://2497"`). IDs are turned
  into the attachment's URL, both on save and for a value already saved.

## [1.4.0] - 2026-10-02

### Changed
- The thank-you message is now rendered by the plugin itself instead of
  being taken from a WordPress page. It's output (hidden) with the form and
  revealed in its place after a successful submission, so it no longer
  depends on a page builder, the page's styles or its publish status. The
  address bar still switches to the thank-you URL (default `/thank-you/`)
  with no page load.
- The submit handler's reply no longer carries any page content, styles or
  scripts; it only returns the notification key.

### Added
- **Settings → Thank-you Message**: URL, title, message (basic HTML allowed)
  and an image picked from the media library. The title and message are
  registered with WPML String Translation; the URL carries the current
  language prefix under WPML.
- The thank-you image opts out of lazy-loading (`skip-lazy`, `no-lazyload`,
  `data-no-lazy`), since it's hidden at page load.
- Opening or reloading the thank-you URL directly redirects to the home page.

### Removed
- The **Thank-you Page** setting and everything that rendered it: Beaver
  Builder layout rendering, the stylesheet and script forwarding, and the
  guard on that page. The page is no longer used and can be deleted.

## [1.3.0] - 2026-10-02

### Changed
- The configured thank-you page's content was shown in place of the form
  instead of being redirected to, with the address bar switched to the
  page's URL via `history.pushState()`; going Back reloads the form. Beaver
  Builder pages were rendered through Beaver Builder, along with the
  stylesheets and scripts they enqueue, and replaced the form page's whole
  layout. With no usable page, a short default message was shown.
- The thank-you page could be Private (and was listed as such in Settings);
  if its WPML translation wasn't published, the original page was used.
- Any successful reply showed the thank-you content, so a saved lead never
  showed an error.

### Removed
- The single-use token links to the thank-you page.

## [1.2.4] - 2026-10-01

### Changed
- Submissions no longer wait on the notification emails on any host. The
  1.2.2 approach (flushing the response with `Connection: close` and sending
  the emails in the same request) still left visitors waiting ~10 s on
  servers where a proxy, CDN or compression layer holds the response until
  PHP exits. The AJAX reply now returns as soon as the lead is saved; the
  browser then fires a `sendBeacon()` to a new `scf_notify` action that sends
  the emails in its own request, using a single-use key. If the beacon never
  arrives, a WP-Cron event sends them about 2 minutes later.

## [1.2.2] - 2026-10-01

### Changed
- Faster submissions on hosts without PHP-FPM / LiteSpeed (e.g. Apache
  mod_php): notification emails are no longer handed to WP-Cron through
  `spawn_cron()`, whose loopback request added at least 1 s to every
  submission. The response is now flushed with `Connection: close` and the
  emails are sent in the same request on every host.

### Fixed
- The honeypot and time-token spam checks now actually run: the form script
  wasn't sending either field, so the server always skipped them.

## [1.2.1] - 2026-10-01

### Changed
- Faster submissions: the lead is saved to the database and the visitor is
  redirected to the thank-you page immediately; notification emails are now
  sent after the response instead of before it. On PHP-FPM / LiteSpeed they go
  out in the same request after the connection closes; on other hosts they are
  sent via a one-off WP-Cron event.
- All form fields are now required: **Teléfono** and **Mensaje** are no longer
  optional (enforced in the browser and on the server).

### Fixed
- The submit button's arrow no longer appears as a broken image: the remote
  `arrow-right.svg` was replaced with an inline "→" character.

## [1.2.0] - 2026-09-08

### Added
- Successful submissions now redirect to a real WordPress page (configurable
  under **Contact Form → Settings → Thank-you Page**) instead of swapping in
  an inline AJAX panel.
- The thank-you page is protected: it can only be opened via a short-lived,
  single-use token minted at submission time, so it isn't reachable by
  bookmarking or guessing its URL.
- WPML support: visitors are redirected to the thank-you page translation
  matching their current language automatically.
- A `scf_form_submit` `dataLayer` event is pushed on successful submission,
  for hooking up Google Tag Manager conversion tracking without depending on
  the (token-suffixed) thank-you page URL.

### Removed
- The old inline thank-you title/message/image settings and the AJAX-rendered
  thank-you panel markup, script, and styles, superseded by the page redirect
  above.

## [1.1.3] and earlier
- Undocumented prior history.
