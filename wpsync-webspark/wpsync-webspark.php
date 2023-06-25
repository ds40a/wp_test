<?php

/*
Plugin Name: wpsync-webspark
Description: A test plugin
*/


if (!defined( 'ABSPATH')) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'src/wpsync-webspark-class.php';

register_activation_hook(__FILE__, 'wpsync_webspark_activation_action');
if ( ! function_exists( 'wpsync_webspark_activation_action' ) ) {
    function wpsync_webspark_activation_action() {
        WpSyncWebspark::enable_schedule();
    }
}

register_activation_hook(__FILE__, 'wpsync_webspark_deactivation_action');
if ( ! function_exists( 'wpsync_webspark_deactivation_action' ) ) {
    function wpsync_webspark_deactivation_action() {
        WpSyncWebspark::disable_schedule();
    }
}

add_action('wpshedule_sync', array( 'WpSyncWebspark', 'sync_action'));
