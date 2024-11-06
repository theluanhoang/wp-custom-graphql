<?php
/*
     Plugin Name: WP Custom GraphQL Query
     Description: Plugin to add custom GraphQL fields for common properties.
     Version: 1.0
     Author: Kenny Truong
*/

require_once($_SERVER['DOCUMENT_ROOT'].'/wp-load.php');

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/categories/class-wcge-category.php';
require_once plugin_dir_path(__FILE__) . 'includes/tags/class-wcge-tags.php';
require_once plugin_dir_path(__FILE__) . 'includes/attributes/class-wcge-attributes.php';
require_once plugin_dir_path(__FILE__) . 'includes/variations/class-wcge-variations.php';
require_once plugin_dir_path(__FILE__) . 'includes/size-table/class-wcge-size-table.php';
require_once plugin_dir_path(__FILE__) . 'includes/products/class-wcge-products.php';
require_once plugin_dir_path(__FILE__) . 'includes/galleries/class-wcge-galleries.php';
require_once plugin_dir_path(__FILE__) . 'includes/orders/class-wcge-orders.php';
require_once plugin_dir_path(__FILE__) . 'includes/users/class-wcge-users.php';

function wcge_init_plugin() {

    // Attributes
    $attribute = new WCGE_Attribute();

    // Categories
    $category = new WCGE_Category();

    // Tags
    $tag = new WCGE_Tag();

    // Variations
    $variations = new WCGE_Variation();

    // SizeTable
    $sizeTable = new WCGE_SizeTable();

    // Product
    $product = new WCGE_Product();

    // Gallery
    $gallery = new WCGE_Galleries();

    // Orders
    $orders = new WCGE_Order();

    // Users
    $users = new WCGE_User();


}
add_action('init', 'wcge_init_plugin');
