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

        $services = $wpdb->get_results("SELECT id, name, description FROM {$services_table} WHERE status = 1");
        foreach ($services as $service) {
            $meta = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$meta_table} WHERE ref_type = 'service' AND ref_id = %d", $service->id));
            if (!$meta) {
                $payload = [
                    'ref_type' => 'service',
                    'ref_id' => $service->id,
                ];
                $wpdb->insert($queue_table, [
                    'type' => 'rewrite',
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
        }
    }

    public function rewrite_one_item() {
        $settings = aghasocial_ai_pages_get_settings();
        if (empty($settings['enable_rewrite'])) {
            return 'rewrite_disabled';
        }

        global $wpdb;
        $queue_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_QUEUE_TABLE;
        $task = $wpdb->get_row("SELECT * FROM {$queue_table} WHERE type = 'rewrite' AND status = 'pending' ORDER BY id ASC LIMIT 1");
        if (!$task) {
            return 'no_tasks';
        }

        $payload = json_decode($task->payload, true);
        $result = $this->process_rewrite($payload['ref_type'], $payload['ref_id']);

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
        $system = 'You are a Persian marketing copywriter. Rewrite titles and descriptions so they look native to Aghasocial brand. Never mention provider, API, or external sources.';
        $prompt = "Title: {$item->name}\nDescription: {$item->description}\nRewrite in Persian with unique SEO-friendly tone. Return JSON with keys: title, description.";
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

        $response = $ai->request_text($prompt, $system, $schema);
        if (is_wp_error($response)) {
            return $response;
        }

        $content = $response['choices'][0]['message']['content'] ?? null;
        $decoded = json_decode($content, true);
        if (!$decoded) {
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
}
