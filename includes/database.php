<?php
defined( 'ABSPATH' ) || exit;

function scf_create_table() {
    global $wpdb;
    $table   = $wpdb->prefix . 'scf_submissions';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        name        VARCHAR(120)        NOT NULL DEFAULT '',
        email       VARCHAR(120)        NOT NULL DEFAULT '',
        phone       VARCHAR(50)                  DEFAULT '',
        company     VARCHAR(120)                 DEFAULT '',
        message     TEXT                         DEFAULT '',
        submitted_at DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

function scf_insert_submission( array $data ): int|false {
    global $wpdb;
    $inserted = $wpdb->insert(
        $wpdb->prefix . 'scf_submissions',
        [
            'name'    => sanitize_text_field( $data['name'] ),
            'email'   => sanitize_email( $data['email'] ),
            'phone'   => sanitize_text_field( $data['phone'] ?? '' ),
            'company' => sanitize_text_field( $data['company'] ?? '' ),
            'message' => sanitize_textarea_field( $data['message'] ),
        ],
        [ '%s', '%s', '%s', '%s', '%s' ]
    );
    return $inserted ? $wpdb->insert_id : false;
}

function scf_get_submission( int $id ): ?array {
    global $wpdb;
    $row = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}scf_submissions WHERE id = %d", $id ),
        ARRAY_A
    );
    return $row ?: null;
}

// Columns the submissions list can be sorted by.
const SCF_SORTABLE_COLUMNS = [ 'id', 'name', 'email', 'phone', 'company', 'message', 'submitted_at' ];

function scf_get_submissions( int $per_page = 20, int $page = 1, string $orderby = 'submitted_at', string $order = 'DESC' ): array {
    global $wpdb;
    $offset  = ( $page - 1 ) * $per_page;
    $table   = $wpdb->prefix . 'scf_submissions';
    // Whitelisted because column names and directions can't be bound by prepare().
    $orderby = in_array( $orderby, SCF_SORTABLE_COLUMNS, true ) ? $orderby : 'submitted_at';
    $order   = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
    return $wpdb->get_results(
        // id as a tiebreaker keeps pages stable when sorted values repeat.
        $wpdb->prepare( "SELECT * FROM {$table} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d", $per_page, $offset ),
        ARRAY_A
    );
}

function scf_count_submissions(): int {
    global $wpdb;
    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}scf_submissions" );
}

function scf_delete_submission( int $id ): bool {
    global $wpdb;
    return (bool) $wpdb->delete( $wpdb->prefix . 'scf_submissions', [ 'id' => $id ], [ '%d' ] );
}

function scf_delete_submissions( array $ids ): int {
    global $wpdb;
    $ids = array_filter( array_map( 'absint', $ids ) );
    if ( ! $ids ) return 0;
    $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
    return (int) $wpdb->query(
        $wpdb->prepare( "DELETE FROM {$wpdb->prefix}scf_submissions WHERE id IN ({$placeholders})", $ids )
    );
}
