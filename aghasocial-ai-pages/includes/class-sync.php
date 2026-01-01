<?php

if (!defined('ABSPATH')) {
    exit;
}

class Aghasocial_AI_Pages_Sync {
    public function sync_services() {
        $settings = aghasocial_ai_pages_get_settings();
        if (empty($settings['enable_sync'])) {
            return 'sync_disabled';
        }

        global $wpdb;
        $providers = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}samyar_api_provider WHERE status = 1 AND autosync = 1");

        if (!$providers) {
            return 'no_providers';
        }

        foreach ($providers as $provider) {
            $response = $this->fetch_provider_services($provider);
            if (is_wp_error($response) || empty($response['data'])) {
                continue;
            }

            $this->upsert_services($provider, $response['data']);
        }

        return 'ok';
    }

    private function fetch_provider_services($provider) {
        $url = rtrim($provider->url, '/') . '?action=services&key=' . urlencode($provider->api_key);
        $response = wp_remote_get($url, ['timeout' => 60]);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        aghasocial_ai_pages_log('provider_services', ['url' => $url], $decoded);

        if (is_wp_error($response)) {
            return $response;
        }

        return $decoded;
    }

    private function upsert_services($provider, $services) {
        global $wpdb;
        $settings = aghasocial_ai_pages_get_settings();
        $services_table = $wpdb->prefix . 'samyar_services';
        $categories_table = $wpdb->prefix . 'samyar_categories';

        foreach ($services as $service) {
            $category_name = $service['category'] ?? $service['category_name'] ?? '';
            $category_id = null;
            $category_description = null;

            if ($category_name) {
                $category_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$categories_table} WHERE name = %s LIMIT 1", $category_name));
                if (!$category_id) {
                    $wpdb->insert($categories_table, [
                        'uid' => null,
                        'name' => $category_name,
                        'description' => $category_description,
                        'image' => null,
                        'icon' => null,
                        'sort' => null,
                        'social_id' => 0,
                        'status' => 1,
                        'link_type' => 'default',
                        'created_at' => current_time('mysql'),
                        'update_at' => current_time('mysql'),
                    ]);
                    $category_id = $wpdb->insert_id;
                }
            }

            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$services_table} WHERE api_service_id = %s AND api_provider_id = %d LIMIT 1", $service['service'] ?? $service['id'], $provider->id));

            $service_name = $service['name'] ?? '';
            $service_description = $service['description'] ?? '';

            $data = [
                'uid' => $provider->uid,
                'cate_id' => $category_id,
                'name' => $service_name,
                'description' => $service_description,
                'min' => $service['min'] ?? null,
                'max' => $service['max'] ?? null,
                'add_type' => 'api',
                'type' => 'default',
                'api_service_id' => $service['service'] ?? $service['id'],
                'api_provider_id' => $provider->id,
                'status' => 1,
                'link_type' => 'default',
                'created_at' => current_time('mysql'),
                'update_at' => current_time('mysql'),
            ];

            if ($existing) {
                $wpdb->update($services_table, $data, ['id' => $existing->id]);
            } else {
                $wpdb->insert($services_table, $data);
            }
        }
    }

    private function rewrite_item($type, $title, $description, $prompt_template = '') {
        if ($title === '') {
            return null;
        }

        $ai = new Aghasocial_AI_Pages_AI();
        $system = 'You are a Persian marketing copywriter. Rewrite titles and descriptions so they look native to Aghasocial brand. Never mention provider, API, or external sources.';
        $prompt_template = $prompt_template ?: "Type: {$type}\nTitle: {title}\nDescription: {description}\nRewrite in Persian with unique SEO-friendly tone. Return JSON with keys: title, description.";
        $prompt = str_replace(['{title}', '{description}'], [$title, $description], $prompt_template);
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
            return null;
        }

        $content = $response['choices'][0]['message']['content'] ?? null;
        $decoded = json_decode($content, true);
        if (!$decoded || empty($decoded['title'])) {
            return null;
        }

        return [
            'title' => $decoded['title'],
            'description' => $decoded['description'] ?? '',
        ];
    }
}
