<?php

if (!defined('ABSPATH')) {
    exit;
}

class Aghasocial_AI_Pages_Rewrite {
    public function enqueue_rewrite_tasks() {
        $settings = aghasocial_ai_pages_get_settings();
        if (empty($settings['enable_rewrite'])) {
            return;
        }

        global $wpdb;
        $services_table = $wpdb->prefix . 'samyar_services';
        $meta_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_META_TABLE;
        $queue_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_QUEUE_TABLE;

        $services = $wpdb->get_results("SELECT id, name, description, cate_id FROM {$services_table} WHERE status = 1");
        if (empty($settings['enable_group_rewrite'])) {
            $batch_size = max(1, (int) $settings['batch_size']);
            $service_ids = [];
            foreach ($services as $service) {
                $meta = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$meta_table} WHERE ref_type = 'service' AND ref_id = %d", $service->id));
                if (!$meta) {
                    $service_ids[] = (int) $service->id;
                }
            }
            $chunks = array_chunk($service_ids, $batch_size);
            foreach ($chunks as $chunk) {
                $payload = [
                    'service_ids' => $chunk,
                ];
                $wpdb->insert($queue_table, [
                    'type' => 'rewrite_service_batch',
                    'status' => 'pending',
                    'payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ]);
            }
        }

        $categories = $wpdb->get_results("SELECT id, name, description FROM {$wpdb->prefix}samyar_categories WHERE status = 1");
        foreach ($categories as $category) {
            $meta = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$meta_table} WHERE ref_type = 'category' AND ref_id = %d", $category->id));
            if (!$meta) {
                $payload = [
                    'ref_type' => 'category',
                    'ref_id' => $category->id,
                ];
                $wpdb->insert($queue_table, [
                    'type' => 'rewrite',
                    'status' => 'pending',
                    'payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ]);
            }

            if (!empty($settings['enable_group_rewrite'])) {
                $service_ids = wp_list_pluck(array_filter($services, function ($service) use ($category) {
                    return (int) $service->cate_id === (int) $category->id;
                }), 'id');
                if ($service_ids) {
                $payload = [
                    'ref_type' => 'category_group',
                    'ref_id' => $category->id,
                    'service_ids' => $service_ids,
                ];
                $wpdb->insert($queue_table, [
                    'type' => 'rewrite_group',
                    'status' => 'pending',
                    'payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ]);
                }
            }
        }
    }

    public function rewrite_one_item() {
        $settings = aghasocial_ai_pages_get_settings();
        if (empty($settings['enable_rewrite'])) {
            return 'rewrite_disabled';
        }

        global $wpdb;
        $queue_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_QUEUE_TABLE;
        $task = $wpdb->get_row("SELECT * FROM {$queue_table} WHERE type IN ('rewrite','rewrite_group','rewrite_service_batch') AND status = 'pending' ORDER BY id ASC LIMIT 1");
        if (!$task) {
            return 'no_tasks';
        }

        $payload = json_decode($task->payload, true);
        if ($task->type === 'rewrite_group') {
            $result = $this->process_group_rewrite($payload);
        } elseif ($task->type === 'rewrite_service_batch') {
            $result = $this->process_service_batch($payload['service_ids'] ?? []);
        } else {
            $result = $this->process_rewrite($payload['ref_type'], $payload['ref_id']);
        }

        if ($result === true) {
            $wpdb->update($queue_table, [
                'status' => 'done',
                'updated_at' => current_time('mysql'),
            ], ['id' => $task->id]);
            return 'ok';
        }

        $wpdb->update($queue_table, [
            'status' => 'error',
            'last_error' => is_wp_error($result) ? $result->get_error_message() : 'unknown_error',
            'updated_at' => current_time('mysql'),
        ], ['id' => $task->id]);

        return 'error';
    }

    public function rewrite_categories_batch() {
        $settings = aghasocial_ai_pages_get_settings();
        if (empty($settings['enable_rewrite'])) {
            return 'rewrite_disabled';
        }
        if (empty($settings['openrouter_api_key'])) {
            return 'missing_api_key';
        }

        global $wpdb;
        $categories = $wpdb->get_results("SELECT id, name, description FROM {$wpdb->prefix}samyar_categories WHERE status = 1");
        if (!$categories) {
            return 'no_categories';
        }

        $items = array_map(function ($category) {
            return [
                'id' => (int) $category->id,
                'title' => aghasocial_ai_pages_sanitize_ai_input($category->name, 200),
                'description' => aghasocial_ai_pages_sanitize_ai_input($category->description, 400),
            ];
        }, $categories);

        $ai = new Aghasocial_AI_Pages_AI();
        $system = 'You are a Persian marketing copywriter. Rewrite each category title/description to be short, clear, and SEO-friendly. Return JSON only.';
        $prompt = "Categories JSON:\n" . wp_json_encode($items, JSON_UNESCAPED_UNICODE) . "\nReturn JSON with key categories: [{id,title,description}].";
        aghasocial_ai_pages_log('rewrite_categories_prompt', [
            'model' => $settings['rewrite_model'],
            'system' => $system,
            'prompt' => $prompt,
        ], [], true);
        $schema = [
            'name' => 'category_rewrite_batch',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'categories' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'title' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                            ],
                            'required' => ['id', 'title', 'description'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['categories'],
                'additionalProperties' => false,
            ],
        ];

        $response = $ai->request_text($prompt, $system, $schema, $settings['rewrite_model']);
        if (is_wp_error($response)) {
            aghasocial_ai_pages_log('rewrite_categories_error', [
                'prompt' => $prompt,
            ], [
                'error' => $response->get_error_message(),
            ], true);
            return $response;
        }

        $content = $response['choices'][0]['message']['content'] ?? null;
        $decoded = $content ? json_decode($content, true) : null;
        if (!is_array($decoded) || empty($decoded['categories'])) {
            aghasocial_ai_pages_log('rewrite_categories_invalid', [
                'content' => $content,
            ], [
                'error' => 'invalid_ai',
            ], true);
            return new WP_Error('invalid_ai', 'Invalid AI response');
        }

        $meta_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_META_TABLE;
        foreach ($decoded['categories'] as $row) {
            if (empty($row['id']) || empty($row['title'])) {
                continue;
            }
            $wpdb->update($wpdb->prefix . 'samyar_categories', [
                'name' => $row['title'],
                'description' => $row['description'],
                'update_at' => current_time('mysql'),
            ], ['id' => (int) $row['id']]);

            $normalized = aghasocial_ai_pages_normalize_title($row['title']);
            $data = [
                'ref_type' => 'category',
                'ref_id' => (int) $row['id'],
                'title' => $row['title'],
                'description' => $row['description'],
                'normalized_title' => $normalized,
                'ai_payload' => wp_json_encode($row, JSON_UNESCAPED_UNICODE),
                'updated_at' => current_time('mysql'),
            ];
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$meta_table} WHERE ref_type = 'category' AND ref_id = %d", (int) $row['id']));
            if ($exists) {
                $wpdb->update($meta_table, $data, ['id' => $exists]);
            } else {
                $wpdb->insert($meta_table, $data);
            }
        }

        return 'ok';
    }

    private function process_rewrite($ref_type, $ref_id) {
        global $wpdb;
        $settings = aghasocial_ai_pages_get_settings();
        $meta_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_META_TABLE;

        if ($settings['dry_run']) {
            return true;
        }

        if ($ref_type === 'service') {
            $item = $wpdb->get_row($wpdb->prepare("SELECT name, description FROM {$wpdb->prefix}samyar_services WHERE id = %d", $ref_id));
        } else {
            $item = $wpdb->get_row($wpdb->prepare("SELECT name, description FROM {$wpdb->prefix}samyar_categories WHERE id = %d", $ref_id));
        }

        if (!$item) {
            return new WP_Error('missing_item', 'Item not found');
        }

        $ai = new Aghasocial_AI_Pages_AI();
        $countries = aghasocial_ai_pages_parse_countries($settings['countries']);
        $clean_title = $item->name;
        if (!empty($settings['strip_country_terms'])) {
            $clean_title = aghasocial_ai_pages_strip_country_terms($clean_title, $countries);
        }
        $system = 'You are a Persian marketing copywriter. Rewrite titles and descriptions so they look native to Aghasocial brand. Never mention provider, API, or external sources. Avoid country words in the title.';
        $prompt = "Title: {$clean_title}\nDescription: {$item->description}\nRewrite in Persian with unique SEO-friendly tone. Return JSON with keys: title, description.";
        aghasocial_ai_pages_log('rewrite_item_prompt', [
            'ref_type' => $ref_type,
            'ref_id' => $ref_id,
            'model' => $settings['rewrite_model'],
            'system' => $system,
            'prompt' => $prompt,
        ], [], true);
        $schema = [
            'name' => 'rewrite_response',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                ],
                'required' => ['title', 'description'],
            ],
        ];

        $response = $ai->request_text($prompt, $system, $schema, $settings['rewrite_model']);
        if (is_wp_error($response)) {
            aghasocial_ai_pages_log('rewrite_item_error', [
                'ref_type' => $ref_type,
                'ref_id' => $ref_id,
            ], [
                'error' => $response->get_error_message(),
            ], true);
            return $response;
        }

        $content = $response['choices'][0]['message']['content'] ?? null;
        $decoded = json_decode($content, true);
        if (!$decoded) {
            aghasocial_ai_pages_log('rewrite_item_invalid', [
                'ref_type' => $ref_type,
                'ref_id' => $ref_id,
                'content' => $content,
            ], [
                'error' => 'invalid_ai',
            ], true);
            return new WP_Error('invalid_ai', 'Invalid AI response');
        }

        $normalized = aghasocial_ai_pages_normalize_title($decoded['title']);

        $data = [
            'ref_type' => $ref_type,
            'ref_id' => $ref_id,
            'title' => $decoded['title'],
            'description' => $decoded['description'],
            'normalized_title' => $normalized,
            'ai_payload' => wp_json_encode($decoded, JSON_UNESCAPED_UNICODE),
            'updated_at' => current_time('mysql'),
        ];

        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$meta_table} WHERE ref_type = %s AND ref_id = %d", $ref_type, $ref_id));
        if ($exists) {
            $wpdb->update($meta_table, $data, ['id' => $exists]);
        } else {
            $wpdb->insert($meta_table, $data);
        }

