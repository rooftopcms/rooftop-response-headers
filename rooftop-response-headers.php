<?php
/*
Plugin Name: Rooftop CMS - Response Headers
Description: Add headers to the API reponse to aid in caching. Thanks to https://github.com/gnotaras/wordpress-add-headers
Version: 0.0.1
Author: Error Studio
Author URI: http://errorstudio.co.uk
Plugin URI: http://errorstudio.co.uk
Text Domain: rooftop-response-headers
*/

/**
 * For each option the developer has requested added to the response (implement the
 * rooftop_header_options to alter) generate the header and send it back with the response
 *
 * @param $options
 * @param $mtime
 * @param $post_data
 */
function generate_headers( $options, $mtime, $post_data ) {
    $headers = [];

    if( $options['add_etag_header'] === true ) {
        $headers['ETag'] = generate_etag( $post_data, $mtime, $options );
    }
    if( $options['add_cache_control_header'] === true ) {
        $headers['Cache-Control'] = generate_cache_control( $post_data, $mtime, $options );
        $headers['Pragma'] = generate_pragma_header( $post_data, $mtime, $options );
    }else {
        $headers['Cache-Control'] = 'no-cache, must-revalidate, max-age=0';
    }
    if( $options['add_last_modified_header'] === true ) {
        $headers['Last-Modified'] = generate_last_modified( $post_data, $mtime, $options );
    }

    if( headers_sent() ) {
        return;
    }

    foreach( $headers as $header_name => $header_value ) {
        if( ! empty($header_name) && ! empty($header_value) ) {
            header( sprintf( '%s: %s', $header_name, $header_value) );
        }
    }
}

/**
 * Generate an ETag header
 *
 * @param $post_data
 * @param $mtime
 * @param $options
 * @return String
 */
function generate_etag( $post_data, $mtime, $options ) {
    global $wp;

    $hashify = array( $mtime, $post_data['date'], serialize( $post_data['guid'] ), $post_data['id'], serialize( $wp->query_vars ) );
    $etag = sha1( serialize( $hashify ) );

    if( $options['generate_weak_etag'] ) {
        return sprintf( 'W/"%s"', $etag );
    }else {
        return sprintf( '"%s"', $etag );
    }
}

/**
 * Generate a Cache-Control header
 *
 * @param $post_data
 * @param $mtime
 * @param $options
 * @return String
 */
function generate_cache_control( $post_data, $mtime, $options ) {
    $default_cache_control_template = 'public, max-age=%s';
    $cache_control_template = apply_filters( 'rooftop_cache_control_header_format', $default_cache_control_template );
    $header_cache_control_value = sprintf( $cache_control_template, $options['cache_max_age_seconds'] );
    return $header_cache_control_value;
}

/**
 * Generate a Last-Modified header
 *
 * @param $post_data
 * @param $mtime
 * @param $options
 * @return String
 */
function generate_pragma_header( $post_data, $mtime, $options ) {
    if( intval($options['cache_max_age_seconds']) > 0 ) {
        return 'public';
    }else {
        return 'no-cache';
    }
}

/**
 * Generate a Last-Modified header
 *
 * @param $post_data
 * @param $mtime
 * @param $options
 * @return mixed
 */
function generate_last_modified( $post_data, $mtime, $options ) {
    $last_modified_value = str_replace( '+0000', 'GMT', gmdate('r', $mtime) );

    return $last_modified_value;
}

/**
 * Add headers for a single post object
 *
 * @param $options
 * @param $post_data
 */
function rooftop_set_headers_for_entity( $options, $post_data ) {
    $mtime = strtotime($post_data['modified_gmt']);
    generate_headers( $options, $mtime, $post_data );
}

/**
 * Add headers for a collection of posts
 *
 * @param $options
 * @param $post_data
 */
function rooftop_set_headers_for_collection( $options, $post_data ) {
    $mtime = strtotime($post_data['modified_gmt']);
    generate_headers( $options, $mtime, $post_data );
}

add_action( 'rest_post_dispatch', function( $response, $handler, $request ) {
    $default_options = array(
        'add_etag_header' => true,
        'add_last_modified_header' => true,
        'add_cache_control_header' => true,
        'generate_weak_etag' => false,
        'cache_max_age_seconds' => 0
    );
    $options = apply_filters( 'rooftop_header_options', $default_options );

    if( array_key_exists( 'id', $response->data ) ) {
        // single
        $post_data = $response->data;
        rooftop_set_headers_for_entity( $options, $post_data );
    }else {
        // collection
        $post_data = $response->data[0];
        rooftop_set_headers_for_collection( $options, $post_data );
    }

    return $response;
}, 99, 3);

?>
