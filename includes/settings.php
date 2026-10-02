<?php
defined( 'ABSPATH' ) || exit;

// ── Default options ────────────────────────────────────────────────────────────
function scf_set_default_options() {
    add_option( 'scf_recipient_emails',  get_option( 'admin_email' ) );
    add_option( 'scf_thankyou_slug',     'thank-you' );
    add_option( 'scf_thankyou_title',    '¡Gracias!' );
    add_option( 'scf_thankyou_message',  'Hemos recibido tu mensaje y te contactaremos pronto.' );
    add_option( 'scf_thankyou_image',    '' );
    add_option( 'scf_rate_limit',        5 );
    add_option( 'scf_btn_label',         'Enviar' );
    add_option( 'scf_from_name',         '' );
    add_option( 'scf_from_email',        '' );
}

// ── Admin menu ────────────────────────────────────────────────────────────────
add_action( 'admin_menu', 'scf_admin_menu' );
function scf_admin_menu() {
    add_menu_page(
        'Contact Form',
        'Contact Form',
        'manage_options',
        'simple-contact-form',
        'scf_render_submissions_page',
        'dashicons-email-alt',
        58
    );
    add_submenu_page(
        'simple-contact-form',
        'Submissions',
        'Submissions',
        'manage_options',
        'simple-contact-form',
        'scf_render_submissions_page'
    );
    add_submenu_page(
        'simple-contact-form',
        'Settings',
        'Settings',
        'manage_options',
        'scf-settings',
        'scf_render_settings_page'
    );
}

