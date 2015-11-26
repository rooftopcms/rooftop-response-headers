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

class Rooftop_Response_Headers {
    private $options, $post_data;

    function __construct() {
        /*
         * the default options can (and should) be altered by implementing the
         * rooftop_response_header_options filter in your rooftop theme functions.php
         */
        $default_options = array(
            'add_etag_header' => true,
            'add_last_modified_header' => true,
            'add_cache_control_header' => true,
            'generate_weak_etag' => false,
            'cache_max_age_seconds' => 0
        );

        add_action( 'rest_post_dispatch', function( $response, $handler, $request) use($default_options) {
            $this->options = apply_filters( 'rooftop_response_header_options', $default_options );

            if($response->status != 200) return $response;

            $this->post_data = $response->data;

            if( array_key_exists( 'id', $response->data ) ) {
                // single
                $this->rooftop_set_headers_for_entity();
            }else {
                // collection
                $this->rooftop_set_headers_for_collection();
            }

            return $response;
        }, 99, 3);
    }

    /**
     * Add headers for a single post object
     *
     * @internal param $options
     * @internal param $post_data
     */
    function rooftop_set_headers_for_entity() {
        $this->generate_headers();
    }

    /**
     * Add headers for a collection of posts
     *
     * @internal param $options
     * @internal param $post_data
     */
    function rooftop_set_headers_for_collection() {
        $this->generate_headers();
    }

    /**
     * For each option the developer has requested added to the response (implement the
     * rooftop_header_options to alter) generate the header and send it back with the response
     */
    function generate_headers() {
        $headers = [];

        if( $this->options['add_etag_header'] === true ) {
            $headers['ETag'] = $this->generate_etag();
        }

        if( $this->options['add_cache_control_header'] === true ) {
            $headers['Cache-Control'] = $this->generate_cache_control();
            $headers['Pragma'] = $this->generate_pragma_header();
        }else {
            $headers['Cache-Control'] = 'no-cache, must-revalidate, max-age=0';
        }

        if( $this->options['add_last_modified_header'] === true && ! is_array($this->post_data) ) {
            $headers['Last-Modified'] = $this->generate_last_modified();
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
     * @internal param $post_data
     * @internal param $mtime
     * @internal param $options
     * @return String
     */
    function generate_etag() {
        global $wp;

        /**
         * if the post data has an 'id' key, then it is a single resource (rather than a collection).
         * generate the hash values for this single post, else we collect the same attributes from the
         * array of post data and stringify them. this ensures we're creating the same ETag for a collection of posts
         */
        if(array_key_exists('id', $this->post_data)) {
            $hash_date = $this->post_data['date'];
            $hash_guid = serialize($this->post_data['guid']);
            $hash_id   = $this->post_data['id'];
            $hash_mtime= strtotime($this->post_data['modified_gmt']);
        }else {
            $hash_date = [];
            $hash_guid = [];
            $hash_id   = [];
            $hash_mtime = [];

            array_map(function($data) use(&$hash_date, &$hash_guid, &$hash_id, &$hash_mtime) {
                array_push($hash_date, $data['date']);
                array_push($hash_id, $data['id']);

                $modified = $this->get_modified_value_for_data($data);
                array_push($hash_mtime, strtotime($modified));

                $guid = array_key_exists('guid', $data) ? $data['guid'] : implode('-', [$data['id'], $data['type'], $data['status']]);
                array_push($hash_guid, $guid);
            }, $this->post_data);

            $hash_date = serialize($hash_date);
            $hash_guid = serialize($hash_guid);
            $hash_id   = serialize($hash_id);
            $hash_mtime= serialize($hash_mtime);
        }

        $hashify = array( $hash_mtime, $hash_date, $hash_guid, $hash_id, serialize( $wp->query_vars ) );
        $etag = sha1( serialize( $hashify ) );

        if( $this->options['generate_weak_etag'] ) {
            return sprintf( 'W/"%s"', $etag );
        }else {
            return sprintf( '"%s"', $etag );
        }
    }

    /**
     * Generate a Cache-Control header
     *
     * @internal param $post_data
     * @internal param $mtime
     * @internal param $options
     * @return String
     */
    function generate_cache_control( ) {
        $default_cache_control_template = 'public, max-age=%s';
        $cache_control_template = apply_filters( 'rooftop_cache_control_header_format', $default_cache_control_template );
        $header_cache_control_value = sprintf( $cache_control_template, $this->options['cache_max_age_seconds'] );
        return $header_cache_control_value;
    }

    /**
     * Generate a Last-Modified header
     *
     * @internal param $post_data
     * @internal param $mtime
     * @internal param $options
     * @return String
     */
    function generate_pragma_header() {
        if( intval($this->options['cache_max_age_seconds']) > 0 ) {
            return 'public';
        }else {
            return 'no-cache';
        }
    }

    /**
     * Generate a Last-Modified header
     *
     * @internal param $post_data
     * @internal param $mtime
     * @internal param $options
     * @return mixed
     */
    function generate_last_modified() {
        $mtime = strtotime($this->post_data['modified_gmt']);
        $last_modified_value = str_replace( '+0000', 'GMT', gmdate('r', $mtime) );

        return $last_modified_value;
    }

    private function get_modified_value_for_data($data) {
        if( array_key_exists('modified_gmt', $data) ) {
            $modified = strtotime($data['modified_gmt']);
        }elseif( array_key_exists('posst', $data) ) {
            $post = get_post($data['post']);
            $modified = strtotime($post->post_modified_gmt);
        }else {
            $modified = mktime();
        }

        return $modified;
    }
}
new Rooftop_Response_Headers();

?>
