<?php
defined( 'ABSPATH' ) || exit;

// Two entry points share one handler. The REST route is the one the form uses:
// it skips admin_init and the rest of the admin-side code that plugins hook into
// admin-ajax.php. admin-ajax.php stays as the fallback for when a security
// plugin blocks anonymous REST requests, and for pages cached with older JS.
add_action( 'wp_ajax_scf_submit',        'scf_handle_submit' );
add_action( 'wp_ajax_nopriv_scf_submit', 'scf_handle_submit' );

add_action( 'rest_api_init', 'scf_register_rest_route' );
function scf_register_rest_route() {
    register_rest_route( 'scf/v1', '/submit', [
        'methods'             => 'POST',
        'callback'            => 'scf_handle_rest_submit',
        'permission_callback' => '__return_true', // Public form; the nonce is checked in the handler.
    ] );
}

function scf_handle_submit() {
    scf_server_timing_start();
    $result = scf_process_submission( wp_unslash( $_POST ) );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    }
    scf_server_timing_send();
    wp_send_json_success( $result ?: null );
}

// Replies in the same shape as admin-ajax (HTTP 200, success true/false), so the
// browser only falls back to admin-ajax when the route itself is unreachable.
function scf_handle_rest_submit( WP_REST_Request $request ): WP_REST_Response {
    scf_server_timing_start();
    $result = scf_process_submission( $request->get_body_params() );
    if ( is_wp_error( $result ) ) {
        return new WP_REST_Response( [ 'success' => false, 'data' => [ 'message' => $result->get_error_message() ] ] );
    }
    scf_server_timing_send();
    return new WP_REST_Response( $result ? [ 'success' => true, 'data' => $result ] : [ 'success' => true ] );
}

// Takes unslashed request fields. Returns the data for the browser (possibly
// empty) or a WP_Error whose message is shown to the visitor.
function scf_process_submission( array $post ) {
    // Nonce check
    if ( ! wp_verify_nonce( (string) ( $post['nonce'] ?? '' ), 'scf_submit_nonce' ) ) {
        return new WP_Error( 'scf_nonce', 'Security verification failed. Please refresh the page.' );
    }

    // ── Spam checks ────────────────────────────────────────────────────────────

    // 1. Honeypot — bots fill the hidden field; humans leave it empty
    $hp_name = 'scf_' . md5( 'hp' . NONCE_SALT );
    if ( ! empty( $post[ $hp_name ] ) ) {
        // Silently succeed so bots think they got through
        return [];
    }

    // 2. Time token — reject if form was submitted in < 3 s (bot) or > 2 h (stale/replay)
    $raw_token = sanitize_text_field( $post['scf_time_token'] ?? '' );
    if ( $raw_token ) {
        $decoded = base64_decode( $raw_token, true );
        if ( $decoded ) {
            [ $ts, $hash ] = array_pad( explode( '|', $decoded, 2 ), 2, '' );
            $ts = (int) $ts;
            $age = time() - $ts;
            if ( $age < 3 || $age > 7200 || ! hash_equals( wp_hash( $ts ), $hash ) ) {
                return new WP_Error( 'scf_token', 'Submission rejected. Please reload the page and try again.' );
            }
        }
    }

    // 3. Rate limit — max 5 submissions per IP per hour via transient
    $ip       = scf_get_client_ip();
    $tr_key   = 'scf_rl_' . md5( $ip );
    $attempts = (int) get_transient( $tr_key );
    $limit    = (int) get_option( 'scf_rate_limit', 5 );
    if ( $attempts >= $limit ) {
        return new WP_Error( 'scf_rate_limit', 'Too many submissions. Please wait a while before trying again.' );
    }
    set_transient( $tr_key, $attempts + 1, HOUR_IN_SECONDS );

    // 4. Content checks — flag obvious spam patterns
    $message_raw = (string) ( $post['scf_message'] ?? '' );
    if ( scf_is_spam_content( $message_raw ) ) {
        return new WP_Error( 'scf_spam', 'Your message was flagged as spam. Please revise it and try again.' );
    }

    // ── End spam checks ────────────────────────────────────────────────────────

    // Collect & validate
    $name    = sanitize_text_field( $post['scf_name']    ?? '' );
    $email   = sanitize_email( $post['scf_email']        ?? '' );
    $phone   = sanitize_text_field( $post['scf_phone']   ?? '' );
    $company = sanitize_text_field( $post['scf_company'] ?? '' );
    $message = sanitize_textarea_field( $post['scf_message'] ?? '' );

    $errors = [];
    if ( empty( $name ) )                         $errors[] = 'El nombre es requerido.';
    if ( empty( $email ) || ! is_email( $email ) ) $errors[] = 'Se requiere un correo electrónico válido.';
    if ( empty( $phone ) )                         $errors[] = 'El teléfono es requerido.';
    if ( empty( $company ) )                       $errors[] = 'El nombre de empresa es requerido.';
    if ( empty( $message ) )                       $errors[] = 'El mensaje es requerido.';

    if ( $errors ) {
        return new WP_Error( 'scf_invalid', implode( ' ', $errors ) );
    }

    // Store in DB
    $lead = compact( 'name', 'email', 'phone', 'company', 'message' );
    $id   = scf_insert_submission( $lead );

    // Reply right away. The browser then fires a sendBeacon() to scf_notify
    // with this one-time key, and that separate request sends the emails, so
    // the visitor never waits on SMTP, whatever sits between PHP and them
    // (proxies, CDNs, mod_deflate) and whether or not the host can close the
    // connection early.
    // If the insert failed (rare), the lead itself is queued instead of its ID:
    // sending inline would bypass the SMTP plugin, which the fast-submit
    // mu-plugin doesn't load for this request.
    return [
        'notify_key' => scf_queue_notification( $id ?: $lead ),
    ];
}

