<?php

class WCGE_Tag {
    public function __construct() {
        add_action('graphql_register_types', [$this, 'register_graphql_fields']);
    }

    /**
     * @throws Exception
     */
    public function register_graphql_fields() {
        $this->register_all_tags_field();
    }

    /**
     * @throws Exception
     *
     * @return void
     */
    private function register_all_tags_field(): void
    {
        register_graphql_field('RootQuery', 'allTags', [
            'type' => ['list_of' => 'AllTag'],
            'description' => __('Get all product tags', 'wp-custom-tags'),
            'resolve' => function() {
                return $this->get_all_tags();
            },
        ]);

        register_graphql_object_type('AllTag', [
            'description' => __('Product Tag', 'wp-custom-tags'),
            'fields' => [
                'id' => ['type' => 'ID'],
                'name' => ['type' => 'String'],
                'slug' => ['type' => 'String'],
            ],
        ]);
    }

    /**
     * @return array
     */
    private function get_all_tags(): array
    {
        $tags = get_terms([
            'taxonomy' => 'product_tag',
            'hide_empty' => false,
        ]);

        $result = [];

        foreach ($tags as $tag) {
            $result[] = [
                'id' => $tag->term_id,
                'name' => $tag->name,
                'slug' => $tag->slug,
            ];
        }

        return $result;
    }
}
