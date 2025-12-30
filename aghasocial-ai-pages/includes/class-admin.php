<?php

if (!defined('ABSPATH')) {
    exit;
}

class Aghasocial_AI_Pages_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_aghasocial_ai_pages_sync', [$this, 'handle_manual_sync']);
        add_action('admin_post_aghasocial_ai_pages_rewrite', [$this, 'handle_manual_rewrite']);
        add_action('admin_post_aghasocial_ai_pages_generate', [$this, 'handle_manual_generate']);
        add_action('admin_post_aghasocial_ai_pages_template', [$this, 'handle_template_update']);
        add_action('admin_post_aghasocial_ai_pages_template_build', [$this, 'handle_template_build']);
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
        $pending_generate = $wpdb->get_results("SELECT id, payload, created_at FROM {$queue_table} WHERE type = 'generate' AND status = 'pending' ORDER BY id ASC LIMIT 50", ARRAY_A);

        if (!empty($_GET['aap_notice'])) {
            $notice = sanitize_text_field(wp_unslash($_GET['aap_notice']));
            echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';
        }

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
                        <th scope="row">Rewrite Model</th>
                        <td><input type="text" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[rewrite_model]" value="<?php echo esc_attr($settings['rewrite_model']); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Quantity Title Model</th>
                        <td><input type="text" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[quantity_title_model]" value="<?php echo esc_attr($settings['quantity_title_model']); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Template Builder Model</th>
                        <td><input type="text" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[template_builder_model]" value="<?php echo esc_attr($settings['template_builder_model']); ?>" class="regular-text" /></td>
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
                        <th scope="row">Category Include (IDs)</th>
                        <td><input type="text" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[category_include]" value="<?php echo esc_attr($settings['category_include']); ?>" class="regular-text" />
                            <p class="description">Comma or space separated category IDs. Empty = all.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Category Exclude (IDs)</th>
                        <td><input type="text" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[category_exclude]" value="<?php echo esc_attr($settings['category_exclude']); ?>" class="regular-text" />
                            <p class="description">Categories to skip. Applied after include list.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Service Quantity Exclude (IDs)</th>
                        <td><input type="text" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[service_quantity_exclude]" value="<?php echo esc_attr($settings['service_quantity_exclude']); ?>" class="regular-text" />
                            <p class="description">Services that should NOT get quantity pages.</p></td>
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

            <h2>Manual Actions</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('aghasocial_ai_pages_manual'); ?>
                <input type="hidden" name="action" value="aghasocial_ai_pages_sync" />
                <?php submit_button('Sync Services + Queue', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('aghasocial_ai_pages_manual'); ?>
                <input type="hidden" name="action" value="aghasocial_ai_pages_rewrite" />
                <?php submit_button('Rewrite 1 Item', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('aghasocial_ai_pages_manual'); ?>
                <input type="hidden" name="action" value="aghasocial_ai_pages_generate" />
                <?php submit_button('Generate 1 Page', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('aghasocial_ai_pages_manual'); ?>
                <input type="hidden" name="action" value="aghasocial_ai_pages_template_build" />
                <?php submit_button('Build Template Page (AI)', 'secondary', 'submit', false); ?>
            </form>

            <h3>Update Template from Existing Page</h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('aghasocial_ai_pages_manual'); ?>
                <input type="hidden" name="action" value="aghasocial_ai_pages_template" />
                <p>
                    <label for="aap_template_page_id">Page ID</label>
                    <input type="number" name="page_id" id="aap_template_page_id" class="small-text" required />
                    <select name="template_type">
                        <option value="elementor_template">Elementor Page Template</option>
                        <option value="pack_template">Kando Pack Template</option>
                    </select>
                    <?php submit_button('Save Template', 'secondary', 'submit', false); ?>
                </p>
            </form>

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

            <h2>Pending Generate Preview (Top 50)</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Queue ID</th>
                        <th>Type</th>
                        <th>Planned Title</th>
                        <th>Ref ID</th>
                        <th>Quantity</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($pending_generate) : ?>
                        <?php foreach ($pending_generate as $row) : ?>
                            <?php $preview = $this->build_queue_preview($row['payload']); ?>
                            <tr>
                                <td><?php echo esc_html($row['id']); ?></td>
                                <td><?php echo esc_html($preview['type']); ?></td>
                                <td><?php echo esc_html($preview['title']); ?></td>
                                <td><?php echo esc_html($preview['ref_id']); ?></td>
                                <td><?php echo esc_html($preview['quantity']); ?></td>
                                <td><?php echo esc_html($row['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="6">No pending generate items.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function build_queue_preview($payload_json) {
        $payload = json_decode($payload_json, true);
        $type = $payload['type'] ?? 'unknown';
        $ref_id = $payload['ref_id'] ?? '';
        $quantity = $payload['quantity'] ?? '';
        $title = $payload['planned_title'] ?? 'Unknown';

        global $wpdb;
        if ($type === 'category') {
            $name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}samyar_categories WHERE id = %d", $ref_id));
            if ($name) {
                $title = $name;
            }
        } elseif ($type === 'service' || $type === 'quantity') {
            $name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}samyar_services WHERE id = %d", $ref_id));
            if ($name) {
                if ($type === 'quantity' && $quantity) {
                    $title = $title !== 'Unknown' ? $title : sprintf('خرید %d %s', $quantity, $name);
                } else {
                    $title = $name;
                }
            }
        }

        return [
            'type' => $type,
            'ref_id' => $ref_id,
            'quantity' => $quantity,
            'title' => $title,
        ];
    }

    private function redirect_with_notice($message) {
        $url = add_query_arg('aap_notice', rawurlencode($message), admin_url('admin.php?page=aghasocial-ai-pages'));
        wp_safe_redirect($url);
        exit;
    }

    public function handle_manual_sync() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('aghasocial_ai_pages_manual');

        $sync = new Aghasocial_AI_Pages_Sync();
        $sync->sync_services();

        $rewrite = new Aghasocial_AI_Pages_Rewrite();
        $rewrite->enqueue_rewrite_tasks();

        $pages = new Aghasocial_AI_Pages_Pages();
        $pages->enqueue_missing_pages();

        $this->redirect_with_notice('Sync completed and queues updated.');
    }

    public function handle_manual_rewrite() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('aghasocial_ai_pages_manual');

        $rewrite = new Aghasocial_AI_Pages_Rewrite();
        $result = $rewrite->rewrite_one_item();

        $this->redirect_with_notice('Rewrite result: ' . $result);
    }

    public function handle_manual_generate() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('aghasocial_ai_pages_manual');

        $pages = new Aghasocial_AI_Pages_Pages();
        $result = $pages->generate_one_page();

        $this->redirect_with_notice('Generate result: ' . $result);
    }

    public function handle_template_update() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('aghasocial_ai_pages_manual');

        $page_id = isset($_POST['page_id']) ? (int) $_POST['page_id'] : 0;
        $template_type = isset($_POST['template_type']) ? sanitize_text_field(wp_unslash($_POST['template_type'])) : '';
        if (!$page_id || !in_array($template_type, ['elementor_template', 'pack_template'], true)) {
            $this->redirect_with_notice('Invalid template update request.');
        }

        $elementor_data = get_post_meta($page_id, '_elementor_data', true);
        if (!$elementor_data) {
            $this->redirect_with_notice('No Elementor data found for that page.');
        }

        $settings = aghasocial_ai_pages_get_settings();
        $settings[$template_type] = $elementor_data;
        update_option(AGHASOCIAL_AI_PAGES_OPTION, $settings);

        $this->redirect_with_notice('Template updated from page ID ' . $page_id . '.');
    }

    public function handle_template_build() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('aghasocial_ai_pages_manual');

        $settings = aghasocial_ai_pages_get_settings();
        $ai = new Aghasocial_AI_Pages_AI();

        $system = 'You are an Elementor page template generator for a Persian marketing website. Build a JSON array for Elementor _elementor_data. Use {title} placeholder where the page title should appear. Include both shortcodes as text widgets: [samyar_services cat={cat_id}] and [kando_service id={service_id}]. Keep layout clean and professional.';
        $prompt = 'Return a JSON array representing Elementor elements. Use text widgets for headings/paragraphs/FAQ placeholders. Include a hero section, benefits list, FAQ section, and CTA. Ensure JSON is valid.';
        $schema = [
            'name' => 'elementor_template',
            'schema' => [
                'type' => 'array',
                'items' => ['type' => 'object'],
            ],
        ];

        $response = $ai->request_text($prompt, $system, $schema, $settings['template_builder_model']);
        $elementor_data = null;
        if (!is_wp_error($response)) {
            $content = $response['choices'][0]['message']['content'] ?? null;
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $elementor_data = $decoded;
            }
        }

        if (!$elementor_data) {
            $elementor_data = $this->build_fallback_template();
        }

        $page_id = wp_insert_post([
            'post_title' => 'Aghasocial Template Draft',
            'post_content' => '',
            'post_status' => 'draft',
            'post_type' => 'page',
        ], true);

        if (is_wp_error($page_id)) {
            $this->redirect_with_notice('Template build failed: ' . $page_id->get_error_message());
        }

        update_post_meta($page_id, '_elementor_data', wp_json_encode($elementor_data, JSON_UNESCAPED_UNICODE));
        update_post_meta($page_id, '_elementor_edit_mode', 'builder');
        update_post_meta($page_id, '_elementor_template_type', 'page');

        $this->redirect_with_notice('Template page created (ID ' . $page_id . '). You can edit and copy {title} placement.');
    }

    private function build_fallback_template() {
        return [
            [
                'id' => wp_generate_uuid4(),
                'elType' => 'section',
                'elements' => [
                    [
                        'id' => wp_generate_uuid4(),
                        'elType' => 'column',
                        'elements' => [
                            [
                                'id' => wp_generate_uuid4(),
                                'elType' => 'widget',
                                'widgetType' => 'heading',
                                'settings' => [
                                    'title' => '{title}',
                                ],
                                'elements' => [],
                            ],
                            [
                                'id' => wp_generate_uuid4(),
                                'elType' => 'widget',
                                'widgetType' => 'text-editor',
                                'settings' => [
                                    'editor' => 'توضیحات کوتاه درباره سرویس. این متن نمونه است.',
                                ],
                                'elements' => [],
                            ],
                            [
                                'id' => wp_generate_uuid4(),
                                'elType' => 'widget',
                                'widgetType' => 'text-editor',
                                'settings' => [
                                    'editor' => '[samyar_services cat={cat_id}]',
                                ],
                                'elements' => [],
                            ],
                            [
                                'id' => wp_generate_uuid4(),
                                'elType' => 'widget',
                                'widgetType' => 'text-editor',
                                'settings' => [
                                    'editor' => '[kando_service id={service_id}]',
                                ],
                                'elements' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
