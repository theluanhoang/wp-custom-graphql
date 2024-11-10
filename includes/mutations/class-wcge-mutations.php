<?php

class WCGE_Mutation {
    public function __construct() {
        add_action('graphql_register_types', [$this, 'registerCheckOrderPaymentMutation']);
        add_action('graphql_register_types', [$this, 'registerUpdateAccountDetailsMutation']);
    }

    /**
     * @return void
     */
    public function registerCheckOrderPaymentMutation() {
        register_graphql_mutation('checkOrderPayment', [
            'inputFields' => [
                'orderId' => ['type' => 'ID'],
                'paymentId' => ['type' => 'String'],
            ],
            'outputFields' => [
                'orderDate' => ['type' => 'String'],
                'orderKey' => ['type' => 'String'],
                'orderId' => ['type' => 'ID'],
                'orderNumber' => ['type' => 'String'],
                'orderStatus' => ['type' => 'String'],
                'orderTotal' => ['type' => 'Float'],
            ],
            'mutateAndGetPayload' => function ($input) {
                $orderId = $input['orderId'];
                $paymentId = $input['paymentId'];

                return $this->updateOrderPaymentStatus($paymentId, $orderId);
            },
        ]);
    }

    private function updateOrderPaymentStatus($paymentId, $orderId) {
        $current_user_id = get_current_user_id();

        $order = wc_get_order($orderId);
       
        if (!$order) {
            throw new GraphQL\Error\Error(__('This order does not belong to you or does not exist', 'wp-custom-orders'));
        }
        
        if ($order->get_user_id() != 0 && $order->get_user_id() != $current_user_id) {
            throw new GraphQL\Error\Error(__('You do not have permission to update the payment status for this order.', 'wp-custom-orders'));
        }
        
        $paypal_status = $this->checkPaypalPaymentStatus($paymentId);

        if ($paypal_status == 'COMPLETED') {
            $order->update_status('processing', __('Payment completed through PayPal', 'wp-custom-orders'));
            return $this->fetchOrderDetailData($orderId);
        }

        throw new GraphQL\Error\Error(__('PayPal payment not completed', 'wp-custom-orders'));
    }

    private function checkPaypalPaymentStatus($paymentId) {
        $paypal_url = "https://api-m.sandbox.paypal.com/v2/checkout/orders/{$paymentId}";
        $client_id = 'AZkmx4HgB5lZVrIYpO6lPfc5d0p9WmV_pwOU3J_7tWEoBSuVpb1yKyL_zhVAdvBb36aaAhGhAKBFb8bg';
        $client_secret = 'EF0vqsdDncQEjCgm_QMIBJFqXph6oBtYtDJVk1Dyibe8cmFgcdk0WLoxIPTzBcMOm2k4caQCKXmRTOB8';
        $token = $this->getPaypalAccessToken($client_id, $client_secret);
        error_log('TOKEN => ' . print_r($token, true));
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ];

        $response = wp_remote_get($paypal_url, [
            'headers' => $headers,
        ]);