// ── Server-Timing diagnostics ─────────────────────────────────────────────────
// Shows up in DevTools → Network → admin-ajax.php → Timing, split into
// "wp-boot" (WordPress + every active plugin loading before our handler runs)
// and "scf" (this plugin's own work: spam checks, DB insert, tokens).
function scf_server_timing_start() {
    $GLOBALS['scf_handler_start'] = microtime( true );
}

function scf_server_timing_send() {
    if ( headers_sent() || empty( $GLOBALS['scf_handler_start'] ) ) return;

    $req_start = (float) ( $_SERVER['REQUEST_TIME_FLOAT'] ?? $GLOBALS['timestart'] ?? $GLOBALS['scf_handler_start'] );
    $boot_ms   = ( $GLOBALS['scf_handler_start'] - $req_start ) * 1000;
    $scf_ms    = ( microtime( true ) - $GLOBALS['scf_handler_start'] ) * 1000;

    header( sprintf(
        'Server-Timing: wp-boot;dur=%.1f;desc="WordPress + plugins load", scf;dur=%.1f;desc="Contact form handler"',
        $boot_ms,
        $scf_ms
    ) );
}

// ── Deferred notifications ────────────────────────────────────────────────────
// Each lead gets a single-use key mapped to its row ID (or to the lead itself
// when it couldn't be stored). Whichever arrives first
// claims it and sends the emails: the beacon from the browser, or a WP-Cron
// event scheduled as a safety net in case the beacon never arrives.

add_action( 'wp_ajax_scf_notify',        'scf_handle_notify' );
add_action( 'wp_ajax_nopriv_scf_notify', 'scf_handle_notify' );
add_action( 'scf_notify_fallback_event', 'scf_claim_and_notify' );

function scf_queue_notification( int|array $lead ): string {
    $key = wp_generate_password( 32, false );
    set_transient( 'scf_ntf_' . $key, $lead, DAY_IN_SECONDS );
    wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, 'scf_notify_fallback_event', [ $key ] );
    return $key;
}