        return true;
    }

    private function process_group_rewrite($payload) {
        global $wpdb;
        $settings = aghasocial_ai_pages_get_settings();
        $meta_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_META_TABLE;

        if ($settings['dry_run']) {
            return true;
        }

        $category = $wpdb->get_row($wpdb->prepare("SELECT id, name, description FROM {$wpdb->prefix}samyar_categories WHERE id = %d", $payload['ref_id']));
        if (!$category) {
            return new WP_Error('missing_category', 'Category not found');
        }

        $service_ids = array_map('intval', $payload['service_ids'] ?? []);
        if (!$service_ids) {
            return new WP_Error('missing_services', 'No services to rewrite');
        }
        $placeholders = implode(',', array_fill(0, count($service_ids), '%d'));
        $services = $wpdb->get_results($wpdb->prepare("SELECT id, name, description FROM {$wpdb->prefix}samyar_services WHERE id IN ($placeholders)", $service_ids));

        $ai = new Aghasocial_AI_Pages_AI();
        $system = 'You are a Persian marketing copywriter. Rewrite category title/description and each service title/description uniquely, concise, and non-repetitive. Keep category titles short and specific. Return JSON only.';
        $service_list = array_map(function ($service) {
            return [
                'id' => (int) $service->id,
                'title' => $service->name,
                'description' => $service->description,
            ];
        }, $services);
        $prompt = "Category: {$category->name}\nDescription: {$category->description}\nServices JSON:\n" . wp_json_encode($service_list, JSON_UNESCAPED_UNICODE) . "\nReturn JSON with keys: category{title,description}, services[{id,title,description}].";
        aghasocial_ai_pages_log('rewrite_group_prompt', [
            'category_id' => $category->id,
            'model' => $settings['rewrite_model'],
            'system' => $system,
            'prompt' => $prompt,
        ], [], true);

        $schema = [
            'name' => 'group_rewrite',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'category' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                        ],
                        'required' => ['title', 'description'],
                        'additionalProperties' => false,
                    ],
                    'services' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'title' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                            ],
                            'required' => ['id', 'title', 'description'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['category', 'services'],
                'additionalProperties' => false,
            ],
        ];

        $response = $ai->request_text($prompt, $system, $schema, $settings['rewrite_model']);
        if (is_wp_error($response)) {
            aghasocial_ai_pages_log('rewrite_group_error', [
                'category_id' => $category->id,
            ], [
                'error' => $response->get_error_message(),
            ], true);
            return $response;
        }

        $content = $response['choices'][0]['message']['content'] ?? null;
        $decoded = $content ? json_decode($content, true) : null;
        if (!is_array($decoded)) {
            aghasocial_ai_pages_log('rewrite_group_invalid', [
                'category_id' => $category->id,
                'content' => $content,
            ], [
                'error' => 'invalid_ai',
            ], true);
            return new WP_Error('invalid_ai', 'Invalid AI response');
        }

        $category_data = $decoded['category'] ?? null;
        if (is_array($category_data)) {
            $wpdb->update($wpdb->prefix . 'samyar_categories', [
                'name' => $category_data['title'],
                'description' => $category_data['description'],
                'update_at' => current_time('mysql'),
            ], ['id' => $category->id]);

            $normalized = aghasocial_ai_pages_normalize_title($category_data['title']);
            $data = [
                'ref_type' => 'category',
                'ref_id' => $category->id,
                'title' => $category_data['title'],
                'description' => $category_data['description'],
                'normalized_title' => $normalized,
                'ai_payload' => wp_json_encode($category_data, JSON_UNESCAPED_UNICODE),
                'updated_at' => current_time('mysql'),
            ];
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$meta_table} WHERE ref_type = 'category' AND ref_id = %d", $category->id));
            if ($exists) {
                $wpdb->update($meta_table, $data, ['id' => $exists]);
            } else {
                $wpdb->insert($meta_table, $data);
            }
        }

        foreach (($decoded['services'] ?? []) as $service_data) {
            if (empty($service_data['id'])) {
                continue;
            }
            $wpdb->update($wpdb->prefix . 'samyar_services', [
                'name' => $service_data['title'],
                'description' => $service_data['description'],
                'update_at' => current_time('mysql'),
            ], ['id' => (int) $service_data['id']]);

            $normalized = aghasocial_ai_pages_normalize_title($service_data['title']);
            $data = [
                'ref_type' => 'service',
                'ref_id' => (int) $service_data['id'],
                'title' => $service_data['title'],
                'description' => $service_data['description'],
                'normalized_title' => $normalized,
                'ai_payload' => wp_json_encode($service_data, JSON_UNESCAPED_UNICODE),
                'updated_at' => current_time('mysql'),
            ];
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$meta_table} WHERE ref_type = 'service' AND ref_id = %d", (int) $service_data['id']));
            if ($exists) {
                $wpdb->update($meta_table, $data, ['id' => $exists]);
            } else {
                $wpdb->insert($meta_table, $data);
            }
        }

        return true;
    }

    private function process_service_batch($service_ids) {
        $settings = aghasocial_ai_pages_get_settings();
        if (!$service_ids) {
            return new WP_Error('missing_services', 'No services to rewrite');
        }
        if (empty($settings['openrouter_api_key'])) {
            return new WP_Error('missing_api_key', 'Missing API key');
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($service_ids), '%d'));
        $services = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, description FROM {$wpdb->prefix}samyar_services WHERE id IN ($placeholders)",
            $service_ids
        ));
        if (!$services) {
            return new WP_Error('missing_services', 'Services not found');
        }

        $items = array_map(function ($service) {
            return [
                'id' => (int) $service->id,
                'title' => aghasocial_ai_pages_sanitize_ai_input($service->name, 200),
                'description' => aghasocial_ai_pages_sanitize_ai_input($service->description, 500),
            ];
        }, $services);

        $ai = new Aghasocial_AI_Pages_AI();
        $system = 'You are a Persian marketing copywriter. Rewrite each service title/description to be concise and SEO-friendly. Return JSON only.';
        $prompt = "Services JSON:\n" . wp_json_encode($items, JSON_UNESCAPED_UNICODE) . "\nReturn JSON with key services: [{id,title,description}].";
        aghasocial_ai_pages_log('rewrite_service_batch_prompt', [
            'model' => $settings['rewrite_model'],
            'system' => $system,
            'prompt' => $prompt,
        ], [], true);
        $schema = [
            'name' => 'service_rewrite_batch',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'services' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'title' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                            ],
                            'required' => ['id', 'title', 'description'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['services'],
                'additionalProperties' => false,
            ],
        ];

        $response = $ai->request_text($prompt, $system, $schema, $settings['rewrite_model']);
        if (is_wp_error($response)) {
            aghasocial_ai_pages_log('rewrite_service_batch_error', [
                'service_ids' => $service_ids,
            ], [
                'error' => $response->get_error_message(),
            ], true);
            return $response;
        }

        $content = $response['choices'][0]['message']['content'] ?? null;
        $decoded = $content ? json_decode($content, true) : null;
        if (!is_array($decoded) || empty($decoded['services'])) {
            aghasocial_ai_pages_log('rewrite_service_batch_invalid', [
                'content' => $content,
            ], [
                'error' => 'invalid_ai',
            ], true);
            return new WP_Error('invalid_ai', 'Invalid AI response');
        }

        $meta_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_META_TABLE;
        foreach ($decoded['services'] as $row) {
            if (empty($row['id']) || empty($row['title'])) {
                continue;
            }
            $wpdb->update($wpdb->prefix . 'samyar_services', [
                'name' => $row['title'],
                'description' => $row['description'],
                'update_at' => current_time('mysql'),
            ], ['id' => (int) $row['id']]);

            $normalized = aghasocial_ai_pages_normalize_title($row['title']);
            $data = [
                'ref_type' => 'service',
                'ref_id' => (int) $row['id'],
                'title' => $row['title'],
                'description' => $row['description'],
                'normalized_title' => $normalized,
                'ai_payload' => wp_json_encode($row, JSON_UNESCAPED_UNICODE),
                'updated_at' => current_time('mysql'),
            ];
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$meta_table} WHERE ref_type = 'service' AND ref_id = %d", (int) $row['id']));
            if ($exists) {
                $wpdb->update($meta_table, $data, ['id' => $exists]);
            } else {
                $wpdb->insert($meta_table, $data);
            }
        }

        return true;
    }
}
