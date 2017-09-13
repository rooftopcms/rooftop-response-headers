<?php
/*
Plugin Name: Rooftop CMS - Response Headers
Description: Add headers to the API reponse to aid in caching. Thanks to https://github.com/gnotaras/wordpress-add-headers
Version: 1.2.2
Author: RooftopCMS
Author URI: https://rooftopcms.com
Plugin URI: https://github.com/rooftopcms/rooftop-response-headers
Text Domain: rooftop-response-headers
*/

class Rooftop_Response_Headers {
    private $options, $post_data;

    private $redis, $redis_key_prefix;

    function __construct() {
        $this->redis = new Predis\Client([
            'scheme' => 'tcp',
            'host'   => REDIS_HOST,
            'port'   => REDIS_PORT,
            'password' => REDIS_PASSWORD
        ]);
        $this->redis_key_prefix = 'site_id:'.get_current_blog_id().':etags:';

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

        add_filter( 'save_post', function($post_id) {
            $post = get_post($post_id);

            $id_key = $this->redis_key_prefix . $post->post_type . 's/' . $post_id;
            $slug_key = $this->redis_key_prefix . $post->post_type . 's/' . $post->post_name;
            $this->redis->del([$id_key]);
            $this->redis->del([$slug_key]);
        }, 20, 1);

        add_action( 'init', function($query) use ($default_options) {
            $this->options = apply_filters( 'rooftop_response_header_options', $default_options );

            $match_etag = array_key_exists('HTTP_IF_NONE_MATCH', $_SERVER) ? preg_replace('/("|\\\)/', '', $_SERVER['HTTP_IF_NONE_MATCH']) : null;

            // if we have a weak etag, strip it down to a regular one so that it matches our key (unless we're generating weak etags in our config)
            if(preg_match('/^W\//', $match_etag) && !$this->options['generate_weak_etag']) {
                $match_etag = preg_replace('/(^W\/|"|\\\)/', '', $match_etag);
            }

            // parse the query string into a $query_string_params array
            parse_str( @parse_url($_SERVER['REQUEST_URI'])['query'], $query_string_params );

            /*
             * ensure the request is specific to a resource by checking that we're hitting /some-path/id (id = \d+) or
             * that we're expecting 1 result in the response by passing the per_page parameter value 1
             */
            if( ( preg_match('/([^\/]+)\/\d+$/', $_SERVER['REQUEST_URI'] ) || @$query_string_params['per_page'] == 1 ) && $match_etag ) {
                $request = parse_url( $_SERVER['REQUEST_URI'] );

                if( array_key_exists( 'query', $request ) ) { // filter query with limit of 1
                    $post_type = array_reverse( preg_split( '/\//', $request['path'] ) )[0];
                    $post_slug = @$query_string_params['filter']['name'];
                    $key = $this->redis_key_prefix . $post_type . '/' . $post_slug;
                }else { // resource query, specifying the resource ID
                    list($post_id, $post_type) = array_reverse( preg_split( '/\//', $request['path'] ) );
                    $key = $this->redis_key_prefix . $post_type . '/' . $post_id;
                }

                $matched = $this->redis->get($key);

                /**
                 * if we've been given an ETag to match against, and the post lookup
                 * has a matching etag, we can return an empty response and a 304 http status
                 */
                if( $matched && $matched == $match_etag ) {
                    status_header(304);

                    if( $this->options['generate_weak_etag'] ) {
                        $etag = sprintf( 'W/"%s"', $matched );
                    }else {
                        $etag = sprintf( '"%s"', $matched );
                    }

                    header( 'ETag: ' . $etag );
                    header( 'X-Powered-By: Rooftop CMS https://www.rooftopcms.com' );
                    exit;
                }
            }
        }, 1);

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

                $page = $request['page'] ? (int)$request['page'] : 1;
                $per_page = $request['per_page'] ? (int)$request['per_page'] : 10;
                $response->header( 'X-WP-Page', $page );
                $response->header( 'X-WP-Per-Page', $per_page );
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
        if( $this->is_collection($this->post_data) || $this->is_resourceful($this->post_data) ) {
            $this->generate_headers();
        }
    }

    /**
     * Add headers for a collection of posts
     *
     * @internal param $options
     * @internal param $post_data
     */
    function rooftop_set_headers_for_collection() {
        if( count($this->post_data) !== 1 ) {
            $this->options['add_etag_header'] = true;
        }

        if( $this->is_collection($this->post_data) || $this->is_resourceful($this->post_data) ) {
            $this->generate_headers();
        }
    }

