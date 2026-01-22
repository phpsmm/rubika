<?php
/**
 * Plugin Name: Aghasocial AI Pages
 * Description: Syncs services from API providers, rewrites content with AI, and generates Elementor landing pages.
 * Version: 1.0.0
 * Author: Aghasocial
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AGHASOCIAL_AI_PAGES_VERSION', '1.0.2');
define('AGHASOCIAL_AI_PAGES_FILE', __FILE__);
define('AGHASOCIAL_AI_PAGES_DIR', plugin_dir_path(__FILE__));
define('AGHASOCIAL_AI_PAGES_URL', plugin_dir_url(__FILE__));

define('AGHASOCIAL_AI_PAGES_OPTION', 'aghasocial_ai_pages_settings');

define('AGHASOCIAL_AI_PAGES_LOG_TABLE', 'aghasocial_ai_logs');
define('AGHASOCIAL_AI_PAGES_QUEUE_TABLE', 'aghasocial_ai_queue');
define('AGHASOCIAL_AI_PAGES_PAGES_TABLE', 'aghasocial_ai_pages');
define('AGHASOCIAL_AI_PAGES_META_TABLE', 'aghasocial_ai_service_meta');
define('AGHASOCIAL_AI_PAGES_OVERRIDE_TABLE', 'aghasocial_ai_overrides');

define('AGHASOCIAL_AI_PAGES_CRON_TOKEN', 'aghasocial_ai_pages_cron_token');

require_once AGHASOCIAL_AI_PAGES_DIR . 'includes/helpers.php';
require_once AGHASOCIAL_AI_PAGES_DIR . 'includes/class-ai.php';
require_once AGHASOCIAL_AI_PAGES_DIR . 'includes/class-sync.php';
require_once AGHASOCIAL_AI_PAGES_DIR . 'includes/class-rewrite.php';
require_once AGHASOCIAL_AI_PAGES_DIR . 'includes/class-pages.php';
require_once AGHASOCIAL_AI_PAGES_DIR . 'includes/class-admin.php';

register_activation_hook(__FILE__, 'aghasocial_ai_pages_activate');

function aghasocial_ai_pages_activate() {
    aghasocial_ai_pages_create_tables();
    if (!get_option(AGHASOCIAL_AI_PAGES_CRON_TOKEN)) {
        update_option(AGHASOCIAL_AI_PAGES_CRON_TOKEN, wp_generate_password(32, false, false));
    }
    update_option('aghasocial_ai_pages_version', AGHASOCIAL_AI_PAGES_VERSION);
}

add_action('plugins_loaded', function () {
    $installed_version = get_option('aghasocial_ai_pages_version');
    if ($installed_version !== AGHASOCIAL_AI_PAGES_VERSION) {
        aghasocial_ai_pages_create_tables();
        update_option('aghasocial_ai_pages_version', AGHASOCIAL_AI_PAGES_VERSION);
    }
    new Aghasocial_AI_Pages_Admin();
});

add_shortcode('aap_breadcrumb', function ($atts) {
    $atts = shortcode_atts([
        'cat_id' => 0,
        'title' => '',
    ], $atts);

    $title = $atts['title'] ?: get_the_title();
    $result = aghasocial_ai_pages_build_breadcrumbs((int) $atts['cat_id'], $title);
    return $result['html'] . $result['schema'];
});
