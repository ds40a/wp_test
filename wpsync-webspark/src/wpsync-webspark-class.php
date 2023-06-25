<?php

class WpSyncWebspark
{
    const PRODUCTS_URL = 'https://wp.webspark.dev/wp-api/products';

    const PRODUCTS_LIMIT = 2000; // ТЗ Максимальное кол-во товаров в базе: 2,000.

    const IN_PROGRESS = 'wp_webspark_sync_in_progress';

    public static function enable_schedule() {
        if (!wp_next_scheduled('wpschedule_sync')) {
            // ТЗ Обновление товарной базы должно происходить каждый час.
            wp_schedule_event(time(), 'hourly', 'wpschedule_sync');
        }
    }

    public static function disable_schedule() {
        wp_clear_scheduled_hook('wpschedule_sync');
    }

    /**
     * Main action. Called by WP scheduler.
     *
     * @return bool
     * @throws WC_Data_Exception
     */
    public static function sync_action(): bool {
        if (!class_exists( 'Woocommerce')) {
            return false;
        }

        // if more than 35 seconds have passed since the last update, we consider that the update has been completed
        // otherwise the update continues
        if ((time() - get_option(self::IN_PROGRESS) ?: 0) < 35) {
            // update in progress - exit
            return false;
        }

        $args = [
            'timeout' => 30, // ТЗ 80% - будет задержка в ответе (до 20с);
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ];
        $response = wp_remote_get(self::PRODUCTS_URL, $args);
        if (is_wp_error($response)) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response));

        if (empty($data) or !is_array($data->data) or count($data->data) === 0) {
            return false;
        }

        if (true === $data->error) {
            return false;
        }

        $skus = array_map(function ($el) {
            return $el->sku;
        }, $data->data);

        // Требование ТЗ
        self::remove_unused_products($skus);

        self::cleanup_unused_media();

        foreach ($data->data as $product) {
            if (self::validate_external_data($product)) {
                self::create_or_update_product($product);
                update_option(self::IN_PROGRESS, time());
            }
        }

        // ТЗ Максимальное кол-во товаров в базе: 2,000.
        self::apply_limit();

        return true;
    }

    /**
     * ТЗ. В случае, если какой-либо из товаров не приходит,
     * он недоступен для заказа и должен быть удален с сайта.
     *
     * @param array $used_products_sku
     */
    public static function remove_unused_products(array $used_products_sku)
    {
        $actual_products_ids = [];

        foreach ($used_products_sku as $sku) {
            $exists_product_id = wc_get_product_id_by_sku($sku);
            if ($exists_product_id) {
                $actual_products_ids[] = $exists_product_id;
            }
        }

        $args = [
            'exclude' => $actual_products_ids,
            'return'  => 'ids',
            'limit'   => -1,
        ];

        $products = wc_get_products($args);

        if (!empty($products)) {
            foreach ($products as $id) {
                wp_delete_post($id);
            }
        }
    }

    /**
     * Get image id or false if error occurs
     *
     * @param string $image_url Image URL.
     * @return int|boolean
     */
    public static function get_image(string $image_url ): bool|int {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $image_url);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        curl_exec($ch);

        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

        $image_id = media_sideload_image( $finalUrl, 0, '', 'id' );

        if ( is_wp_error( $image_id ) ) {
            return false;
        } else {
            return $image_id;
        }
    }

    /**
     * Create or update product
     *
     * @param object $item
     * @return int
     * @throws WC_Data_Exception
     */
    public static function create_or_update_product(object $item): int {
        $product_id = wc_get_product_id_by_sku( $item->sku );
        if ($product_id) {
            $product = new WC_Product_Simple( $product_id );
        } else {
            $product = new WC_Product_Simple();
        }
        $product->set_sku($item->sku);
        $product->set_name($item->name);
        $product->set_description($item->description);
        $product->set_regular_price($item->price);
        $product->set_stock_quantity($item->in_stock);
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');
        $product->set_stock_status('in_stock');
        $image_id = self::get_image($item->picture);
        if ($image_id) {
            $product->set_image_id($image_id);
        }

        return $product->save();
    }

    /**
     *
     * @param int $limit
     */
    public static function apply_limit($limit = self::PRODUCTS_LIMIT)
    {
        $query = new WC_Product_Query( array(
            'limit' => $limit,
            'orderby' => 'id',
            'order' => 'DESC',
            'return' => 'ids',
        ) );
        $fresh_products = $query->get_products();
        if (empty($products) or count($fresh_products) <= $limit) {
            return;
        }

        $args = array(
            'exclude' => $fresh_products,
            'return'  => 'ids',
        );

        $products = wc_get_products($args);
        if (!empty($products)) {
            foreach ($products as $id) {
                wp_delete_post($id);
            }
        }
    }

    /**
     * Check data structure
     * @param $data Object
     *  {
     *     "sku": "a1123c6d-2307-4c6c-8d15-a174d4cd031e",
     *     "name": "Wine - Red, Mosaic Zweigelt",
     *     "description": "Pain in joint, upper arm",
     *     "price": "$32.52",
     *     "picture": "http://dummyimage.com/229x121.jpg/5fa2dd/ffffff",
     *     "in_stock": 164
     * }
     * @return bool
     */
    public static function validate_external_data($data): bool {
        $fields = [
            'sku',
            'name',
            'description',
            'price',
            'picture',
            'in_stock',
        ];
            $is_ok = array_reduce(
                $fields,
                function($result, $el) use ($data) {
                    return $result and property_exists($data, $el);
                },
                true
            );
            if (!$is_ok) {
                error_log(
                    sprintf(
                        'wpsync-webspark: external data structure is mismatch%s%s',
                        PHP_EOL,
                        var_export($data, true)
                    )
                );
                return false;
            }

        return true;
    }

    /**
     *  Remove unused media
     */
    public static function cleanup_unused_media()
    {
        $qStr = <<<QSTR
SELECT id
FROM   wp_posts i
WHERE i.post_type = 'attachment' AND
  NOT EXISTS (SELECT * FROM wp_posts p WHERE p.ID = i.post_parent) AND
  NOT EXISTS (SELECT * FROM wp_postmeta pm WHERE pm.meta_key = '_thumbnail_id' AND pm.meta_value = i.ID)
QSTR;
        global $wpdb;
        if ($attachments = $wpdb->get_results($qStr)) {
            foreach ($attachments as $attachment){
                $attachment_path = get_attached_file( $attachment->id);
                if (!preg_match('~wp-content/uploads/[0-9]+/~', $attachment_path)) {
                    continue;
                }
                wp_delete_attachment($attachment->id, true);
                @unlink($attachment_path);
            }
        }
    }
}
