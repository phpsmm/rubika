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
        add_action('admin_post_aghasocial_ai_pages_template_rebuild', [$this, 'handle_template_rebuild']);
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
        $show_pending = !empty($_GET['aap_show_pending']);
        $show_logs = !empty($_GET['aap_show_logs']);
        $show_rewrites = !empty($_GET['aap_show_rewrites']);
        $pending_generate = $show_pending
            ? $wpdb->get_results("SELECT id, payload, created_at FROM {$queue_table} WHERE type = 'generate' AND status = 'pending' ORDER BY id ASC LIMIT 50", ARRAY_A)
            : [];

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
                        <th scope="row">Category Rewrite Prompt</th>
                        <td>
                            <textarea name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[rewrite_category_prompt]" rows="3" class="large-text code"><?php echo esc_textarea($settings['rewrite_category_prompt']); ?></textarea>
                            <p class="description">Use placeholders: {title}</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Service Rewrite Prompt</th>
                        <td>
                            <textarea name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[rewrite_service_prompt]" rows="3" class="large-text code"><?php echo esc_textarea($settings['rewrite_service_prompt']); ?></textarea>
                            <p class="description">Use placeholders: {title}, {description}</p>
                        </td>
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
                        <th scope="row">Template JSON Schema</th>
                        <td><input type="checkbox" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[template_use_json_schema]" value="1" <?php checked($settings['template_use_json_schema'], 1); ?> />
                            <p class="description">If enabled, send response_format json_schema. Disable if provider rejects schema.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Image Model</th>
                        <td><input type="text" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[image_model]" value="<?php echo esc_attr($settings['image_model']); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Enable AI Images</th>
                        <td><input type="checkbox" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[enable_ai_images]" value="1" <?php checked($settings['enable_ai_images'], 1); ?> /></td>
                    </tr>
                    <tr>
                        <th scope="row">Image Prompt Template</th>
                        <td><input type="text" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[image_prompt_template]" value="<?php echo esc_attr($settings['image_prompt_template']); ?>" class="regular-text" /></td>
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
                        <th scope="row">Enable Group Rewrite</th>
                        <td><input type="checkbox" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[enable_group_rewrite]" value="1" <?php checked($settings['enable_group_rewrite'], 1); ?> />
                            <p class="description">Rewrite each category and its services in one AI call to avoid duplicates.</p></td>
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
                        <th scope="row">AI Timeout (seconds)</th>
                        <td><input type="number" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[ai_timeout]" value="<?php echo esc_attr($settings['ai_timeout']); ?>" class="small-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Content Prompt Template</th>
                        <td>
                            <textarea name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[content_prompt_template]" rows="5" class="large-text code"><?php echo esc_textarea($settings['content_prompt_template']); ?></textarea>
                            <p class="description">Placeholders: {service}, {title}. Output must be JSON.</p>
                        </td>
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
                        <th scope="row">Generate Quantity + Country Pages</th>
                        <td><input type="checkbox" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[enable_country_quantity]" value="1" <?php checked($settings['enable_country_quantity'], 1); ?> />
                            <p class="description">If enabled, quantity pages are generated per country adjective.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Country Quantity Include (names)</th>
                        <td><input type="text" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[country_quantity_include]" value="<?php echo esc_attr($settings['country_quantity_include']); ?>" class="regular-text" />
                            <p class="description">Comma or space separated country names. Empty = all.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Strip Country Terms From Titles</th>
                        <td><input type="checkbox" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[strip_country_terms]" value="1" <?php checked($settings['strip_country_terms'], 1); ?> />
                            <p class="description">Remove country words from service titles before rewriting/quantity titles.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Title Noise Terms</th>
                        <td>
                            <textarea name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[title_noise_terms]" rows="4" class="large-text"><?php echo esc_textarea($settings['title_noise_terms']); ?></textarea>
                            <p class="description">One term per line (or comma-separated). These will be removed from quantity titles.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Enable AI Quantity Titles</th>
                        <td><input type="checkbox" name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[enable_quantity_ai_titles]" value="1" <?php checked($settings['enable_quantity_ai_titles'], 1); ?> />
                            <p class="description">If disabled, quantity pages use deterministic titles like خرید {عدد} {موضوع}.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Category Topic Overrides</th>
                        <td>
                            <textarea name="<?php echo esc_attr(AGHASOCIAL_AI_PAGES_OPTION); ?>[category_topic_overrides]" rows="4" class="large-text"><?php echo esc_textarea($settings['category_topic_overrides']); ?></textarea>
                            <p class="description">One per line: category_id|موضوع. Example: 12|لایک اینستاگرام</p>
                        </td>
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
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('aghasocial_ai_pages_manual'); ?>
                <input type="hidden" name="action" value="aghasocial_ai_pages_template_rebuild" />
                <?php submit_button('Rebuild Template Page (AI)', 'secondary', 'submit', false); ?>
            </form>
            <?php if (!empty($settings['template_page_id'])) : ?>
                <p>Last Template Page ID: <strong><?php echo esc_html($settings['template_page_id']); ?></strong></p>
            <?php endif; ?>
            <h3>Template Builder Status</h3>
            <p><strong>Last Model:</strong> <?php echo esc_html($settings['template_last_used_model'] ?: '-'); ?></p>
            <p><strong>Last Built At:</strong> <?php echo esc_html($settings['template_last_built_at'] ?: '-'); ?></p>
            <p><strong>Status:</strong> <?php echo esc_html($settings['template_last_status'] ?: '-'); ?></p>
            <?php if (!empty($settings['template_last_error'])) : ?>
                <p><strong>Last Error:</strong> <?php echo esc_html($settings['template_last_error']); ?></p>
            <?php endif; ?>
            <?php if (!empty($settings['template_last_response'])) : ?>
                <details>
                    <summary>Last AI Response (truncated)</summary>
                    <pre style="white-space: pre-wrap;"><?php echo esc_html($settings['template_last_response']); ?></pre>
                </details>
            <?php endif; ?>
            <?php if (!empty($settings['template_last_request'])) : ?>
                <details>
                    <summary>Last AI Request (truncated)</summary>
                    <pre style="white-space: pre-wrap;"><?php echo esc_html($settings['template_last_request']); ?></pre>
                </details>
            <?php endif; ?>
            <?php if (empty($settings['enable_logging'])) : ?>
                <p class="description">Logging is disabled. Enable logging to capture full AI request/response in logs.</p>
            <?php endif; ?>

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

            <h2>Last Generated Page</h2>
            <?php if (!empty($settings['last_generated_page_id'])) : ?>
                <p>
                    ID: <strong><?php echo esc_html($settings['last_generated_page_id']); ?></strong><br />
                    Title: <strong><?php echo esc_html($settings['last_generated_title']); ?></strong><br />
                    Created: <strong><?php echo esc_html($settings['last_generated_at']); ?></strong><br />
                    <a href="<?php echo esc_url(get_edit_post_link((int) $settings['last_generated_page_id'])); ?>" target="_blank">Edit Page</a>
                </p>
            <?php else : ?>
                <p>No page generated yet.</p>
            <?php endif; ?>

            <h2>Placeholder AI Status</h2>
            <p><strong>Last Built At:</strong> <?php echo esc_html($settings['placeholders_last_built_at'] ?: '-'); ?></p>
            <p><strong>Status:</strong> <?php echo esc_html($settings['placeholders_last_status'] ?: '-'); ?></p>
            <?php if (!empty($settings['placeholders_last_error'])) : ?>
                <p><strong>Last Error:</strong> <?php echo esc_html($settings['placeholders_last_error']); ?></p>
            <?php endif; ?>
            <?php if (!empty($settings['placeholders_last_request'])) : ?>
                <details>
                    <summary>Last Placeholder Request (truncated)</summary>
                    <pre style="white-space: pre-wrap;"><?php echo esc_html($settings['placeholders_last_request']); ?></pre>
                </details>
            <?php endif; ?>
            <?php if (!empty($settings['placeholders_last_response'])) : ?>
                <details>
                    <summary>Last Placeholder Response (truncated)</summary>
                    <pre style="white-space: pre-wrap;"><?php echo esc_html($settings['placeholders_last_response']); ?></pre>
                </details>
            <?php endif; ?>

            <h2>AI Logs (Latest 50)</h2>
            <p>
                <a class="button" href="<?php echo esc_url(add_query_arg('aap_show_logs', '1', admin_url('admin.php?page=aghasocial-ai-pages'))); ?>">Load Logs</a>
            </p>
            <?php if ($show_logs) : ?>
                <?php
                $logs_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_LOG_TABLE;
                $logs = $wpdb->get_results("SELECT id, context, created_at, request, response FROM {$logs_table} ORDER BY id DESC LIMIT 50", ARRAY_A);
                ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Context</th>
                            <th>Created</th>
                            <th>Request</th>
                            <th>Response</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($logs) : ?>
                            <?php foreach ($logs as $log) : ?>
                                <tr>
                                    <td><?php echo esc_html($log['id']); ?></td>
                                    <td><?php echo esc_html($log['context']); ?></td>
                                    <td><?php echo esc_html($log['created_at']); ?></td>
                                    <td><details><summary>View</summary><pre style="white-space: pre-wrap;"><?php echo esc_html($log['request']); ?></pre></details></td>
                                    <td><details><summary>View</summary><pre style="white-space: pre-wrap;"><?php echo esc_html($log['response']); ?></pre></details></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="5">No logs found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2>Rewritten Items (Latest 50)</h2>
            <p>
                <a class="button" href="<?php echo esc_url(add_query_arg('aap_show_rewrites', '1', admin_url('admin.php?page=aghasocial-ai-pages'))); ?>">Load Rewrites</a>
            </p>
            <?php if ($show_rewrites) : ?>
                <?php
                $meta_table = $wpdb->prefix . AGHASOCIAL_AI_PAGES_META_TABLE;
                $rewrites = $wpdb->get_results("SELECT id, ref_type, ref_id, title, updated_at FROM {$meta_table} ORDER BY updated_at DESC LIMIT 50", ARRAY_A);
                ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Type</th>
                            <th>Ref ID</th>
                            <th>Title</th>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rewrites) : ?>
                            <?php foreach ($rewrites as $row) : ?>
                                <tr>
                                    <td><?php echo esc_html($row['id']); ?></td>
                                    <td><?php echo esc_html($row['ref_type']); ?></td>
                                    <td><?php echo esc_html($row['ref_id']); ?></td>
                                    <td><?php echo esc_html($row['title']); ?></td>
                                    <td><?php echo esc_html($row['updated_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="5">No rewritten items found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2>Pending Generate Preview (Top 50)</h2>
            <p>
                <a class="button" href="<?php echo esc_url(add_query_arg('aap_show_pending', '1', admin_url('admin.php?page=aghasocial-ai-pages'))); ?>">Load Pending Preview</a>
            </p>
            <?php if ($show_pending) : ?>
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
            <?php endif; ?>
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

        $elementor_data = $this->build_template_from_ai();

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
        update_post_meta($page_id, '_elementor_page_settings', [
            'page_layout' => 'elementor_canvas',
        ]);

        $settings = aghasocial_ai_pages_get_settings();
        $settings['template_page_id'] = $page_id;
        update_option(AGHASOCIAL_AI_PAGES_OPTION, $settings);

        $this->redirect_with_notice('Template page created (ID ' . $page_id . '). You can edit and copy {title} placement.');
    }

    public function handle_template_rebuild() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('aghasocial_ai_pages_manual');

        $settings = aghasocial_ai_pages_get_settings();
        $page_id = !empty($settings['template_page_id']) ? (int) $settings['template_page_id'] : 0;
        if (!$page_id) {
            $this->redirect_with_notice('No template page found. Build one first.');
        }

        $elementor_data = $this->build_template_from_ai();
        update_post_meta($page_id, '_elementor_data', wp_json_encode($elementor_data, JSON_UNESCAPED_UNICODE));
        update_post_meta($page_id, '_elementor_edit_mode', 'builder');
        update_post_meta($page_id, '_elementor_template_type', 'page');
        update_post_meta($page_id, '_elementor_page_settings', [
            'page_layout' => 'elementor_canvas',
        ]);

        $this->redirect_with_notice('Template page rebuilt for ID ' . $page_id . '.');
    }

    private function build_template_from_ai() {
        $settings = aghasocial_ai_pages_get_settings();
        $ai = new Aghasocial_AI_Pages_AI();

        $system = 'You are an expert Elementor designer for Persian landing pages. Output ONLY valid JSON for Elementor _elementor_data. Create a full landing page with multiple sections, rich layout, spacing, backgrounds, and visual hierarchy. No markdown.';
        $prompt = 'Build a Persian marketing landing page for “demo {title}”. HARD REQUIREMENTS: Output must be a JSON object with key "elements" (array) suitable for Elementor _elementor_data. Use at least 8 sections. MUST include these shortcodes exactly once each as text-editor widgets: [samyar_services cat={cat_id}] and [kando_service id={service_id}]. Include these widget types across the page: heading, text-editor, image, icon-list (3+ items), button (multiple CTAs), toggle (FAQ 5 items). DESIGN RULES: Use padding/margins, column layouts (2 and 3 columns), and card-like boxes with border-radius + subtle shadow via Elementor settings where possible. Add at least 2 sections with background gradients or overlays. Add at least one stats section (3 numbers), one process steps section (3 steps), one trust section (logos or badges as images or icon-list). Copy must be Persian, specific to social services, conversion-focused, not generic. Avoid placeholder URLs like example.com; use relative like /assets/img/... Output ONLY JSON.';
        $schema = [
            'name' => 'elementor_template',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'elements' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                        ],
                    ],
                ],
            ],
        ];

        $schema_to_use = !empty($settings['template_use_json_schema']) ? $schema : null;
        $response = $ai->request_text($prompt, $system, $schema_to_use, $settings['template_builder_model']);
        $settings['template_last_used_model'] = $settings['template_builder_model'];
        $settings['template_last_built_at'] = current_time('mysql');
        $settings['template_last_request'] = wp_json_encode([
            'model' => $settings['template_builder_model'],
            'system' => $system,
            'prompt' => $prompt,
            'json_schema' => $schema_to_use,
        ], JSON_UNESCAPED_UNICODE);

        $result = $this->parse_template_response($response, $settings);
        if ($result['elements']) {
            $validation = $this->validate_template_elements($result['elements']);
            if ($validation['ok']) {
                return $result['elements'];
            }
            $settings['template_last_status'] = 'invalid_template';
            $settings['template_last_error'] = $validation['error'];
            $settings['template_last_used_fallback'] = 0;
            update_option(AGHASOCIAL_AI_PAGES_OPTION, $settings);
        }

        $retry_prompt = 'Return ONLY valid JSON object with key "elements". MUST include {title} in a heading. MUST include text-editor widgets with [samyar_services cat={cat_id}] and [kando_service id={service_id}] once each. MUST include icon-list (3+ items), toggle FAQ (5 items), multiple CTA buttons, stats section (3 numbers), process steps (3 steps), trust section. Use Persian conversion copy. Output ONLY JSON.';
        $retry_response = $ai->request_text($retry_prompt, $system, $schema_to_use, $settings['template_builder_model']);
        $settings['template_last_request'] = wp_json_encode([
            'model' => $settings['template_builder_model'],
            'system' => $system,
            'prompt' => $retry_prompt,
            'json_schema' => $schema_to_use,
        ], JSON_UNESCAPED_UNICODE);
        $settings['template_last_built_at'] = current_time('mysql');

        $retry_result = $this->parse_template_response($retry_response, $settings);
        if ($retry_result['elements']) {
            $validation = $this->validate_template_elements($retry_result['elements']);
            if ($validation['ok']) {
                return $retry_result['elements'];
            }
            $settings['template_last_status'] = 'invalid_template';
            $settings['template_last_error'] = $validation['error'];
            $settings['template_last_used_fallback'] = 1;
            update_option(AGHASOCIAL_AI_PAGES_OPTION, $settings);
        }

        $settings['template_last_status'] = 'invalid_json';
        $settings['template_last_error'] = 'AI response was not valid JSON.';
        $settings['template_last_used_fallback'] = 1;
        update_option(AGHASOCIAL_AI_PAGES_OPTION, $settings);
        return $this->build_fallback_template();
    }

    private function parse_template_response($response, &$settings) {
        if (is_wp_error($response)) {
            $settings['template_last_status'] = 'error';
            $settings['template_last_error'] = $response->get_error_message();
            $settings['template_last_response'] = '';
            $settings['template_last_used_fallback'] = 1;
            update_option(AGHASOCIAL_AI_PAGES_OPTION, $settings);
            return ['elements' => null];
        }

        $settings['template_last_response'] = wp_json_encode($response, JSON_UNESCAPED_UNICODE);
        if ($settings['template_last_response']) {
            $settings['template_last_response'] = mb_substr($settings['template_last_response'], 0, 10000);
        }

        $content = $response['choices'][0]['message']['content'] ?? null;
        $decoded = $content ? json_decode($content, true) : null;
        if (!is_array($decoded)) {
            $decoded = $this->extract_json_object($content);
        }
        if (is_array($decoded) && isset($decoded['elements']) && is_array($decoded['elements'])) {
            $settings['template_last_status'] = 'ok';
            $settings['template_last_error'] = '';
            $settings['template_last_used_fallback'] = 0;
            update_option(AGHASOCIAL_AI_PAGES_OPTION, $settings);
            return ['elements' => $decoded['elements']];
        }

        return ['elements' => null];
    }

    private function extract_json_object($content) {
        if (!$content) {
            return null;
        }
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $json = substr($content, $start, $end - $start + 1);
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function validate_template_elements($elements) {
        $widgets = [];
        $this->collect_widgets($elements, $widgets);

        $has_title = false;
        $has_services = false;
        $has_service = false;
        $has_icon_list = false;
        $has_toggle = false;
        $has_button = false;
        $has_image = false;

        foreach ($widgets as $widget) {
            $type = $widget['widgetType'] ?? '';
            $settings = $widget['settings'] ?? [];
            if ($type === 'heading' && isset($settings['title']) && strpos($settings['title'], '{title}') !== false) {
                $has_title = true;
            }
            if ($type === 'text-editor' && isset($settings['editor'])) {
                $editor = $settings['editor'];
                if (is_array($editor)) {
                    $editor = wp_json_encode($editor, JSON_UNESCAPED_UNICODE);
                }
                if (is_string($editor) && strpos($editor, '[samyar_services cat={cat_id}]') !== false) {
                    $has_services = true;
                }
                if (is_string($editor) && strpos($editor, '[kando_service id={service_id}]') !== false) {
                    $has_service = true;
                }
            }
            if ($type === 'icon-list') {
                $has_icon_list = true;
            }
            if ($type === 'toggle') {
                $has_toggle = true;
            }
            if ($type === 'button') {
                $has_button = true;
            }
            if ($type === 'image') {
                $has_image = true;
            }
        }

        $missing = [];
        if (!$has_title) {
            $missing[] = '{title} heading';
        }
        if (!$has_services) {
            $missing[] = 'services shortcode';
        }
        if (!$has_service) {
            $missing[] = 'service shortcode';
        }
        if (!$has_icon_list) {
            $missing[] = 'icon-list';
        }
        if (!$has_toggle) {
            $missing[] = 'FAQ toggle';
        }
        if (!$has_button) {
            $missing[] = 'CTA button';
        }
        if (!$has_image) {
            $missing[] = 'image';
        }

        if ($missing) {
            return [
                'ok' => false,
                'error' => 'Missing required widgets: ' . implode(', ', $missing),
            ];
        }

        return ['ok' => true, 'error' => ''];
    }

    private function collect_widgets($elements, &$widgets) {
        foreach ((array) $elements as $element) {
            if (!is_array($element)) {
                continue;
            }
            if (($element['elType'] ?? '') === 'widget') {
                $widgets[] = $element;
            }
            if (!empty($element['elements'])) {
                $this->collect_widgets($element['elements'], $widgets);
            }
        }
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
                                'widgetType' => 'image',
                                'settings' => [
                                    'caption' => 'تصویر قهرمان (Hero)',
                                ],
                                'elements' => [],
                            ],
                            [
                                'id' => wp_generate_uuid4(),
                                'elType' => 'widget',
                                'widgetType' => 'icon-list',
                                'settings' => [
                                    'icon_list' => [
                                        ['text' => 'کیفیت بالا', 'icon' => ['value' => 'fas fa-check', 'library' => 'fa-solid']],
                                        ['text' => 'تحویل سریع', 'icon' => ['value' => 'fas fa-check', 'library' => 'fa-solid']],
                                        ['text' => 'پشتیبانی واقعی', 'icon' => ['value' => 'fas fa-check', 'library' => 'fa-solid']],
                                    ],
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
                            [
                                'id' => wp_generate_uuid4(),
                                'elType' => 'widget',
                                'widgetType' => 'toggle',
                                'settings' => [
                                    'tabs' => [
                                        [
                                            'tab_title' => 'سوال متداول 1',
                                            'tab_content' => 'پاسخ نمونه برای سوال متداول.',
                                        ],
                                        [
                                            'tab_title' => 'سوال متداول 2',
                                            'tab_content' => 'پاسخ نمونه برای سوال متداول.',
                                        ],
                                    ],
                                ],
                                'elements' => [],
                            ],
                            [
                                'id' => wp_generate_uuid4(),
                                'elType' => 'widget',
                                'widgetType' => 'button',
                                'settings' => [
                                    'text' => 'ثبت سفارش',
                                    'link' => [
                                        'url' => '#',
                                    ],
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
