<?php

class WCGE_Attribute {
    public function __construct() {
        add_action('graphql_register_types', [$this, 'register_graphql_fields']);
    }

    /**
     * @throws Exception
     */
    public function register_graphql_fields() {
        $this->register_global_attributes_field();
        $this->register_delete_non_global_attribute_mutation();
        $this->register_add_global_attribute_mutation();
        $this->register_add_global_attribute_non_variation_mutation();
        $this->register_update_global_attribute_variations_mutation();
        $this->register_update_product_global_attribute_status_query();
        $this->register_product_attributes_and_variations_field();
    }

    /**
     * @throws Exception
     *
     * @return void
     */
    private function register_global_attributes_field(): void {
        register_graphql_field('RootQuery', 'allGlobalAttributes', [
            'type' => ['list_of' => 'GlobalAttribute'],
            'description' => __('Get all global attributes', 'wp-custom-global-attributes'),
            'args' => [
                'excludeCertainAttributes' => [
                    'type' => 'Boolean',
                    'description' => __('Exclude attributes like color, Colour, and Size', 'wp-custom-global-attributes'),
                    'defaultValue' => false,
                ],
            ],
            'resolve' => function($root, $args) {
                return $this->get_all_global_attributes($args['excludeCertainAttributes']);
            },
        ]);

        register_graphql_object_type('GlobalAttribute', [
            'description' => __('Global Attribute', 'wp-custom-global-attributes'),
            'fields' => [
                'id' => ['type' => 'ID'],
                'name' => ['type' => 'String'],
                'slug' => ['type' => 'String'],
                'nodes' => ['type' => ['list_of' => 'Term']],
            ],
        ]);

        register_graphql_object_type('Term', [
            'description' => __('Term of a Global Attribute', 'wp-custom-global-attributes'),
            'fields' => [
                'id' => ['type' => 'ID'],
                'name' => ['type' => 'String'],
                'slug' => ['type' => 'String'],
            ],
        ]);
    }

