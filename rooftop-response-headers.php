<?php
/*
Plugin Name: Rooftop CMS - Response Headers
Description: Add headers to the API reponse to aid in caching
Version: 0.0.1
Author: Error Studio
Author URI: http://errorstudio.co.uk
Plugin URI: http://errorstudio.co.uk
Text Domain: rooftop-response-headers
*/

add_action( 'rest_init', function() {
    $a = func_get_args();
    $f = 1;
} );

?>
