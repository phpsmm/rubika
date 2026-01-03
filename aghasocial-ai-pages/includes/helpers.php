<?php

if (!defined('ABSPATH')) {
    exit;
}

function aghasocial_ai_pages_get_settings() {
    $defaults = [
        'openrouter_api_key' => '',
        'text_model' => 'openrouter.ai/openai/gpt-5-nano',
        'rewrite_model' => 'openrouter.ai/openai/gpt-5-nano',
        'enable_group_rewrite' => 1,
        'quantity_title_model' => 'openrouter.ai/openai/gpt-4o-mini',
        'template_builder_model' => 'openrouter.ai/openai/gpt-4o-mini',
        'template_page_id' => '',
        'template_last_status' => '',
        'template_last_error' => '',
        'template_last_response' => '',
        'template_last_request' => '',
        'template_last_used_model' => '',
        'template_last_built_at' => '',
        'template_last_used_fallback' => 0,
        'template_use_json_schema' => 0,
        'enable_ai_images' => 0,
        'image_prompt_template' => 'تصویر حرفه‌ای و مینیمال برای {title} با رنگ‌بندی برند آقاسوشال',
        'last_generated_page_id' => '',
        'last_generated_title' => '',
        'last_generated_at' => '',
        'ai_timeout' => 120,
        'rewrite_category_prompt' => "Title: {title}\nThis is a category title. Rewrite in Persian, short and relevant, avoid long phrases. Return JSON with keys: title, description.",
        'rewrite_service_prompt' => "Title: {title}\nDescription: {description}\nThis is a service title and description. Rewrite in Persian, concise and SEO-friendly. Return JSON with keys: title, description.",
        'placeholders_last_status' => '',
        'placeholders_last_error' => '',
        'placeholders_last_request' => '',
        'placeholders_last_response' => '',
        'placeholders_last_built_at' => '',
        'content_prompt_template' => "Service: {service}\nTitle: {title}\nWrite SEO-friendly Persian HTML body content with multiple H2 sections, bullet lists, and a professional tone. Return JSON with keys: description, content, cta_title, cta_text, cta_button, faq (5 items: question/answer), testimonials (3 items: name/text).",
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
        'enable_country_quantity' => 0,
        'country_quantity_include' => '',
        'strip_country_terms' => 1,
        'title_noise_terms' => "بین المللی\nتخفیف ویژه\nحداقل سفارش\nسرعت پایین\nکاملا خارجی\nفیک\nواقعی\nظاهر واقعی",
        'enable_quantity_ai_titles' => 0,
        'elementor_template' => '',
        'pack_template' => '',
        'page_template' => 'elementor_header_footer',
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
    $max_bytes = 10000;
    $request_json = wp_json_encode($request, JSON_UNESCAPED_UNICODE);
    $response_json = wp_json_encode($response, JSON_UNESCAPED_UNICODE);

    $wpdb->insert($table, [
        'context' => $context,
        'request' => aghasocial_ai_pages_truncate_payload($request_json, $max_bytes),
        'response' => aghasocial_ai_pages_truncate_payload($response_json, $max_bytes),
        'created_at' => current_time('mysql'),
    ]);
}

function aghasocial_ai_pages_truncate_payload($payload, $max_bytes) {
    if ($payload === null) {
        return null;
    }
    $payload = (string) $payload;
    $length = strlen($payload);
    if ($length <= $max_bytes) {
        return $payload;
    }

    $suffix = '... (truncated, original bytes: ' . $length . ')';
    $keep = $max_bytes - strlen($suffix);
    if ($keep < 0) {
        return substr($payload, 0, $max_bytes);
    }

    return substr($payload, 0, $keep) . $suffix;
}

function aghasocial_ai_pages_create_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset = $wpdb->get_charset_collate();
    $queue_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_QUEUE_TABLE;
    $log_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_LOG_TABLE;
    $pages_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_PAGES_TABLE;
    $meta_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_META_TABLE;
    $override_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_OVERRIDE_TABLE;

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

    $override_sql = "CREATE TABLE {$override_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ref_type VARCHAR(20) NOT NULL,
        ref_id BIGINT UNSIGNED NOT NULL,
        topic VARCHAR(255) NULL,
        generate_mode VARCHAR(20) NOT NULL DEFAULT 'category_only',
        single_service_page TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY ref_unique (ref_type, ref_id),
        KEY ref_type (ref_type)
    ) {$charset};";

    dbDelta($queue_sql);
    dbDelta($log_sql);
    dbDelta($pages_sql);
    dbDelta($meta_sql);
    dbDelta($override_sql);
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
        $parts = array_values(array_filter(array_map('trim', explode('|', $line))));
        if (!$parts) {
            continue;
        }
        $aliases = $parts;
        $result[] = [
            'name' => $parts[0],
            'adjective' => $parts[1] ?? $parts[0],
            'aliases' => $aliases,
        ];
    }
    return $result;
}

