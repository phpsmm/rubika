<?php

if (!defined('ABSPATH')) {
    exit;
}

class Aghasocial_AI_Pages_Pages {
    public function enqueue_missing_pages() {
        global $wpdb;
        $settings = aghasocial_ai_pages_get_settings();
        if (empty($settings['enable_generate'])) {
            return;
        }

        $services_table = $wpdb->prefix . 'samyar_services';
        $categories_table = $wpdb->prefix . 'samyar_categories';
        $pages_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_PAGES_TABLE;
        $queue_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_QUEUE_TABLE;

        $categories = $wpdb->get_results("SELECT id, name FROM {$categories_table} WHERE status = 1");
        foreach ($categories as $category) {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$pages_table} WHERE type = 'category' AND ref_id = %d", $category->id));
            if (!$exists) {
                $payload = [
                    'type' => 'category',
                    'ref_id' => $category->id,
                ];
                $wpdb->insert($queue_table, [
                    'type' => 'generate',
                    'status' => 'pending',
                    'payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ]);
            }
        }

        $services = $wpdb->get_results(
            "SELECT s.id, s.name, s.cate_id, m.normalized_title
            FROM {$services_table} s
            LEFT JOIN {$wpdb->prefix}" . AGHASOCIAL_AI_PAGES_META_TABLE . " m
            ON m.ref_type = 'service' AND m.ref_id = s.id
            WHERE s.status = 1"
        );
        $groups = [];
        foreach ($services as $service) {
            $normalized = $service->normalized_title ?: aghasocial_ai_pages_normalize_title($service->name);
            $groups[$normalized][] = $service;
        }

        foreach ($groups as $normalized => $group_services) {
            $primary = $group_services[0];
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$pages_table} WHERE type = 'service' AND ref_id = %d", $primary->id));
            if (!$exists) {
                $payload = [
                    'type' => 'service',
                    'ref_id' => $primary->id,
                    'category_id' => $primary->cate_id,
                    'service_ids' => wp_list_pluck($group_services, 'id'),
                    'normalized' => $normalized,
                ];
                $wpdb->insert($queue_table, [
                    'type' => 'generate',
                    'status' => 'pending',
                    'payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ]);
            }

            $quantities = aghasocial_ai_pages_parse_quantities($settings['quantity_list']);
            foreach ($quantities as $quantity) {
                $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$pages_table} WHERE type = 'quantity' AND ref_id = %d AND quantity = %d", $primary->id, $quantity));
                if (!$exists) {
                    $payload = [
                        'type' => 'quantity',
                        'ref_id' => $primary->id,
                        'quantity' => $quantity,
                        'category_id' => $primary->cate_id,
                        'service_ids' => wp_list_pluck($group_services, 'id'),
                        'normalized' => $normalized,
                    ];
                    $wpdb->insert($queue_table, [
                        'type' => 'generate',
                        'status' => 'pending',
                        'payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql'),
                    ]);
                }
            }
        }
    }

    public function generate_one_page() {
        $settings = aghasocial_ai_pages_get_settings();
        if (empty($settings['enable_generate'])) {
            return 'generate_disabled';
        }
        if (!empty($settings['dry_run'])) {
            return 'dry_run';
        }

        global $wpdb;
        $queue_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_QUEUE_TABLE;
        $pages_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_PAGES_TABLE;
        $task = $wpdb->get_row("SELECT * FROM {$queue_table} WHERE type = 'generate' AND status = 'pending' ORDER BY id ASC LIMIT 1");
        if (!$task) {
            return 'no_tasks';
        }

        $payload = json_decode($task->payload, true);
        $page_id = $this->build_page($payload);

        if ($page_id && !is_wp_error($page_id)) {
            $wpdb->update($queue_table, [
                'status' => 'done',
                'updated_at' => current_time('mysql'),
            ], ['id' => $task->id]);

            $wpdb->insert($pages_table, [
                'page_id' => $page_id,
                'type' => $payload['type'],
                'ref_id' => $payload['ref_id'],
                'quantity' => $payload['quantity'] ?? null,
                'country' => $payload['country'] ?? null,
                'status' => 'draft',
                'created_at' => current_time('mysql'),
            ]);

            return 'ok';
        }

        $wpdb->update($queue_table, [
            'status' => 'error',
            'last_error' => is_wp_error($page_id) ? $page_id->get_error_message() : 'unknown_error',
            'updated_at' => current_time('mysql'),
        ], ['id' => $task->id]);

        return 'error';
    }

