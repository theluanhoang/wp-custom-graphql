<?php

class WCGE_User {
    public function __construct() {
        // Đăng ký các trường GraphQL khi các loại được đăng ký
        add_action('graphql_register_types', [$this, 'register_graphql_fields']);
    }

    public function register_graphql_fields() {
        register_graphql_field('RootQuery', 'tokenExpiration', [
            'type' => 'String',
            'resolve' => function ($root, $args, $context) {

                var_dump($context);
                die();

                // Kiểm tra xem $context có phải là đối tượng không
                if (is_object($context) && method_exists($context, 'get_header')) {
                    // Lấy token từ headers
                    $auth_header = $context->get_header('Authorization');
                    $token = '';

                    // Kiểm tra xem header có chứa Bearer token không
                    if (preg_match('/Bearer\s(\S+)/', $auth_header, $matches)) {
                        $token = $matches[1];
                    }

                    // Nếu không có token, trả về thông báo tương ứng
                    if (empty($token)) {
                        return 'Không có token.';
                    }

                    // Gọi hàm để lấy thời gian hết hạn
                    $expiration_time = $this->get_token_expiration_time($token);
                    return $expiration_time ? $expiration_time : 'Token không hợp lệ hoặc không có thời gian hết hạn.';
                }

                // Nếu $context không phải là đối tượng, trả về thông báo lỗi
                return 'Lỗi: Tham số không hợp lệ.';
            }
        ]);
    }

    // Hàm để lấy thời gian hết hạn của token
    private function get_token_expiration_time($token) {
        // Giả định token là JWT
        $parts = explode('.', $token);

        // Kiểm tra định dạng token
        if (count($parts) !== 3) {
            return null; // Không phải là JWT hợp lệ
        }

        // Giải mã phần payload của token
        $payload = json_decode(base64_decode($parts[1]), true);

        // Kiểm tra xem có trường exp không
        if (isset($payload['exp'])) {
            // Chuyển đổi thời gian hết hạn từ timestamp sang định dạng ngày giờ
            return date('Y-m-d H:i:s', $payload['exp']);
        }

        return null; // Không tìm thấy trường exp
    }
}