function aghasocial_ai_pages_contains_country_terms($text, $countries) {
    if ($text === '') {
        return false;
    }
    foreach ($countries as $country) {
        foreach ($country['aliases'] ?? [] as $alias) {
            if ($alias !== '' && mb_strpos($text, $alias) !== false) {
                return true;
            }
        }
    }
    return false;
}

function aghasocial_ai_pages_detect_country($text, $countries) {
    if ($text === '') {
        return null;
    }
    foreach ($countries as $country) {
        foreach ($country['aliases'] ?? [] as $alias) {
            if ($alias !== '' && mb_strpos($text, $alias) !== false) {
                return $country;
            }
        }
    }
    return null;
}

function aghasocial_ai_pages_cleanup_title($title) {
    $title = preg_replace('/\\([^\\)]*\\)/u', '', $title);
    $title = preg_replace('/\\s+/u', ' ', $title);
    return trim($title);
}

function aghasocial_ai_pages_remove_noise_terms($text, $terms_string) {
    $terms = preg_split('/\\r\\n|\\r|\\n|,/', (string) $terms_string);
    $terms = array_filter(array_map('trim', $terms));
    if (!$terms) {
        return $text;
    }
    foreach ($terms as $term) {
        if ($term === '') {
            continue;
        }
        $text = preg_replace('/\\b' . preg_quote($term, '/') . '\\b/u', '', $text);
    }
    $text = preg_replace('/\\s+/u', ' ', $text);
    return trim($text);
}

function aghasocial_ai_pages_sanitize_ai_input($text, $max_length = 800) {
    $text = html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8');
    $text = wp_strip_all_tags($text);
    $text = preg_replace('/[\\x{1F000}-\\x{1FFFF}]/u', '', $text);
    $text = preg_replace('/\\s+/u', ' ', $text);
    $text = trim($text);
    if ($max_length > 0 && mb_strlen($text) > $max_length) {
        $text = mb_substr($text, 0, $max_length);
    }
    return $text;
}

function aghasocial_ai_pages_get_override_map($ref_type) {
    global $wpdb;
    $override_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_OVERRIDE_TABLE;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT ref_id, topic, generate_mode, single_service_page FROM {$override_table} WHERE ref_type = %s",
        $ref_type
    ), ARRAY_A);
    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row['ref_id']] = [
            'topic' => $row['topic'],
            'generate_mode' => $row['generate_mode'],
            'single_service_page' => (int) $row['single_service_page'],
        ];
    }
    return $map;
}

function aghasocial_ai_pages_upsert_override($ref_type, $ref_id, $topic, $generate_mode, $single_service_page = 0) {
    global $wpdb;
    $override_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_OVERRIDE_TABLE;
    $data = [
        'ref_type' => $ref_type,
        'ref_id' => (int) $ref_id,
        'topic' => $topic !== '' ? $topic : null,
        'generate_mode' => $generate_mode ?: 'both',
        'single_service_page' => $single_service_page ? 1 : 0,
        'updated_at' => current_time('mysql'),
    ];
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$override_table} WHERE ref_type = %s AND ref_id = %d",
        $ref_type,
        $ref_id
    ));
    if ($existing) {
        $wpdb->update($override_table, $data, ['id' => $existing]);
    } else {
        $wpdb->insert($override_table, $data);
    }
}

function aghasocial_ai_pages_clean_topic_source($text) {
    $text = aghasocial_ai_pages_cleanup_title($text);
    $text = preg_replace('/[\\/|_]+/u', ' ', $text);
    $text = preg_replace('/[^\\p{L}\\p{N}\\s]+/u', ' ', $text);
    $text = preg_replace('/\\d+/u', ' ', $text);
    $text = preg_replace('/\\s+/u', ' ', $text);
    return trim($text);
}

