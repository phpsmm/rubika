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

        $include_categories = aghasocial_ai_pages_parse_id_list($settings['category_include']);
        $exclude_categories = aghasocial_ai_pages_parse_id_list($settings['category_exclude']);
        $excluded_services = aghasocial_ai_pages_parse_id_list($settings['service_quantity_exclude']);
        $category_overrides = aghasocial_ai_pages_get_override_map('category');

        $categories = $wpdb->get_results("SELECT id, name FROM {$categories_table} WHERE status = 1");
        foreach ($categories as $category) {
            if ($include_categories && !in_array((int) $category->id, $include_categories, true)) {
                continue;
            }
            if ($exclude_categories && in_array((int) $category->id, $exclude_categories, true)) {
                continue;
            }
            $category_override = $category_overrides[(int) $category->id] ?? null;
            if (!$category_override) {
                continue;
            }
            $category_mode = $category_override['generate_mode'] ?? 'category';
            if ($category_mode === 'none') {
                continue;
            }
            if ($category_mode !== 'both') {
                continue;
            }
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$pages_table} WHERE type = 'category' AND ref_id = %d", $category->id));
            if (!$exists) {
                $payload = [
                    'type' => 'category',
                    'ref_id' => $category->id,
                ];
                $payload_json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
                $queued = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$queue_table} WHERE type = 'generate' AND status = 'pending' AND payload = %s LIMIT 1",
                    $payload_json
                ));
                if (!$queued) {
                    $wpdb->insert($queue_table, [
                        'type' => 'generate',
                        'status' => 'pending',
                        'payload' => $payload_json,
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql'),
                    ]);
                }
            }
        }

        $services = $wpdb->get_results(
            "SELECT s.id, s.name, s.cate_id, c.name as category_name, m.normalized_title, m.title as rewritten_title
            FROM {$services_table} s
            LEFT JOIN {$categories_table} c ON c.id = s.cate_id
            LEFT JOIN {$wpdb->prefix}" . AGHASOCIAL_AI_PAGES_META_TABLE . " m
            ON m.ref_type = 'service' AND m.ref_id = s.id
            WHERE s.status = 1"
        );
        $groups = [];
        $topic_groups = [];
        foreach ($services as $service) {
            $normalized = $service->normalized_title ?: aghasocial_ai_pages_normalize_title($service->name);
            $groups[$normalized][] = $service;

            $category_override = $category_overrides[(int) $service->cate_id] ?? null;
            if (!$category_override) {
                continue;
            }
            $category_mode = $category_override['generate_mode'] ?? 'category';
            if ($category_mode === 'none') {
                continue;
            }
            $topic_override = '';
            if ($category_override && !empty($category_override['topic'])) {
                $topic_override = $category_override['topic'];
            }
            $topic = $topic_override ?: aghasocial_ai_pages_extract_topic($service->name, $service->category_name, $settings);
            if ($topic !== '') {
                $topic_key = aghasocial_ai_pages_normalize_title($topic);
                if (!isset($topic_groups[$topic_key])) {
                    $topic_groups[$topic_key] = [
                        'topic' => $topic,
                        'services' => [],
                    ];
                }
                $topic_groups[$topic_key]['services'][] = $service;
            }
        }

        foreach ($groups as $normalized => $group_services) {
            $primary = $group_services[0];
            if ($include_categories && !in_array((int) $primary->cate_id, $include_categories, true)) {
                continue;
            }
            if ($exclude_categories && in_array((int) $primary->cate_id, $exclude_categories, true)) {
                continue;
            }
            $category_override = $category_overrides[(int) $primary->cate_id] ?? null;
            if (!$category_override) {
                continue;
            }
            $category_mode = $category_override['generate_mode'] ?? 'category';
            if ($category_mode !== 'both') {
                continue;
            }
            $single_service_page = !empty($category_override['single_service_page']);
            if (!$single_service_page) {
                continue;
            }
            $service_page = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$pages_table} WHERE type = 'service' AND (group_key = %s OR ref_id = %d) ORDER BY id ASC LIMIT 1",
                $normalized,
                $primary->id
            ));
            if ($service_page) {
                if (empty($service_page->group_key)) {
                    $wpdb->update($pages_table, ['group_key' => $normalized], ['id' => $service_page->id]);
                }
                $this->update_service_page((int) $service_page->page_id, wp_list_pluck($group_services, 'id'));
            } else {
                $payload = [
                    'type' => 'service',
                    'ref_id' => $primary->id,
                    'category_id' => $primary->cate_id,
                    'service_ids' => wp_list_pluck($group_services, 'id'),
                    'normalized' => $normalized,
                ];
                $payload_json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
                $queued = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$queue_table} WHERE type = 'generate' AND status = 'pending' AND payload = %s LIMIT 1",
                    $payload_json
                ));
                if (!$queued) {
                    $wpdb->insert($queue_table, [
                        'type' => 'generate',
                        'status' => 'pending',
                        'payload' => $payload_json,
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql'),
                    ]);
                }
            }

        }

        $quantities = aghasocial_ai_pages_parse_quantities($settings['quantity_list']);
        $countries = aghasocial_ai_pages_parse_countries($settings['countries']);
        foreach ($topic_groups as $topic_key => $group) {
            $group_services = $group['services'];
            $primary = $group_services[0];
            if ($include_categories && !in_array((int) $primary->cate_id, $include_categories, true)) {
                continue;
            }
            if ($exclude_categories && in_array((int) $primary->cate_id, $exclude_categories, true)) {
                continue;
            }
            $category_override = $category_overrides[(int) $primary->cate_id] ?? null;
            if (!$category_override) {
                continue;
            }
            if ($category_override && $category_override['generate_mode'] === 'none') {
                continue;
            }
            $category_mode = $category_override['generate_mode'] ?? 'category';
            if (!in_array($category_mode, ['category', 'both'], true)) {
                continue;
            }
            if ($excluded_services && in_array((int) $primary->id, $excluded_services, true)) {
                continue;
            }
            if (empty($group['topic'])) {
                continue;
            }

            $country_pages = [];
            foreach ($group_services as $service) {
                $matched = aghasocial_ai_pages_detect_country($service->name, $countries);
                if ($matched) {
                    $country_pages[$matched['adjective']] = $matched['adjective'];
                }
            }
            foreach ($country_pages as $adjective) {
                $title = trim(sprintf('خرید %s %s', $group['topic'], $adjective));
                $country_page = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$pages_table} WHERE type = 'country' AND country = %s AND (group_key = %s OR ref_id = %d) ORDER BY id ASC LIMIT 1",
                    $adjective,
                    $topic_key,
                    $primary->id
                ));
                if ($country_page) {
                    if (empty($country_page->group_key)) {
                        $wpdb->update($pages_table, ['group_key' => $topic_key], ['id' => $country_page->id]);
                    }
                    $this->update_country_page((int) $country_page->page_id, (int) $primary->cate_id, $title);
                    continue;
                }
                $payload = [
                    'type' => 'country',
                    'ref_id' => $primary->id,
                    'category_id' => $primary->cate_id,
                    'country' => $adjective,
                    'service_ids' => wp_list_pluck($group_services, 'id'),
                    'normalized' => $topic_key,
                    'planned_title' => $title,
                ];
                $payload_json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
                $queued = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$queue_table} WHERE type = 'generate' AND status = 'pending' AND payload = %s LIMIT 1",
                    $payload_json
                ));
                if (!$queued) {
                    $wpdb->insert($queue_table, [
                        'type' => 'generate',
                        'status' => 'pending',
                        'payload' => $payload_json,
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql'),
                    ]);
                }
            }

            $base_name = $group['topic'];
            $base_name = preg_replace('/^\\s*خرید\\s+/u', '', $base_name);
            if (!empty($settings['strip_country_terms'])) {
                $base_name = aghasocial_ai_pages_strip_country_terms($base_name, $countries);
            }
            $base_name = aghasocial_ai_pages_remove_noise_terms($base_name, $settings['title_noise_terms']);
            if ($base_name === '') {
                continue;
            }
            $title_template = $this->get_quantity_title_template($topic_key);
            $quantity_titles = $this->generate_quantity_titles($base_name, $quantities);
            $has_country_term = aghasocial_ai_pages_contains_country_terms($primary->name, $countries);
            if (!$has_country_term) {
                foreach ($quantities as $quantity) {
                    $planned_title = $quantity_titles[$quantity]
                        ?? $this->build_quantity_title_from_template($title_template, $quantity, $base_name);
                    $quantity_page = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$pages_table} WHERE type = 'quantity' AND quantity = %d AND (group_key = %s OR ref_id = %d) ORDER BY id ASC LIMIT 1",
                        $quantity,
                        $topic_key,
                        $primary->id
                    ));
                    if ($quantity_page) {
                        if (empty($quantity_page->group_key)) {
                            $wpdb->update($pages_table, ['group_key' => $topic_key], ['id' => $quantity_page->id]);
                        }
                        $this->update_quantity_page(
                            (int) $quantity_page->page_id,
                            wp_list_pluck($group_services, 'id'),
                            $quantity,
                            null,
                            $planned_title
                        );
                        continue;
                    }
                    $payload = [
                        'type' => 'quantity',
                        'ref_id' => $primary->id,
                        'quantity' => $quantity,
                        'category_id' => $primary->cate_id,
                        'service_ids' => wp_list_pluck($group_services, 'id'),
                        'normalized' => $topic_key,
                        'planned_title' => $planned_title,
                    ];
                    $payload_json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
                    $queued = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$queue_table} WHERE type = 'generate' AND status = 'pending' AND payload = %s LIMIT 1",
                        $payload_json
                    ));
                    if (!$queued) {
                        $wpdb->insert($queue_table, [
                            'type' => 'generate',
                            'status' => 'pending',
                            'payload' => $payload_json,
                            'created_at' => current_time('mysql'),
                            'updated_at' => current_time('mysql'),
                        ]);
                    }
                }
            }

            if (!empty($settings['enable_country_quantity'])) {
                $country_include = array_map('trim', preg_split('/[\s,]+/', (string) $settings['country_quantity_include']));
                $country_include = array_filter($country_include);
                $matched_country = $has_country_term ? aghasocial_ai_pages_detect_country($primary->name, $countries) : null;
                $country_list = $matched_country ? [$matched_country] : $countries;
                foreach ($country_list as $country) {
                    if ($country_include && !in_array($country['name'], $country_include, true)) {
                        continue;
                    }
                    $adjective = $country['adjective'];
                    foreach ($quantities as $quantity) {
                        $title = $quantity_titles[$quantity]
                            ?? $this->build_quantity_title_from_template($title_template, $quantity, $base_name);
                        $title = trim(sprintf('%s %s', $title, $adjective));
                        $quantity_page = $wpdb->get_row($wpdb->prepare(
                            "SELECT * FROM {$pages_table} WHERE type = 'quantity' AND quantity = %d AND country = %s AND (group_key = %s OR ref_id = %d) ORDER BY id ASC LIMIT 1",
                            $quantity,
                            $adjective,
                            $topic_key,
                            $primary->id
                        ));
                        if ($quantity_page) {
                            if (empty($quantity_page->group_key)) {
                                $wpdb->update($pages_table, ['group_key' => $topic_key], ['id' => $quantity_page->id]);
                            }
                            $this->update_quantity_page(
                                (int) $quantity_page->page_id,
                                wp_list_pluck($group_services, 'id'),
                                $quantity,
                                $adjective,
                                $title
                            );
                            continue;
                        }
                        $payload = [
                            'type' => 'quantity',
                            'ref_id' => $primary->id,
                            'quantity' => $quantity,
                            'category_id' => $primary->cate_id,
                            'service_ids' => wp_list_pluck($group_services, 'id'),
                            'normalized' => $topic_key,
                            'country' => $adjective,
                            'planned_title' => $title,
                        ];
                        $payload_json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
                        $queued = $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM {$queue_table} WHERE type = 'generate' AND status = 'pending' AND payload = %s LIMIT 1",
                            $payload_json
                        ));
                        if (!$queued) {
                            $wpdb->insert($queue_table, [
                                'type' => 'generate',
                                'status' => 'pending',
                                'payload' => $payload_json,
                                'created_at' => current_time('mysql'),
                                'updated_at' => current_time('mysql'),
                            ]);
                        }
                    }
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
                'group_key' => $payload['normalized'] ?? null,
                'quantity' => $payload['quantity'] ?? null,
                'country' => $payload['country'] ?? null,
                'status' => 'draft',
                'created_at' => current_time('mysql'),
            ]);

            $settings['last_generated_page_id'] = $page_id;
            $settings['last_generated_title'] = get_the_title($page_id);
            $settings['last_generated_at'] = current_time('mysql');
            update_option(AGHASOCIAL_AI_PAGES_OPTION, $settings);

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
        $planned_title = $payload['planned_title'] ?? null;

        if ($type === 'category') {
            return $this->create_category_page($ref_id);
        }

        if ($type === 'service') {
            return $this->create_service_page($service_ids);
        }

        if ($type === 'country') {
            return $this->create_country_page((int) $payload['category_id'], $country, $planned_title);
        }

        if ($type === 'quantity') {
            return $this->create_quantity_page($service_ids, $quantity, $country, $planned_title);
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

        $placeholders = $this->build_ai_placeholders($title, $category->name);
        return $this->create_elementor_page($title, $content, [], $placeholders, $category->name, (int) $category_id, 0, 'category');
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

        $placeholders = $this->build_ai_placeholders($title, $service->name);
        return $this->create_elementor_page($title, $content, [], $placeholders, $service->name, (int) $service->cate_id, (int) $service_id, 'service');
    }

    private function create_country_page($category_id, $country, $planned_title = null) {
        global $wpdb;
        $category = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}samyar_categories WHERE id = %d", (int) $category_id));
        if (!$category) {
            return new WP_Error('missing_category', 'Category not found');
        }

        $title = $planned_title ?: trim(sprintf('خرید %s %s', $category->name, $country));
        $content = '[samyar_services cat=' . (int) $category_id . ']';
        $placeholders = $this->build_ai_placeholders($title, $category->name);
        return $this->create_elementor_page($title, $content, [], $placeholders, $category->name, (int) $category_id, 0, 'country');
    }

    private function update_service_page($page_id, $service_ids) {
        if (!$page_id) {
            return;
        }

        global $wpdb;
        $service_id = (int) $service_ids[0];
        $service = $wpdb->get_row($wpdb->prepare("SELECT name FROM {$wpdb->prefix}samyar_services WHERE id = %d", $service_id));
        if (!$service) {
            return;
        }

        $shortcodes = [];
        foreach ($service_ids as $id) {
            $shortcodes[] = '[kando_service id=' . (int) $id . ']';
        }
        $content = implode("\n", $shortcodes);

        wp_update_post([
            'ID' => $page_id,
            'post_title' => $service->name,
            'post_content' => $content,
        ]);
    }

    private function create_quantity_page($service_ids, $quantity, $country = null, $planned_title = null) {
        global $wpdb;
        $service_id = (int) $service_ids[0];
        $service = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}samyar_services WHERE id = %d", $service_id));
        if (!$service) {
            return new WP_Error('missing_service', 'Service not found');
        }

        $title = $planned_title ?: trim(sprintf('خرید %d %s %s', $quantity, $service->name, $country ? $country : ''));
        $elementor_data = $this->build_kando_pack_group($service_ids, $quantity, $service->name);

        $placeholders = $this->build_ai_placeholders($title, $service->name);
        return $this->create_elementor_page($title, '', $elementor_data, $placeholders, $service->name, (int) $service->cate_id, (int) $service_id, 'quantity');
    }

    private function update_quantity_page($page_id, $service_ids, $quantity, $country = null, $planned_title = null) {
        if (!$page_id) {
            return;
        }

        $settings = aghasocial_ai_pages_get_settings();
        global $wpdb;
        $service_id = (int) $service_ids[0];
        $service = $wpdb->get_row($wpdb->prepare("SELECT name FROM {$wpdb->prefix}samyar_services WHERE id = %d", $service_id));
        if (!$service) {
            return;
        }

        $title = $planned_title ?: trim(sprintf('خرید %d %s %s', $quantity, $service->name, $country ? $country : ''));
        $elementor_data = $this->build_kando_pack_group($service_ids, $quantity, $service->name);

        wp_update_post([
            'ID' => $page_id,
            'post_title' => $title,
        ]);

        update_post_meta($page_id, '_elementor_data', wp_json_encode($elementor_data, JSON_UNESCAPED_UNICODE));
        update_post_meta($page_id, '_elementor_edit_mode', 'builder');
        update_post_meta($page_id, '_elementor_template_type', 'page');
        $page_template = $settings['page_template'] ?? '';
        if (!$page_template) {
            $page_template = 'elementor_header_footer';
        }
        update_post_meta($page_id, '_wp_page_template', $page_template);
    }

    private function update_country_page($page_id, $category_id, $title) {
        if (!$page_id) {
            return;
        }
        $settings = aghasocial_ai_pages_get_settings();
        wp_update_post([
            'ID' => $page_id,
            'post_title' => $title,
        ]);
        update_post_meta($page_id, '_elementor_edit_mode', 'builder');
        update_post_meta($page_id, '_elementor_template_type', 'page');
        $page_template = $settings['page_template'] ?? '';
        if (!$page_template) {
            $page_template = 'elementor_header_footer';
        }
        update_post_meta($page_id, '_wp_page_template', $page_template);
    }

    private function generate_quantity_titles($service_name, $quantities) {
        $settings = aghasocial_ai_pages_get_settings();
        if (empty($settings['enable_quantity_ai_titles'])) {
            return [];
        }

        if (empty($settings['enable_rewrite']) || empty($settings['openrouter_api_key'])) {
            return [];
        }

        if (!$quantities) {
            return [];
        }

        $ai = new Aghasocial_AI_Pages_AI();
        $system = 'You are a Persian SEO copywriter. Create natural, click-worthy titles for quantity-based landing pages. Avoid awkward phrases, avoid country terms, and avoid duplicating the word خرید if it is already in the service name. Return JSON map: quantity -> title.';
        $prompt = "Service: {$service_name}\nQuantities: " . implode(',', $quantities) . "\nReturn JSON object where each key is a quantity and value is a unique Persian title.";
        $schema = [
            'name' => 'quantity_titles',
            'schema' => [
                'type' => 'object',
                'additionalProperties' => ['type' => 'string'],
            ],
        ];

        $response = $ai->request_text($prompt, $system, $schema, $settings['quantity_title_model']);
        if (is_wp_error($response)) {
            return [];
        }

        $content = $response['choices'][0]['message']['content'] ?? null;
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return [];
        }

        $titles = [];
        foreach ($decoded as $qty => $title) {
            if (is_numeric($qty) && is_string($title)) {
                $titles[(int) $qty] = $title;
            }
        }

        return $titles;
    }

    private function get_quantity_title_template($group_key) {
        global $wpdb;
        $pages_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_PAGES_TABLE;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT page_id FROM {$pages_table} WHERE type = 'quantity' AND group_key = %s ORDER BY id ASC LIMIT 1",
            $group_key
        ));
        if (!$row || empty($row->page_id)) {
            return '';
        }
        $title = get_the_title((int) $row->page_id);
        if (!$title) {
            return '';
        }
        if (!preg_match('/\\d+/', $title)) {
            return '';
        }
        return preg_replace('/\\d+/', '{quantity}', $title, 1);
    }

    private function build_quantity_title_from_template($template, $quantity, $base_name) {
        if ($template) {
            return str_replace('{quantity}', (string) $quantity, $template);
        }
        return sprintf('خرید %d %s', $quantity, $base_name);
    }

    private function build_kando_pack_group($service_ids, $quantity, $service_name) {
        $settings = aghasocial_ai_pages_get_settings();
        global $wpdb;
        $service_rows = [];
        if ($service_ids) {
            $placeholders = implode(',', array_fill(0, count($service_ids), '%d'));
            $service_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, name, description FROM {$wpdb->prefix}samyar_services WHERE id IN ($placeholders)",
                    $service_ids
                )
            );
        }

        $service_map = [];
        foreach ($service_rows as $row) {
            $service_map[(int) $row->id] = $row;
        }

        $elements = [];
        foreach ($service_ids as $service_id) {
        $template = $settings['pack_template'];
        $service_row = $service_map[(int) $service_id] ?? null;
        $service_label = $service_row && $service_row->name ? $service_row->name : $service_name;
        $quantity_en = (string) $quantity;
        $quantity_fa = aghasocial_ai_pages_persian_digits($quantity_en);
        $pack_title = trim(sprintf('%s %s', $quantity_fa, $service_label));
        $pack_content = $service_row && $service_row->description
            ? $service_row->description
            : sprintf('بسته %s برای %s', $quantity_en, $service_label);
        if ($template) {
            $template = str_replace(
                [
                    '{{service_id}}',
                    '{{quantity}}',
                    '{{quantity_en}}',
                    '{{quantity_fa}}',
                    '{{service_name}}',
                    '{{service_title}}',
                    '{{service_description}}',
                ],
                [
                    $service_id,
                    $quantity,
                    $quantity_en,
                    $quantity_fa,
                    $service_label,
                    $pack_title,
                    $pack_content,
                ],
                $template
            );
                $decoded = json_decode($template, true);
                if ($decoded) {
                    if (isset($decoded['elType'])) {
                        $elements[] = $decoded;
                    } elseif (array_is_list($decoded)) {
                        $elements = array_merge($elements, $decoded);
                    } else {
                        $elements[] = $decoded;
                    }
                    continue;
                }
            }

            $elements[] = [
                'id' => wp_generate_uuid4(),
                'elType' => 'widget',
                'widgetType' => 'kando-pack',
                'settings' => [
                    'service-id' => $service_id,
                    'pack-title' => $pack_title,
                    'pack-number' => $quantity,
                    'pack-content' => $pack_content,
                ],
                'elements' => [],
            ];
        }

        return [[
            'id' => wp_generate_uuid4(),
            'elType' => 'container',
            'settings' => [
                'container_type' => 'grid',
                'grid_columns_grid' => [
                    'unit' => 'fr',
                    'size' => '3',
                ],
                'grid_rows_grid' => [
                    'unit' => 'fr',
                    'size' => '1',
                ],
                'grid_rows_grid_mobile' => [
                    'unit' => 'fr',
                    'size' => '1',
                ],
            ],
            'elements' => $elements,
            'isInner' => false,
        ]];
    }

    private function create_elementor_page($title, $content, $elementor_data, $placeholders = [], $service_name = '', $category_id = 0, $service_id = 0, $page_type = 'default') {
        $settings = aghasocial_ai_pages_get_settings();
        $fallback = '';
        if ($service_id) {
            $fallback = 'service-' . (int) $service_id;
        } elseif ($category_id) {
            $fallback = 'category-' . (int) $category_id;
        }
        $slug_source_title = $title;
        $page_title = $placeholders['_page_title'] ?? $title;
        $slug = aghasocial_ai_pages_generate_slug($slug_source_title, $fallback);
        $post_id = wp_insert_post([
            'post_title' => $page_title,
            'post_name' => $slug,
            'post_content' => $content,
            'post_status' => 'draft',
            'post_type' => 'page',
            'comment_status' => 'open',
        ], true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        $pack_elements = $page_type === 'quantity' ? $elementor_data : [];
        $template = $settings['elementor_template'];
        if ($template) {
            $decoded = json_decode($template, true);
            if ($decoded) {
                if ($page_type === 'quantity') {
                    $elementor_data = $this->inject_pack_elements($decoded, $pack_elements);
                } else {
                    $elementor_data = $decoded;
                }
            }
        }

        if (!empty($elementor_data)) {
            $placeholders['{cat_id}'] = (string) (int) $category_id;
            $placeholders['{service_id}'] = (string) (int) $service_id;
            $placeholders['{breadcrumb}'] = '';
            $placeholders['{breadcrumb_schema}'] = '';
            if ($page_type === 'quantity') {
                $breadcrumb = $this->build_breadcrumbs($category_id, $page_title);
                $placeholders['{breadcrumb}'] = $breadcrumb['html'];
                $placeholders['{breadcrumb_schema}'] = $breadcrumb['schema'];
            }
            $elementor_data = $this->apply_placeholders_to_elementor($elementor_data, $placeholders);
            if ($page_type === 'quantity') {
                $elementor_data = $this->inject_pack_elements($elementor_data, $pack_elements);
                $placeholders['{pack_elements}'] = '';
                $elementor_data = $this->apply_placeholders_to_elementor($elementor_data, $placeholders);
            }
            $image_result = $this->attach_ai_images($elementor_data, $title, $service_name);
            $elementor_data = $image_result['data'];
            if (!empty($image_result['image'])) {
                $image_placeholders = [
                    '{image_url}' => $image_result['image']['url'] ?? '',
                    '{image_id}' => (string) ($image_result['image']['id'] ?? ''),
                    '{image_alt}' => $image_result['image']['alt'] ?? '',
                ];
                $elementor_data = $this->apply_placeholders_to_elementor($elementor_data, $image_placeholders);
            }
            update_post_meta($post_id, '_elementor_data', wp_json_encode($elementor_data, JSON_UNESCAPED_UNICODE));
            update_post_meta($post_id, '_elementor_edit_mode', 'builder');
            update_post_meta($post_id, '_elementor_template_type', 'page');
            update_post_meta($post_id, '_elementor_page_settings', [
                'page_layout' => 'elementor_canvas',
            ]);
            $page_template = $settings['page_template'] ?? '';
            if (!$page_template) {
                $page_template = 'elementor_header_footer';
            }
            update_post_meta($post_id, '_wp_page_template', $page_template);
        }

        return $post_id;
    }

    // Build placeholder content from AI (or fallback values if AI fails).
    private function build_ai_placeholders($title, $service_name) {
        $settings = aghasocial_ai_pages_get_settings();
        $fallback_placeholders = [
            '{title}' => $title,
            '{description}' => '',
            '{content}' => '',
            '{cta-title}' => '',
            '{cta-text}' => '',
            '{cta-button}' => '',
            '{note_title}' => '',
        ];
        for ($i = 1; $i <= 4; $i++) {
            $fallback_placeholders['{note' . $i . '}'] = '';
            $fallback_placeholders['{note_explanation' . $i . '}'] = '';
        }
        for ($i = 1; $i <= 5; $i++) {
            $fallback_placeholders['{faq-' . $i . '-question}'] = '';
            $fallback_placeholders['{faq-' . $i . '-answer}'] = '';
        }
        for ($i = 1; $i <= 3; $i++) {
            $fallback_placeholders['{testimonial-' . $i . '}'] = '';
            $fallback_placeholders['{testimonial-name-' . $i . '}'] = '';
        }

        if (empty($settings['openrouter_api_key'])) {
            return $fallback_placeholders;
        }

        $ai = new Aghasocial_AI_Pages_AI();
        $system = 'You are a Persian marketing copywriter. Output ONLY valid JSON. Do not add any extra text.';
        $prompt_template = $settings['content_prompt_template'];
        $prompt_template = $prompt_template ?: "Service: {service}\nTitle: {title}\nWrite SEO-friendly Persian HTML body content with multiple H2 sections, bullet lists, and a professional tone. Return JSON with keys: description, content, cta_title, cta_text, cta_button, faq (5 items: question/answer), testimonials (3 items: name/text).";
        $prompt = str_replace(['{service}', '{title}'], [$service_name, $title], $prompt_template);
        $prompt .= "\nWe are calling you via API and will parse JSON only. If you include anything outside JSON, it will be rejected.";
        $schema = [
            'name' => 'landing_placeholders',
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'content' => ['type' => 'string'],
                    'cta_title' => ['type' => 'string'],
                    'cta_text' => ['type' => 'string'],
                    'cta_button' => ['type' => 'string'],
                    'note_title' => ['type' => 'string'],
                    'notes' => [
                        'type' => 'array',
                        'minItems' => 4,
                        'maxItems' => 4,
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'explanation' => ['type' => 'string'],
                            ],
                            'required' => ['title', 'explanation'],
                        ],
                    ],
                    'faq' => [
                        'type' => 'array',
                        'minItems' => 5,
                        'maxItems' => 5,
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'question' => ['type' => 'string'],
                                'answer' => ['type' => 'string'],
                            ],
                            'required' => ['question', 'answer'],
                        ],
                    ],
                    'testimonials' => [
                        'type' => 'array',
                        'minItems' => 3,
                        'maxItems' => 3,
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'text' => ['type' => 'string'],
                            ],
                            'required' => ['name', 'text'],
                        ],
                    ],
                ],
                'required' => [
                    'title',
                    'description',
                    'content',
                    'cta_title',
                    'cta_text',
                    'cta_button',
                    'note_title',
                    'notes',
                    'faq',
                    'testimonials',
                ],
            ],
        ];

        $settings['placeholders_last_request'] = wp_json_encode([
            'model' => $settings['text_model'],
            'system' => $system,
            'prompt' => $prompt,
            'json_schema' => $schema,
        ], JSON_UNESCAPED_UNICODE);
        $settings['placeholders_last_built_at'] = current_time('mysql');

        $response = $ai->request_text($prompt, $system, $schema, $settings['text_model']);
        if (is_wp_error($response)) {
            $settings['placeholders_last_status'] = 'error';
            $settings['placeholders_last_error'] = $response->get_error_message();
            $settings['placeholders_last_response'] = '';
            update_option(AGHASOCIAL_AI_PAGES_OPTION, $settings);
            return $fallback_placeholders;
        }

        $content = $response['choices'][0]['message']['content'] ?? null;
        $settings['placeholders_last_response'] = wp_json_encode($response, JSON_UNESCAPED_UNICODE);
        if ($settings['placeholders_last_response']) {
            $settings['placeholders_last_response'] = mb_substr($settings['placeholders_last_response'], 0, 10000);
        }
        $decoded = $content ? json_decode($content, true) : null;
        if (!is_array($decoded)) {
            $settings['placeholders_last_status'] = 'invalid_json';
            $settings['placeholders_last_error'] = 'AI response was not valid JSON.';
            update_option(AGHASOCIAL_AI_PAGES_OPTION, $settings);
            return $fallback_placeholders;
        }
        $settings['placeholders_last_status'] = 'ok';
        $settings['placeholders_last_error'] = '';
        update_option(AGHASOCIAL_AI_PAGES_OPTION, $settings);

        $page_title = $this->normalize_ai_text($decoded['title'] ?? '');
        if ($page_title === '') {
            $page_title = $title;
        }

        $placeholders = [
            '{title}' => $page_title,
            '{description}' => $this->normalize_ai_text($decoded['description'] ?? ''),
            '{content}' => $this->normalize_ai_text($decoded['content'] ?? ''),
            '{cta-title}' => $this->normalize_ai_text($decoded['cta_title'] ?? ''),
            '{cta-text}' => $this->normalize_ai_text($decoded['cta_text'] ?? ''),
            '{cta-button}' => $this->normalize_ai_text($decoded['cta_button'] ?? ''),
            '_page_title' => $page_title,
        ];

        $faq = $decoded['faq'] ?? [];
        for ($i = 1; $i <= 5; $i++) {
            $item = $faq[$i - 1] ?? [];
            $placeholders['{faq-' . $i . '-question}'] = $this->normalize_ai_text($item['question'] ?? '');
            $placeholders['{faq-' . $i . '-answer}'] = $this->normalize_ai_text($item['answer'] ?? '');
        }

        $testimonials = $decoded['testimonials'] ?? [];
        for ($i = 1; $i <= 3; $i++) {
            $item = $testimonials[$i - 1] ?? [];
            $placeholders['{testimonial-' . $i . '}'] = $this->normalize_ai_text($item['text'] ?? '');
            $placeholders['{testimonial-name-' . $i . '}'] = $this->normalize_ai_text($item['name'] ?? '');
        }

        $note_title = $this->normalize_ai_text($decoded['note_title'] ?? '');
        $placeholders['{note_title}'] = $note_title;
        $notes = $decoded['notes'] ?? [];
        for ($i = 1; $i <= 4; $i++) {
            $item = $notes[$i - 1] ?? [];
            $placeholders['{note' . $i . '}'] = $this->normalize_ai_text($item['title'] ?? '');
            $placeholders['{note_explanation' . $i . '}'] = $this->normalize_ai_text($item['explanation'] ?? '');
        }

        if (!empty($faq)) {
            $schema = [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => [],
            ];
            foreach ($faq as $item) {
                if (empty($item['question']) || empty($item['answer'])) {
                    continue;
                }
                $schema['mainEntity'][] = [
                    '@type' => 'Question',
                    'name' => $item['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $item['answer'],
                    ],
                ];
            }
            if (!empty($schema['mainEntity'])) {
                $placeholders['{faq-schema}'] = '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
            }
        }

        return $placeholders;
    }

    private function apply_placeholders_to_elementor($elementor_data, $placeholders) {
        return $this->replace_placeholders_recursive($elementor_data, $placeholders);
    }

    private function normalize_ai_text($text) {
        $text = (string) $text;
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace("\\\\n", "\n", $text);
        $text = str_replace("\\n", "\n", $text);
        $text = preg_replace('/>\s*n\s*</u', '><', $text);
        $text = preg_replace('/\s*n\s*(?=<)/u', '', $text);
        $text = preg_replace('/^\s*n\s*$/m', '', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    private function replace_placeholders_recursive($data, $placeholders) {
        if (is_string($data)) {
            return str_replace(array_keys($placeholders), array_values($placeholders), $data);
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->replace_placeholders_recursive($value, $placeholders);
            }
            return $data;
        }

        return $data;
    }

    private function build_breadcrumbs($category_id, $page_title) {
        return aghasocial_ai_pages_build_breadcrumbs($category_id, $page_title);
    }

    private function inject_pack_elements($elements, $pack_elements) {
        if (empty($pack_elements)) {
            return $elements;
        }

        $result = [];
        foreach ($elements as $element) {
            if (!is_array($element)) {
                $result[] = $element;
                continue;
            }

            if ($this->element_is_category_list($element)) {
                continue;
            }

            if ($this->element_is_pack_placeholder($element)) {
                foreach ($pack_elements as $pack_element) {
                    $result[] = $pack_element;
                }
                continue;
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                $element['elements'] = $this->inject_pack_elements($element['elements'], $pack_elements);
            }

            $result[] = $element;
        }

        return $result;
    }

    private function element_is_pack_placeholder($element) {
        if (!isset($element['settings']) || !is_array($element['settings'])) {
            return false;
        }

        foreach ($element['settings'] as $value) {
            if (is_string($value) && $this->string_has_pack_placeholder($value)) {
                return true;
            }
            if (is_array($value) && $this->settings_contain_pack_placeholder($value)) {
                return true;
            }
        }

        return false;
    }

    private function settings_contain_pack_placeholder($settings) {
        foreach ($settings as $value) {
            if (is_string($value) && $this->string_has_pack_placeholder($value)) {
                return true;
            }
            if (is_array($value) && $this->settings_contain_pack_placeholder($value)) {
                return true;
            }
        }

        return false;
    }

    private function string_has_pack_placeholder($value) {
        return trim($value) === '{pack_elements}' || strpos($value, '{pack_elements}') !== false;
    }

    private function element_is_category_list($element) {
        if (($element['elType'] ?? '') !== 'widget') {
            return false;
        }
        if (($element['widgetType'] ?? '') !== 'shortcode') {
            return false;
        }
        $shortcode = $element['settings']['shortcode'] ?? '';
        return is_string($shortcode) && stripos($shortcode, 'samyar_services') !== false;
    }

    private function attach_ai_images($elementor_data, $title, $service_name) {
        $settings = aghasocial_ai_pages_get_settings();
        if (empty($settings['enable_ai_images']) || empty($settings['openrouter_api_key'])) {
            return [
                'data' => $elementor_data,
                'image' => null,
            ];
        }

        $prompt = $settings['image_prompt_template'] ?: 'تصویر حرفه‌ای و مینیمال برای {title}';
        $prompt = str_replace(['{title}', '{service}'], [$title, $service_name], $prompt);

        $ai = new Aghasocial_AI_Pages_AI();
        $response = $ai->request_image($prompt);
        aghasocial_ai_pages_log('ai_image', [
            'model' => $settings['image_model'],
            'prompt' => $prompt,
        ], $response);
        if (is_wp_error($response)) {
            return [
                'data' => $elementor_data,
                'image' => null,
            ];
        }

        $image_url = $response['data'][0]['url'] ?? null;
        if (!$image_url) {
            return [
                'data' => $elementor_data,
                'image' => null,
            ];
        }

        $attachment_id = $this->sideload_image($image_url, $title);
        if (!$attachment_id) {
            return [
                'data' => $elementor_data,
                'image' => null,
            ];
        }

        $image_data = [
            'id' => $attachment_id,
            'url' => wp_get_attachment_url($attachment_id),
            'alt' => $title,
        ];

        $replacement = $this->replace_image_placeholders($elementor_data, $image_data);
        if (!$replacement['replaced']) {
            $replacement['data'] = $this->replace_first_image_widget($replacement['data'], $image_data);
        }

        return [
            'data' => $replacement['data'],
            'image' => $image_data,
        ];
    }

    private function replace_image_placeholders($elements, $image_data) {
        $replaced = false;
        foreach ($elements as $index => $element) {
            if (!is_array($element)) {
                continue;
            }
            if (($element['elType'] ?? '') === 'widget' && ($element['widgetType'] ?? '') === 'image') {
                $settings = $element['settings'] ?? [];
                $image_settings = $settings['image'] ?? [];
                $url = $image_settings['url'] ?? '';
                $id = $image_settings['id'] ?? '';
                $alt = $image_settings['alt'] ?? '';
                $has_placeholder = (is_string($url) && strpos($url, '{image_url}') !== false)
                    || (is_string($id) && strpos($id, '{image_id}') !== false)
                    || (is_string($alt) && strpos($alt, '{image_alt}') !== false);
                if ($has_placeholder) {
                    $elements[$index]['settings']['image']['id'] = $image_data['id'];
                    $elements[$index]['settings']['image']['url'] = $image_data['url'];
                    $elements[$index]['settings']['image']['alt'] = $image_data['alt'];
                    $replaced = true;
                }
            }
            if (!empty($element['elements'])) {
                $child = $this->replace_image_placeholders($element['elements'], $image_data);
                $elements[$index]['elements'] = $child['data'];
                if ($child['replaced']) {
                    $replaced = true;
                }
            }
        }

        return [
            'data' => $elements,
            'replaced' => $replaced,
        ];
    }

    private function replace_first_image_widget($elements, $image_data) {
        foreach ($elements as $index => $element) {
            if (!is_array($element)) {
                continue;
            }
            if (($element['elType'] ?? '') === 'widget' && ($element['widgetType'] ?? '') === 'image') {
                $elements[$index]['settings']['image'] = $image_data;
                return $elements;
            }
            if (!empty($element['elements'])) {
                $elements[$index]['elements'] = $this->replace_first_image_widget($element['elements'], $image_data);
            }
        }
        return $elements;
    }

    private function sideload_image($url, $title) {
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $tmp = download_url($url);
        if (is_wp_error($tmp)) {
            return 0;
        }

        $processed = $this->prepare_ai_image($tmp);
        if ($processed) {
            $tmp = $processed;
        }

        $file = [
            'name' => sanitize_file_name($title) . '-' . wp_generate_uuid4() . '.webp',
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload($file, 0);
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return 0;
        }

        return $attachment_id;
    }

    private function prepare_ai_image($tmp_path) {
        $editor = wp_get_image_editor($tmp_path);
        if (is_wp_error($editor)) {
            return '';
        }

        $editor->resize(512, 512, true);

        $quality = 82;
        $best_path = '';
        while ($quality >= 50) {
            $saved = $editor->save(null, 'image/webp', ['quality' => $quality]);
            if (is_wp_error($saved) || empty($saved['path'])) {
                break;
            }

            $best_path = $saved['path'];
            if (filesize($best_path) <= 100 * 1024) {
                break;
            }

            $quality -= 10;
        }

        if ($best_path) {
            @unlink($tmp_path);
            return $best_path;
        }

        return '';
    }
}
