<?php

if (!defined('ABSPATH')) {
    exit;
}

function aghasocial_ai_pages_get_settings() {
    $defaults = [
        'openrouter_api_key' => '',
        'text_model' => 'openrouter.ai/openai/gpt-5-nano',
        'rewrite_model' => 'openrouter.ai/openai/gpt-5-nano',
        'quantity_title_model' => 'openrouter.ai/openai/gpt-4o-mini',
        'template_builder_model' => 'openrouter.ai/openai/gpt-4o-mini',
        'template_page_id' => '',
        'image_model' => 'openrouter.ai/google/gemini-2.5-flash-image-preview',
        'enable_logging' => 0,
        'dry_run' => 0,
        'enable_sync' => 1,
        'enable_rewrite' => 1,
        'enable_generate' => 1,
        'batch_size' => 10,
        'sleep_seconds' => 2,
        'quantity_list' => '50,100,200,300,400,500,1000,2000,3000,4000,5000,6000,7000,8000,9000,10000,20000,30000,40000,50000,60000,70000,80000,90000,100000,200000,500000,1000000',
        'countries' => "ایران|ایرانی\nآلمان|آلمانی\nبرزیل|برزیلی",
        'category_include' => '',
        'category_exclude' => '',
        'service_quantity_exclude' => '',
        'elementor_template' => '',
        'pack_template' => '',
        'ai_similarity' => 1,
    ];

    $settings = get_option(AGHASOCIAL_AI_PAGES_OPTION, []);

    return wp_parse_args($settings, $defaults);
}

function aghasocial_ai_pages_normalize_title($title) {
    $title = mb_strtolower($title);
    $title = preg_replace('/سرور\s*\d+/u', '', $title);
    $title = preg_replace('/\s+/u', ' ', $title);
    return trim($title);
}

function aghasocial_ai_pages_log($context, $request, $response) {
    $settings = aghasocial_ai_pages_get_settings();
    if (empty($settings['enable_logging'])) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_LOG_TABLE;

    $wpdb->insert($table, [
        'context' => $context,
        'request' => wp_json_encode($request, JSON_UNESCAPED_UNICODE),
        'response' => wp_json_encode($response, JSON_UNESCAPED_UNICODE),
        'created_at' => current_time('mysql'),
    ]);
}

function aghasocial_ai_pages_create_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset = $wpdb->get_charset_collate();
    $queue_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_QUEUE_TABLE;
    $log_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_LOG_TABLE;
    $pages_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_PAGES_TABLE;
    $meta_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_META_TABLE;

    $queue_sql = "CREATE TABLE {$queue_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        type VARCHAR(50) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        payload LONGTEXT NULL,
        last_error TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY type (type),
        KEY status (status)
    ) {$charset};";

    $log_sql = "CREATE TABLE {$log_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        context VARCHAR(50) NOT NULL,
        request LONGTEXT NULL,
        response LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id)
    ) {$charset};";

    $pages_sql = "CREATE TABLE {$pages_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        page_id BIGINT UNSIGNED NULL,
        type VARCHAR(50) NOT NULL,
        ref_id BIGINT UNSIGNED NULL,
        group_key VARCHAR(191) NULL,
        quantity INT NULL,
        country VARCHAR(50) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY unique_page (type, ref_id, quantity, country),
        KEY group_key (group_key)
    ) {$charset};";

    $meta_sql = "CREATE TABLE {$meta_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ref_type VARCHAR(20) NOT NULL,
        ref_id BIGINT UNSIGNED NOT NULL,
        title TEXT NULL,
        description LONGTEXT NULL,
        normalized_title TEXT NULL,
        ai_payload LONGTEXT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY ref_unique (ref_type, ref_id)
    ) {$charset};";

    dbDelta($queue_sql);
    dbDelta($log_sql);
    dbDelta($pages_sql);
    dbDelta($meta_sql);
}

function aghasocial_ai_pages_parse_quantities($quantity_list) {
    $items = array_filter(array_map('trim', explode(',', $quantity_list)));
    $quantities = [];
    foreach ($items as $item) {
        if (is_numeric($item)) {
            $quantities[] = (int) $item;
        }
    }
    return array_values(array_unique($quantities));
}

function aghasocial_ai_pages_parse_countries($countries) {
    $lines = preg_split('/\r\n|\r|\n/', $countries);
    $result = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = array_map('trim', explode('|', $line));
        $result[] = [
            'name' => $parts[0],
            'adjective' => $parts[1] ?? $parts[0],
        ];
    }
    return $result;
}

function aghasocial_ai_pages_parse_id_list($value) {
    $items = preg_split('/[\s,]+/', (string) $value);
    $ids = [];
    foreach ($items as $item) {
        $item = trim($item);
        if ($item === '') {
            continue;
        }
        if (is_numeric($item)) {
            $ids[] = (int) $item;
        }
    }
    return array_values(array_unique($ids));
}

function aghasocial_ai_pages_find_wp_load($start_dir) {
    $dir = $start_dir;
    $attempts = 0;
    while ($dir && $attempts < 8) {
        $path = $dir . '/wp-load.php';
        if (file_exists($path)) {
            return $path;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
        $attempts++;
    }
    return null;
}
