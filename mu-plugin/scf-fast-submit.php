<?php
/**
 * Plugin Name: EBC Form – Fast Submit
 * Description: Loads only EBC Form while a contact form submission is handled, so the visitor doesn't wait for every other plugin to boot. Installed and removed automatically by EBC Form.
 * Version: 1.5.0
 * Author: Eduardo Baeza
 */

// Only the submit request is trimmed. The email request (scf_notify) and the
// WP-Cron fallback load every plugin, so SMTP plugins still handle wp_mail().
// Add define( 'SCF_FAST_SUBMIT', false ); to wp-config.php to turn this off.

defined( 'ABSPATH' ) || exit;

if ( defined( 'SCF_FAST_SUBMIT' ) && ! SCF_FAST_SUBMIT ) return;

function scf_fast_is_submit_request(): bool {
    if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) return false;

    // admin-ajax.php?action=scf_submit
    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
        return 'scf_submit' === ( $_REQUEST['action'] ?? '' );
    }

    // REST: /wp-json/scf/v1/submit or ?rest_route=/scf/v1/submit
    $route = (string) ( $_GET['rest_route'] ?? (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) );
    return (bool) preg_match( '#/scf/v1/submit/?$#', $route );
}

if ( ! scf_fast_is_submit_request() ) return;

// Keep only EBC Form's main file. If it isn't active, change nothing.
function scf_fast_only_this_plugin( $plugins ) {
    if ( ! is_array( $plugins ) ) return $plugins;
    $kept = array_filter( $plugins, function ( $plugin ) {
        return 'simple-contact-form.php' === basename( (string) $plugin );
    } );
    return $kept ?: $plugins;
}
add_filter( 'option_active_plugins', 'scf_fast_only_this_plugin', PHP_INT_MAX );

// Multisite: network-activated plugins are stored as keys. EBC Form may be
// active per site instead, so an empty result is fine here.
add_filter( 'site_option_active_sitewide_plugins', function ( $plugins ) {
    if ( ! is_array( $plugins ) ) return $plugins;
    return array_filter( $plugins, function ( $plugin ) {
        return 'simple-contact-form.php' === basename( (string) $plugin );
    }, ARRAY_FILTER_USE_KEY );
}, PHP_INT_MAX );