    /**
     * For each option the developer has requested added to the response (implement the
     * rooftop_header_options to alter) generate the header and send it back with the response
     */
    function generate_headers() {
        $headers = [];

        if( $this->options['add_etag_header'] === true ) {
            $post = (is_array($this->post_data) && !array_key_exists('id', $this->post_data)) ? array_values($this->post_data)[0] : $this->post_data;
            $post_type = $this->getType($post);
            $post_id   = $this->getId($post);

            $etag = $this->generate_etag();

            if( $this->options['generate_weak_etag'] ) {
                $headers['ETag'] = sprintf( 'W/"%s"', $etag );
            }else {
                $headers['ETag'] = sprintf( '"%s"', $etag );
            }

            $id_key = $this->redis_key_prefix . $post_type . 's/' . $post_id;
            $slug_key = $this->redis_key_prefix . $post_type . 's/' . $post['slug'];
            $this->redis->set($id_key, $etag);
            $this->redis->set($slug_key, $etag);
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
     * Returns the content/menu/taxonomy type/name of the given resource
     *
     * @param $data
     * @return mixed
     */
    function getType($data) {
        if(array_key_exists( 'type', $data) && gettype($data['type'])=="string") {
            $type = $data['type'];
        }elseif( array_key_exists( 'taxonomy', $data ) ){
            $type = $data['taxonomy'];
        }elseif( array_key_exists( 'name', $data ) ) {
            $type = $data['name'];
        }elseif( array_key_exists( 'term_id', $data ) ) {
            $type = $data['term_id'];
        }else {
            // if all else fails, return the resource name
            $path_parts = preg_split( '/\//', $_SERVER['REQUEST_URI'] );
            if($this->is_resourceful($data)) {
                $type = $path_parts[count($path_parts)-2];
            }else {
                $type = end($path_parts);
            }
            $type = preg_replace('/s$/', '', $type);
        }

        return $type;
    }

    /**
     * Returns the content/menu/taxonomy type/name of the given resource
     *
     * @param $data
     * @return mixed
     */
    function getId($data) {
        $id = http_build_query( (array)$data );

        if(array_key_exists( 'id', $data) ) {
            $id = $data['id'];
        }elseif( array_key_exists( 'ID', $data ) ) {
            $id = $data['ID'];
        }elseif( array_key_exists( 'taxonomy_id', $data ) ) {
            $id = $data['taxonomy_id'];
        }elseif( array_key_exists( 'term_id', $data ) ) {
            $id = $data['term_id'];
        }elseif( array_key_exists( 'slug', $data ) ) {
            $id = $data['slug'];
        }

        return $id;
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
        if( $this->is_collection( $this->post_data ) ) {
            if( $this->is_resourceful( array_values( $this->post_data )[0] ) ){
                $hashify = $this->values_for_resource_collection($this->post_data);
            }else {
                $hashify = $this->values_for_collection($this->post_data);
            }
        }elseif( $this->is_resourceful( $this->post_data ) ) {
            $hashify = $this->values_for_resource($this->post_data);
        }else {
            $hashify = $this->values_for_response($this->post_data);
        }

        $last_uri_segment = array_reverse(array_values(preg_split('/\//', $_SERVER['REQUEST_URI'])))[0];
        $etag = sha1( $last_uri_segment . '=' . serialize( $hashify ) );

        return $etag;
    }

    function values_for_resource($data) {
        $values = array();
        $values[] = @$data['id'];
        $values[] = @$data['date'];
        $values[] = serialize(@$data['guid']);
        $values[] = strtotime(@$data['modified_gmt']);

        return array_values($values);
    }
    function values_for_resource_collection($data) {
        $values = [];

        foreach($data as $resource_data) {
            $values[] = $this->values_for_resource($resource_data);
        }
        return array_values($values);
    }
    function values_for_response($data) {
        if( array_key_exists( 'routes', $data ) ) {
            $values = array_keys($data['routes']);
        }elseif( array_key_exists( 'items', $data ) ) { // wp-api request
            $values = array_map(function($i){
                $id = array_key_exists('ID', $i) ? $i['ID'] : $i['id'];
                return $id.':'.$i['title'];
            }, $data['items']);
        }else {
            if( is_array( $data ) ) {
                $values = array_values( $data );
            }else {
                $values = [$data];
            }
        }

        return $values;
    }
    function values_for_collection($data) {
        $values = [];

        foreach($data as $resource_data) {
            $values[] = $this->values_for_response($resource_data);
        }
        return array_values($values);
    }

    function get_request_id( $data ) {
        if( array_key_exists( 'ID', $data ) ) {
            return $data['ID'];
        }elseif( array_key_exists( 'id', $data ) ) {
            return $data['id'];
        }

        return null;
    }

    function is_collection( $data ) {
        $is_collection = false;

        if( count( $data ) ) {
            $first = array_values( $data )[0];
            if( is_array( $first ) ) {
                $first_attribute = array_values(array_keys(array_values($data)[0]))[0];
            }else {
                return false;
            }

            $all_have_attribute = array_unique(array_map(function($i) use ($first_attribute) {
                if( is_array( $i ) ) {
                    return array_key_exists($first_attribute, $i);
                }else {
                    return false;
                }
            }, $data));

            if( count( $all_have_attribute ) === 1 && array_values( $all_have_attribute )[0]=== true ) {
                $is_collection = true;
            }
        }

        return $is_collection;
    }

    function is_resourceful( $data ) {
        if( ( array_key_exists( 'id', $data ) || array_key_exists( 'ID', $data ) ) && array_key_exists( 'date', $data ) ) {
            return true;
        }
        return false;
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

    /**
     * If the post data doesn't have a modified_gmt (ie it is content that belongs to something else
     * that we generate a cache/ETag for) get the associated post and use its modified_gmt value
     *
     * @param $data
     * @return int
     */
    private function get_modified_value_for_data($data) {
        if( array_key_exists('modified_gmt', $data) ) {
            $modified = strtotime($data['modified_gmt']);
        }elseif( array_key_exists('post', $data) ) {
            $post = get_post($data['post']);
            $modified = strtotime($post->post_modified_gmt);
        }else {
            $modified = time();
        }

        return $modified;
    }
}
new Rooftop_Response_Headers();

?>
