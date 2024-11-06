<?php

class WCGE_Variation {

    private $attributes;

    public function __construct() {
        add_action('graphql_register_types', [$this, 'register_graphql_fields']);
        $this->attributes = new WCGE_Attribute();
    }

    /**
     * @throws Exception
     */
    public function register_graphql_fields() {
        $this->register_update_attribute_to_variation_mutation();
        $this->register_manage_variations_mutation();
        $this->register_manage_global_variations_mutation();
    }

    /**
     * @throws Exception
     *
     * @return void
     */
    private function register_update_attribute_to_variation_mutation(): void
    {
        register_graphql_mutation('updateAttributeToVariation', [
            'inputFields' => [
                'productId' => ['type' => 'ID'],
                'variationId' => ['type' => 'ID'],
                'attributeId' => ['type' => 'ID'],
                'termName' => ['type' => 'ID'],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'message' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function($input, $context, $info) {
                return $this->update_attribute_to_variation($input);
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
    private function update_attribute_to_variation($input): array
    {
        $product_id = base64_decode($input['productId']);
        $product_id = str_replace('product:', '', $product_id);
        $variation_id = base64_decode($input['variationId']);
        $variation_id = (int) str_replace('product_variation:', '', $variation_id);
        $attribute_id = (int) $input['attributeId'];
        $term_name = $input['termName'];

        $variation = wc_get_product($variation_id);

        if (!$variation->get_id()) {
            return [
                'success' => false,
                'message' => __('Variation not found', 'wp-custom-global-attributes')
            ];
        }

        $attribute_name = wc_attribute_taxonomy_name_by_id($attribute_id);
        if (!$attribute_name) {
            return [
                'success' => false,
                'message' => __('Attribute taxonomy not found', 'wp-custom-global-attributes')
            ];
        }

        $attributes = $variation->get_attributes();
        $attributes[$attribute_name] = strtolower($term_name);

        $newVariation = new WC_Product_Variation();
        $newVariation->set_id($variation_id);
        $newVariation->set_name($variation->get_name());
        $newVariation->set_attributes($attributes);
        $newVariation->set_parent_id($product_id);
        $newVariation->set_regular_price($variation->get_regular_price());
        $newVariation->set_sale_price($variation->get_sale_price());
        $newVariation->set_stock_quantity($variation->get_stock_quantity());
        $newVariation->set_manage_stock($variation->get_manage_stock());
        $newVariation->set_weight($variation->get_weight());
        $newVariation->set_backorders($variation->get_backorders());
        $newVariation->set_tax_class($variation->get_tax_class());
        $newVariation->set_shipping_class_id($variation->get_shipping_class_id());
        $newVariation->set_image_id($variation->get_image_id());
        $newVariation->set_purchase_note($variation->get_purchase_note());

        $saved = $newVariation->save();

        wc_delete_product_transients($saved);
        clean_post_cache($saved);

        if (!$saved) {
            return [
                'success' => false,
                'message' => __('Failed to save variation', 'wp-custom-global-attributes')
            ];
        }

        return [
            'success' => true,
            'message' => __('Attribute added to variation successfully', 'wp-custom-global-attributes')
        ];
    }

    /**
     * @throws Exception
     *
     * @return void
     */
    private function register_manage_variations_mutation(): void
    {
        register_graphql_input_type('AttributeInput', [
            'description' => __('Input type for managing attributes', 'wp-custom-global-attributes'),
            'fields' => [
                'attributeId' => ['type' => 'ID'],
                'termIds' => ['type' => ['list_of' => 'ID']]
            ],
        ]);

        register_graphql_input_type('UpdateVariationInput', [
            'description' => __('Input type for managing variations', 'wp-custom-global-attributes'),
            'fields' => [
                'variationIdList' => ['type' => ['list_of' => 'ID']],
                'attributeId' => ['type' => 'ID'],
                'termId' => ['type' => 'ID']
            ],
        ]);

        register_graphql_mutation('manageVariations', [
            'inputFields' => [
                'productId' => ['type' => 'ID'],
                'listGlobalAttributes' => [
                    'type' => ['list_of' => 'AttributeInput'],
                    'description' => __('List of global attributes associated with the variations', 'wp-custom-global-attributes'),
                ],
                'listNonGlobalAttributes' => [
                    'type' => ['list_of' => 'String'],
                    'description' => __('List of non-global attributes for the product', 'wp-custom-global-attributes'),
                ],
                'listUpdateVariations' => [
                    'type' => ['list_of' => 'UpdateVariationInput'],
                    'description' => __('List of variations with operations', 'wp-custom-global-attributes'),
                ],
                'listDeleteGlobalAttributes' => [
                    'type' => ['list_of' => 'ID'],
                    'description' => __('List of global attributes to delete for the product', 'wp-custom-global-attributes'),
                ],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'message' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function($input, $context, $info) {
                return $this->manage_variations($input);
            },
        ]);
    }

    /**
     * @param array $input
     *
     * @throws Exception
     *
     * @return array
     */
    private function manage_variations(array $input): array
    {
        $product_id = base64_decode($input['productId']);
        $product_id = str_replace('product:', '', $product_id);

        // Fetch the product once
        $product = wc_get_product($product_id);
        if (!$product) {
            return [
                'success' => false,
                'message' => __('Product not found', 'wp-custom-global-attributes')
            ];
        }

        foreach ($input['listGlobalAttributes'] as $attribute_input) {
            $attributeId = (int) $attribute_input['attributeId'];
            $termIds = $attribute_input['termIds'];

            $this->add_global_attribute_to_product($attributeId, $termIds, $product);
        }

        foreach ($input['listUpdateVariations'] as $variation_input) {
            $attributeId = (int) $variation_input['attributeId'];
            $termId = (int) $variation_input['termId'];

            foreach ($variation_input['variationIdList'] as $variationId) {
                $variationId = base64_decode($variationId);
                $variationId = (int) str_replace('product_variation:', '', $variationId);
                $this->update_global_attribute_to_variation($variationId, $attributeId, $termId, $product_id);
            }
        }

        if (!empty($input['listNonGlobalAttributes'])) {
            $result = $this->delete_non_global_attribute($product, $input['listNonGlobalAttributes']);
            if (!$result['success']) {
                return $result;
            }
        }

        if (!empty($input['listDeleteGlobalAttributes'])) {
            $result = $this->delete_global_attributes($product, $input['listDeleteGlobalAttributes']);
            update_post_meta($product_id, 'updateProductGlobalAttributeStatus', "false");

            if (!$result['success']) {
                return $result;
            }

        }

        return [
            'success' => true,
            'message' => __('Variations managed successfully', 'wp-custom-global-attributes')
        ];
    }

    /**
     * @param $attributeId
     * @param $termIds
     * @param $product
     *
     * @return array
     */
    public function add_global_attribute_to_product($attributeId, $termIds, $product): array
    {
        $attributes = $product->get_attributes();
        $attribute_name = wc_attribute_taxonomy_name_by_id($attributeId);

        if (!$attribute_name) {
            return [
                'success' => false,
                'message' => __('Attribute taxonomy not found', 'wp-custom-global-attributes')
            ];
        }

        $termNames = [];
        foreach ($termIds as $termId) {
            $term = get_term($termId, $attribute_name);
            if ($term && !is_wp_error($term)) {
                $termNames[] = $term->name;
            }
        }

        $attribute_object = new WC_Product_Attribute();
        $attribute_object->set_id($attributeId);
        $attribute_object->set_name($attribute_name);
        $attribute_object->set_options($termNames);
        $attribute_object->set_position(sizeof($attributes));
        $attribute_object->set_visible(true);
        $attribute_object->set_variation(true);

        $attributes[count($attributes)] = $attribute_object;

        $product->set_attributes($attributes);
        $saved = $product->save();

        wc_delete_product_transients($saved);
        clean_post_cache($saved);

        return [
            'success' => true,
            'message' => __('Global attribute added successfully', 'wp-custom-global-attributes')
        ];
    }

    /**
     * @param $variationId
     * @param $attributeId
     * @param $termId
     * @param $productId
     *
     * @return array
     */
    private function update_global_attribute_to_variation($variationId, $attributeId, $termId, $productId): array
    {
        $variation = wc_get_product($variationId);

        if (!$variation || !$variation->get_id()) {
            return [
                'success' => false,
                'message' => __('Variation not found', 'wp-custom-global-attributes')
            ];
        }

        $attribute_name = wc_attribute_taxonomy_name_by_id($attributeId);
        if (!$attribute_name) {
            return [
                'success' => false,
                'message' => __('Attribute taxonomy not found', 'wp-custom-global-attributes')
            ];
        }

        $term = get_term($termId, $attribute_name);
        if (!$term || is_wp_error($term)) {
            return [
                'success' => false,
                'message' => __('Term not found', 'wp-custom-global-attributes')
            ];
        }

        $attributes = $variation->get_attributes();
        $attributes[strtolower(urlencode($attribute_name))] =  $term->slug;

        $variation->set_attributes($attributes);
        $variation->set_parent_id($productId);

        $saved = $variation->save();

        wc_delete_product_transients($variationId);
        clean_post_cache($variationId);

        if (!$saved) {
            return [
                'success' => false,
                'message' => __('Failed to save variation', 'wp-custom-global-attributes')
            ];
        }

        return [
            'success' => true,
            'message' => __('Attribute added to variation successfully', 'wp-custom-global-attributes')
        ];
    }

    /**
     * @param WC_Product $product
     * @param array $nonGlobalAttributes
     *
     * @throws Exception
     *
     * @return array
     */
    public function delete_non_global_attribute($product, array $nonGlobalAttributes): array
    {
        $attributes = $product->get_attributes();
        $nonGlobalAttributesLower = array_map('strtolower', $nonGlobalAttributes);

        foreach ($attributes as $key => $attribute) {
            if ($attribute->get_id() === 0 && in_array(strtolower($attribute->get_name()), $nonGlobalAttributesLower)) {
                unset($attributes[$key]);
            }
        }

        $product->set_attributes($attributes);
        $saved = $product->save();

        wc_delete_product_transients($saved);
        clean_post_cache($saved);

        if (!$saved) {
            error_log('Failed to save product');
            return [
                'success' => false,
                'message' => __('Failed to remove attributes', 'wp-custom-global-attributes')
            ];
        }

        return [
            'success' => true,
            'message' => __('Attributes removed successfully', 'wp-custom-global-attributes')
        ];
    }

    /**
     * @param WC_Product $product
     * @param array $deleteGlobalAttribute
     *
     * @throws Exception
     *
     * @return array
     */
    public function delete_global_attributes($product, array $deleteGlobalAttribute): array
    {
        $attributes = $product->get_attributes();
        $deleteGlobalAttribute = array_map('intval', $deleteGlobalAttribute);

        foreach ($attributes as $key => $attribute) {
            if (in_array($attribute->get_id(), $deleteGlobalAttribute)) {
                unset($attributes[$key]);
            }
        }

        $product->set_attributes($attributes);
        $saved = $product->save();

        wc_delete_product_transients($saved);
        clean_post_cache($saved);

        if (!$saved) {
            error_log('Failed to save product');
            return [
                'success' => false,
                'message' => __('Failed to remove attributes', 'wp-custom-global-attributes')
            ];
        }

        return [
            'success' => true,
            'message' => __('Attributes removed successfully', 'wp-custom-global-attributes')
        ];
    }


    /**
     * @throws Exception
     *
     * @return void
     */
    private function register_manage_global_variations_mutation(): void
    {
        register_graphql_mutation('manageGlobalVariations', [
            'inputFields' => [
                'productId' => ['type' => 'ID'],
                'listVariations' => [
                    'type' => ['list_of' => 'GlobalVariationInput'],
                    'description' => __('List of variations with operations', 'wp-custom-global-attributes'),
                ],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'message' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function($input, $context, $info) {
                return $this->manage_global_variations($input);
            },
        ]);

        register_graphql_input_type('GlobalVariationInput', [
            'description' => __('Input type for managing variations', 'wp-custom-global-attributes'),
            'fields' => [
                'variationIdList' => ['type' => ['list_of' => 'ID']],
                'deleteGlobalAttribute' => [
                    'type' => 'DeleteGlobalAttributeInput',
                    'description' => __('Details for deleting non-global attribute', 'wp-custom-global-attributes'),
                ],
                'addGlobalAttribute' => [
                    'type' => 'AddGlobalAttributeInput',
                    'description' => __('Details for adding global attribute', 'wp-custom-global-attributes'),
                ],
            ],
        ]);

        register_graphql_input_type('DeleteGlobalAttributeInput', [
            'description' => __('Input type for deleting a non-global attribute', 'wp-custom-global-attributes'),
            'fields' => [
                'attributeId' => ['type' => 'ID'],
                'termId' => ['type' => 'ID'],
            ],
        ]);

        register_graphql_input_type('AddGlobalAttributeInput', [
            'description' => __('Input type for adding a global attribute', 'wp-custom-global-attributes'),
            'fields' => [
                'attributeId' => ['type' => 'ID'],
                'termId' => ['type' => 'ID'],
                'termName' => ['type' => 'ID'],
            ],
        ]);
    }

    /**
     * @param $input
     *
     * @throws Exception
     *
     * @return array
     */
    private function manage_global_variations($input): array
    {
        foreach ($input['listVariations'] as $variation_input) {
            // Delete non-global attribute
            $delete_input = [
                'productId' => $input['productId'],
                'attributeId' => $variation_input['deleteGlobalAttribute']['attributeId'],
                'termId' => $variation_input['deleteGlobalAttribute']['termId']
            ];
            $this->attributes->delete_global_attribute($delete_input);

            // Add global attribute
            $add_input = [
                'productId' => $input['productId'],
                'attributeId' => $variation_input['addGlobalAttribute']['attributeId'],
                'termId' => $variation_input['addGlobalAttribute']['termId'],
            ];
            $this->attributes->add_global_attribute_to_product($add_input);

            // Update variation attributes if needed
            foreach ($variation_input['variationIdList'] as $variationId) {
                $update_input = [
                    'productId' => $input['productId'],
                    'variationId' => $variationId,
                    'attributeId' => $variation_input['addGlobalAttribute']['attributeId'],
                    'termName' => $variation_input['addGlobalAttribute']['termName'],
                ];
                $this->update_attribute_to_variation($update_input);
            }
        }

        return [
            'success' => true,
            'message' => __('Variations managed successfully', 'wp-custom-global-attributes')
        ];
    }
}