function scf_handle_notify() {
    $key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';

    ignore_user_abort( true );
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 120 ); // SMTP to several recipients can be slow.
    }

    if ( $key && scf_claim_and_notify( $key ) ) {
        wp_clear_scheduled_hook( 'scf_notify_fallback_event', [ $key ] );
    }
    wp_die( '', '', [ 'response' => 204 ] );
}

function scf_claim_and_notify( string $key ): bool {
    if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', $key ) ) return false;

    $tr_key = 'scf_ntf_' . $key;
    $queued = get_transient( $tr_key );
    if ( ! $queued ) return false;
    delete_transient( $tr_key ); // Single use.

    $lead = is_array( $queued ) ? $queued : scf_get_submission( (int) $queued );
    if ( ! $lead ) return false;

    scf_send_lead_notifications( $lead );
    return true;
}

// Kept so any events queued by 1.2.1 still get delivered.
add_action( 'scf_send_notifications_event', 'scf_send_lead_notifications' );
function scf_send_lead_notifications( array $lead ) {
    scf_send_notifications( $lead['name'], $lead['email'], $lead['phone'], $lead['company'], $lead['message'] );
}

// ── Thank-you message ─────────────────────────────────────────────────────────
// The message is rendered by the shortcode (hidden) and revealed in place of the
// form after a successful submission, while the address bar switches to the
// thank-you URL via history.pushState(). Nothing is served at that URL, so a
// visitor who reloads or opens it directly is sent to the home page.

// Settings text, translated through WPML String Translation when it's active
// (a pass-through otherwise). Strings are registered when settings are saved.
function scf_thankyou_string( string $name, string $default ): string {
    $value = (string) get_option( 'scf_thankyou_' . $name, $default );
    return (string) apply_filters( 'wpml_translate_single_string', $value, 'simple-contact-form', 'Thank-you ' . $name );
}

// The saved image may be an attachment ID instead of a URL; esc_url_raw()
// stores "2497" as "http://2497", so recognise that form too.
function scf_thankyou_image_url(): string {
    $value = (string) get_option( 'scf_thankyou_image', '' );
    if ( preg_match( '#^(?:https?://)?(\d+)/?$#', $value, $m ) ) {
        return (string) wp_get_attachment_url( (int) $m[1] );
    }
    return $value;
}

// home_url() carries the current language prefix under WPML (e.g. /en/thank-you/).
function scf_thankyou_url(): string {
    $slug = trim( (string) get_option( 'scf_thankyou_slug', 'thank-you' ), '/' );
    return $slug ? home_url( user_trailingslashit( $slug ) ) : '';
}

add_action( 'template_redirect', 'scf_redirect_thankyou_url' );
function scf_redirect_thankyou_url() {
    $thankyou = scf_thankyou_url();
    if ( ! $thankyou ) return;

    $requested = trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
    $expected  = trim( (string) wp_parse_url( $thankyou, PHP_URL_PATH ), '/' );
    if ( '' === $requested || $requested !== $expected ) return;

    wp_safe_redirect( home_url( '/' ) );
    exit;
}

