<?php
/**
 * Plugin Name: Dumont API
 * Description: Custom REST API endpoint for Dumont DMS listing sync.
 * Version:     2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'DUMONT_API_KEY', 'DUMONT2026SECRET' );

// CORS headers
add_action( 'rest_api_init', function () {
    remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
    add_filter( 'rest_pre_serve_request', function ( $value ) {
        header( 'Access-Control-Allow-Origin: *' );
        header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
        header( 'Access-Control-Allow-Headers: Content-Type, X-Dumont-Key' );
        return $value;
    } );
}, 15 );

// Handle OPTIONS preflight
add_action( 'init', function () {
    if ( isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
        header( 'Access-Control-Allow-Origin: *' );
        header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
        header( 'Access-Control-Allow-Headers: Content-Type, X-Dumont-Key' );
        exit( 0 );
    }
} );

// Route registration
add_action( 'rest_api_init', function () {
    $auth = 'dumont_check_key';
    register_rest_route( 'dumont/v1', '/listing', [
        'methods'             => 'POST',
        'callback'            => 'dumont_create_listing',
        'permission_callback' => $auth,
    ] );
    register_rest_route( 'dumont/v1', '/listing/update', [
        'methods'             => 'POST',
        'callback'            => 'dumont_update_listing',
        'permission_callback' => $auth,
    ] );
    register_rest_route( 'dumont/v1', '/listing/delete', [
        'methods'             => 'POST',
        'callback'            => 'dumont_delete_listing',
        'permission_callback' => $auth,
    ] );
} );

// Auth
function dumont_check_key( $request ) {
    if ( isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) return true;
    return $request->get_header( 'X-Dumont-Key' ) === DUMONT_API_KEY;
}

// Save meta
function dumont_save_meta( int $post_id, array $data ): void {
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
function dumont_save_terms( int $post_id, array $data ): void {
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
function dumont_set_photos( int $post_id, array $photos ): void {
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
        .dumont-carfax-wrap {
            margin-top: 20px;
            margin-bottom: 10px;
        }
        .dumont-carfax-btn {
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
        .dumont-carfax-btn:hover {
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
        wrap.className = 'dumont-carfax-wrap';
        wrap.innerHTML = '<a href="<?php echo esc_url( $carfax_url ); ?>" target="_blank" rel="noopener noreferrer" class="dumont-carfax-btn">&#x1F4CB; View Carfax Report</a>';
        desc.parentNode.insertBefore( wrap, desc.nextSibling );
    });
    </script>
    <?php
} );

// CREATE
function dumont_create_listing( $request ) {
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
    dumont_save_meta( $post_id, $data );
    dumont_save_terms( $post_id, $data );
    dumont_set_photos( $post_id, $data['photos'] ?? [] );
    return new WP_REST_Response( [ 'id' => $post_id, 'link' => get_permalink( $post_id ) ], 201 );
}

// UPDATE
function dumont_update_listing( $request ) {
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
    dumont_save_meta( $post_id, $data );
    dumont_save_terms( $post_id, $data );
    dumont_set_photos( $post_id, $data['photos'] ?? [] );
    return new WP_REST_Response( [ 'id' => $post_id, 'link' => get_permalink( $post_id ) ], 200 );
}

// DELETE
function dumont_delete_listing( $request ) {
    $data    = $request->get_json_params();
    $post_id = intval( $data['post_id'] ?? 0 );
    if ( ! $post_id || ! get_post( $post_id ) ) {
        return new WP_REST_Response( [ 'message' => 'Invalid post_id' ], 400 );
    }
    wp_delete_post( $post_id, true );
    return new WP_REST_Response( [ 'deleted' => true, 'id' => $post_id ], 200 );
}