function aghasocial_ai_pages_generate_slug($title, $fallback = '') {
    $text = html_entity_decode((string) $title, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/[\\x{1F000}-\\x{1FFFF}]/u', '', $text);
    $text = preg_replace('/[^A-Za-z0-9\\s-]+/', '', $text);
    $text = preg_replace('/\\s+/', '-', trim($text));
    $text = strtolower($text);
    if ($text === '') {
        $translated = aghasocial_ai_pages_translate_slug($title);
        if ($translated) {
            return $translated;
        }
    }
    if ($text === '') {
        $fallback = preg_replace('/[^A-Za-z0-9\\s-]+/', '', (string) $fallback);
        $fallback = preg_replace('/\\s+/', '-', trim($fallback));
        $fallback = strtolower($fallback);
        if ($fallback !== '') {
            return $fallback;
        }
        return 'page-' . wp_generate_uuid4();
    }
    return $text;
}

function aghasocial_ai_pages_translate_slug($title) {
    $settings = aghasocial_ai_pages_get_settings();
    if (empty($settings['openrouter_api_key'])) {
        return '';
    }

    $cache = get_option('aghasocial_ai_pages_slug_cache', []);
    if (!is_array($cache)) {
        $cache = [];
    }
    $key = md5((string) $title);
    if (!empty($cache[$key])) {
        return $cache[$key];
    }

    $ai = new Aghasocial_AI_Pages_AI();
    $system = 'You generate short English slugs. Output only lowercase words separated by hyphens.';
    $prompt = "Title: {$title}\nReturn a short English slug (3-6 words), lowercase, hyphenated. No extra text.";
    $schema = [
        'name' => 'slug_response',
        'schema' => [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string'],
            ],
            'required' => ['slug'],
            'additionalProperties' => false,
        ],
    ];
    $response = $ai->request_text($prompt, $system, $schema, $settings['text_model']);
    if (is_wp_error($response)) {
        return '';
    }
    $content = $response['choices'][0]['message']['content'] ?? '';
    $decoded = json_decode($content, true);
    $slug = $decoded['slug'] ?? '';
    $slug = preg_replace('/[^A-Za-z0-9\\s-]+/', '', (string) $slug);
    $slug = preg_replace('/\\s+/', '-', trim($slug));
    $slug = strtolower($slug);
    if ($slug === '') {
        return '';
    }
    $cache[$key] = $slug;
    update_option('aghasocial_ai_pages_slug_cache', $cache);
    return $slug;
}

function aghasocial_ai_pages_extract_topic($service_name, $category_name, $settings) {
    $sources = array_filter([$category_name, $service_name]);
    foreach ($sources as $source) {
        $clean = aghasocial_ai_pages_remove_noise_terms($source, $settings['title_noise_terms']);
        $clean = aghasocial_ai_pages_clean_topic_source($clean);
        $clean = preg_replace('/\\bخرید\\b/u', '', $clean);
        $clean = preg_replace('/\\s+/u', ' ', $clean);
        $clean = trim($clean);
        if ($clean === '') {
            continue;
        }

        $subject = aghasocial_ai_pages_find_subject($clean);
        $platform = aghasocial_ai_pages_find_platform($clean);
        if ($subject) {
            return trim($subject . ($platform ? ' ' . $platform : ''));
        }

        return $clean;
    }

    return '';
}

function aghasocial_ai_pages_find_subject($text) {
    $subjects = [
        'لایک',
        'فالوور',
        'بازدید',
        'ویو',
        'کامنت',
        'اشتراک',
        'سابسکرایب',
        'رفرال',
        'ریفرال',
        'ممبر',
        'جوین',
        'ایمپرشن',
        'کلیک',
    ];
    foreach ($subjects as $subject) {
        if (mb_strpos($text, $subject) !== false) {
            return $subject;
        }
    }
    return '';
}

function aghasocial_ai_pages_find_platform($text) {
    $platforms = [
        'اینستاگرام',
        'تلگرام',
        'یوتیوب',
        'توییتر',
        'ایکس',
        'واتساپ',
        'تیک تاک',
        'کلاب هاوس',
        'لینکدین',
        'فیس بوک',
        'فیسبوک',
        'روبیکا',
    ];
    foreach ($platforms as $platform) {
        if (mb_strpos($text, $platform) !== false) {
            return $platform;
        }
    }
    return '';
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

function aghasocial_ai_pages_strip_country_terms($text, $countries) {
    if ($text === '') {
        return $text;
    }
    $terms = [];
    foreach ($countries as $country) {
        foreach ($country['aliases'] ?? [] as $alias) {
            if ($alias !== '') {
                $terms[] = preg_quote($alias, '/');
            }
        }
    }
    if (!$terms) {
        return $text;
    }
    $pattern = '/\\b(' . implode('|', $terms) . ')\\b/u';
    $text = preg_replace($pattern, '', $text);
    $text = preg_replace('/\\s+/u', ' ', $text);
    return trim($text);
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