        if (is_wp_error($response)) {
            throw new GraphQL\Error\Error(__('Failed to contact PayPal API', 'wp-custom-orders'));
        }

        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        error_log('$data => ' . print_r($data, true));
        if (!isset($data['status'])) {
            throw new GraphQL\Error\Error(__('Invalid response from PayPal API', 'wp-custom-orders'));
        }
        return $data['status'];
    }

    private function getPaypalAccessToken($client_id, $client_secret) {
        $paypal_token_url = 'https://api-m.sandbox.paypal.com/v1/oauth2/token';
        $body = [
            'grant_type' => 'client_credentials',
        ];

        $headers = [
            'Authorization' => 'Basic ' . base64_encode("{$client_id}:{$client_secret}"),
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        $response = wp_remote_post($paypal_token_url, [
            'body' => $body,
            'headers' => $headers,
        ]);

        if (is_wp_error($response)) {
            throw new GraphQL\Error\Error(__('Failed to obtain PayPal access token', 'wp-custom-orders'));
        }

        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        if (isset($data['access_token'])) {
            return $data['access_token'];
        }

        throw new GraphQL\Error\Error(__('Failed to obtain valid PayPal access token', 'wp-custom-orders'));
    }

    /**
     * @param $order_id
     *
     * @return array
     */
    private function fetchOrderDetailData($order_id): array
    {
        $current_user_id = get_current_user_id();

        $order = wc_get_order($order_id);

        if (!$order) {
            throw new GraphQL\Error\Error(__('This order does not belong to you or does not exist', 'wp-custom-orders'));
        }
        
        if ($order->get_user_id() != 0 && $order->get_user_id() != $current_user_id) {
            throw new GraphQL\Error\Error(__('You do not have permission to update the payment status for this order.', 'wp-custom-orders'));
        }

        $order_items = [];
        foreach ($order->get_items() as $item) {
            $order_items[] = [
                'productName' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'tax' => $item->get_total_tax(),
                'total' => $item->get_total(),
            ];
        }

        return [
            'orderDate' => $order->get_date_created()->date('Y-m-d H:i:s'),
            'orderId' => $order->get_id(),
            'orderKey' => $order->get_order_key(),
            'orderNumber' => $order->get_order_number(),
            'orderStatus' => $order->get_status(),
            'orderTotal' => $order->get_total(),
            'orderItems' => $order_items,
        ];
    }

    public function registerUpdateAccountDetailsMutation() {
        register_graphql_mutation('updateAccountDetails', [
            'inputFields' => [
                'firstName' => ['type' => 'String'],
                'lastName' => ['type' => 'String'],
                'displayName' => ['type' => 'String'],
                'phone' => ['type' => 'String'],
                'email' => ['type' => 'String'],
                'address1' => ['type' => 'String'],
                'address2' => ['type' => 'String'],
                'country' => ['type' => 'String'],
                'state' => ['type' => 'String'],
                'city' => ['type' => 'String'],
                'postcode' => ['type' => 'String'],
            ],
            'outputFields' => [
                'status' => ['type' => 'String'],
                'message' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function ($input) {
                return $this->updateAccountDetails($input);
            },
        ]);
    }
    
    private function updateAccountDetails($input) {
        $current_user_id = get_current_user_id();
        if (!$current_user_id) {
            throw new GraphQL\Error\UserError(__('You must be logged in to update account details', 'wp-custom-orders'));
        }
    
        $user_data = [];
        if (isset($input['email'])) {
            $user_data['user_email'] = sanitize_email($input['email']);
        }
        if (isset($input['firstName'])) {
            update_user_meta($current_user_id, 'first_name', sanitize_text_field($input['firstName']));
        }
        if (isset($input['lastName'])) {
            update_user_meta($current_user_id, 'last_name', sanitize_text_field($input['lastName']));
        }
        if (isset($input['displayName'])) {
            wp_update_user([
                'ID' => $current_user_id,
                'display_name' => sanitize_text_field($input['displayName']),
            ]);
        }
        if (isset($input['phone'])) {
            update_user_meta($current_user_id, 'billing_phone', sanitize_text_field($input['phone']));
        }
    
        if (!empty($user_data)) {
            $user_data['ID'] = $current_user_id;
            $user_update = wp_update_user($user_data);
            if (is_wp_error($user_update)) {
                throw new GraphQL\Error\Error(__('Failed to update account details', 'wp-custom-orders'));
            }
        }
    
        update_user_meta($current_user_id, 'billing_address_1', sanitize_text_field($input['address1'] ?? ''));
        update_user_meta($current_user_id, 'billing_address_2', sanitize_text_field($input['address2'] ?? ''));
        update_user_meta($current_user_id, 'billing_city', sanitize_text_field($input['city'] ?? ''));
        update_user_meta($current_user_id, 'billing_postcode', sanitize_text_field($input['postcode'] ?? ''));
        update_user_meta($current_user_id, 'billing_country', sanitize_text_field($input['country'] ?? ''));
        update_user_meta($current_user_id, 'billing_state', sanitize_text_field($input['state'] ?? ''));
    
        return [
            'status' => 'SUCCESS',
            'message' => __('Account details updated successfully', 'wp-custom-orders'),
        ];
    }
}