<?php

class WCGE_Product {
    public function __construct() {
        add_action('graphql_register_types', [$this, 'register_graphql_fields']);
    }

    /**
     * @throws Exception
     */
    public function register_graphql_fields() {
        $this->register_product_status_query();
        $this->register_detail_product_query();
        $this->register_update_product_details_mutation();
    }

    /**
     * Register the custom root query to get product status.
     */
    private function register_product_status_query(): void
    {
        register_graphql_field('RootQuery', 'getProductStatusList', [
            'type' => ['list_of' => 'ProductStatus'],
            'description' => __('Get status of all products', 'wp-custom-product-status'),
            'resolve' => function() {
                $products = wc_get_products(array('limit' => -1));
                $product_status_list = [];

                foreach ($products as $product) {
                    $product_status_list[] = $this->get_product_status($product->get_id());
                }

//                $product_status_list[] = $this->get_product_status("cHJvZHVjdDoxMjgxOTI=");

                return $product_status_list;
            },
        ]);

        register_graphql_object_type('ProductStatus', [
            'fields' => [
                'id' => ['type' => 'ID'],
                'productName' => ['type' => 'String'],
                'hasGlobalAttributes' => ['type' => 'Boolean'],
                'hasImages' => ['type' => 'Boolean'],
                'hasSizeTable' => ['type' => 'Boolean'],
                'hasTextAndSelectionFields' => ['type' => 'Boolean'],
            ],
        ]);
    }

