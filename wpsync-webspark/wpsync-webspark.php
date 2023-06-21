<?php

/*
Plugin Name: wpsync-webspark
Description: A test plugin
*/

function create_the_custom_table() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $table_name = $wpdb->prefix . 'test_products';

    $sql = "CREATE TABLE " . $table_name . " (
	id int(11) NOT NULL AUTO_INCREMENT,
	sku VARCHAR(100) NOT NULL,
	name VARCHAR(300) NOT NULL,
	description text NULL,
	price varchar(15),
	picture VARCHAR(100) NULL,
	in_stock int,
	PRIMARY KEY  (id),
	KEY sku (sku)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

register_activation_hook(__FILE__, 'create_the_custom_table');

if( ! wp_next_scheduled( 'wpsync_webspark_hook_1') ) {
    wp_schedule_event( time(), 'hourly', 'wpsync_webspark_hook_1' );
}

add_action( 'wpsync_webspark_hook_1', 'sync_db', 10, 0 );

function sync_db() {
    require_once ABSPATH . WPINC . '/http.php';
    $request = wp_remote_get('https://wp.webspark.dev/wp-api/products');
    if( is_wp_error($request) ) {
        return;
    }

    $body = wp_remote_retrieve_body($request);

    $data = json_decode($body);

    if(empty($data) or !is_array($data->data) or count($data->data) === 0) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'test_products';

    $wpdb->query("UPDATE $table_name SET in_stock = 0");
    foreach ($data->data as $rec) {
        if (count($wpdb->get_results($wpdb->prepare("SELECT 1 FROM $table_name WHERE sku = %s", $rec->sku)))) {
            $sqlStr = $wpdb->prepare(
                "UPDATE $table_name SET name=%s, description = %s, price = %s, picture = %s, in_stock = %d WHERE sku = %s",
                $rec->name,
                $rec->description,
                $rec->price,
                $rec->picture,
                $rec->in_stock,
                $rec->sku
            );
        } else {
            $sqlStr = $wpdb->prepare(
                "INSERT INTO $table_name (sku, name, description, price, picture, in_stock) VALUES (%s, %s, %s, %s, %s, %d)",
                $rec->sku,
                $rec->name,
                $rec->description,
                $rec->price,
                $rec->picture,
                $rec->in_stock
            );
        }

        $wpdb->query($sqlStr);
    }

    // В случае, если какой-либо из товаров не приходит, он недоступен для заказа и должен быть удален с сайта.
    $wpdb->query("DELETE FROM $table_name WHERE in_stock = 0");

    // Максимальное кол-во товаров в базе: 2,000.
    $result = $wpdb->get_results("SELECT id FROM $table_name ORDER BY id DESC LIMIT 1 OFFSET 2000");
    if (count($result)) {
        $wpdb->query("DELETE FROM $table_name WHERE id <= {$result[0]->id}");
    }
}

//sync_db();