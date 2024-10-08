<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$table_name = $wpdb->prefix . 'wc_product_statistics';

$wpdb->query("DROP TABLE IF EXISTS {$table_name}");

//delete options
delete_option('wc_product_fake_view_count');
delete_option('wc_product_fake_purchase_count');
