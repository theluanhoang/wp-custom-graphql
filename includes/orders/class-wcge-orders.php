<?php

class WCGE_Order {
    public function __construct() {
        add_action('graphql_register_types', [$this, 'registerCustomGraphQLFields']);
        add_action('graphql_register_types', [$this, 'registerCustomOrderType']); // Register the custom order type here
    }

    public function registerCustomGraphQLFields()
    {
        register_graphql_field('RootQuery', 'fetchCustomerOrders', [
            'type' => [
                'list_of' => 'CustomOrder', // Define a custom CustomOrder type
            ],
            'description' => __('Fetch a list of customer orders with custom fields', 'wp-custom-orders'),
            'resolve' => function ($source, $args) {
                return $this->fetchCustomOrderData();
            },
        ]);

        // Register the fetchOrderDetail field
        register_graphql_field('RootQuery', 'fetchOrderDetail', [
            'type' => 'CustomOrderDetail',
            'description' => __('Fetch details of a specific order by ID', 'wp-custom-orders'),
            'args' => [
                'orderId' => [
                    'type' => 'ID',
                    'description' => __('The ID of the order to retrieve', 'wp-custom-orders'),
                ],
            ],
            'resolve' => function ($source, $args) {
                return $this->fetchOrderDetailData($args['orderId']);
            },
        ]);
    }

    public function registerCustomOrderType() {
        // Register a custom GraphQL Order type for the new fields
        register_graphql_object_type('CustomOrder', [
            'description' => __('Custom Order details for the custom query', 'wp-custom-orders'),
            'fields' => [
                'orderDate' => ['type' => 'String', 'description' => __('The date the custom order was created', 'wp-custom-orders')],
                'orderId' => ['type' => 'ID', 'description' => __('The unique ID of the custom order in the database', 'wp-custom-orders')],
                'orderNumber' => ['type' => 'String', 'description' => __('The custom order number', 'wp-custom-orders')],
                'orderStatus' => ['type' => 'String', 'description' => __('The current status of the custom order', 'wp-custom-orders')],
                'orderTotal' => ['type' => 'Float', 'description' => __('The total amount of the custom order', 'wp-custom-orders')],
                'costTotal' => ['type' => 'Float', 'description' => __('The total amount of the custom order', 'wp-custom-orders')],
            ],
        ]);

        // Detail type with items
        register_graphql_object_type('CustomOrderDetail', [
            'description' => __('Detailed information about a customer order', 'wp-custom-orders'),
            'fields' => [
                'orderDate' => ['type' => 'String', 'description' => __('The date the order was created', 'wp-custom-orders')],
                'orderId' => ['type' => 'ID', 'description' => __('The unique ID of the order', 'wp-custom-orders')],
                'orderNumber' => ['type' => 'String', 'description' => __('The order number', 'wp-custom-orders')],
                'orderStatus' => ['type' => 'String', 'description' => __('The current status of the order', 'wp-custom-orders')],
                'orderTotal' => ['type' => 'Float', 'description' => __('The total amount of the order', 'wp-custom-orders')],
                'orderItems' => [
                    'type' => ['list_of' => 'OrderItem'],
                    'description' => __('List of items in the order', 'wp-custom-orders'),
                ],
            ],
        ]);

        // Define OrderItem type for individual items in an order
        register_graphql_object_type('OrderItem', [
            'description' => __('Details of an individual item in the order', 'wp-custom-orders'),
            'fields' => [
                'productName' => ['type' => 'String', 'description' => __('The name of the product', 'wp-custom-orders')],
                'quantity' => ['type' => 'Int', 'description' => __('Quantity of the product in the order', 'wp-custom-orders')],
                'tax' => ['type' => 'Float', 'description' => __('Tax amount for the product', 'wp-custom-orders')],
                'total' => ['type' => 'Float', 'description' => __('Total price for the product', 'wp-custom-orders')],
            ],
        ]);
    }

    private function fetchCustomOrderData(): array {
        // Ensure the user is logged in
        $current_user_id = get_current_user_id();


        if (!$current_user_id) {
            status_header(401);
            throw new GraphQL\Error\UserError(__('You must be logged in to view order details', 'wp-custom-orders'));
        }

        // Fetch orders for the current user, sorted by date (newest first)
        $customer_orders = wc_get_orders([
            'customer_id' => $current_user_id,
            'limit' => 100,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        // Prepare the custom order data
        $custom_order_list = [];
        foreach ($customer_orders as $customer_order) {
            $custom_order_list[] = [
                'orderDate' => $customer_order->get_date_created()->date('Y-m-d H:i:s'),
                'orderId' => $customer_order->get_id(),
                'orderNumber' => $customer_order->get_order_number(),
                'orderStatus' => $customer_order->get_status(),
                'orderTotal' => count($customer_order->get_items()),
                'costTotal' => $customer_order->get_total(),
            ];
        }

        return $custom_order_list;
    }

    private function fetchOrderDetailData($order_id) {
        $current_user_id = get_current_user_id();
        if (!$current_user_id) {
            throw new GraphQL\Error\Error(__('You must be logged in to view order details', 'wp-custom-orders'));
        }

        $order = wc_get_order($order_id);


        // Check if order exists and belongs to the current user
        if (!$order || $order->get_user_id() != $current_user_id) {
            throw new GraphQL\Error\Error(__('This order does not belong to you or does not exist', 'wp-custom-orders'));
        }

        // Fetch order items with details
        $order_items = [];
        foreach ($order->get_items() as $item) {
            $order_items[] = [
                'productName' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'tax' => $item->get_total_tax(),
                'total' => $item->get_total(),
            ];
        }

        // Return order details
        return [
            'orderDate' => $order->get_date_created()->date('Y-m-d H:i:s'),
            'orderId' => $order->get_id(),
            'orderNumber' => $order->get_order_number(),
            'orderStatus' => $order->get_status(),
            'orderTotal' => $order->get_total(),
            'orderItems' => $order_items,
        ];
    }
}