    /**
     * Lấy tất cả các global attributes
     *
     * @param bool $excludeCertainAttributes
     * @return array
     */
    private function get_all_global_attributes(bool $excludeCertainAttributes = false): array {
        $attributes = wc_get_attribute_taxonomies();
        $result = [];

        foreach ($attributes as $attribute) {
            if ($excludeCertainAttributes && in_array(strtolower($attribute->attribute_label), ['color', 'colour', 'size'])) {
                continue;
            }

            $taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name);
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
            ]);

            $term_nodes = [];

            foreach ($terms as $term) {
                $term_nodes[] = [
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                ];
            }

            $result[] = [
                'id' => $attribute->attribute_id,
                'name' => $attribute->attribute_label,
                'slug' => $attribute->attribute_name,
                'nodes' => $term_nodes,
            ];
        }

        return $result;
    }

    /**
     * @throws Exception
     *
     * @return void
     */
    private function register_delete_non_global_attribute_mutation(): void
    {
        register_graphql_mutation('deleteNonGlobalAttribute', [
            'inputFields' => [
                'productId' => ['type' => 'ID'],
                'attributeName' => ['type' => 'String'],
                'option' => ['type' => 'String'],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'message' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function($input, $context, $info) {
                return $this->delete_non_global_attribute($input);
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
    public function delete_non_global_attribute($input): array
    {
        $product_id =  base64_decode($input['productId']);
        $product_id = str_replace('product:', '', $product_id);
        $attribute_name = $input['attributeName'];
        $option_to_remove = $input['option'];

        $product = wc_get_product($product_id);

        if (!$product) {
            return [
                'success' => false,
                'message' => __('Product not found', 'wp-custom-global-attributes')
            ];
        }

        $attributes = $product->get_attributes();

        foreach ($attributes as $key => $attribute) {
            if (strtolower($key) === strtolower($attribute_name) && $attribute->get_id() === 0) {
                $options = array_map('strtolower', $attribute->get_options());
                $option_to_remove_lower = strtolower($option_to_remove);
                $updated_options = array_diff($options, [$option_to_remove_lower]);
                $updated_options = array_values($updated_options);

                if (empty($updated_options)) {
                    unset($attributes[$key]);
                } else {
                    $attribute_object = new WC_Product_Attribute();
                    $attribute_object->set_name($attribute->get_name());
                    $attribute_object->set_options($updated_options);
                    $attribute_object->set_position($attribute->get_position());
                    $attribute_object->set_visible(true);
                    $attribute_object->set_variation(true);

                    $attributes[$key] = $attribute_object;
                }
            }
        }

        $product->set_attributes($attributes);
        $saved = $product->save();

        wc_delete_product_transients($saved);
        clean_post_cache($saved);

        if (!$saved) {
            error_log('Failed to save product');
        }

        return [
            'success' => true,
            'message' => __('Attribute removed successfully', 'wp-custom-global-attributes')
        ];
    }

    /**
     * @throws Exception
     *
     * @return void
     */
    private function register_add_global_attribute_mutation(): void
    {
        register_graphql_mutation('addGlobalAttributeToProduct', [
            'inputFields' => [
                'productId' => ['type' => 'ID'],
                'attributeId' => ['type' => 'ID'],
                'termId' => ['type' => 'ID'],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'message' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function($input, $context, $info) {
                return $this->add_global_attribute_to_product($input);
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
    public function add_global_attribute_to_product($input): array
    {
        $product_id = base64_decode($input['productId']);
        $product_id = str_replace('product:', '', $product_id);
        $attribute_id = (int) $input['attributeId'];
        $term_id = (int) $input['termId'];

        $product = wc_get_product($product_id);

        if (!$product) {
            return [
                'success' => false,
                'message' => __('Product not found', 'wp-custom-global-attributes')
            ];
        }

        $attributes = $product->get_attributes();
        $isExistAttribute = false;

        foreach ($attributes as $key => $attribute) {
            if ($attribute->get_id() === $attribute_id) {
                $options = $attribute->get_options();

                if (!in_array($term_id, $options)) {
                    $options[] = $term_id;
                }

                $updated_options = array_values($options);

                $attribute_object = new WC_Product_Attribute();
                $attribute_object->set_id($attribute_id);
                $attribute_object->set_name($attribute->get_name());
                $attribute_object->set_options($updated_options);
                $attribute_object->set_position($attribute->get_position());
                $attribute_object->set_visible(true);
                $attribute_object->set_variation(true);

                $attributes[$key] = $attribute_object;

                $isExistAttribute = true;
            }
        }

        if (!$isExistAttribute) {
            $attribute_name = wc_attribute_taxonomy_name_by_id($attribute_id);

            if (!$attribute_name) {
                return [
                    'success' => false,
                    'message' => __('Attribute taxonomy not found', 'wp-custom-global-attributes')
                ];
            }

            $new_options = [$term_id];

            $attribute_object = new WC_Product_Attribute();
            $attribute_object->set_id($attribute_id);
            $attribute_object->set_name($attribute_name);
            $attribute_object->set_options($new_options);
            $attribute_object->set_position(sizeof($attributes));
            $attribute_object->set_visible(true);
            $attribute_object->set_variation(true);

            $attributes[count($attributes)] = $attribute_object;
        }

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
     * @param $input
     *
     * @return void
     *@throws Exception
     *
     */
    public function delete_global_attribute($input): void
    {
        $product_id =  base64_decode($input['productId']);
        $product_id = str_replace('product:', '', $product_id);
        $attribute_id = (int) $input['attributeId'];
        $termId = (int) $input['termId'];

        $product = wc_get_product($product_id);

        if (!$product) {
            [
                'success' => false,
                'message' => __('Product not found', 'wp-custom-global-attributes')
            ];
            return;
        }

        $attributes = $product->get_attributes();

        foreach ($attributes as $key => $attribute) {
            if ($attribute_id === $attribute->get_id()) {

                $options = $attribute->get_options();
                $updated_options = array_diff($options, [$termId]);
                $updated_options = array_values($updated_options);

                if (empty($updated_options)) {
                    unset($attributes[$key]);
                } else {
                    $attribute_object = new WC_Product_Attribute();
                    $attribute_object->set_id($attribute_id);
                    $attribute_object->set_name($attribute->get_name());
                    $attribute_object->set_options($updated_options);
                    $attribute_object->set_position($attribute->get_position());
                    $attribute_object->set_visible(true);
                    $attribute_object->set_variation(true);

                    $attributes[$key] = $attribute_object;
                }
            }
        }

        $product->set_attributes($attributes);
        $saved = $product->save();

        wc_delete_product_transients($saved);
        clean_post_cache($saved);

        if (!$saved) {
            error_log('Failed to save product');
        }

        [
            'success' => true,
            'message' => __('Attribute removed successfully', 'wp-custom-global-attributes')
        ];
    }

    /**
     * @throws Exception
     *
     * @return void
     */
    private function register_add_global_attribute_non_variation_mutation(): void
    {
        register_graphql_mutation('addGlobalAttributeNonVariationToProduct', [
            'inputFields' => [
                'productId' => ['type' => 'ID'],
                'listAttributes' => ['type' => ['list_of' => 'AttributeInput']]
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'message' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function($input, $context, $info) {
                return $this->add_global_attribute_non_variation_to_product($input);
            },
        ]);

        register_graphql_input_type('AttributeInput', [
            'description' => 'Input type for attribute and terms',
            'fields' => [
                'attributeId' => ['type' => 'ID'],
                'termIds' => ['type' => ['list_of' => 'ID']],
            ]
        ]);
    }

    /**
     * @param $input
     *
     * @throws Exception
     *
     * @return array
     */
    private function add_global_attribute_non_variation_to_product($input): array
    {
        $product_id = base64_decode($input['productId']);
        $product_id = str_replace('product:', '', $product_id);

        $list_attributes = $input['listAttributes'];

        $product = wc_get_product($product_id);

        if (!$product) {
            return [
                'success' => false,
                'message' => __('Product not found', 'wp-custom-global-attributes')
            ];
        }

        $attributes = $product->get_attributes();
        $keep_attribute_ids = [7, 1, 2, 0];

        $attributes = array_filter($attributes, function($attribute) use ($keep_attribute_ids) {
            return in_array($attribute->get_id(), $keep_attribute_ids);
        });

        foreach ($list_attributes as $listAttribute) {
            $attribute_id = (int) $listAttribute['attributeId'];
            $term_ids = array_map('intval', $listAttribute['termIds']);

            $attribute_name = wc_attribute_taxonomy_name_by_id($attribute_id);

            if (!$attribute_name) {
                return [
                    'success' => false,
                    'message' => __('Attribute taxonomy not found', 'wp-custom-global-attributes')
                ];
            }

            $attribute_object = new WC_Product_Attribute();
            $attribute_object->set_id($attribute_id);
            $attribute_object->set_name($attribute_name);
            $attribute_object->set_options($term_ids);
            $attribute_object->set_position(sizeof($attributes));
            $attribute_object->set_visible(true);
            $attribute_object->set_variation(false);

            $attributes[] = $attribute_object;
        }

        $product->set_attributes($attributes);
        $saved = $product->save();

        wc_delete_product_transients($saved);
        clean_post_cache($saved);

        return [
            'success' => true,
            'message' => __('Global attribute (non-variation) added successfully', 'wp-custom-global-attributes')
        ];
    }

    /**
     * @throws Exception
     *
     * @return void
     */
    private function register_update_global_attribute_variations_mutation(): void
    {
        register_graphql_mutation('updateGlobalAttributeVariations', [
            'inputFields' => [
                'productId' => ['type' => 'ID'],
                'listVariations' => ['type' => ['list_of' => 'VariationInput']]
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'message' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function($input, $context, $info) {
                return $this->update_global_attribute_variations($input);
            },
        ]);

        register_graphql_input_type('VariationInput', [
            'description' => 'Input type for variations and terms',
            'fields' => [
                'addAttributeId' => ['type' => 'ID'],
                'deleteAttributeId' => ['type' => 'ID'],
                'variationIdList' => ['type' => ['list_of' => 'ID']],
                'deletedTermId' => ['type' => 'ID'],
                'updatedTermId' => ['type' => 'ID'],
            ]
        ]);
    }

    /**
     * @param array $input
     *
     * @throws Exception
     *
     * @return array
     */
    private function update_global_attribute_variations(array $input): array {
        $product_id = base64_decode($input['productId']);
        $product_id = str_replace('product:', '', $product_id);

        $product = wc_get_product($product_id);
        if (!$product) {
            return [
                'success' => false,
                'message' => __('Product not found', 'wp-custom-global-attributes')
            ];
        }

        foreach ($input['listVariations'] as $variation_input) {
            $addAttributeId = (int) $variation_input['addAttributeId'];
            $deleteAttributeId = (int) $variation_input['deleteAttributeId'];
            $deletedTermId = (int) $variation_input['deletedTermId'];
            $updatedTermId = (int) $variation_input['updatedTermId'];

            if (is_null($variation_input['variationIdList'])) {
                $this->delete_and_update_term_to_global_attribute($product, $addAttributeId, $deleteAttributeId, $deletedTermId, $updatedTermId, false);
            } else {
                $this->delete_and_update_term_to_global_attribute($product, $addAttributeId, $deleteAttributeId, $deletedTermId, $updatedTermId, true);

                foreach ($variation_input['variationIdList'] as $variationId) {
                    $variationId = base64_decode($variationId);
                    $variationId = (int) str_replace('product_variation:', '', $variationId);
                    $this->update_global_attribute_to_variation($variationId, $addAttributeId, $updatedTermId);
                }
            }
        }


        update_post_meta($product_id, 'updateProductGlobalAttributeStatus', "false");

        return [
            'success' => true,
            'message' => __('Variations managed successfully', 'wp-custom-global-attributes')
        ];
    }

    /**
     * @param WC_Product $product
     * @param $attributeId
     * @param $deletedTermId
     * @param $updatedTermId
     * @param $isVariation
     *
     * @return array
     */
    public function delete_and_update_term_to_global_attribute($product, $addAttributeId, $deleteAttributeId, $deletedTermId, $updatedTermId, $isVariation): array
    {
        $attributes = $product->get_attributes();
        $attribute_name = wc_attribute_taxonomy_name_by_id($deleteAttributeId);
        $attribute_name_encode = strtolower(urlencode($attribute_name));

        if (!$attribute_name || !isset($attributes[$attribute_name_encode])) {
            return [
                'success' => false,
                'message' => __('Attribute taxonomy not found', 'wp-custom-global-attributes')
            ];
        }

        $attribute = $attributes[$attribute_name_encode];

        $termIds = $attribute->get_options();

        if (($deletedKey = array_search($deletedTermId, $termIds)) !== false) {
            unset($termIds[$deletedKey]);
        }

        $attribute->set_options($termIds);

        $attribute_object = new WC_Product_Attribute();
        $attribute_object->set_id($deleteAttributeId);
        $attribute_object->set_name($attribute_name);
        $attribute_object->set_options($termIds);
        $attribute_object->set_position(sizeof($attributes));
        $attribute_object->set_visible(true);
        $attribute_object->set_variation($isVariation);

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
     * @param $updatedTermId
     *
     * @return array
     */
    private function update_global_attribute_to_variation($variationId, $attributeId, $updatedTermId): array
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

        $term = get_term($updatedTermId, $attribute_name);
        if (!$term || is_wp_error($term)) {
            return [
                'success' => false,
                'message' => __('Term not found', 'wp-custom-global-attributes')
            ];
        }

        $attributes = $variation->get_attributes();
        $attributes[strtolower(urlencode($attribute_name))] =  $term->slug;

        $variation->set_attributes($attributes);
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
            'message' => __('Attribute updated in variation successfully', 'wp-custom-global-attributes')
        ];
    }

    /**
     * Register the custom root query to get product status.
     */
    private function register_update_product_global_attribute_status_query(): void
    {
        register_graphql_field('RootQuery', 'updateProductGlobalAttributeStatus', [
            'type' => 'Boolean',
            'description' => __('Get status of all products', 'wp-custom-product-status'),
            'args' => [
                'productId' => ['type' => 'ID', 'description' => __('The ID of the product to retrieve the status')],
            ],
            'resolve' => function($root, $args) {
                $product_id = base64_decode($args['productId']);
                $product_id = str_replace('product:', '', $product_id);

                $product = wc_get_product($product_id);

                if (!$product) {
                    return null;
                }

                $isUpdate = get_post_meta($product_id, 'updateProductGlobalAttributeStatus', true);

                // tam thoi luon luon true
                if ($isUpdate === 'false') {
                    return false;
                }

                return true;

            },
        ]);
    }

    /**
     * Register the custom root query to get product attributes and variations.
     */
    private function register_product_attributes_and_variations_field() {
        register_graphql_field('Product', 'getAttributesAndVariationsOfProduct', [
            'type' => 'ProductAttributesAndVariations',
            'description' => __('Get attributes and variations of a product', 'wp-custom-graphql'),
            'args' => [
                'id' => [
                    'type' => 'ID',
                    'description' => __('The ID of the product', 'wp-custom-graphql'),
                ],
            ],
            'resolve' => function($product, $root, $args) {
                return $this->get_product_attributes_and_variations($product->ID);
            }
        ]);

        register_graphql_object_type('ProductAttributesAndVariations', [
            'description' => __('Attributes and variations of a product', 'wp-custom-graphql'),
            'fields' => [
                'id' => ['type' => 'ID'],
                'name' => ['type' => 'String'],
                'attributes' => [
                    'type' => 'AttributeConnection', // Kết nối các attributes
                ],
                'variations' => [
                    'type' => 'VariationConnection', // Kết nối các variations
                ],
            ],
        ]);

        // Đăng ký loại AttributeConnection
        register_graphql_object_type('AttributeConnection', [
            'description' => __('Connection of Attributes', 'wp-custom-graphql'),
            'fields' => [
                'nodes' => [
                    'type' => ['list_of' => 'AttributeNode'],
                ],
            ],
        ]);

        // Đăng ký loại AttributeNode
        register_graphql_object_type('AttributeNode', [
            'description' => __('Node representing an attribute', 'wp-custom-graphql'),
            'fields' => [
                'attributeId' => [
                    'type' => 'ID',
                    'description' => __('ID of the attribute', 'wp-custom-graphql'),
                ],
                'id' => [
                    'type' => 'ID',
                    'description' => __('Encoded ID', 'wp-custom-graphql'),
                ],
                'options' => [
                    'type' => ['list_of' => 'String'],
                    'description' => __('Options for the attribute', 'wp-custom-graphql'),
                ],
                'name' => [
                    'type' => 'String',
                    'description' => __('Name of the attribute', 'wp-custom-graphql'),
                ],
                'label' => [
                    'type' => 'String',
                    'description' => __('Label of the attribute', 'wp-custom-graphql'),
                ],
                'scope' => [
                    'type' => 'String',
                    'description' => __('Scope of the attribute', 'wp-custom-graphql'),
                ],
            ],
        ]);

        // Đăng ký loại VariationConnection
        register_graphql_object_type('VariationConnection', [
            'description' => __('Connection of Variations', 'wp-custom-graphql'),
            'fields' => [
                'nodes' => [
                    'type' => ['list_of' => 'VariationNode'],
                ],
            ],
        ]);

        // Đăng ký loại VariationNode
        register_graphql_object_type('VariationNode', [
            'description' => __('Node representing a variation', 'wp-custom-graphql'),
            'fields' => [
                'id' => [
                    'type' => 'ID',
                    'description' => __('Encoded ID of the variation', 'wp-custom-graphql'),
                ],
                'name' => [
                    'type' => 'String',
                    'description' => __('Name of the variation', 'wp-custom-graphql'),
                ],
                'attributes' => [
                    'type' => 'AttributeEdgeConnection',
                    'description' => __('Attributes of the variation', 'wp-custom-graphql'),
                ],
            ],
        ]);

        // Đăng ký loại AttributeEdgeConnection
        register_graphql_object_type('AttributeEdgeConnection', [
            'description' => __('Connection of Attribute Edges', 'wp-custom-graphql'),
            'fields' => [
                'edges' => [
                    'type' => ['list_of' => 'AttributeEdge'],
                ],
            ],
        ]);

        // Đăng ký loại AttributeEdge
        register_graphql_object_type('AttributeEdge', [
            'description' => __('Edge for an attribute', 'wp-custom-graphql'),
            'fields' => [
                'node' => [
                    'type' => 'AttributeEdgeNode',
                ],
            ],
        ]);

        // Đăng ký loại AttributeEdgeNode
        register_graphql_object_type('AttributeEdgeNode', [
            'description' => __('Node for the attribute edge', 'wp-custom-graphql'),
            'fields' => [
                'attributeId' => [
                    'type' => 'ID',
                    'description' => __('ID of the attribute', 'wp-custom-graphql'),
                ],
                'id' => [
                    'type' => 'ID',
                    'description' => __('Encoded ID for the attribute edge', 'wp-custom-graphql'),
                ],
                'label' => [
                    'type' => 'String',
                    'description' => __('Label of the attribute', 'wp-custom-graphql'),
                ],
                'name' => [
                    'type' => 'String',
                    'description' => __('Name of the attribute', 'wp-custom-graphql'),
                ],
                'value' => [
                    'type' => 'String',
                    'description' => __('Value of the attribute', 'wp-custom-graphql'),
                ],
            ],
        ]);
    }

    /**
     * @param int $product_id
     *
     * @return array|null
     */
    private function get_product_attributes_and_variations(int $product_id) {
        $product = wc_get_product($product_id);

        if (!$product) {
            return null;
        }

        // Lấy thông tin attributes
        $attributes = [];
        foreach ($product->get_attributes() as $attribute) {
            $options = [];

            if ($attribute->is_taxonomy()) {
                $terms = wc_get_product_terms($product_id, $attribute->get_name(), ['fields' => 'all']);
                foreach ($terms as $term) {
                    $options[] = $term->name;
                }
            } else {
                $options = $attribute->get_options();
            }

            $attributes[] = [
                'attributeId' => $attribute->get_id(),
                'id' => base64_encode('pa_' . $attribute->get_name() . ':' . $product_id),
                'options' => $options,
                'name' => $attribute->get_name(),
                'label' => $attribute->get_name(),
                'scope' => $attribute->is_taxonomy() ? 'GLOBAL' : 'LOCAL',
            ];
        }

        // Lấy thông tin variations
        $variations = [];
        if ($product->is_type('variable')) {
            $variation_ids = $product->get_children();
            foreach ($variation_ids as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation) {
                    continue;
                }

                $variation_attributes = [];
                foreach ($variation->get_attributes() as $name => $value) {
                    $attribute_name = substr($name, 3);
                    $attribute_id = wc_attribute_taxonomy_id_by_name($attribute_name);

                    $variation_attributes[] = [
                        'attributeId' => $attribute_id ? (int) $attribute_id : 0,
                        'id' => base64_encode($variation_id . '||' . $name . '||' . $value),
                        'label' => urldecode(wc_attribute_label($name)),
                        'name' => urldecode($name),
                        'value' => urldecode($value),
                    ];
                }

                $variations[] = [
                    'id' => base64_encode('product_variation:' . $variation_id),
                    'name' => $variation->get_name(),
                    'attributes' => [
                        'edges' => array_map(function($attr) {
                            return ['node' => $attr];
                        }, $variation_attributes),
                    ],
                ];
            }
        }

        return [
            'id' => base64_encode('product:' . $product_id),
            'name' => $product->get_name(),
            'attributes' => [
                'nodes' => $attributes,
            ],
            'variations' => [
                'nodes' => $variations,
            ],
        ];
    }

    /**
     * @param string $name
     *
     * @return object|null
     */
    private function get_attribute_taxonomy_by_name_custom($name) {
        $attribute_taxonomies = wc_get_attribute_taxonomies();
        if (!$attribute_taxonomies) {
            return null;
        }

        foreach ($attribute_taxonomies as $taxonomy) {
            if ($taxonomy->attribute_name === $name) {
                return $taxonomy;
            }
        }

        return null;
    }

}
