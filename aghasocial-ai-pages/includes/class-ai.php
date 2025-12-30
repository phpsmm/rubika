<?php

if (!defined('ABSPATH')) {
    exit;
}

class Aghasocial_AI_Pages_AI {
    public function request_text($prompt, $system, $json_schema = null, $model = null) {
        $settings = aghasocial_ai_pages_get_settings();
        $body = [
            'model' => $model ?: $settings['text_model'],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        if ($json_schema) {
            $body['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => $json_schema,
            ];
        }

        $response = $this->send_request('https://openrouter.ai/api/v1/chat/completions', $body);
        return $response;
    }

    public function request_image($prompt) {
        $settings = aghasocial_ai_pages_get_settings();
        $body = [
            'model' => $settings['image_model'],
            'prompt' => $prompt,
        ];

        return $this->send_request('https://openrouter.ai/api/v1/images/generations', $body);
    }

    private function send_request($url, $body) {
        $settings = aghasocial_ai_pages_get_settings();
        $headers = [
            'Authorization' => 'Bearer ' . $settings['openrouter_api_key'],
            'Content-Type' => 'application/json',
        ];

        $request = [
            'headers' => $headers,
            'body' => wp_json_encode($body, JSON_UNESCAPED_UNICODE),
            'timeout' => 60,
        ];

        $response = wp_remote_post($url, $request);

        aghasocial_ai_pages_log('openrouter', $body, $response);

        if (is_wp_error($response)) {
            return $response;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data;
    }
}
