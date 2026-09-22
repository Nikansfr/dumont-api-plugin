<?php
/**
 * Plugin Name: Quantum Dealer Mind API
 * Description: Custom REST API endpoint for Quantum Dealer Mind listing sync.
 * Version:     3.0.0
 * GitHub Plugin URI: Nikansfr/dumont-api-plugin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ---------------------------------------------------------------------------
// Auth keys — set in wp-config.php, e.g.:
//   define( 'QUANTUM_API_KEY', '...' );
// No hardcoded default: if it isn't defined, quantum/v1 requests are rejected.
//
// TRANSITIONAL: also accepts the old dumont/v1 route + X-Dumont-Key header,
// checked against DUMONT_API_KEY_LEGACY — but ONLY if that constant is itself
// defined in wp-config.php. There's no hardcoded value here either, so the
// leaked DUMONT2026SECRET never reappears in this file. Remove this whole
// legacy block (the dumont/v1 registration, the X-Dumont-Key check, and the
// DUMONT_API_KEY_LEGACY constant) in a follow-up release once every site's
// DMS app is confirmed on quantum/v1 + X-Quantum-Key.
//
// IMPORTANT — this plugin can auto-update itself (below) on its own schedule,
// not yours. Before this version reaches a given site (by auto-update or a
// manual install), that site's wp-config.php must already define AT LEAST
// ONE of QUANTUM_API_KEY or DUMONT_API_KEY_LEGACY. If neither is defined,
// every request — old path and new path alike — is rejected until
// wp-config.php is edited, since neither constant has a fallback value.
// ---------------------------------------------------------------------------

// Auto-updater via GitHub — unchanged from the live plugin.
add_filter( 'pre_set_site_transient_update_plugins', function( $transient ) {
    if ( empty( $transient->checked ) ) return $transient;

    $plugin_slug = plugin_basename( __FILE__ );
    $github_url  = 'https://api.github.com/repos/Nikansfr/dumont-api-plugin/releases/latest';

    $response = wp_remote_get( $github_url, [
        'headers' => [ 'User-Agent' => 'WordPress/' . get_bloginfo('version') ]
    ]);

    if ( is_wp_error( $response ) ) return $transient;

    $release = json_decode( wp_remote_retrieve_body( $response ) );
    if ( empty( $release->tag_name ) ) return $transient;

    $latest_version = ltrim( $release->tag_name, 'v' );
    $current_version = $transient->checked[ $plugin_slug ] ?? '0';

    if ( version_compare( $latest_version, $current_version, '>' ) ) {
        $transient->response[ $plugin_slug ] = (object)[
            'slug'        => 'dumont-api-plugin',
            'plugin'      => $plugin_slug,
            'new_version' => $latest_version,
            'url'         => 'https://github.com/Nikansfr/dumont-api-plugin',
            'package'     => $release->zipball_url,
        ];
    }

    return $transient;
});

// ---------------------------------------------------------------------------
// CORS — restricted to the DMS app's own origin, the only browser-side caller
// of this API. This doesn't touch the plugin's public /listing/* pages: those
// are ordinary server-rendered WordPress pages, not requests through this
// REST API, so a browser never applies CORS to them.
// ---------------------------------------------------------------------------
function qdm_allowed_origin(): string {
    // Hardcoded to the DMS app's production origin. If the app is ever served
    // from another domain (staging, a future domain change) or you need to
    // test publish/update/delete against this site from local dev, add that
    // origin here.
    $allowed = [ 'https://dms.thedumontdigital.com' ];
    $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
    return in_array( $origin, $allowed, true ) ? $origin : '';
}

function qdm_send_cors_headers(): void {
    $origin = qdm_allowed_origin();
    if ( $origin !== '' ) {
        header( 'Access-Control-Allow-Origin: ' . $origin );
    }
    header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
    // TRANSITIONAL: X-Dumont-Key stays listed until the legacy block above is removed.
    header( 'Access-Control-Allow-Headers: Content-Type, X-Quantum-Key, X-Dumont-Key' );
}

add_action( 'rest_api_init', function () {
    remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
    add_filter( 'rest_pre_serve_request', function ( $value ) {
        qdm_send_cors_headers();
        return $value;
    } );
}, 15 );

// Handle OPTIONS preflight
add_action( 'init', function () {
    if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
        qdm_send_cors_headers();
        exit( 0 );
    }
} );

// ---------------------------------------------------------------------------
// Route registration — quantum/v1, plus TRANSITIONAL dumont/v1 for the bridge
// ---------------------------------------------------------------------------
add_action( 'rest_api_init', function () {
    $auth = 'qdm_check_key';
    // TRANSITIONAL: remove 'dumont/v1' from this list once every site's DMS
    // app is confirmed calling quantum/v1.
    foreach ( [ 'quantum/v1', 'dumont/v1' ] as $namespace ) {
        register_rest_route( $namespace, '/listing', [
            'methods'             => 'POST',
            'callback'            => 'qdm_create_listing',
            'permission_callback' => $auth,
        ] );
        register_rest_route( $namespace, '/listing/update', [
            'methods'             => 'POST',
            'callback'            => 'qdm_update_listing',
            'permission_callback' => $auth,
        ] );
        register_rest_route( $namespace, '/listing/delete', [
            'methods'             => 'POST',
            'callback'            => 'qdm_delete_listing',
            'permission_callback' => $auth,
        ] );
    }
} );

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------
function qdm_check_key( $request ) {
    // Preflight never carries the key header — let it through; the browser
    // still requires the real POST to pass this check on its own.
    if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) return true;

    if ( defined( 'QUANTUM_API_KEY' ) && QUANTUM_API_KEY !== '' ) {
        $provided = (string) $request->get_header( 'X-Quantum-Key' );
        if ( hash_equals( QUANTUM_API_KEY, $provided ) ) return true;
    }

    // TRANSITIONAL — see the note near the top of this file. Remove once
    // every site is confirmed on quantum/v1 + X-Quantum-Key.
    if ( defined( 'DUMONT_API_KEY_LEGACY' ) && DUMONT_API_KEY_LEGACY !== '' ) {
        $legacy = (string) $request->get_header( 'X-Dumont-Key' );
        if ( hash_equals( DUMONT_API_KEY_LEGACY, $legacy ) ) return true;
    }

    return false;
}

// Save meta
function qdm_save_meta( int $post_id, array $data ): void {
    update_post_meta( $post_id, '_listing_price',       sanitize_text_field( $data['price']       ?? '' ) );
    update_post_meta( $post_id, '_listing_year',        sanitize_text_field( $data['year']        ?? '' ) );
    update_post_meta( $post_id, '_listing_mileage',     sanitize_text_field( $data['mileage']     ?? '' ) );
    update_post_meta( $post_id, '_listing_vin',         sanitize_text_field( $data['vin']         ?? '' ) );
    update_post_meta( $post_id, '_listing_color',       sanitize_text_field( $data['color']       ?? '' ) );
    update_post_meta( $post_id, '_listing_stock_no',    sanitize_text_field( $data['stock_no']    ?? '' ) );
    update_post_meta( $post_id, '_listing_engine_size', sanitize_text_field( $data['engine_size'] ?? '' ) );
    update_post_meta( $post_id, '_listing_door',        sanitize_text_field( $data['doors']       ?? '' ) );
    update_post_meta( $post_id, '_listing_cylinder',    sanitize_text_field( $data['cylinders']   ?? '' ) );
    update_post_meta( $post_id, '_listing_carfax_url',  esc_url_raw( $data['carfax_url']          ?? '' ) );
}

// Save taxonomy terms
function qdm_save_terms( int $post_id, array $data ): void {
    $map = [
        'make'         => 'listing_make',
        'model'        => 'listing_model',
        'condition'    => 'listing_condition',
        'transmission' => 'listing_transmission',
        'body_type'    => 'listing_type',
        'fuel_type'    => 'listing_fuel_type',
        'drive_type'   => 'listing_drive_type',
    ];
    foreach ( $map as $field => $taxonomy ) {
        if ( ! empty( $data[ $field ] ) ) {
            wp_set_object_terms( $post_id, strtolower( $data[ $field ] ), $taxonomy );
        }
    }
    if ( ! empty( $data['doors'] ) ) {
        wp_set_object_terms( $post_id, $data['doors'] . '-doors', 'listing_door' );
    }
    if ( ! empty( $data['features'] ) && is_array( $data['features'] ) ) {
        $feature_slugs = array_map( 'sanitize_title', $data['features'] );
        wp_set_object_terms( $post_id, $feature_slugs, 'listing_feature' );
    }
}

// Set photos
function qdm_set_photos( int $post_id, array $photos ): void {
    if ( empty( $photos ) ) return;

    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $attach_ids = [];

    foreach ( $photos as $photo ) {
        if ( empty( $photo['data'] ) || empty( $photo['name'] ) ) continue;

        $binary = base64_decode( $photo['data'], true );
        if ( $binary === false ) continue;

        $mime     = sanitize_mime_type( $photo['type'] ?? 'image/jpeg' );
        $name     = sanitize_file_name( $photo['name'] );
        $tmp_path = wp_tempnam( $name );

        file_put_contents( $tmp_path, $binary );

        $file_array = [
            'name'     => $name,
            'type'     => $mime,
            'tmp_name' => $tmp_path,
            'error'    => 0,
            'size'     => strlen( $binary ),
        ];

        $sideload = wp_handle_sideload( $file_array, [ 'test_form' => false, 'test_size' => true ] );

        if ( isset( $sideload['error'] ) ) {
            @unlink( $tmp_path );
            continue;
        }

        $attach_id = wp_insert_attachment( [
            'post_mime_type' => $sideload['type'],
            'post_title'     => preg_replace( '/\.[^.]+$/', '', $name ),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_parent'    => $post_id,
        ], $sideload['file'], $post_id );

        if ( is_wp_error( $attach_id ) ) continue;

        $attach_data = wp_generate_attachment_metadata( $attach_id, $sideload['file'] );
        wp_update_attachment_metadata( $attach_id, $attach_data );
        wp_update_post( [ 'ID' => $attach_id, 'post_parent' => $post_id ] );

        $attach_ids[] = $attach_id;
    }

    if ( empty( $attach_ids ) ) return;

    set_post_thumbnail( $post_id, $attach_ids[0] );

    $gallery_data = [];
    foreach ( $attach_ids as $index => $id ) {
        if ( $index === 0 ) continue;
        $url = wp_get_attachment_url( $id );
        if ( $url ) {
            $gallery_data[ $id ] = $url;
        }
    }
    update_post_meta( $post_id, '_listing_gallery', $gallery_data );
    update_post_meta( $post_id, 'listing_gallery',  $gallery_data );

    for ( $i = 0; $i < 10; $i++ ) {
        delete_post_meta( $post_id, '_listing_gallery_' . $i );
    }
    foreach ( $attach_ids as $index => $id ) {
        if ( $index === 0 ) continue;
        update_post_meta( $post_id, '_listing_gallery_' . ( $index - 1 ), $id );
    }
    update_post_meta( $post_id, '_listing_gallery_count', count( $attach_ids ) - 1 );
}

// Inject Carfax button after description
add_action( 'wp_footer', function () {
    if ( ! is_singular( 'listing' ) ) return;
    $post_id    = get_the_ID();
    $carfax_url = get_post_meta( $post_id, '_listing_carfax_url', true );
    if ( empty( $carfax_url ) ) return;
    ?>
    <style>
        .qdm-carfax-wrap {
            margin-top: 20px;
            margin-bottom: 10px;
        }
        .qdm-carfax-btn {
            display: flex;
            width: 100%;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px 28px;
            background: linear-gradient(135deg, #e65c00, #f9a825);
            color: #fff !important;
            font-size: 15px;
            font-weight: 700;
            text-decoration: none !important;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(230,92,0,0.35);
            letter-spacing: 0.3px;
            transition: opacity 0.2s, transform 0.15s;
        }
        .qdm-carfax-btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
            color: #fff !important;
        }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var desc = document.querySelector('div.listing-detail-description');
        if ( ! desc ) return;
        var wrap = document.createElement('div');
        wrap.className = 'qdm-carfax-wrap';
        wrap.innerHTML = '<a href="<?php echo esc_url( $carfax_url ); ?>" target="_blank" rel="noopener noreferrer" class="qdm-carfax-btn">&#x1F4CB; View Carfax Report</a>';
        desc.parentNode.insertBefore( wrap, desc.nextSibling );
    });
    </script>
    <?php
} );

// CREATE
function qdm_create_listing( $request ) {
    $data = $request->get_json_params();
    $post_id = wp_insert_post( [
        'post_title'   => sanitize_text_field( $data['title'] ?? '' ),
        'post_content' => wp_kses_post( $data['description'] ?? '' ),
        'post_status'  => 'publish',
        'post_type'    => 'listing',
    ], true );
    if ( is_wp_error( $post_id ) ) {
        return new WP_REST_Response( [ 'message' => $post_id->get_error_message() ], 500 );
    }
    qdm_save_meta( $post_id, $data );
    qdm_save_terms( $post_id, $data );
    qdm_set_photos( $post_id, $data['photos'] ?? [] );
    return new WP_REST_Response( [ 'id' => $post_id, 'link' => get_permalink( $post_id ) ], 201 );
}

// UPDATE
function qdm_update_listing( $request ) {
    $data    = $request->get_json_params();
    $post_id = intval( $data['post_id'] ?? 0 );
    if ( ! $post_id || ! get_post( $post_id ) ) {
        return new WP_REST_Response( [ 'message' => 'Invalid post_id' ], 400 );
    }
    wp_update_post( [
        'ID'           => $post_id,
        'post_title'   => sanitize_text_field( $data['title'] ?? '' ),
        'post_content' => wp_kses_post( $data['description'] ?? '' ),
        'post_status'  => 'publish',
    ] );
    qdm_save_meta( $post_id, $data );
    qdm_save_terms( $post_id, $data );
    qdm_set_photos( $post_id, $data['photos'] ?? [] );
    return new WP_REST_Response( [ 'id' => $post_id, 'link' => get_permalink( $post_id ) ], 200 );
}

// DELETE
function qdm_delete_listing( $request ) {
    $data    = $request->get_json_params();
    $post_id = intval( $data['post_id'] ?? 0 );
    if ( ! $post_id || ! get_post( $post_id ) ) {
        return new WP_REST_Response( [ 'message' => 'Invalid post_id' ], 400 );
    }
    wp_delete_post( $post_id, true );
    return new WP_REST_Response( [ 'deleted' => true, 'id' => $post_id ], 200 );
}
