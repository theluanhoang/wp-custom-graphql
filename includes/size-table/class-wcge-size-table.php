<?php

class WCGE_SizeTable {
    public function __construct() {
        add_action('graphql_register_types', [$this, 'register_graphql_fields']);
    }

    /**
     * @throws Exception
     */
    public function register_graphql_fields() {
        $this->register_update_size_table_mutation();
        $this->register_get_size_table_of_product();
    }

    /**
     * @throws Exception
     *
     * @return void
     */
    private function register_update_size_table_mutation(): void
    {
        register_graphql_mutation('updateSizeTable', [
            'inputFields' => [
                'productId' => ['type' => 'ID'],
                'sizeTable' => ['type' => 'String'],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'message' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function($input, $context, $info) {
                return $this->update_size_table($input);
            },
        ]);
    }

    /**
     * @param $input
     *
     * @throws Exception
     *
     * @return array
     */
    private function update_size_table($input): array
    {
        $product_id = base64_decode($input['productId']);
        $product_id = str_replace('product:', '', $product_id);

        $size_table_data = $input['sizeTable'];

        $product = wc_get_product($product_id);

        if (!$product) {
            return [
                'success' => false,
                'message' => __('Product not found', 'wp-custom-global-attributes')
            ];
        }

        update_post_meta($product_id, 'size_table', $size_table_data);

        return [
            'success' => true,
            'message' => __('Size table updated successfully', 'wp-custom-global-attributes')
        ];
    }

    /**
     * @throws Exception
     *
     * @return void
     */
    private function register_get_size_table_of_product(): void
    {
        register_graphql_field('RootQuery', 'getSizeTableOfProduct', [
            'type' => 'String',
            'description' => __('Get size table of a product', 'wp-custom-global-attributes'),
            'args' => [
                'productId' => [
                    'type' => 'ID',
                    'description' => __('ID of the product', 'wp-custom-global-attributes'),
                ],
                'key' => [
                    'type' => 'String',
                    'description' => __('Meta key to fetch', 'wp-custom-global-attributes'),
                ],
            ],
            'resolve' => function($root, $args) {
                return $this->get_size_table_of_product($args);
            },
        ]);
    }

    /**
     * @param $input
     *
     * @throws Exception
     *
     * @return ?string
     */
    private function get_size_table_of_product($input): ?string
    {
        $product_id = base64_decode($input['productId']);
        $product_id = str_replace('product:', '', $product_id);

        $key = $input['key'];

        $size_table = get_post_meta($product_id, $key, true);

        if ($size_table) {
            return $size_table;
        }

        return null;
    }
}
