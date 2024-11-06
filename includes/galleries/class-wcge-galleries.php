<?php

class WCGE_Galleries {
    public function __construct() {
        add_action('graphql_register_types', [$this, 'register_graphql_fields']);
    }

    /**
     * @throws Exception
     */
    public function register_graphql_fields() {
        $this->register_upload_image_mutation();
        $this->register_all_product_images_query();
        $this->register_update_product_images_mutation();
        $this->register_color_specific_galleries_query();
        $this->register_save_color_specific_galleries_mutation();
        $this->register_all_product_image_query();
        $this->register_save_all_product_images_galleries_mutation();
        $this->register_delete_product_image_mutation();
        $this->register_delete_image_mutation();
        $this->register_delete_image_in_color_gallery_mutation();
        $this->register_delete_image_in_main_gallery_mutation();
        $this->register_product_size_chart_query();
        $this->register_save_size_chart_mutation();
        $this->register_product_delete_size_chart_query();
        $this->register_get_image_info_query();
        $this->register_update_image_info_mutation();
    }

    /**
     * @throws Exception
     *
     * @return void
     */
    private function register_upload_image_mutation(): void
    {
        register_graphql_mutation('uploadImage', [
            'inputFields' => [
                'file' => ['type' => 'String'], // Đường dẫn tệp tin dưới dạng base64
                'filename' => ['type' => 'String'], // Tên tệp tin
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'message' => ['type' => 'String'],
                'attachmentId' => ['type' => 'ID'], // ID của attachment được tạo
            ],
            'mutateAndGetPayload' => function($input, $context, $info) {
                return $this->upload_image($input);
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
    private function upload_image($input): array
    {
        // Giải mã dữ liệu hình ảnh từ base64
        $data_prefix = 'data:image/';
        if (strpos($input['file'], $data_prefix) === 0) {
            $file_data = substr($input['file'], strpos($input['file'], ',') + 1);
            $file_data = base64_decode($file_data);
            if ($file_data === false) {
                return [
                    'success' => false,
                    'message' => __('Base64 decode failed', 'wp-custom-upload-image'),
                ];
            }

            // Kiểm tra xem nội dung giải nén có phải là một tệp hình ảnh hay không
            if (!@imagecreatefromstring($file_data)) {
                return [
                    'success' => false,
                    'message' => __('Decoded data is not a valid image', 'wp-custom-upload-image'),
                ];
            }
        } else {
            return [
                'success' => false,
                'message' => __('Invalid base64 image format', 'wp-custom-upload-image'),
            ];
        }

        $filename = sanitize_file_name($input['filename']);

        // Lấy thư mục upload chuẩn của WordPress
        $upload_dir = wp_upload_dir();
        if (empty($upload_dir['path'])) {
            return [
                'success' => false,
                'message' => __('Upload directory not found', 'wp-custom-upload-image'),
            ];
        }

        // Tạo đường dẫn đầy đủ cho file
        $upload_path = $upload_dir['path'] . '/' . $filename; // Đường dẫn lưu file

        // Kiểm tra quyền ghi vào thư mục upload
        if (!is_writable($upload_dir['path'])) {
            return [
                'success' => false,
                'message' => __('Upload directory is not writable', 'wp-custom-upload-image'),
            ];
        }

        // Tạo file tạm từ dữ liệu hình ảnh
        if (file_put_contents($upload_path, $file_data) === false) {
            return [
                'success' => false,
                'message' => __('Failed to save image to the upload directory', 'wp-custom-upload-image'),
            ];
        }

        // Kiểm tra loại file
        $file_type = wp_check_filetype($filename, null);
        if (!$file_type['type']) {
            unlink($upload_path); // Xóa file nếu loại không hợp lệ
            return [
                'success' => false,
                'message' => __('File type is not valid', 'wp-custom-upload-image'),
            ];
        }

        // Tạo attachment
        $attachment = [
            'guid'           => $upload_dir['url'] . '/' . $filename, // Đường dẫn URL đầy đủ
            'post_mime_type' => $file_type['type'],
            'post_title'     => sanitize_file_name($filename),
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];

        // Thêm attachment vào thư viện media
        $attach_id = wp_insert_attachment($attachment, $upload_path);
        if (is_wp_error($attach_id)) {
            unlink($upload_path); // Xóa file nếu thêm không thành công
            return [
                'success' => false,
                'message' => __('Failed to insert attachment into media library', 'wp-custom-upload-image'),
            ];
        }

        // Cần phải kích hoạt lại các hàm để xử lý tệp tin
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $upload_path);
        if (!$attach_data) {
            return [
                'success' => false,
                'message' => __('Failed to generate attachment metadata', 'wp-custom-upload-image'),
            ];
        }
        wp_update_attachment_metadata($attach_id, $attach_data);

        return [
            'success' => true,
            'message' => __('Image uploaded successfully', 'wp-custom-upload-image'),
            'attachmentId' => $attach_id,
        ];
    }

    /**
     * Register the custom root query to get all product ímages.
     */
    private function register_all_product_images_query(): void
    {
        register_graphql_field('RootQuery', 'getAllProductImages', [
            'type' => ['list_of' => 'ProductImage'],
            'args' => [
                'productId' => ['type' => 'ID', 'description' => __('The ID of the product to retrieve images for')],
            ],
            'resolve' => function($root, $args) {
                return $this->get_product_images($args);
            },
        ]);

        register_graphql_object_type('ProductImage', [
            'fields' => [
                'id' => ['type' => 'ID'],
                'title' => ['type' => 'String'],
                'sourceUrl' => ['type' => 'String'],
                'isMain' => ['type' => 'Boolean'],
                'altText' => ['type' => 'String'],
            ],
        ]);
    }

    /**
     * @param $input
     *
     * @return array
     */
    function get_product_images($input): array
    {
        $product_id = base64_decode($input['productId']);
        $product_id = str_replace('product:', '', $product_id);

        $product = wc_get_product($product_id);

        if (!$product) {
            return [];
        }

        $images = [];

        $featured_image_id = $product->get_image_id();
        if ($featured_image_id) {
            $images[] = [
                'id' => base64_encode('post:'.$featured_image_id),
                'title' => get_the_title($featured_image_id),
                'sourceUrl' => wp_get_attachment_url($featured_image_id),
                'isMain' => true,
                'altText' => get_post_meta($featured_image_id, '_wp_attachment_image_alt', true)
            ];
        }

        $gallery_image_ids = $product->get_gallery_image_ids();
        foreach ($gallery_image_ids as $image_id) {
            $images[] = [
                'id' => base64_encode('post:'.$image_id),
                'title' => get_the_title($image_id),
                'sourceUrl' => wp_get_attachment_url($image_id),
                'isMain' => false,
                'altText' => get_post_meta($image_id, '_wp_attachment_image_alt', true)
            ];
        }

        return $images;
    }

    /**
     * Register the custom mutation to update product images.
     */
    private function register_update_product_images_mutation(): void
    {
        register_graphql_mutation('updateProductImages', [
            'inputFields' => [
                'productId' => ['type' => 'ID'],
                'listProductImages' => [
                    'type' => ['list_of' => 'String'],
                    'description' => __('List of product images to update'),
                ],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'message' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function($input,$context, $info) {
                return $this->update_product_images($input);
            },
        ]);
    }

    /**
     * Update product images.
     *
     * @param $input
     *
     * @return array
     */
    function update_product_images($input): array
    {
        $product_id = base64_decode($input['productId']);
        $product_id = str_replace('product:', '', $product_id);

        $product = wc_get_product($product_id);

        if (!$product) {
            return [
                'success' => false,
                'message' => __('Product not found.'),
            ];
        }

        // Get the list of image IDs from the input
        $list_product_images = $input['listProductImages'];

        if (empty($list_product_images)) {
            $product->set_image_id(0);
            $product->set_gallery_image_ids([]);
        } else {
            $array_map = [];
            foreach ($list_product_images as $key => $image) {
                $post_id = base64_decode($image);
                $post_id = str_replace('post:', '', $post_id);
                $array_map[$key] = $post_id;
            }
            $image_ids = $array_map;

            if (!empty($image_ids)) {
                $product->set_image_id($image_ids[0]);
            }

            if (count($image_ids) > 1) {
                $gallery_ids = array_slice($image_ids, 1);
                $product->set_gallery_image_ids($gallery_ids);
            } else {
                $product->set_gallery_image_ids([]);
            }
        }

        $product->save();

        return [
            'success' => true,
            'message' => __('Product images updated successfully.'),
        ];
    }

    /**
     * Register the custom root query to get color specific galleries.
     */
    private function register_color_specific_galleries_query(): void
    {
        register_graphql_field('RootQuery', 'getColorSpecificGalleries', [
            'type' => 'String',
            'description' => __('Get  color specific galleries of a product', 'wp-custom-global-attributes'),
            'args' => [
                'productId' => ['type' => 'ID', 'description' => __('The ID of the product to retrieve color galleries for')],
            ],
            'resolve' => function($root, $args) {
                return $this->get_color_specific_galleries($args);
            },
        ]);
    }

    /**
     * Get color specific galleries from product metadata.
     *
     * @param array $args
     *
     * @return ?string
     */
    function get_color_specific_galleries(array $args):  ?string
    {
        $product_id = base64_decode($args['productId']);
        $product_id = str_replace('product:', '', $product_id);

        $product = wc_get_product($product_id);

        if (!$product) {
            return null;
        }

        $color_specific_galleries = get_post_meta($product_id, 'color_specific_galleries', true);

        if (empty($color_specific_galleries) || !is_array($color_specific_galleries)) {
            $temp = [];
            update_post_meta($product_id, 'color_specific_galleries', $temp);
            $color_specific_galleries = get_post_meta($product_id, 'color_specific_galleries', true);
        }

        $attributes = $product->get_attributes();

        $color_attributes = [];

        foreach ($attributes as $attribute) {
            if (in_array($attribute['id'], [1, 7])) {
                $color_attributes = $attribute['options'];
                break;
            }
        }

        $attribute_names = [];

        foreach ($color_attributes as $term_id) {
            $term = get_term($term_id);
            if ($term && !is_wp_error($term)) {
                $attribute_names[] = strtolower($term->name);
            }
        }

        foreach ($color_specific_galleries as $key => $gallery) {
            if (!in_array($key, $attribute_names)) {
                unset($color_specific_galleries[$key]);
            }
        }

        foreach ($attribute_names as $attribute_name) {
            if (!isset($color_specific_galleries[strtolower($attribute_name)])) {
                $color_specific_galleries[strtolower($attribute_name)] = [];
            }
        }

        foreach ($color_specific_galleries as $key => &$gallery) {
            foreach ($gallery as &$image) {
                $image_id = base64_decode($image['id']);
                $image_id = (int) str_replace('post:', '', $image_id);

                $attachment = get_post($image_id);

                if ($attachment && $attachment->post_type === 'attachment') {
                    $image['altText'] = get_post_meta($image_id, '_wp_attachment_image_alt', true);
                    $image['title'] = $attachment->post_title;
                    $image['sourceUrl'] = wp_get_attachment_url($image_id);
                }
            }
        }

        update_post_meta($product_id, 'color_specific_galleries', $color_specific_galleries);

        return json_encode($color_specific_galleries);
    }

    /**
     * Register the custom mutation to save color specific galleries.
     */
    private function register_save_color_specific_galleries_mutation(): void
    {
        register_graphql_mutation('saveColorSpecificGalleries', [
            'inputFields' => [
                'productId' => [
                    'type' => 'ID',
                    'description' => __('The ID of the product to save color galleries for'),
                ],
                'colorSpecificGalleries' => [
                    'type' => 'string',
                    'description' => __('Color specific galleries to save in product metadata'),
                ],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'message' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function($input, $context, $info) {
                return $this->save_color_specific_galleries($input);
            },
        ]);
    }

    /**
     * Save color specific galleries in product metadata.
     *
     * @param array $input
     * @return array
     */
    function save_color_specific_galleries(array $input): array
    {
        $product_id = base64_decode($input['productId']);
        $product_id = str_replace('product:', '', $product_id);

        $color_specific_galleries = $input['colorSpecificGalleries'];
        $color_specific_galleries_array = json_decode($color_specific_galleries, true);

        $product = wc_get_product($product_id);

        if (!$product) {
            return [
                'success' => false,
                'message' => __('Product not found.'),
            ];
        }

        update_post_meta($product_id, 'color_specific_galleries', $color_specific_galleries_array);

        return [
            'success' => true,
            'message' => __('Color specific galleries saved successfully.'),
        ];
    }

    /**
     * Register the custom root query to get all product image galleries.
     */
    private function register_all_product_image_query(): void
    {
        register_graphql_field('RootQuery', 'getAllProductImagesPool', [
            'type' => 'String',
            'description' => __('Get all image of a product', 'wp-custom-global-attributes'),
            'args' => [
                'productId' => ['type' => 'ID', 'description' => __('The ID of the product to retrieve all image for')],
            ],
            'resolve' => function($root, $args) {
                return $this->get_all_product_images($args);
            },
        ]);
    }

    /**
     * Get all images from product metadata.
     *
     * @param array $args
     *
     * @return ?string
     */
    function get_all_product_images(array $args):  ?string
    {
        $product_id = base64_decode($args['productId']);
        $product_id = str_replace('product:', '', $product_id);

        $product = wc_get_product($product_id);

        if (!$product) {
            return null;
        }

        $all_images = get_post_meta($product_id, 'all_images', true);

        if (empty($all_images) || !is_array($all_images)) {
            $all_images = [];

            // Get the main image
            $main_image_id = $product->get_image_id();
            if ($main_image_id) {
                $main_image_url = wp_get_attachment_url($main_image_id);
                $main_image_title = get_the_title($main_image_id);
                if ($main_image_url) {
                    $all_images[] = [
                        'id' => base64_encode('post:'.$main_image_id),
                        'title' => $main_image_title,
                        'sourceUrl' => $main_image_url
                    ];
                }
            }

            // Get images from the product galleries
            $gallery_image_ids = $product->get_gallery_image_ids();
            if (!empty($gallery_image_ids)) {
                foreach ($gallery_image_ids as $image_id) {
                    $encoded_image_id = base64_encode('post:'.$image_id);
                    if (!in_array($encoded_image_id, array_column($all_images, 'id'))) {
                        $all_images[] = [
                            'id' => $encoded_image_id,
                            'title' => get_the_title($image_id),
                            'sourceUrl' => wp_get_attachment_url($image_id)
                        ];
                    }
                }
            }

            // Get images from product variations
            if ($product->is_type('variable')) {
                $variations = $product->get_children();
                foreach ($variations as $variation_id) {
                    $variation = wc_get_product($variation_id);
                    if ($variation) {
                        $variation_image_id = $variation->get_image_id();
                        if ($variation_image_id) {
                            $variation_image_url = wp_get_attachment_url($variation_image_id);
                            $variation_image_title = get_the_title($variation_image_id);
                            $encoded_variation_image_id = base64_encode('post:'.$variation_image_id);
                            if (!in_array($encoded_variation_image_id, array_column($all_images, 'id'))) {
                                $all_images[] = [
                                    'id' => $encoded_variation_image_id,
                                    'title' => $variation_image_title,
                                    'sourceUrl' => $variation_image_url
                                ];
                            }
                        }
                    }
                }
            }

            // Get images from product description
            $description = $product->get_description();
            if (!empty($description)) {
                // Use regex to extract image URLs from HTML
                if (preg_match_all('/<img[^>]+src=["\']?([^"\'>]+)["\']?/i', $description, $matches)) {
                    $unique_image_urls = array_unique($matches[1]);

                    foreach ($unique_image_urls as $key => $external_image_url) {
                        $media_id = $this->upload_external_image_to_media_library($external_image_url);

                        if ($media_id) {
                            $all_images[] = [
                                'id' => base64_encode('post:'.$media_id),
                                'title' => get_the_title($media_id),
                                'sourceUrl' => wp_get_attachment_url($media_id)
                            ];
                        }
                    }
                }
            }

            update_post_meta($product_id, 'all_images', $all_images);
            $all_images = get_post_meta($product_id, 'all_images', true);
        }

        foreach ($all_images as &$image) {
            $image_id = base64_decode($image['id']);
            $image_id = (int) str_replace('post:', '', $image_id);

            $attachment = get_post($image_id);

            if ($attachment && $attachment->post_type === 'attachment') {
                $image['altText'] = get_post_meta($image_id, '_wp_attachment_image_alt', true);
                $image['title'] = $attachment->post_title;
                $new_url = wp_get_attachment_url($image_id);
                $image['sourceUrl'] = $new_url;
            }
        }

        // xuli

        return json_encode($all_images);
    }

    /**
     * @param string $image_url
     *
     * @return false
     */
    function upload_external_image_to_media_library(string $image_url)
    {
        // Lấy nội dung file từ URL
        $image_data = file_get_contents($image_url);

        // Kiểm tra nếu quá trình tải xuống thành công
        if ($image_data === false) {
            return new WP_Error('image_download_failed', 'Failed to download the image from the provided URL.');
        }

        // Lấy tên file từ URL, đổi đuôi thành .jpeg nếu cần
        $file_name = basename($image_url);
        $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);

        // Đảm bảo tên file có đuôi .jpeg
        if (strtolower($file_ext) !== 'jpeg') {
            $file_name = str_replace($file_ext, 'jpeg', $file_name);
        }

        // Tạo file trong thư viện media của WordPress
        $upload = wp_upload_bits($file_name, null, $image_data);

        if (!$upload['error']) {
            // Tạo attachment cho file đã tải
            $attachment_id = wp_insert_attachment([
                'guid' => $upload['url'],
                'post_mime_type' => 'image/jpeg',  // Đảm bảo MIME type là image/jpeg
                'post_title' => sanitize_file_name($file_name),
                'post_content' => '',
                'post_status' => 'inherit',
            ], $upload['file']);

            // Tạo metadata cho attachment
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
            wp_update_attachment_metadata($attachment_id, $attach_data);

            return $attachment_id;
        } else {
            return new WP_Error('upload_failed', $upload['error']);
        }
    }

    /**
     * Register the custom mutation to save all product images galleries.
     */
    private function register_save_all_product_images_galleries_mutation(): void
    {
        register_graphql_mutation('saveAllProductImagesGalleries', [
            'inputFields' => [
                'productId' => [
                    'type' => 'ID',
                    'description' => __('The ID of the product to save image galleries for'),
                ],
                'file' => [
                    'type' => 'String',
                    'description' => __('Base64 encoded image file to save'),
                ],
                'filename' => [
                    'type' => 'String',
                    'description' => __('The name of the file to save'),
                ],
                'galleryAssignment' => [
                    'type' => ['list_of' => 'String'],
                    'description' => __('An array to assign the image to a specific gallery'),
                ]
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'message' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function($input, $context, $info) {
                return $this->save_all_product_images_galleries($input);
            },
        ]);
    }


    /**
     * Save product images in product metadata.
     *
     * @param array $input
     * @return array
     */
    function save_all_product_images_galleries(array $input): array
    {
        $product_id = base64_decode($input['productId']);
        $product_id = str_replace('product:', '', $product_id);

        // Decode base64 image data
        $data_prefix = 'data:image/';
        if (strpos($input['file'], $data_prefix) === 0) {
            $file_data = substr($input['file'], strpos($input['file'], ',') + 1);
            $file_data = base64_decode($file_data);
            if ($file_data === false) {
                return [
                    'success' => false,
                    'message' => __('Base64 decode failed', 'wp-custom-upload-image'),
                ];
            }

            // Verify that the decoded data is a valid image
            if (!@imagecreatefromstring($file_data)) {
                return [
                    'success' => false,
                    'message' => __('Decoded data is not a valid image', 'wp-custom-upload-image'),
                ];
            }
        } else {
            return [
                'success' => false,
                'message' => __('Invalid base64 image format', 'wp-custom-upload-image'),
            ];
        }

        $filename = sanitize_file_name($input['filename']);

        // Get the WordPress upload directory
        $upload_dir = wp_upload_dir();
        if (empty($upload_dir['path'])) {
            return [
                'success' => false,
                'message' => __('Upload directory not found', 'wp-custom-upload-image'),
            ];
        }

        // Create the full path for the file
        $upload_path = $upload_dir['path'] . '/' . $filename;

        // Check if the upload directory is writable
        if (!is_writable($upload_dir['path'])) {
            return [
                'success' => false,
                'message' => __('Upload directory is not writable', 'wp-custom-upload-image'),
            ];
        }

        // Save the image file to the upload directory
        if (file_put_contents($upload_path, $file_data) === false) {
            return [
                'success' => false,
                'message' => __('Failed to save image to the upload directory', 'wp-custom-upload-image'),
            ];
        }

        // Check the file type
        $file_type = wp_check_filetype($filename, null);
        if (!$file_type['type']) {
            unlink($upload_path); // Delete the file if it's not a valid type
            return [
                'success' => false,
                'message' => __('File type is not valid', 'wp-custom-upload-image'),
            ];
        }

        // Create an attachment post
        $attachment = [
            'guid'           => $upload_dir['url'] . '/' . $filename,
            'post_mime_type' => $file_type['type'],
            'post_title'     => sanitize_file_name($filename),
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];

        // Insert the attachment into the media library
        $attach_id = wp_insert_attachment($attachment, $upload_path);
        if (is_wp_error($attach_id)) {
            unlink($upload_path); // Delete the file if the insertion fails
            return [
                'success' => false,
                'message' => __('Failed to insert attachment into media library', 'wp-custom-upload-image'),
            ];
        }

        // Generate attachment metadata and update it
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $upload_path);
        if (!$attach_data) {
            return [
                'success' => false,
                'message' => __('Failed to generate attachment metadata', 'wp-custom-upload-image'),
            ];
        }
        wp_update_attachment_metadata($attach_id, $attach_data);

        // Get the product
        $product = wc_get_product($product_id);
        if (!$product) {
            return [
                'success' => false,
                'message' => __('Product not found.', 'wp-custom-upload-image'),
            ];
        }

        $new_image_data = [
            'id' => base64_encode('post:'.$attach_id),
            'title' => get_the_title($attach_id),
            'sourceUrl' => wp_get_attachment_url($attach_id),
        ];

        if (isset($input['galleryAssignment'])) {

            $galleryType = $input['galleryAssignment'][0];

            if ($galleryType === 'is_main') {
                $product->set_image_id($attach_id);
                $product->save();
            } elseif ($galleryType === 'product_gallery') {
                $gallery_ids = $product->get_gallery_image_ids();

                $exclude_id = base64_decode($input['galleryAssignment'][1]);
                $exclude_id = (int) str_replace('post:', '', $exclude_id);

                $gallery_ids = array_filter($gallery_ids, function($item) use ($exclude_id) {
                    return $item !== $exclude_id;
                });

                $gallery_ids[] = $attach_id;

                $product->set_gallery_image_ids($gallery_ids);
                $product->save();
            } else {
                $color_specific_galleries = get_post_meta($product_id, 'color_specific_galleries', true);

                $gallery_ids = $color_specific_galleries[strtolower($galleryType)];
                $exclude_id = $input['galleryAssignment'][1];

                $gallery_ids = array_filter($gallery_ids, function($item) use ($exclude_id) {
                    return $item['id'] !== $exclude_id;
                });

                $gallery_ids[] = $new_image_data;
                $color_specific_galleries[strtolower($galleryType)] = array_values($gallery_ids);

                update_post_meta($product_id, 'color_specific_galleries', $color_specific_galleries);
            }
        } else {
            $all_images = get_post_meta($product_id, 'all_images', true);

            if (empty($all_images) || !is_array($all_images)) {
                $all_images = [$new_image_data];
            } else {
                array_unshift($all_images, $new_image_data);
            }
            update_post_meta($product_id, 'all_images', $all_images);
        }

        return [
            'success' => true,
            'message' => __('Product images saved successfully.', 'wp-custom-upload-image'),
        ];
    }


    /**
     * @return void
     */
    private function register_delete_product_image_mutation(): void
    {
        register_graphql_mutation('deleteProductImage', [
            'inputFields' => [
                'productId' => [
                    'type' => 'ID',
                    'description' => __('The ID of the product from which to delete the image'),
                ],
                'imageId' => [
                    'type' => 'ID',
                    'description' => __('The ID of the image to delete'),
                ],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'message' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function($input, $context, $info) {
                return $this->delete_product_image($input);
            },
        ]);
    }

    /**
     * @param array $input
     *
     * @return array
     */
    function delete_product_image(array $input): array
    {
        $product_id = base64_decode($input['productId']);
        $product_id = str_replace('product:', '', $product_id);
        $image_id = $input['imageId'];
        $image_id_to_delete = base64_decode($input['imageId']);
        $image_id_to_delete = str_replace('post:', '', $image_id_to_delete);

        $product = wc_get_product($product_id);

        if (!$product) {
            return [
                'success' => false,
                'message' => __('Product not found.', 'wp-custom-upload-image'),
            ];
        }

        $colorSpecificGalleries = get_post_meta($product_id, 'color_specific_galleries', true);

        if(!empty($colorSpecificGalleries)) {
            foreach ($colorSpecificGalleries as $color => $images) {
                foreach ($images as $key => $image) {
                    if ($image['id'] === $input['imageId']) {
                        unset($images[$key]);
                    }
                }

                $colorSpecificGalleries[$color] = array_values($images);
            }

            update_post_meta($product_id, 'color_specific_galleries', $colorSpecificGalleries);
        }

        $featured_image_id = $product->get_image_id();
        if ($featured_image_id === $image_id_to_delete) {
            delete_post_meta($product_id, '_thumbnail_id', $image_id_to_delete);
        }

        $gallery_image_ids = $product->get_gallery_image_ids();
        if (in_array($image_id_to_delete, $gallery_image_ids)) {
            $new_gallery_ids = array_diff($gallery_image_ids, [$image_id_to_delete]);
            $product->set_gallery_image_ids($new_gallery_ids);
            $product->save();
        }

        // Retrieve existing metadata
        $all_images = get_post_meta($product_id, 'all_images', true);
        if (empty($all_images) || !is_array($all_images)) {
            return [
                'success' => false,
                'message' => __('No images found for this product', 'wp-custom-delete-image'),
            ];
        }

        // Find the image to delete
        foreach ($all_images as $key => $image) {
            if (strval($image['id']) === strval($image_id)) {
                // Remove the image from the array
                unset($all_images[$key]);

                // Update the metadata with the modified array
                update_post_meta($product_id, 'all_images', array_values($all_images));

                return [
                    'success' => true,
                    'message' => __('Image deleted successfully', 'wp-custom-delete-image'),
                ];
            }
        }

        return [
            'success' => false,
            'message' => __('Image not found', 'wp-custom-delete-image'),
        ];
    }

    /**
     * Register the custom mutation to delete an image by its ID.
     */
    private function register_delete_image_mutation(): void
    {
        register_graphql_mutation('deleteImageById', [
            'inputFields' => [
                'imageId' => [
                    'type' => 'ID',
                    'description' => __('The ID of the image to delete'),
                ],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'message' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function($input) {
                return $this->delete_image_by_id($input);
            },
        ]);
    }

    /**
     * Delete an image by its ID.
     *
     * @param array $input
     *
     * @return array
     */
    function delete_image_by_id($input): array
    {
        $image_id = base64_decode($input['imageId']);
        $image_id = str_replace('post:', '', $image_id);

        $products = wc_get_products(array('limit' => -1));

        foreach ($products as $product) {
            if (!$product) {
                continue;
            }
            $product_id = $product->get_id();

            $colorSpecificGalleries = get_post_meta($product_id, 'color_specific_galleries', true);

            if(!empty($colorSpecificGalleries)) {
                foreach ($colorSpecificGalleries as $color => $images) {
                    foreach ($images as $key => $image) {
                        if ($image['id'] === $input['imageId']) {
                            unset($images[$key]);
                        }
                    }

                    $colorSpecificGalleries[$color] = array_values($images);
                }

                update_post_meta($product_id, 'color_specific_galleries', $colorSpecificGalleries);
            }
        }

        if (!wp_attachment_is_image($image_id)) {
            return [
                'success' => false,
                'message' => __('The provided ID does not correspond to a valid image.'),
            ];
        }

        $result = wp_delete_attachment($image_id, true);

        if ($result) {
            return [
                'success' => true,
                'message' => __('Image deleted successfully.'),
            ];
        } else {
            return [
                'success' => false,
                'message' => __('Failed to delete the image.'),
            ];
        }
    }

    /**
     * @return void
     */
    private function register_delete_image_in_color_gallery_mutation(): void
    {
        register_graphql_mutation('deleteImageInColorGalleryById', [
            'inputFields' => [
                'productId' => [
                    'type' => 'ID',
                    'description' => __('The ID of the image to delete'),
                ],
                'colorName' => [
                    'type' => 'String',
                    'description' => __('The color galleries name of image to delete'),
                ],
                'imageId' => [
                    'type' => 'ID',
                    'description' => __('The ID of the image to delete'),
                ],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'message' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function($input) {
                return $this->delete_image_in_color_gallery_by_id($input);
            },
        ]);
    }

    /**
     * Delete an image by its ID.
     *
     * @param array $input
     *
     * @return array
     */
    function delete_image_in_color_gallery_by_id(array $input): array
    {
        $product_id = base64_decode($input['productId']);
        $product_id = str_replace('product:', '', $product_id);
        $colorName = strtolower($input['colorName']);
        $image_id = base64_decode($input['imageId']);
        $image_id = str_replace('post:', '', $image_id);

        $product = wc_get_product($product_id);

        if (!$product) {
            return [
                'success' => false,
                'message' => __('Product not found.'),
            ];
        }

        $colorSpecificGalleries = get_post_meta($product_id, 'color_specific_galleries', true);

        if(!empty($colorSpecificGalleries)) {
            foreach ($colorSpecificGalleries as $color => $images) {
                if($colorName === $color) {
                    foreach ($images as $key => $image) {
                        if ($image['id'] === $input['imageId']) {
                            unset($images[$key]);
                            break;
                        }
                    }
                }

                $colorSpecificGalleries[$color] = array_values($images);
            }

            update_post_meta($product_id, 'color_specific_galleries', $colorSpecificGalleries);
        }

        return [
            'success' => true,
            'message' => __('Image deleted successfully.'),
        ];
    }

    /**
     * @return void
     */
    private function register_delete_image_in_main_gallery_mutation(): void
    {
        register_graphql_mutation('deleteImageInMainGalleryById', [
            'inputFields' => [
                'productId' => [
                    'type' => 'ID',
                    'description' => __('The ID of the image to delete'),
                ],
                'isMain' => [
                    'type' => 'Boolean',
                    'description' => __('The ID of the image to delete'),
                    'defaultValue' => false,
                ],
                'imageId' => [
                    'type' => 'ID',
                    'description' => __('The ID of the image to delete'),
                ],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'message' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function($input) {
                return $this->delete_image_in_main_gallery_by_id($input);
            },
        ]);
    }

    /**
     * Delete an image by its ID.
     *
     * @param array $input
     *
     * @return array
     */
    function delete_image_in_main_gallery_by_id(array $input): array
    {
        $product_id = base64_decode($input['productId']);
        $product_id = str_replace('product:', '', $product_id);
        $image_id = base64_decode($input['imageId']);
        $image_id = str_replace('post:', '', $image_id);

        $product = wc_get_product($product_id);

        if (!$product) {
            return [
                'success' => false,
                'message' => __('Product not found.'),
            ];
        }

        $featured_image_id = $product->get_image_id();
        if ($featured_image_id === $image_id && $input['isMain']) {
            delete_post_meta($product_id, '_thumbnail_id', $image_id);
            return [
                'success' => true,
                'message' => __('Featured image deleted successfully.'),
            ];
        }

        $gallery_image_ids = $product->get_gallery_image_ids();
        if (in_array($image_id, $gallery_image_ids)) {
            $new_gallery_ids = array_diff($gallery_image_ids, [$image_id]);
            $product->set_gallery_image_ids($new_gallery_ids);
            $product->save();

            return [
                'success' => true,
                'message' => __('Gallery image deleted successfully.'),
            ];
        }

        return [
            'success' => false,
            'message' => __('Image not found in the galleries.'),
        ];
    }

    /**
     * Register the custom root query to get the product size chart.
     */
    private function register_product_size_chart_query(): void
    {
        register_graphql_field('RootQuery', 'getProductSizeChart', [
            'type' => 'String',
            'description' => __('Get the size chart of a product', 'wp-custom-global-attributes'),
            'args' => [
                'productId' => ['type' => 'ID', 'description' => __('The ID of the product to retrieve the size chart for')],
            ],
            'resolve' => function($root, $args) {
                return $this->get_product_size_chart($args);
            },
        ]);
    }


    /**
     * Get the size chart from product metadata.
     *
     * @param array $args
     *
     * @return ?string
     */
    function get_product_size_chart(array $args): ?string
    {
        $product_id = base64_decode($args['productId']);
        $product_id = str_replace('product:', '', $product_id);

        $product = wc_get_product($product_id);

        if (!$product) {
            return null;
        }

        // Fetch size chart from meta
        $size_chart = get_post_meta($product_id, 'size_chart', true);

        // If no size chart is found, initialize it
        if (empty($size_chart) || !is_array($size_chart)) {
            $size_chart = [];
            update_post_meta($product_id, 'size_chart', $size_chart);
            $size_chart = get_post_meta($product_id, 'size_chart', true);
        }

        return json_encode($size_chart);
    }

    /**
     * Register the custom mutation to save the product size chart.
     */
    private function register_save_size_chart_mutation(): void
    {
        register_graphql_mutation('saveProductSizeChart', [
            'inputFields' => [
                'productId' => [
                    'type' => 'ID',
                    'description' => __('The ID of the product to save the size chart for'),
                ],
                'sizeChart' => [
                    'type' => 'String',
                    'description' => __('Size chart to save in product metadata'),
                ],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'message' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function($input, $context, $info) {
                return $this->save_size_chart($input);
            },
        ]);
    }

    /**
     * Save the size chart in product metadata.
     *
     * @param array $input
     * @return array
     */
    function save_size_chart(array $input): array
    {
        $product_id = base64_decode($input['productId']);
        $product_id = str_replace('product:', '', $product_id);

        $size_chart = $input['sizeChart'];
        $size_chart_array = json_decode($size_chart, true);

        $product = wc_get_product($product_id);

        if (!$product) {
            return [
                'success' => false,
                'message' => __('Product not found.'),
            ];
        }

        if (is_array($size_chart_array) && count($size_chart_array) === 0) {
            update_post_meta($product_id, 'size_chart', $size_chart_array);

            return [
                'success' => true,
                'message' => __('Size chart saved successfully'),
            ];
        }

        if (is_array($size_chart_array) && count($size_chart_array) === 1) {
            $input['imageId'] = $size_chart_array[0]['id'];

            update_post_meta($product_id, 'size_chart', $size_chart_array);

            $this->delete_product_image($input);

            return [
                'success' => true,
                'message' => __('Size chart saved successfully with child ID: ' . $input['imageId']),
            ];
        } else {
            return [
                'success' => false,
                'message' => __('Size chart not saved. The array must have exactly one element.'),
            ];
        }
    }

    /**
     * Register the custom root query to delete the product size chart.
     */
    private function register_product_delete_size_chart_query(): void
    {
        register_graphql_field('RootQuery', 'deleteImageOfSizeChart', [
            'type' => 'String',
            'description' => __('Delete the size chart of a product', 'wp-custom-global-attributes'),
            'args' => [
                'productId' => ['type' => 'ID', 'description' => __('The ID of the product to delete the size chart for')],
            ],
            'resolve' => function($root, $args) {
                return $this->delete_product_size_chart($args);
            },
        ]);
    }


    /**
     * Delete the size chart from product metadata.
     *
     * @param array $args
     *
     * @return ?string
     */
    function delete_product_size_chart(array $args): ?string
    {
        $product_id = base64_decode($args['productId']);
        $product_id = str_replace('product:', '', $product_id);

        $product = wc_get_product($product_id);

        if (!$product) {
            return null;
        }

        $size_chart = [];
        update_post_meta($product_id, 'size_chart', $size_chart);
        $size_chart = get_post_meta($product_id, 'size_chart', true);

        update_post_meta($product_id, 'size_chart', $size_chart);

        return json_encode($size_chart);
    }

    /**
     * @return void
     */
    function register_get_image_info_query() {
        register_graphql_field('RootQuery', 'getImageInfo', [
            'type' => 'ImageInfo',
            'args' => [
                'imageId' => [
                    'type' => 'ID',
                    'description' => __('The ID of the image to retrieve information for')
                ]
            ],
            'resolve' => function($root, $args, $context, $info) {
                $image_id = base64_decode($args['imageId']);
                $image_id = str_replace('post:', '', $image_id);

                // Get the attachment details
                $attachment = get_post($image_id);

                if (!$attachment || $attachment->post_type !== 'attachment') {
                    return null;
                }

                // Get image details
                $image_url = wp_get_attachment_url($image_id);
                $alt_text = get_post_meta($image_id, '_wp_attachment_image_alt', true);
                $image_title = $attachment->post_title;

                return [
                    'id' => base64_encode('post:' . $image_id),
                    'alt' => $alt_text,
                    'title' => $image_title,
                    'url' => $image_url,
                ];
            }
        ]);

        register_graphql_object_type( 'ImageInfo', [
            'description' => __( 'Image Information', 'wp-graphql' ),
            'fields' => [
                'id' => [
                    'type' => 'ID',
                    'description' => __( 'The ID of the image' ),
                ],
                'alt' => [
                    'type' => 'String',
                    'description' => __( 'The alt text of the image' ),
                ],
                'title' => [
                    'type' => 'String',
                    'description' => __( 'The title of the image' ),
                ],
                'url' => [
                    'type' => 'String',
                    'description' => __( 'The URL of the image' ),
                ],
            ],
        ]);
    }

    /**
     * Register the mutation to update image information.
     */
    function register_update_image_info_mutation() {
        register_graphql_mutation('updateImageInfo', [
            'inputFields' => [
                'images' => [
                    'type' => ['list_of' => 'ImageInput'],
                    'description' => __('List of images with new alt text and title to update'),
                ],
            ],
            'outputFields' => [
                'success' => [
                    'type' => 'Boolean',
                    'description' => __('Whether the update was successful or not'),
                ],
                'message' => [
                    'type' => 'String',
                    'description' => __('A message detailing the result of the operation'),
                ],
            ],
            'mutateAndGetPayload' => function($input, $context, $info) {


                foreach ($input['images'] as $imageInput) {
                    $image_id = base64_decode($imageInput['imageId']);
                    $image_id = str_replace('post:', '', $image_id);

                    $attachment = get_post($image_id);

                    if (!$attachment || $attachment->post_type !== 'attachment') {
                        $messages[] = __('Image not found or invalid: ' . $imageInput['imageId'], 'wp-custom-update-image-info');
                        $success = false;
                        continue;
                    }

                    if (isset($imageInput['altText']) && !empty($imageInput['altText'])) {
                        update_post_meta($image_id, '_wp_attachment_image_alt', sanitize_text_field($imageInput['altText']));
                    }

                    if (isset($imageInput['title']) && !empty($imageInput['title'])) {
                        // Sử dụng pathinfo để xử lý chính xác tiêu đề có hoặc không có phần mở rộng
                        $path_info = pathinfo($imageInput['title']);
                        $title_without_extension = $path_info['filename']; // Lấy tên tệp không có phần mở rộng

                        // Cập nhật tiêu đề bài viết mà không có phần mở rộng
                        wp_update_post([
                            'ID' => $image_id,
                            'post_title' => sanitize_text_field($title_without_extension),
                        ]);

                        $current_url = wp_get_attachment_url($image_id);
                        $file_path = get_attached_file($image_id);

                        $file_extension = pathinfo($current_url, PATHINFO_EXTENSION);

                        // Tiêu chuẩn hóa tên tệp mới
//                        $new_filename = sanitize_title($title_without_extension) . '.' . $file_extension;

                        $sanitized_title = sanitize_file_name($title_without_extension);
                        if (empty($sanitized_title)) {
                            $messages[] = __('Sanitized title is empty for image: ' . $imageInput['imageId'], 'wp-custom-update-image-info');
                            $success = false;
                            continue;
                        }

                        $new_filename = $sanitized_title . '.' . $file_extension;

                        // Tạo đường dẫn tệp mới
                        $new_file_path = path_join(pathinfo($file_path, PATHINFO_DIRNAME), $new_filename);

                        // Thử đổi tên tệp
                        if (file_exists($file_path)) {
                            if (rename($file_path, $new_file_path)) {
                                update_attached_file($image_id, $new_file_path);
                            } else {
                                $messages[] = __('Failed to rename file for image: ' . $imageInput['imageId'], 'wp-custom-update-image-info');
                                $success = false;
                                continue;
                            }
                        } else {
                            $messages[] = __('Source file does not exist: ' . $file_path, 'wp-custom-update-image-info');
                            $success = false;
                            continue;
                        }

                        // Lấy URL mới sau khi đã đổi tên
                        $new_url = wp_get_attachment_url($image_id);
                        $messages[] = __('Image updated successfully: ' . $imageInput['imageId'], 'wp-custom-update-image-info');
                    }

                    $messages[] = __('Image updated successfully: ' . $imageInput['imageId'], 'wp-custom-update-image-info');
                }

                return [
                    'success' => true,
                    'message' => __('Image information updated successfully', 'wp-custom-update-image-info'),
                ];
            },
        ]);

        register_graphql_input_type('ImageInput', [
            'description' => __('Input fields for updating multiple images'),
            'fields' => [
                'imageId' => [
                    'type' => 'ID',
                    'description' => __('The ID of the image to update'),
                ],
                'altText' => [
                    'type' => 'String',
                    'description' => __('The new alt text for the image'),
                ],
                'title' => [
                    'type' => 'String',
                    'description' => __('The new title for the image'),
                ],
            ],
        ]);
    }

}