    /**
     * Get the status of a product.
     *
     * @param string $product_id
     *
     * @return array
     */
    private function get_product_status(string $product_id): array
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            return [
                'id' => $product_id,
                'productName' => null,
                'hasGlobalAttributes' => false,
                'hasImages' => false,
                'hasSizeTable' => false,
                'hasTextAndSelectionFields' => false,
            ];
        }

        $attributes = $product->get_attributes();
        $isGlobalAttribute = false;
        foreach ($attributes as $key => $attribute) {
            if ($attribute->get_id() !== 0 ) {
                $isGlobalAttribute = true;
                break;
            }
        }

        $has_global_attributes = $isGlobalAttribute;

        $has_images = !empty($product->get_gallery_image_ids()) && !empty($product->get_image_id());

        if($has_images) {
            $has_images = false;
            $color_specific_galleries = get_post_meta($product_id, 'color_specific_galleries', true);

            foreach ($color_specific_galleries as $gallery) {
                if (!empty($gallery)) {
                    $has_images = true;
                    break;
                }
            }
        }

        $size_table = get_post_meta($product_id, 'size_table', true);
        $has_size_table = !empty($size_table);

        if($has_size_table)
        {
            $size_table_array = json_decode($size_table, true);
            $has_size_table = false;

            if (is_array($size_table_array)) {
                foreach ($size_table_array as $row) {
                    foreach ($row as $value) {
                        if (!empty($value)) {
                            $has_size_table = true;
                            break 2;
                        }
                    }
                }
            }
        }

        $has_text_and_selection_fields = $this->has_all_required_fields_filled($product_id);

        return [
            'id' => base64_encode('product:' . $product_id),
            'productName' => $product->get_name(),
            'hasGlobalAttributes' => $has_global_attributes,
            'hasImages' => $has_images,
            'hasSizeTable' => $has_size_table,
            'hasTextAndSelectionFields' => $has_text_and_selection_fields,
        ];
    }

    /**
     * @param int $product_id
     *
     * @return bool
     */
    private function has_all_required_fields_filled(int $product_id): bool
    {
        $product = wc_get_product($product_id);

        $title = $product->get_name();
        if (empty($title)) {
            return false;
        }

        $short_description = $product->get_short_description();
        if (empty($short_description)) {
            return false;
        }

        $description = $product->get_description();
        if (empty($description)) {
            return false;
        }

        $categories = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'names']);
        if (is_array($categories) && count($categories) === 0) {
            return false;
        }

        $tags = wp_get_post_terms($product_id, 'product_tag', ['fields' => 'names']);
        if (is_array($tags) && count($tags) === 0) {
            return false;
        }

        $yoast_focus_keyword = get_post_meta($product_id, '_yoast_wpseo_focuskw', true);
        if (empty($yoast_focus_keyword)) {
            return false;
        }

        $yoast_title_template = get_post_meta($product_id, '_yoast_wpseo_title', true);
        $yoast_title = wpseo_replace_vars($yoast_title_template, get_post($product_id));
        if (empty($yoast_title)) {
            return false;
        }

        $yoast_slug = get_post_field('post_name', $product_id);
        if (empty($yoast_slug)) {
            return false;
        }

        $yoast_meta_description = get_post_meta($product_id, '_yoast_wpseo_metadesc', true);
        if (empty($yoast_meta_description)) {
            return false;
        }

        if ($product->is_type('variable')) {
            $available_variations = $product->get_available_variations();
            foreach ($available_variations as $variation_data) {
                $variation = wc_get_product($variation_data['variation_id']);
                $variation_description = $variation->get_description();

                if (empty($variation_description)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @throws Exception
     *
     * @return void
     */
    function register_detail_product_query(): void
    {
        register_graphql_field('RootQuery', 'getProductDetailsById', [
            'type' => 'ProductDetails',
            'args' => [
                'productId' => [
                    'type' => 'ID',
                    'description' => __('ID of the product', 'wp-custom-global-attributes'),
                ]
            ],
            'resolve' => function($root, $args) {
                return $this->get_detail_product($args);
            }
        ]);

        register_graphql_object_type('ProductDetails', [
            'fields' => [
                'id' => ['type' => 'ID'],
                'title' => ['type' => 'String'],
                'description' => ['type' => 'String'],
                'shortDescription' => ['type' => 'String'],
                'categories' => ['type' => ['list_of' => 'CategoryType']],
                'tags' => ['type' => ['list_of' => 'TagType']],
                'yoastSeo' => ['type' => 'YoastSEO'],
//                'variations' => ['type' => ['list_of' => 'ProductVariationDetail']],
                'attributes' => ['type' => ['list_of' => 'ProductAttributes']]
            ]
        ]);

        register_graphql_object_type('CategoryType', [
            'fields' => [
                'id' => ['type' => 'ID'],
                'name' => ['type' => 'String'],
            ]
        ]);

        register_graphql_object_type('TagType', [
            'fields' => [
                'id' => ['type' => 'ID'],
                'name' => ['type' => 'String'],
            ]
        ]);

        register_graphql_object_type('YoastSEO', [
            'fields' => [
                'focusKeyword' => ['type' => 'String'],
                'title' => ['type' => 'String'],
                'slug' => ['type' => 'String'],
                'metaDescription' => ['type' => 'String'],
            ]
        ]);

//        register_graphql_object_type('ProductVariationDetail', [
//            'fields' => [
//                'variationId' => ['type' => 'ID'],
//                'title' => ['type' => 'String'],
//                'description' => ['type' => 'String'],
//            ]
//        ]);

        register_graphql_object_type('ProductAttributes', [
            'fields' => [
                'id' => ['type' => 'ID'],
                'name' => ['type' => 'String'],
                'slug' => ['type' => 'String'],
                'value' => ['type' => ['list_of' => 'String']],
            ]
        ]);
    }

    /**
     * @param $input
     *
     * @return ?array
     *@throws Exception
     *
     */
    private function get_detail_product($input): ?array
    {
        $product_id = base64_decode($input['productId']);
        $product_id = str_replace('product:', '', $product_id);

        $product = wc_get_product($product_id);

        if (!$product) {
            return null;
        }

        $yoast_title_template = get_post_meta($product_id, '_yoast_wpseo_title', true);
        $yoast_title = wpseo_replace_vars($yoast_title_template, get_post($product_id));

//        $variations = [];
//        if ($product->is_type('variable')) {
//            $variation_ids = $product->get_children(); // Lấy tất cả các variation ID
//            foreach ($variation_ids as $variation_id) {
//                $variation = wc_get_product($variation_id);
//                if ($variation) {
//                    $variations[] = [
//                        'variationId' => base64_encode('product_variation:' . $variation->get_id()),
//                        'title' => $variation->get_name(),
//                        'description' => $variation->get_description(),
//                    ];
//                }
//            }
//        }

        $attributes = $product->get_attributes();
        $productAttributes = [];

        foreach ($attributes as $attribute) {
            if (in_array($attribute['id'], [1, 2, 7])) {
                continue;
            }

            $productAttributes[] = [
                'id' => $attribute['id'] ,
                'name' => $attribute['name'],
                'slug' => $attribute['slug'],
                'value' =>  $attribute['options']
            ];
        }

        $categories = [];
        $tags = [];

        $category_terms = wp_get_post_terms($product_id, 'product_cat');
        foreach ($category_terms as $term) {
            $categories[] = [
                'id' => $term->term_id,
                'name' => urldecode($term->name)
            ];
        }

        $tag_terms = wp_get_post_terms($product_id, 'product_tag');
        foreach ($tag_terms as $term) {
            $tags[] = [
                'id' => $term->term_id,
                'name' => urldecode($term->name)
            ];
        }

        return [
            'id' => $input['productId'],
            'title' => urldecode($product->get_name()),
            'description' => $product->get_description(),
            'shortDescription' => $product->get_short_description(),
            'categories' => $categories,
            'tags' => $tags,
            'yoastSeo' => [
                'focusKeyword' => urldecode(get_post_meta($product_id, '_yoast_wpseo_focuskw', true)),
                'title' => urldecode($yoast_title),
                'slug' => urldecode(get_post_field('post_name', $product_id)),
                'metaDescription' => urldecode(get_post_meta($product_id, '_yoast_wpseo_metadesc', true)),
            ],
//            'variations' => $variations,
            'attributes' => $productAttributes,
        ];
    }

    /**
     * Register the mutation to update product details.
     */
    function register_update_product_details_mutation(): void
    {
        register_graphql_mutation('updateProductDetails', [
            'inputFields' => [
                'productId' => [
                    'type' => 'ID',
                    'description' => __('ID of the product', 'wp-custom-product-detail'),
                ],
                'title' => [
                    'type' => 'String',
                    'description' => __('Title of the product', 'wp-custom-product-detail'),
                ],
                'description' => [
                    'type' => 'String',
                    'description' => __('Description of the product', 'wp-custom-product-detail'),
                ],
                'shortDescription' => [
                    'type' => 'String',
                    'description' => __('Short Description of the product', 'wp-custom-product-detail'),
                ],
                'categories' => [
                    'type' => ['list_of' => 'ID'],
                    'description' => __('Categories of the product', 'wp-custom-product-detail'),
                ],
                'tags' => [
                    'type' => ['list_of' => 'ID'],
                    'description' => __('Tags of the product', 'wp-custom-product-detail'),
                ],
                'yoastSeo' => [
                    'type' => 'YoastSEOInput',
                    'description' => __('Yoast SEO data', 'wp-custom-product-detail'),
                ],
//                'variations' => [
//                    'type' => ['list_of' => 'VariationDetailInput'],
//                    'description' => __('Variations of the product', 'wp-custom-product-detail'),
//                ],
                'listAttributes' => [
                    'type' => ['list_of' => 'ProductAttributeInputt'],
                    'description' => __('List of attributes to be added', 'wp-custom-product-detail'),
                ],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'message' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function($input) {
                return $this->update_product_details_mutation($input);
            }
        ]);

        register_graphql_input_type('YoastSEOInput', [
            'fields' => [
                'focusKeyword' => ['type' => 'String'],
                'title' => ['type' => 'String'],
                'slug' => ['type' => 'String'],
                'metaDescription' => ['type' => 'String'],
            ],
        ]);

//        register_graphql_input_type('VariationDetailInput', [
//            'fields' => [
//                'variationId' => ['type' => 'ID'],
//                'description' => ['type' => 'String'],
//            ],
//        ]);

        register_graphql_input_type('ProductAttributeInputt', [
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
    private function update_product_details_mutation($input): array {
        $product_id = base64_decode($input['productId']);
        $product_id = str_replace('product:', '', $product_id);

        $product = wc_get_product($product_id);
        if (!$product) {
            return [
                'success' => false,
                'message' => __('Product not found', 'wp-custom-product-detail')
            ];
        }

        $is_updated = false;

        if (!empty($input['title']) && $product->get_name() !== $input['title']) {
            $product->set_name($input['title']);
            $is_updated = true;
        }

        if (!empty($input['description']) && $product->get_description() !== $input['description']) {
            $product->set_description($input['description']);
            $is_updated = true;
        }

        if (!empty($input['shortDescription']) && $product->get_short_description() !== $input['shortDescription']) {
            $product->set_short_description($input['shortDescription']);
            $is_updated = true;
        }

        $this->update_product_terms($product_id, $input, $is_updated);

        $this->update_product_seo($product, $input, $is_updated);

        if ($this->update_product_attributes($product, $input, $is_updated)) {
            $is_updated = true;
        }

        if ($is_updated) {
            $product->save();
        }

        return [
            'success' => true,
            'message' => __('Product details updated successfully.'),
        ];
    }

    private function update_product_terms($product_id, $input, &$is_updated) {
        if (isset($input['categories'])) {
            $current_categories = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
            if (array_diff($input['categories'], $current_categories) || array_diff($current_categories, $input['categories'])) {
                wp_set_post_terms($product_id, $input['categories'], 'product_cat');
                $is_updated = true;
            }
        }

        if (isset($input['tags'])) {
            $tag_ids = array_map('intval', $input['tags']);
            $current_tags = wp_get_post_terms($product_id, 'product_tag', ['fields' => 'ids']);

            if (array_diff($tag_ids, $current_tags) || array_diff($current_tags, $tag_ids)) {
                // Cập nhật các tag dựa trên ID
                wp_set_post_terms($product_id, $tag_ids, 'product_tag', false);
                $is_updated = true;
            }
        }

    }

    private function update_product_seo($product, $input, &$is_updated) {
        if (!empty($input['yoastSeo'])) {
            $yoast_seo = $input['yoastSeo'];

            // Kiểm tra và cập nhật từng trường meta
            if (get_post_meta($product->get_id(), '_yoast_wpseo_focuskw', true) !== $yoast_seo['focusKeyword']) {
                update_post_meta($product->get_id(), '_yoast_wpseo_focuskw', $yoast_seo['focusKeyword']);
                $is_updated = true;
            }
            if (get_post_meta($product->get_id(), '_yoast_wpseo_title', true) !== $yoast_seo['title']) {
                update_post_meta($product->get_id(), '_yoast_wpseo_title', $yoast_seo['title']);
                $is_updated = true;
            }
            if ($product->get_slug() !== $yoast_seo['slug']) {
                wp_update_post(['ID' => $product->get_id(), 'post_name' => $yoast_seo['slug']]);
                $is_updated = true;
            }
            if (get_post_meta($product->get_id(), '_yoast_wpseo_metadesc', true) !== $yoast_seo['metaDescription']) {
                update_post_meta($product->get_id(), '_yoast_wpseo_metadesc', $yoast_seo['metaDescription']);
                $is_updated = true;
            }
        }
    }

    private function update_product_attributes($product, $input, &$is_updated) {
        $attributes = $product->get_attributes();
        $changed = false;

        if (!empty($input['listAttributes'])) {
            foreach ($input['listAttributes'] as $listAttribute) {
                $attribute_id = (int) $listAttribute['attributeId'];
                $term_ids = array_map('intval', $listAttribute['termIds']);
                $attribute_name = wc_attribute_taxonomy_name_by_id($attribute_id);

                if (!$attribute_name) {
                    return [
                        'success' => false,
                        'message' => __('Attribute taxonomy not found', 'wp-custom-global-attributes')
                    ];
                }

                $existing_attribute = null;
                foreach ($attributes as $attribute) {
                    if ($attribute->get_id() === $attribute_id) {
                        $existing_attribute = $attribute;
                        break;
                    }
                }

                if (!$existing_attribute || $existing_attribute->get_options() !== $term_ids) {
                    $attribute_object = new WC_Product_Attribute();
                    $attribute_object->set_id($attribute_id);
                    $attribute_object->set_name($attribute_name);
                    $attribute_object->set_options($term_ids);
                    $attributes[] = $attribute_object;
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $product->set_attributes($attributes);
            $is_updated = true;
        }

        return $changed;
    }

}