// Returns false if any recipient failed (the reason is in scf_mail_error()).
function scf_send_notifications( string $name, string $email, string $phone, string $company, string $message, array $to = [] ): bool {
    if ( ! $to ) {
        $raw   = get_option( 'scf_recipient_emails', get_option( 'admin_email' ) );
        $lines = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
        $to    = array_filter( $lines, 'is_email' );
    }

    if ( empty( $to ) ) return false;

    $site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
    $subject = sprintf( '[%s] Nueva solicitud de contacto de %s', $site, $name );

    $body  = "Se recibió una nueva solicitud de contacto.\n\n";
    $body .= "Nombre:  {$name}\n";
    $body .= "Correo:  {$email}\n";
    if ( $phone )   $body .= "Teléfono: {$phone}\n";
    if ( $company ) $body .= "Empresa:  {$company}\n";
    if ( $message ) $body .= "\nMensaje:\n{$message}\n";
    $body .= "\n---\nEnviado desde {$site}";

    $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

    // An explicit sender on the site's own domain. Left empty, the SMTP plugin
    // (or WordPress's wordpress@<domain> default) decides.
    $from_email = (string) get_option( 'scf_from_email', '' );
    if ( is_email( $from_email ) ) {
        $from_name = scf_mail_name( (string) get_option( 'scf_from_name', '' ) ?: $site );
        $headers[] = "From: {$from_name} <{$from_email}>";
    }

    // The visitor goes in Reply-To only, never From: sending "from" their
    // address fails SPF/DMARC for their domain and lands in spam.
    if ( is_email( $email ) ) {
        $headers[] = 'Reply-To: ' . scf_mail_name( $name ) . " <{$email}>";
    }

    // Without an SMTP plugin, PHP mail() uses the server's own address as the
    // envelope sender (Return-Path); align it with From so SPF checks the
    // site's domain.
    $align_sender = function ( $phpmailer ) {
        if ( 'mail' === $phpmailer->Mailer && is_email( $phpmailer->From ) && ! $phpmailer->Sender ) {
            $phpmailer->Sender = $phpmailer->From;
        }
    };
    $GLOBALS['scf_mail_error'] = '';
    $record_error = function ( $error ) {
        $GLOBALS['scf_mail_error'] = $error->get_error_message();
    };
    add_action( 'phpmailer_init', $align_sender );
    add_action( 'wp_mail_failed', $record_error );

    $ok = true;
    foreach ( $to as $recipient ) {
        $ok = wp_mail( $recipient, $subject, $body, $headers ) && $ok;
    }

    remove_action( 'phpmailer_init', $align_sender );
    remove_action( 'wp_mail_failed', $record_error );
    return $ok;
}

function scf_mail_error(): string {
    return (string) ( $GLOBALS['scf_mail_error'] ?? '' );
}

// wp_mail() splits address headers on commas, so a name like "Pérez, Juan"
// would break Reply-To. Drop the characters that are special in addresses.
function scf_mail_name( string $name ): string {
    return trim( preg_replace( '/[",;:<>@\\\\]+/', ' ', $name ) );
}

// ── Helper: resolve real client IP ────────────────────────────────────────────
function scf_get_client_ip(): string {
    $keys = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ];
    foreach ( $keys as $key ) {
        if ( ! empty( $_SERVER[ $key ] ) ) {
            // X-Forwarded-For may contain a comma-separated list; take the first
            $ip = trim( explode( ',', $_SERVER[ $key ] )[0] );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
        }
    }
    return '0.0.0.0';
}

// ── Helper: basic content spam detection ──────────────────────────────────────
function scf_is_spam_content( string $text ): bool {
    // Too many URLs
    $url_count = preg_match_all( '/https?:\/\//i', $text );
    if ( $url_count > 3 ) return true;

    // Spam keyword list (extend as needed)
    $keywords = [
        'casino', 'viagra', 'cialis', 'crypto', 'bitcoin', 'nft', 'forex',
        'make money fast', 'earn \$', 'click here', 'free offer',
        'cheap meds', 'buy now', 'limited time offer', 'act now',
        'seo service', 'backlink', 'loan offer',
    ];
    $lower = mb_strtolower( $text );
    foreach ( $keywords as $kw ) {
        if ( str_contains( $lower, $kw ) ) return true;
    }

    // All-caps ratio > 60 % on messages longer than 20 chars
    $letters = preg_replace( '/[^a-zA-Z]/', '', $text );
    if ( strlen( $letters ) > 20 ) {
        $upper_ratio = strlen( preg_replace( '/[^A-Z]/', '', $letters ) ) / strlen( $letters );
        if ( $upper_ratio > 0.6 ) return true;
    }

    return false;
}