// ── Settings save ─────────────────────────────────────────────────────────────
add_action( 'admin_init', 'scf_save_settings' );
function scf_save_settings() {
    if ( ! isset( $_POST['scf_settings_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['scf_settings_nonce'], 'scf_save_settings' ) ) wp_die( 'Security check failed.' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );

    update_option( 'scf_recipient_emails', sanitize_textarea_field( $_POST['scf_recipient_emails'] ?? '' ) );
    update_option( 'scf_from_name',        sanitize_text_field( wp_unslash( $_POST['scf_from_name'] ?? '' ) ) );
    update_option( 'scf_from_email',       sanitize_email( wp_unslash( $_POST['scf_from_email'] ?? '' ) ) );
    $slug = implode( '/', array_filter( array_map( 'sanitize_title', explode( '/', wp_unslash( $_POST['scf_thankyou_slug'] ?? '' ) ) ) ) );
    update_option( 'scf_thankyou_slug',    $slug );
    update_option( 'scf_thankyou_title',   sanitize_text_field( wp_unslash( $_POST['scf_thankyou_title'] ?? '' ) ) );
    update_option( 'scf_thankyou_message', wp_kses_post( wp_unslash( $_POST['scf_thankyou_message'] ?? '' ) ) );
    $image = trim( wp_unslash( $_POST['scf_thankyou_image'] ?? '' ) );
    if ( ctype_digit( $image ) ) {
        $image = (string) wp_get_attachment_url( (int) $image ); // An attachment ID.
    }
    update_option( 'scf_thankyou_image',   esc_url_raw( $image ) );

    // Make the thank-you text translatable in WPML String Translation.
    do_action( 'wpml_register_single_string', 'simple-contact-form', 'Thank-you title',   get_option( 'scf_thankyou_title' ) );
    do_action( 'wpml_register_single_string', 'simple-contact-form', 'Thank-you message', get_option( 'scf_thankyou_message' ) );
    update_option( 'scf_rate_limit',       max( 1, absint( $_POST['scf_rate_limit'] ?? 5 ) ) );
    update_option( 'scf_btn_label',        sanitize_text_field( $_POST['scf_btn_label'] ?? 'Enviar' ) );

    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
    } );
}

// ── Test email ────────────────────────────────────────────────────────────────
// Sends a sample notification through exactly the same code as a real lead, so
// it can be checked with a tool like mail-tester.com.
add_action( 'admin_init', 'scf_send_test_email' );
function scf_send_test_email() {
    if ( ! isset( $_POST['scf_test_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['scf_test_nonce'], 'scf_send_test' ) ) wp_die( 'Security check failed.' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );

    $to = sanitize_email( wp_unslash( $_POST['scf_test_to'] ?? '' ) );
    if ( ! is_email( $to ) ) {
        $notice = [ 'error', 'Enter a valid address to send the test email to.' ];
    } else {
        $user = wp_get_current_user();
        $sent = scf_send_notifications(
            'Prueba EBC Form', $user->user_email, '+52 55 0000 0000', get_bloginfo( 'name' ),
            'Este es un correo de prueba enviado desde Ajustes del formulario de contacto.', [ $to ]
        );
        $notice = $sent
            ? [ 'success', sprintf( 'Test email sent to %s. If it lands in spam, see the checklist under Email Sender.', $to ) ]
            : [ 'error', 'The test email could not be sent: ' . ( scf_mail_error() ?: 'wp_mail() returned false.' ) ];
    }

    add_action( 'admin_notices', function () use ( $notice ) {
        printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
    } );
}

// ── Settings page media picker ────────────────────────────────────────────────
add_action( 'admin_enqueue_scripts', 'scf_settings_assets' );
function scf_settings_assets() {
    if ( 'scf-settings' !== ( $_GET['page'] ?? '' ) ) return;

    wp_enqueue_media();
    wp_add_inline_script( 'media-editor', <<<'JS'
jQuery( function ( $ ) {
    var $input = $( '#scf_thankyou_image' ), $preview = $( '#scf_thankyou_image_preview' ), frame;
    function set( url ) { $input.val( url ); $preview.attr( 'src', url ).toggle( !! url ); }
    $( '#scf_thankyou_image_pick' ).on( 'click', function () {
        frame = frame || wp.media( { title: 'Thank-you image', library: { type: 'image' }, multiple: false } );
        frame.off( 'select' ).on( 'select', function () {
            set( frame.state().get( 'selection' ).first().get( 'url' ) );
        } );
        frame.open();
    } );
    $( '#scf_thankyou_image_clear' ).on( 'click', function () { set( '' ); } );
    $input.on( 'change', function () { set( $input.val() ); } );
} );
JS
    );
}

// ── Submissions page ──────────────────────────────────────────────────────────
function scf_render_submissions_page() {
    // Handle single delete
    if ( isset( $_GET['delete_id'] ) && check_admin_referer( 'scf_delete_' . absint( $_GET['delete_id'] ) ) ) {
        scf_delete_submission( absint( $_GET['delete_id'] ) );
        echo '<div class="notice notice-success is-dismissible"><p>Submission deleted.</p></div>';
    }

    // Handle bulk delete
    if ( 'delete' === ( $_POST['scf_bulk_action'] ?? '' ) && check_admin_referer( 'scf_bulk_submissions' ) ) {
        $deleted = scf_delete_submissions( (array) ( $_POST['ids'] ?? [] ) );
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            $deleted ? 'success' : 'warning',
            $deleted ? esc_html( sprintf( _n( '%d submission deleted.', '%d submissions deleted.', $deleted ), $deleted ) ) : 'No submissions selected.'
        );
    }

    $total = scf_count_submissions();
    $pages = (int) ceil( $total / 20 );
    // Clamped so deleting everything on the last page doesn't leave it empty.
    $page  = isset( $_GET['paged'] ) ? max( 1, min( absint( $_GET['paged'] ), $pages ) ) : 1;

    $orderby = sanitize_key( $_GET['orderby'] ?? '' );
    $orderby = in_array( $orderby, SCF_SORTABLE_COLUMNS, true ) ? $orderby : 'submitted_at';
    $order   = 'asc' === strtolower( $_GET['order'] ?? '' ) ? 'asc' : 'desc';
    $items   = scf_get_submissions( 20, $page, $orderby, $order );

    $list_url = add_query_arg( [ 'page' => 'simple-contact-form', 'orderby' => $orderby, 'order' => $order ], admin_url( 'admin.php' ) );
    $date_fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

    // Sortable header cell in WordPress list-table markup, so core styles the
    // indicators. ID and Date sort newest first on the first click.
    $sort_th = function ( string $column, string $label ) use ( $orderby, $order ) {
        $desc_first = in_array( $column, [ 'id', 'submitted_at' ], true );
        if ( $column === $orderby ) {
            $classes = "sorted {$order}";
            $next    = 'asc' === $order ? 'desc' : 'asc';
            $aria    = sprintf( ' aria-sort="%s"', 'asc' === $order ? 'ascending' : 'descending' );
        } else {
            $classes = 'sortable ' . ( $desc_first ? 'asc' : 'desc' );
            $next    = $desc_first ? 'desc' : 'asc';
            $aria    = '';
        }
        $url = add_query_arg( [ 'page' => 'simple-contact-form', 'orderby' => $column, 'order' => $next ], admin_url( 'admin.php' ) );
        printf(
            '<th scope="col" class="manage-column column-%1$s %2$s"%3$s><a href="%4$s"><span>%5$s</span><span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span></a></th>',
            esc_attr( 'submitted_at' === $column ? 'date' : $column ),
            esc_attr( $classes ),
            $aria,
            esc_url( $url ),
            esc_html( $label )
        );
    };
    ?>
    <style>
        /* Minimal list: no frame or stripes, hairline row separators, and every
           row the same height. Long values end in an ellipsis and are shown in
           full in the row's expandable details. */
        .scf-submissions.widefat { table-layout: fixed; border: 0; box-shadow: none; background: transparent; }
        .scf-submissions thead th,
        .scf-submissions thead td { border-bottom: 1px solid #dcdcde; background: transparent; }
        .scf-submissions thead th,
        .scf-submissions thead th a { color: #50575e; font-weight: 600; }
        .scf-submissions tbody > tr:not(.scf-detail) > * {
            height: 44px;
            padding-top: 0;
            padding-bottom: 0;
            vertical-align: middle;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            background: #fff;
            border-bottom: 1px solid #f0f0f1;
        }
        .scf-submissions tbody > tr:not(.scf-detail) { cursor: pointer; }
        .scf-submissions tbody .check-column { cursor: default; }
        .scf-submissions tbody .check-column input { margin-top: 0; }
        .scf-submissions .column-toggle  { width: 32px; padding-left: 0; padding-right: 0; text-align: center; }
        .scf-submissions .column-id      { width: 64px; }
        .scf-submissions .column-name    { width: 14%; }
        .scf-submissions .column-email   { width: 18%; }
        .scf-submissions .column-phone   { width: 11%; }
        .scf-submissions .column-company { width: 12%; }
        .scf-submissions .column-date    { width: 160px; }
        .scf-submissions .column-actions { width: 64px; text-align: right; }

        .scf-toggle {
            display: inline-flex; align-items: center; justify-content: center;
            width: 28px; height: 28px; padding: 0;
            border: 0; border-radius: 4px; background: none; color: #50575e; cursor: pointer;
        }
        .scf-toggle:focus { outline: 0; box-shadow: 0 0 0 2px #2271b1; }
        .scf-toggle .dashicons { transition: transform .15s ease; }
        .scf-toggle[aria-expanded="true"] .dashicons { transform: rotate(90deg); }

        .scf-detail > td { padding: 12px 16px 16px 72px; background: #f6f7f7; border-bottom: 1px solid #f0f0f1; }
        .scf-detail dl { display: grid; grid-template-columns: max-content 1fr; gap: 6px 24px; margin: 0; }
        .scf-detail dt { color: #50575e; font-weight: 600; }
        .scf-detail dd { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; }
    </style>
    <div class="wrap">
        <h1>Contact Form Submissions <span class="scf-count">(<?php echo $total; ?>)</span></h1>

        <?php if ( empty( $items ) ) : ?>
            <p>No submissions yet.</p>
        <?php else : ?>
        <form method="post" id="scf-submissions-form">
            <?php wp_nonce_field( 'scf_bulk_submissions' ); ?>
            <div class="tablenav top">
                <div class="alignleft actions bulkactions">
                    <label for="scf-bulk-action" class="screen-reader-text">Select bulk action</label>
                    <select name="scf_bulk_action" id="scf-bulk-action">
                        <option value="">Bulk actions</option>
                        <option value="delete">Delete</option>
                    </select>
                    <?php submit_button( 'Apply', 'action', '', false, [ 'id' => 'scf-bulk-apply' ] ); ?>
                </div>
            </div>
        <table class="wp-list-table widefat scf-submissions">
            <thead>
                <tr>
                    <td class="manage-column column-cb check-column">
                        <label class="screen-reader-text" for="scf-select-all">Select all</label>
                        <input id="scf-select-all" type="checkbox">
                    </td>
                    <td class="manage-column column-toggle"><span class="screen-reader-text">Details</span></td>
                    <?php
                    $sort_th( 'id', 'ID' );
                    $sort_th( 'name', 'Name' );
                    $sort_th( 'email', 'Email' );
                    $sort_th( 'phone', 'Phone' );
                    $sort_th( 'company', 'Company' );
                    $sort_th( 'message', 'Message' );
                    $sort_th( 'submitted_at', 'Date' );
                    ?>
                    <th scope="col" class="manage-column column-actions"><span class="screen-reader-text">Actions</span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $items as $row ) :
                $id      = (int) $row['id'];
                $date    = date_i18n( $date_fmt, strtotime( $row['submitted_at'] ) );
                $del_url = wp_nonce_url(
                    add_query_arg( [ 'delete_id' => $id, 'paged' => $page ], $list_url ),
                    'scf_delete_' . $id
                );
                ?>
                <tr>
                    <th scope="row" class="check-column">
                        <label class="screen-reader-text" for="scf-cb-<?php echo $id; ?>">Select submission <?php echo $id; ?></label>
                        <input id="scf-cb-<?php echo $id; ?>" type="checkbox" name="ids[]" value="<?php echo $id; ?>">
                    </th>
                    <td class="column-toggle">
                        <button type="button" class="scf-toggle" aria-expanded="false" aria-controls="scf-detail-<?php echo $id; ?>">
                            <span class="screen-reader-text">Show details for submission <?php echo $id; ?></span>
                            <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                        </button>
                    </td>
                    <td class="column-id"><?php echo $id; ?></td>
                    <td class="column-name"><?php echo esc_html( $row['name'] ); ?></td>
                    <td class="column-email"><a href="mailto:<?php echo esc_attr( $row['email'] ); ?>"><?php echo esc_html( $row['email'] ); ?></a></td>
                    <td class="column-phone"><?php echo esc_html( $row['phone'] ); ?></td>
                    <td class="column-company"><?php echo esc_html( $row['company'] ?? '' ); ?></td>
                    <td class="column-message"><?php echo esc_html( $row['message'] ); ?></td>
                    <td class="column-date"><?php echo esc_html( $date ); ?></td>
                    <td class="column-actions">
                        <a href="<?php echo esc_url( $del_url ); ?>" class="button-link button-link-delete"
                           onclick="return confirm('Delete this submission?')">Delete</a>
                    </td>
                </tr>
                <tr class="scf-detail" id="scf-detail-<?php echo $id; ?>" hidden>
                    <td colspan="10">
                        <dl>
                            <dt>ID</dt><dd><?php echo $id; ?></dd>
                            <dt>Name</dt><dd><?php echo esc_html( $row['name'] ); ?></dd>
                            <dt>Email</dt><dd><a href="mailto:<?php echo esc_attr( $row['email'] ); ?>"><?php echo esc_html( $row['email'] ); ?></a></dd>
                            <dt>Phone</dt><dd><?php echo esc_html( $row['phone'] ); ?></dd>
                            <dt>Company</dt><dd><?php echo esc_html( $row['company'] ?? '' ); ?></dd>
                            <dt>Message</dt><dd><?php echo esc_html( $row['message'] ); ?></dd>
                            <dt>Date</dt><dd><?php echo esc_html( $date ); ?></dd>
                        </dl>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </form>
        <script>
            // Select-all checkbox and shift-click ranges come from WordPress's common.js.
            document.getElementById( 'scf-submissions-form' ).addEventListener( 'submit', function ( e ) {
                if ( 'delete' !== document.getElementById( 'scf-bulk-action' ).value ) return;
                var count = this.querySelectorAll( 'input[name="ids[]"]:checked' ).length;
                if ( ! count ) { e.preventDefault(); alert( 'Select at least one submission.' ); return; }
                if ( ! confirm( 'Delete ' + count + ' submission' + ( count > 1 ? 's' : '' ) + '? This cannot be undone.' ) ) e.preventDefault();
            } );

            // Clicking the chevron, or anywhere else on the row, opens and closes
            // the full details row below it. Links, the checkbox and text
            // selection keep their normal behaviour.
            document.querySelector( '.scf-submissions tbody' ).addEventListener( 'click', function ( e ) {
                var btn = e.target.closest( '.scf-toggle' );
                if ( ! btn ) {
                    var row = e.target.closest( 'tr:not(.scf-detail)' );
                    if ( ! row || e.target.closest( 'a, input, label, button, .check-column' ) ) return;
                    if ( String( window.getSelection() ) ) return;
                    btn = row.querySelector( '.scf-toggle' );
                }
                var open = 'true' !== btn.getAttribute( 'aria-expanded' );
                var label = btn.querySelector( '.screen-reader-text' );
                btn.setAttribute( 'aria-expanded', open );
                label.textContent = label.textContent.replace( /^\w+/, open ? 'Hide' : 'Show' );
                document.getElementById( btn.getAttribute( 'aria-controls' ) ).hidden = ! open;
            } );
        </script>
        <?php if ( $pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links( [
                        'base'    => add_query_arg( 'paged', '%#%' ),
                        'format'  => '',
                        'current' => $page,
                        'total'   => $pages,
                    ] );
                    ?>
                </div>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

// ── Settings page ─────────────────────────────────────────────────────────────
function scf_render_settings_page() {
    $emails      = get_option( 'scf_recipient_emails', get_option( 'admin_email' ) );
    $ty_image    = scf_thankyou_image_url();
    ?>
    <div class="wrap">
        <h1>Contact Form Settings</h1>
        <form method="post">
            <?php wp_nonce_field( 'scf_save_settings', 'scf_settings_nonce' ); ?>

            <h2>Form</h2>
            <table class="form-table">
                <tr>
                    <th><label for="scf_btn_label">Button Label</label></th>
                    <td><input type="text" id="scf_btn_label" name="scf_btn_label"
                               value="<?php echo esc_attr( get_option('scf_btn_label','Enviar') ); ?>" class="regular-text"></td>
                </tr>
            </table>
            </table>

            <h2>Recipients</h2>
            <table class="form-table">
                <tr>
                    <th><label for="scf_recipient_emails">Email Addresses</label></th>
                    <td>
                        <textarea id="scf_recipient_emails" name="scf_recipient_emails"
                                  rows="4" class="large-text"><?php echo esc_textarea( $emails ); ?></textarea>
                        <p class="description">One email per line. All listed addresses will receive a notification on every submission.</p>
                    </td>
                </tr>
            </table>

            <h2>Email Sender</h2>
            <table class="form-table">
                <tr>
                    <th><label for="scf_from_name">From name</label></th>
                    <td><input type="text" id="scf_from_name" name="scf_from_name" class="regular-text"
                               value="<?php echo esc_attr( get_option( 'scf_from_name', '' ) ); ?>"
                               placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"></td>
                </tr>
                <tr>
                    <th><label for="scf_from_email">From email</label></th>
                    <td>
                        <input type="email" id="scf_from_email" name="scf_from_email" class="regular-text"
                               value="<?php echo esc_attr( get_option( 'scf_from_email', '' ) ); ?>"
                               placeholder="contacto@<?php echo esc_attr( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>">
                        <p class="description">
                            Use an address on this site's domain, and the same one your SMTP plugin or email
                            service is set up to send from. Leave empty to let the SMTP plugin decide.
                            The visitor's address is always set as Reply-To, so replying still reaches them.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>Inbox checklist</th>
                    <td>
                        <ul style="margin:0;padding-left:1.2em;list-style:disc;">
                            <li>Send through an SMTP plugin (e.g. WP Mail SMTP, FluentSMTP) connected to your
                                email provider or a sending service, not through the server's PHP <code>mail()</code>.</li>
                            <li>The domain of the From address has <strong>SPF</strong>, <strong>DKIM</strong> and
                                <strong>DMARC</strong> DNS records that include that provider.</li>
                            <li>Send a test below to <a href="https://www.mail-tester.com/" target="_blank" rel="noopener">mail-tester.com</a>
                                and fix what it reports.</li>
                        </ul>
                    </td>
                </tr>
            </table>

            <h2>Spam Protection</h2>
            <table class="form-table">
                <tr>
                    <th><label for="scf_rate_limit">Rate limit</label></th>
                    <td>
                        <input type="number" id="scf_rate_limit" name="scf_rate_limit" min="1" max="100"
                               value="<?php echo esc_attr( (int) get_option( 'scf_rate_limit', 5 ) ); ?>"
                               style="width:80px;"> submissions per IP per hour
                        <p class="description">Visitors who exceed this limit will see an error. Default: 5.</p>
                    </td>
                </tr>
                <tr>
                    <th>Active protections</th>
                    <td>
                        <ul style="margin:0;padding-left:1.2em;">
                            <li><strong>Honeypot</strong> — invisible field that only bots fill out</li>
                            <li><strong>Time token</strong> — rejects submissions under 3 s (bots) or over 2 h old (replays)</li>
                            <li><strong>Rate limiting</strong> — IP-based submission cap via WP transients</li>
                            <li><strong>Content filter</strong> — flags excessive URLs, spam keywords, and all-caps messages</li>
                        </ul>
                        <p class="description" style="margin-top:.5em;">No external services or API keys required.</p>
                    </td>
                </tr>
            </table>

            <h2>Thank-you Message</h2>
            <p class="description">
                Shown in place of the form after a successful submission. The browser's address
                bar changes to the URL below without loading a new page.
            </p>
            <table class="form-table">
                <tr>
                    <th><label for="scf_thankyou_slug">URL</label></th>
                    <td>
                        <code><?php echo esc_html( trailingslashit( home_url() ) ); ?></code>
                        <input type="text" id="scf_thankyou_slug" name="scf_thankyou_slug" class="regular-text"
                               value="<?php echo esc_attr( get_option( 'scf_thankyou_slug', 'thank-you' ) ); ?>">
                        <p class="description">
                            Leave empty to keep the form page's URL. Nothing is served at this URL:
                            visitors who reload it or open it directly are sent to the home page,
                            so don't use the address of an existing page.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="scf_thankyou_title">Title</label></th>
                    <td><input type="text" id="scf_thankyou_title" name="scf_thankyou_title" class="large-text"
                               value="<?php echo esc_attr( get_option( 'scf_thankyou_title', '¡Gracias!' ) ); ?>"></td>
                </tr>
                <tr>
                    <th><label for="scf_thankyou_message">Message</label></th>
                    <td>
                        <textarea id="scf_thankyou_message" name="scf_thankyou_message" rows="4"
                                  class="large-text"><?php echo esc_textarea( get_option( 'scf_thankyou_message', 'Hemos recibido tu mensaje y te contactaremos pronto.' ) ); ?></textarea>
                        <p class="description">
                            Basic HTML (bold, links) is allowed. With WPML, translate the title and message
                            under WPML → String Translation (domain <code>simple-contact-form</code>).
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="scf_thankyou_image">Image</label></th>
                    <td>
                        <input type="url" id="scf_thankyou_image" name="scf_thankyou_image" class="large-text"
                               value="<?php echo esc_attr( $ty_image ); ?>">
                        <p>
                            <button type="button" class="button" id="scf_thankyou_image_pick">Choose image</button>
                            <button type="button" class="button-link" id="scf_thankyou_image_clear">Remove</button>
                        </p>
                        <img id="scf_thankyou_image_preview" src="<?php echo esc_url( $ty_image ); ?>" alt=""
                             style="max-width:200px;height:auto;border-radius:12px;<?php echo $ty_image ? '' : 'display:none;'; ?>">
                    </td>
                </tr>
            </table>

            <p class="submit">
                <?php submit_button( 'Save Settings', 'primary', 'submit', false ); ?>
            </p>

            <hr>
            <h2>Shortcode</h2>
            <p>Place this shortcode anywhere on a page or post:</p>
            <code style="font-size:1.1em;padding:8px 14px;background:#f0f0f0;border-radius:4px;">[simple_contact_form]</code>
        </form>

        <hr>
        <h2>Send a Test Email</h2>
        <p>Sends a sample lead notification using the saved settings above.</p>
        <form method="post">
            <?php wp_nonce_field( 'scf_send_test', 'scf_test_nonce' ); ?>
            <input type="email" name="scf_test_to" class="regular-text" required
                   value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>">
            <?php submit_button( 'Send test email', 'secondary', 'scf_send_test', false ); ?>
        </form>
    </div>
    <?php
}