    private function build_page($payload) {
        $type = $payload['type'];
        $ref_id = $payload['ref_id'];
        $quantity = $payload['quantity'] ?? null;
        $country = $payload['country'] ?? null;
        $service_ids = $payload['service_ids'] ?? [$ref_id];

        if ($type === 'category') {
            return $this->create_category_page($ref_id);
        }

        if ($type === 'service') {
            return $this->create_service_page($service_ids);
        }

        if ($type === 'quantity') {
            return $this->create_quantity_page($service_ids, $quantity, $country);
        }

        return new WP_Error('invalid_type', 'Invalid page type');
    }

    private function create_category_page($category_id) {
        global $wpdb;
        $category = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}samyar_categories WHERE id = %d", $category_id));
        if (!$category) {
            return new WP_Error('missing_category', 'Category not found');
        }

        $title = $category->name;
        $content = '[samyar_services cat=' . (int) $category_id . ']';

        return $this->create_elementor_page($title, $content, []);
    }

    private function create_service_page($service_ids) {
        global $wpdb;
        $service_id = (int) $service_ids[0];
        $service = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}samyar_services WHERE id = %d", $service_id));
        if (!$service) {
            return new WP_Error('missing_service', 'Service not found');
        }

        $title = $service->name;
        $shortcodes = [];
        foreach ($service_ids as $id) {
            $shortcodes[] = '[kando_service id=' . (int) $id . ']';
        }
        $content = implode("\n", $shortcodes);

        return $this->create_elementor_page($title, $content, []);
    }

    private function create_quantity_page($service_ids, $quantity, $country = null) {
        global $wpdb;
        $service_id = (int) $service_ids[0];
        $service = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}samyar_services WHERE id = %d", $service_id));
        if (!$service) {
            return new WP_Error('missing_service', 'Service not found');
        }

        $title = trim(sprintf('خرید %d %s %s', $quantity, $service->name, $country ? $country : ''));
        $elementor_data = $this->build_kando_pack_group($service_ids, $quantity, $service->name);

        return $this->create_elementor_page($title, '', $elementor_data);
    }

    private function build_kando_pack_group($service_ids, $quantity, $service_name) {
        $settings = aghasocial_ai_pages_get_settings();
        $elements = [];
        foreach ($service_ids as $service_id) {
            $template = $settings['pack_template'];
            if ($template) {
                $template = str_replace(['{{service_id}}', '{{quantity}}', '{{service_name}}'], [$service_id, $quantity, $service_name], $template);
                $decoded = json_decode($template, true);
                if ($decoded) {
                    $elements = array_merge($elements, $decoded);
                    continue;
                }
            }

            $elements[] = [
                'id' => wp_generate_uuid4(),
                'elType' => 'widget',
                'widgetType' => 'kando-pack',
                'settings' => [
                    'service-id' => $service_id,
                    'pack-title' => sprintf('%d %s', $quantity, $service_name),
                    'pack-number' => $quantity,
                    'pack-content' => sprintf('بسته %d برای %s', $quantity, $service_name),
                ],
                'elements' => [],
            ];
        }

        return $elements;
    }

    private function create_elementor_page($title, $content, $elementor_data) {
        $settings = aghasocial_ai_pages_get_settings();
        $post_id = wp_insert_post([
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => 'draft',
            'post_type' => 'page',
        ], true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        $template = $settings['elementor_template'];
        if ($template) {
            $decoded = json_decode($template, true);
            if ($decoded) {
                $elementor_data = $decoded;
            }
        }

        if (!empty($elementor_data)) {
            update_post_meta($post_id, '_elementor_data', wp_json_encode($elementor_data, JSON_UNESCAPED_UNICODE));
            update_post_meta($post_id, '_elementor_edit_mode', 'builder');
            update_post_meta($post_id, '_elementor_template_type', 'page');
        }

        return $post_id;
    }
}
