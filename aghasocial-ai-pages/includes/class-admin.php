<?php

if (!defined('ABSPATH')) {
    exit;
}

class Aghasocial_AI_Pages_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function register_menu() {
        add_menu_page(
            'Aghasocial AI Pages',
            'Aghasocial AI Pages',
            'manage_options',
            'aghasocial-ai-pages',
            [$this, 'render_settings_page'],
            'dashicons-admin-site-alt3'
        );
    }

    public function register_settings() {
        register_setting('aghasocial_ai_pages', AGHASOCIAL_AI_PAGES_OPTION);
    }

    public function render_settings_page() {
        $settings = aghasocial_ai_pages_get_settings();
        $token = get_option(AGHASOCIAL_AI_PAGES_CRON_TOKEN);
        $sync_url = add_query_arg('token', $token, plugins_url('cron/sync.php', AGHASOCIAL_AI_PAGES_FILE));
        $rewrite_url = add_query_arg('token', $token, plugins_url('cron/rewrite.php', AGHASOCIAL_AI_PAGES_FILE));
        $generate_url = add_query_arg('token', $token, plugins_url('cron/generate.php', AGHASOCIAL_AI_PAGES_FILE));
        global $wpdb;
        $queue_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_QUEUE_TABLE;
        $queue_counts = $wpdb->get_results("SELECT type, status, COUNT(*) as count FROM {$queue_table} GROUP BY type, status", ARRAY_A);

        ?>
        <div class="wrap">
            <h1>Aghasocial AI Pages</h1>
            <form method="post" action="options.php">
                <?php settings_fields('aghasocial_ai_pages'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">OpenRouter API Key</th>
                        <td><input type="text" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[openrouter_api_key]" value="<?php echo esc_attr($settings['openrouter_api_key']); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Text Model</th>
                        <td><input type="text" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[text_model]" value="<?php echo esc_attr($settings['text_model']); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Image Model</th>
                        <td><input type="text" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[image_model]" value="<?php echo esc_attr($settings['image_model']); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Enable Logging</th>
                        <td><input type="checkbox" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[enable_logging]" value="1" <?php checked($settings['enable_logging'], 1); ?> /></td>
                    </tr>
                    <tr>
                        <th scope="row">Dry Run</th>
                        <td><input type="checkbox" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[dry_run]" value="1" <?php checked($settings['dry_run'], 1); ?> /></td>
                    </tr>
                    <tr>
                        <th scope="row">Enable Sync</th>
                        <td><input type="checkbox" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[enable_sync]" value="1" <?php checked($settings['enable_sync'], 1); ?> /></td>
                    </tr>
                    <tr>
                        <th scope="row">Enable Rewrite</th>
                        <td><input type="checkbox" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[enable_rewrite]" value="1" <?php checked($settings['enable_rewrite'], 1); ?> /></td>
                    </tr>
                    <tr>
                        <th scope="row">Enable Generate</th>
                        <td><input type="checkbox" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[enable_generate]" value="1" <?php checked($settings['enable_generate'], 1); ?> /></td>
                    </tr>
                    <tr>
                        <th scope="row">Batch Size</th>
                        <td><input type="number" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[batch_size]" value="<?php echo esc_attr($settings['batch_size']); ?>" class="small-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Sleep Seconds</th>
                        <td><input type="number" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[sleep_seconds]" value="<?php echo esc_attr($settings['sleep_seconds']); ?>" class="small-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Quantity List</th>
                        <td><textarea name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[quantity_list]" rows="3" class="large-text"><?php echo esc_textarea($settings['quantity_list']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row">Quantity Alias</th>
                        <td><input type="text" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[quantity_alias]" value="<?php echo esc_attr($settings['quantity_alias']); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Countries</th>
                        <td><textarea name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[countries]" rows="5" class="large-text"><?php echo esc_textarea($settings['countries']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row">Elementor Template JSON</th>
                        <td><textarea name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[elementor_template]" rows="6" class="large-text code"><?php echo esc_textarea($settings['elementor_template']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row">Kando Pack Template JSON</th>
                        <td><textarea name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[pack_template]" rows="6" class="large-text code"><?php echo esc_textarea($settings['pack_template']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row">AI Similarity Grouping</th>
                        <td><input type="checkbox" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[ai_similarity]" value="1" <?php checked($settings['ai_similarity'], 1); ?> /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <h2>Cron URLs (cPanel)</h2>
            <p>Sync: <code><?php echo esc_html($sync_url); ?></code></p>
            <p>Rewrite: <code><?php echo esc_html($rewrite_url); ?></code></p>
            <p>Generate: <code><?php echo esc_html($generate_url); ?></code></p>
            <h2>Queue Status</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Count</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($queue_counts) : ?>
                        <?php foreach ($queue_counts as $row) : ?>
                            <tr>
                                <td><?php echo esc_html($row['type']); ?></td>
                                <td><?php echo esc_html($row['status']); ?></td>
                                <td><?php echo esc_html($row['count']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="3">No queue items.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
