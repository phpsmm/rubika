<?php

$root = dirname(__DIR__, 2);
$wp_load = null;
$dir = $root;
$attempts = 0;
while ($dir && $attempts < 8) {
    $candidate = $dir . '/wp-load.php';
    if (file_exists($candidate)) {
        $wp_load = $candidate;
        break;
    }
    $parent = dirname($dir);
    if ($parent === $dir) {
        break;
    }
    $dir = $parent;
    $attempts++;
}
if (!$wp_load) {
    exit('wp-load.php not found');
}
require_once $wp_load;

$plugin_file = $root . '/aghasocial-ai-pages/aghasocial-ai-pages.php';
if (file_exists($plugin_file)) {
    require_once $plugin_file;
}

$token = $_GET['token'] ?? '';
if ($token !== get_option(AGHASOCIAL_AI_PAGES_CRON_TOKEN)) {
    exit('invalid token');
}

$settings = aghasocial_ai_pages_get_settings();
if (!empty($settings['dry_run'])) {
    exit('dry_run');
}

$sync = new Aghasocial_AI_Pages_Sync();
$result = $sync->sync_services();

$rewrite = new Aghasocial_AI_Pages_Rewrite();
$rewrite->enqueue_rewrite_tasks();

$pages = new Aghasocial_AI_Pages_Pages();
$pages->enqueue_missing_pages();

exit($result);
