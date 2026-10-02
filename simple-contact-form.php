<?php
/**
 * Plugin Name: EBC Form
 * Description: A contact form that stores submissions in DB, sends emails to multiple recipients, and shows a customizable thank-you message.
 * Version: 1.8.0
 * Author: Eduardo Baeza
 * Text Domain: simple-contact-form
 */

defined( 'ABSPATH' ) || exit;

define( 'SCF_VERSION', '1.8.0' );
define( 'SCF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ── Includes ──────────────────────────────────────────────────────────────────
require_once SCF_PLUGIN_DIR . 'includes/database.php';
require_once SCF_PLUGIN_DIR . 'includes/settings.php';
require_once SCF_PLUGIN_DIR . 'includes/form-handler.php';
require_once SCF_PLUGIN_DIR . 'includes/shortcode.php';

// ── Activation / deactivation ─────────────────────────────────────────────────
register_activation_hook( __FILE__, 'scf_activate' );
function scf_activate() {
    scf_create_table();
    scf_set_default_options();
    scf_install_mu_plugin();
}

register_deactivation_hook( __FILE__, 'scf_uninstall_mu_plugin' );

// ── Fast-submit must-use plugin ───────────────────────────────────────────────
// mu-plugin/scf-fast-submit.php makes WordPress load only this plugin while a
// submission is handled. It has to live in wp-content/mu-plugins/ to run before
// other plugins load, so it's copied there on activation and after updates.
define( 'SCF_MU_PLUGIN_FILE', 'scf-fast-submit.php' );

function scf_install_mu_plugin(): bool {
    $src  = SCF_PLUGIN_DIR . 'mu-plugin/' . SCF_MU_PLUGIN_FILE;
    $dest = WPMU_PLUGIN_DIR . '/' . SCF_MU_PLUGIN_FILE;

    $ok = ( file_exists( $dest ) && md5_file( $dest ) === md5_file( $src ) )
        || ( wp_mkdir_p( WPMU_PLUGIN_DIR ) && @copy( $src, $dest ) );

    update_option( 'scf_mu_plugin_version', $ok ? SCF_VERSION : 'failed' );
    return $ok;
}

function scf_uninstall_mu_plugin() {
    $dest = WPMU_PLUGIN_DIR . '/' . SCF_MU_PLUGIN_FILE;
    if ( file_exists( $dest ) ) {
        @unlink( $dest );
    }
    delete_option( 'scf_mu_plugin_version' );
}

// Plugin updates don't run the activation hook, so check once per version.
add_action( 'admin_init', 'scf_maybe_install_mu_plugin' );
function scf_maybe_install_mu_plugin() {
    if ( wp_doing_ajax() || ! current_user_can( 'activate_plugins' ) ) return;
    if ( SCF_VERSION === get_option( 'scf_mu_plugin_version' ) ) return;
    scf_install_mu_plugin();
}

add_action( 'admin_notices', 'scf_mu_plugin_notice' );
function scf_mu_plugin_notice() {
    if ( 'failed' !== get_option( 'scf_mu_plugin_version' ) || ! current_user_can( 'activate_plugins' ) ) return;
    printf(
        '<div class="notice notice-warning"><p><strong>EBC Form:</strong> couldn\'t copy %1$s into %2$s, so form submissions load every plugin and are slower. Copy it there manually from the plugin\'s <code>mu-plugin</code> folder.</p></div>',
        '<code>' . esc_html( SCF_MU_PLUGIN_FILE ) . '</code>',
        '<code>' . esc_html( str_replace( ABSPATH, '', WPMU_PLUGIN_DIR ) ) . '</code>'
    );
}

// ── Scripts & styles ──────────────────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', 'scf_enqueue_assets' );
function scf_enqueue_assets() {
    wp_enqueue_style( 'scf-style', SCF_PLUGIN_URL . 'assets/css/form.css', [], SCF_VERSION );
    wp_enqueue_script( 'scf-script', SCF_PLUGIN_URL . 'assets/js/form.js', [ 'jquery' ], SCF_VERSION, true );
    wp_localize_script( 'scf-script', 'scf_ajax', [
        'ajax_url'   => admin_url( 'admin-ajax.php' ),
        // rest_route form rather than rest_url(): it works without pretty
        // permalinks and without WPML's language prefix, which the fast-submit
        // mu-plugin doesn't load WPML to understand.
        'rest_url'   => site_url( '/?rest_route=/scf/v1/submit' ),
        'rest_nonce' => wp_create_nonce( 'wp_rest' ),
        'nonce'      => wp_create_nonce( 'scf_submit_nonce' ),
    ] );
}
