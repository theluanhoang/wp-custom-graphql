<?php

class WCGE_Category {
    public function __construct() {
        add_action('graphql_register_types', [$this, 'register_graphql_fields']);
    }

    /**
     * @throws Exception
     */
    public function register_graphql_fields() {
        $this->register_all_categories_field();
    }

    /**
     * @throws Exception
     *
     * @return void
     */
    private function register_all_categories_field(): void
    {
        register_graphql_field('RootQuery', 'allCategories', [
            'type' => ['list_of' => 'AllCategory'],
            'description' => __('Get all product categories', 'wp-custom-categories'),
            'args' => [
                'language' => [
                    'type' => 'String',
                    'description' => __('Language code to get categories in a specific language', 'wp-custom-categories')
                ],
            ],
            'resolve' => function($root, $args) {
                $language = isset($args['language']) ? $args['language'] : null;
                return $this->get_all_categories($language);
            },
        ]);

        register_graphql_object_type('AllCategory', [
            'description' => __('Product Category', 'wp-custom-categories'),
            'fields' => [
                'id' => ['type' => 'ID'],
                'name' => ['type' => 'String'],
                'parentId' => ['type' => 'ID'],
                'description' => ['type' => 'String'],
            ],
        ]);
    }

    /**
     * @param string|null $language
     * @return array
     */
    private function get_all_categories(?string $language = null): array
    {
        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ]);

        $result = [];

        foreach ($categories as $category) {
            $translated_id = apply_filters('wpml_object_id', $category->term_id, 'product_cat', true, $language);

            if ($translated_id) {
                $translated_category = get_term($translated_id, 'product_cat');

                $result[] = [
                    'id' => $category->term_id,
                    'name' => $translated_category->name,
                    'parentId' => $category->parent ? $category->parent : null,
                    'description' => $translated_category->description
                ];
            }
        }

        return $result;
    }
}
