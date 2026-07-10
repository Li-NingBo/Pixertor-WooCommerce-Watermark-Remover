<?php
/**
 * 商品图片列表页面 — 水印移除任务控制
 *
 * 显示所有商品图片（主图、画廊图、内容图），
 * 支持多选、并发处理。该页面由 WWR_Admin_Settings
 * 注册为独立菜单的子页面。
 *
 * @package WWR
 * @since  1.3.0
 */

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

class WWR_Product_Page {

    private string $page_slug = 'wwr-remove-watermarks';

    public function __construct() {
        // 不再注册独立菜单 — 菜单由 WWR_Admin_Settings 统一管理
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // AJAX handlers.
        add_action('wp_ajax_wwr_start_process', [$this, 'ajax_start_process']);
        add_action('wp_ajax_wwr_poll_task', [$this, 'ajax_poll_task']);
    }

    // ── Assets ───────────────────────────────────────────────────────

    public function enqueue_assets(string $hook): void {
        if ($hook !== 'wwr-dashboard_page_' . $this->page_slug) {
            return;
        }

        $settings = get_option(WWR_SETTINGS_OPTION_KEY, []);

        wp_enqueue_style(
            'wwr-admin-css',
            WWR_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WWR_VERSION
        );

        wp_enqueue_script(
            'wwr-admin-js',
            WWR_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            WWR_VERSION,
            true
        );

        wp_localize_script('wwr-admin-js', 'WWR_Admin', [
            'ajax_url'       => admin_url('admin-ajax.php'),
            'nonce_start'    => wp_create_nonce('wwr_start_process'),
            'nonce_poll'     => wp_create_nonce('wwr_poll_task'),
            'poll_interval'  => WWR_POLL_INTERVAL * 1000, // JS 使用毫秒
            'parallel_tasks' => (int) ($settings['parallel_tasks'] ?? 10),
            'i18n'           => [
                'select_image'    => '请至少选择一张图片。',
                'confirm_start'   => '确认开始对所选图片进行水印移除处理？系统将并发处理这些图片。',
                'processing'      => '处理中...',
                'uploading'       => '正在上传图片到 API...',
                'ai_working'      => 'AI 正在移除水印...',
                'downloading'     => '正在下载处理结果...',
                'success'         => '全部完成！',
                'success_single'  => '水印移除完成！图片已替换。',
                'error'           => '发生错误，请重试。',
                'timeout'         => 'AI 处理超时，请重试。',
                'start_btn'       => '开始移除水印',
                'stop_btn'        => '停止',
                'stopping'        => '正在停止...',
                'starting'        => '正在启动...',
                'queue_progress'  => '%d 个处理中，%d 个排队中',
                'queue_done'      => '%d 张图片处理成功。',
                'select_all'      => '全选',
                'deselect_all'    => '取消全选',
                'queued'          => '排队中',
                'skipped'         => '跳过',
                'stopped_msg'     => '已停止。完成 %d 个，失败 %d 个，剩余 %d 个。',
                'mixed_result'    => '%d 个成功，%d 个失败。',
            ],
        ]);
    }

    // ── 页面渲染 ─────────────────────────────────────────────────────

    public function render_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('您没有足够的权限访问此页面。');
        }

        $images = $this->collect_product_images();
        ?>
        <div class="wrap wwr-wrap">
            <h1>移除商品图片水印</h1>

            <div class="wwr-toolbar">
                <button
                    type="button"
                    id="wwr-select-all-btn"
                    class="button button-secondary"
                >
                    全选
                </button>

                <button
                    type="button"
                    id="wwr-start-btn"
                    class="button button-primary button-hero"
                    disabled
                >
                    开始移除水印
                </button>

                <button
                    type="button"
                    id="wwr-stop-btn"
                    class="button button-secondary"
                    style="display:none;"
                >
                    停止
                </button>

                <span id="wwr-queue-counter" class="wwr-queue-counter"></span>
                <span id="wwr-status" class="wwr-status"></span>
            </div>

            <div id="wwr-progress-bar" class="wwr-progress-bar" style="display:none;">
                <div class="wwr-progress-fill"></div>
                <span class="wwr-progress-text">0%</span>
            </div>

            <?php if (empty($images)): ?>
                <div class="notice notice-info" style="margin-top: 15px;">
                    <p>未找到商品图片。请先添加带有图片的商品。</p>
                </div>
            <?php else: ?>
                <div id="wwr-image-grid" class="wwr-image-grid">
                    <?php foreach ($images as $img): ?>
                        <div
                            class="wwr-image-card"
                            data-attachment-id="<?php echo esc_attr($img['attachment_id']); ?>"
                            data-product-id="<?php echo esc_attr($img['product_id']); ?>"
                            data-image-type="<?php echo esc_attr($img['type']); ?>"
                        >
                            <!-- 复选框 — 右上角 -->
                            <div class="wwr-checkbox-overlay">
                                <input
                                    type="checkbox"
                                    name="wwr_select_images[]"
                                    class="wwr-checkbox"
                                    id="wwr-img-<?php echo esc_attr($img['attachment_id']); ?>"
                                    value="<?php echo esc_attr($img['attachment_id']); ?>"
                                >
                                <label
                                    for="wwr-img-<?php echo esc_attr($img['attachment_id']); ?>"
                                    class="wwr-checkbox-label"
                                ></label>
                            </div>

                            <!-- 缩略图 -->
                            <div class="wwr-image-wrap">
                                <img
                                    src="<?php echo esc_url($img['thumbnail_url']); ?>"
                                    alt="<?php echo esc_attr($img['product_name']); ?>"
                                    loading="lazy"
                                >
                            </div>

                            <!-- 信息栏 -->
                            <div class="wwr-image-info">
                                <span class="wwr-product-name" title="<?php echo esc_attr($img['product_name']); ?>">
                                    <?php echo esc_html(mb_strimwidth($img['product_name'], 0, 40, '…')); ?>
                                </span>
                                <span class="wwr-image-badge wwr-badge-<?php echo esc_attr($img['type']); ?>">
                                    <?php echo esc_html($img['type_label']); ?>
                                </span>
                            </div>

                            <!-- 单张卡片状态（处理过程中显示） -->
                            <div class="wwr-card-status" style="display:none;"></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── 收集所有商品图片 ─────────────────────────────────────────────

    /**
     * 遍历所有已发布的 WooCommerce 商品，收集：
     *  - 商品主图
     *  - 画廊图片
     *  - 描述中的内嵌图片
     *
     * @return array<int, array{
     *     attachment_id: int,
     *     product_id: int,
     *     product_name: string,
     *     type: string,
     *     type_label: string,
     *     thumbnail_url: string
     * }>
     */
    protected function collect_product_images(): array {
        $images = [];
        $seen   = []; // attachment_id => true — 去重。

        $products = wc_get_products([
            'limit'  => -1,
            'status' => 'publish',
            'type'   => ['simple', 'variable', 'external', 'grouped'],
        ]);

        foreach ($products as $product) {
            $product_id   = $product->get_id();
            $product_name = $product->get_name();

            // 1. 主图。
            $thumbnail_id = $product->get_image_id();
            if ($thumbnail_id && empty($seen[$thumbnail_id])) {
                $seen[$thumbnail_id] = true;
                $images[] = $this->build_image_entry(
                    $thumbnail_id, $product_id, $product_name, 'featured', '主图'
                );
            }

            // 2. 画廊图片。
            $gallery_ids = $product->get_gallery_image_ids();
            foreach ($gallery_ids as $gid) {
                if ($gid && empty($seen[$gid])) {
                    $seen[$gid] = true;
                    $images[] = $this->build_image_entry(
                        $gid, $product_id, $product_name, 'gallery', '画廊'
                    );
                }
            }

            // 3. 描述中的图片。
            $content = $product->get_description();
            if (!empty($content)) {
                preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches);
                if (!empty($matches[1])) {
                    foreach ($matches[1] as $img_url) {
                        $att_id = attachment_url_to_postid($img_url);
                        if ($att_id && empty($seen[$att_id])) {
                            $seen[$att_id] = true;
                            $images[] = $this->build_image_entry(
                                $att_id, $product_id, $product_name, 'content', '内容'
                            );
                        }
                    }
                }
            }
        }

        return $images;
    }

    /**
     * 构建统一的图片条目数组。
     */
    protected function build_image_entry(
        int $attachment_id,
        int $product_id,
        string $product_name,
        string $type,
        string $type_label
    ): array {
        $thumb = wp_get_attachment_image_url($attachment_id, [300, 300]);
        if (!$thumb) {
            $thumb = wc_placeholder_img_src('thumbnail');
        }

        return [
            'attachment_id' => $attachment_id,
            'product_id'    => $product_id,
            'product_name'  => $product_name,
            'type'          => $type,
            'type_label'    => $type_label,
            'thumbnail_url' => $thumb,
        ];
    }

    // ── AJAX: 开始处理 ───────────────────────────────────────────────

    /**
     * 将选中的附件上传到 Pixertor-ToAPIs，并创建生成任务。
     *
     * 使用 transient 存储 task ↔ attachment 映射，供轮询步骤使用。
     *
     * 期望 POST 参数：
     *   - attachment_id (int)
     *   - _ajax_nonce (nonce: wwr_start_process)
     */
    public function ajax_start_process(): void {
        check_ajax_referer('wwr_start_process');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => '权限不足。']);
        }

        $attachment_id = isset($_POST['attachment_id']) ? (int) $_POST['attachment_id'] : 0;
        if ($attachment_id <= 0) {
            wp_send_json_error(['message' => '无效的附件 ID。']);
        }

        // 验证附件存在且为图片。
        if (!wp_attachment_is_image($attachment_id)) {
            wp_send_json_error(['message' => '所选项目不是有效的图片。']);
        }

        // 步骤 1：上传图片到 Pixertor-ToAPIs。
        $file_path = get_attached_file($attachment_id);
        if (!$file_path) {
            wp_send_json_error(['message' => '无法在磁盘上找到图片文件。']);
        }

        $upload = WWR_API::upload_image($file_path);
        if (!$upload['success']) {
            wp_send_json_error(['message' => $upload['error']]);
        }

        // 步骤 2：创建生成任务。
        $prompt = trim(
            'Remove ALL watermarks, logos, text overlays, copyright marks, and stamps from this image. ' .
            'Keep every other detail — the product, colors, textures, lighting, background — EXACTLY as-is. ' .
            'Do NOT crop, resize, or change the composition. ' .
            'The output must be indistinguishable from the original except watermarks are gone.'
        );

        $size = WWR_API::guess_aspect_ratio($file_path);

        $task = WWR_API::create_task(
            $upload['url'],
            $prompt,
            $size
        );

        if (!$task['success']) {
            wp_send_json_error(['message' => $task['error']]);
        }

        // 存储映射，供轮询处理器使用。
        set_transient(
            'wwr_task_' . $task['task_id'],
            [
                'attachment_id'  => $attachment_id,
                'original_url'   => wp_get_attachment_url($attachment_id),
                'file_path'      => $file_path,
                'product_ids'    => $this->find_product_ids_by_attachment($attachment_id),
                'image_type'     => $this->detect_image_type($attachment_id),
                'started_at'     => time(),
            ],
            30 * MINUTE_IN_SECONDS
        );

        wp_send_json_success([
            'task_id'       => $task['task_id'],
            'attachment_id' => $attachment_id,
            'status'        => 'queued',
        ]);
    }

    // ── AJAX: 轮询任务并完成 ─────────────────────────────────────────

    /**
     * 轮询 Pixertor-ToAPIs 任务状态。完成后下载结果图片，
     * 插入 WordPress 媒体库，并替换引用。
     *
     * 期望 POST 参数：
     *   - task_id (string)
     *   - _ajax_nonce (nonce: wwr_poll_task)
     */
    public function ajax_poll_task(): void {
        check_ajax_referer('wwr_poll_task');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => '权限不足。']);
        }

        $task_id = sanitize_text_field($_POST['task_id'] ?? '');
        if (empty($task_id)) {
            wp_send_json_error(['message' => '缺少任务 ID。']);
        }

        $result = WWR_API::query_task($task_id);

        if (!$result['success']) {
            // 可能是最终失败。
            delete_transient('wwr_task_' . $task_id);
            wp_send_json_error([
                'message' => $result['error'] ?? 'API 查询失败。',
                'status'  => $result['status'] ?? 'failed',
            ]);
        }

        // 仍在处理中 — 通知客户端继续轮询。
        if ($result['status'] !== 'completed') {
            wp_send_json_success([
                'status'   => $result['status'],
                'progress' => $result['progress'],
                'finished' => false,
            ]);
        }

        // ── 完成任务！下载、插入、替换。 ──────────────────────────

        $task_data = get_transient('wwr_task_' . $task_id);
        if (!$task_data) {
            // Transient 已过期 — 仍尝试用现有数据完成。
            $task_data = [];
        }

        $attachment_id = $task_data['attachment_id'] ?? 0;

        // 步骤 1：下载结果图片。
        $tmp_file = $this->download_image($result['image_url']);
        if (!$tmp_file) {
            delete_transient('wwr_task_' . $task_id);
            wp_send_json_error(['message' => '下载处理后的图片失败。']);
        }

        // 步骤 2：插入 WordPress 媒体库。
        $new_attachment_id = $this->insert_into_media_library(
            $tmp_file,
            $attachment_id,
            $task_data['product_ids'] ?? []
        );

        @unlink($tmp_file);

        if (!$new_attachment_id) {
            delete_transient('wwr_task_' . $task_id);
            wp_send_json_error(['message' => '将图片保存到媒体库失败。']);
        }

        // 步骤 3：替换商品图片引用。
        $new_url = wp_get_attachment_url($new_attachment_id);
        $replacements = $this->replace_product_image(
            $attachment_id,
            $new_attachment_id,
            $new_url,
            $task_data
        );

        // 清理。
        delete_transient('wwr_task_' . $task_id);

        wp_send_json_success([
            'status'            => 'completed',
            'progress'          => 100,
            'finished'          => true,
            'new_attachment_id' => $new_attachment_id,
            'new_url'           => $new_url,
            'replacements'      => $replacements,
        ]);
    }

    // ── 辅助方法 ──────────────────────────────────────────────────────

    /**
     * 下载远程图片到临时文件。
     *
     * @return string|false 本地路径，失败返回 false。
     */
    protected function download_image(string $url) {
        $tmp = wp_tempnam('wwr_download_');
        if (!$tmp) {
            return false;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        $data = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200 || empty($data)) {
            @unlink($tmp);
            return false;
        }

        file_put_contents($tmp, $data);
        return $tmp;
    }

    /**
     * 将文件插入 WordPress 媒体库，可选择关联到商品 ID。
     *
     * 尽量保持相同文件名主干，以便 URL 保持相似。
     */
    protected function insert_into_media_library(
        string $file_path,
        int $original_attachment_id = 0,
        array $product_ids = []
    ): int {
        if (!function_exists('wp_read_image_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // 生成友好的文件名。
        $original_title = '';
        if ($original_attachment_id) {
            $original_title = get_the_title($original_attachment_id);
            $original_title = sanitize_file_name($original_title);
        }

        if (empty($original_title)) {
            $original_title = 'watermark-removed';
        }

        $original_title .= '-nowm';

        $file = [
            'name'     => $original_title . '.png',
            'tmp_name' => $file_path,
        ];

        $post_parent = !empty($product_ids) ? $product_ids[0] : 0;

        $attachment_id = media_handle_sideload(
            $file,
            $post_parent,
            sprintf(
                '%s — 水印已移除',
                get_the_title($original_attachment_id) ?: '图片'
            ),
            [
                'post_content' => sprintf(
                    '附件 #%d 的 AI 去水印版本。',
                    $original_attachment_id
                ),
            ]
        );

        if (is_wp_error($attachment_id)) {
            error_log('WWR: media_handle_sideload 失败: ' . $attachment_id->get_error_message());
            return 0;
        }

        // 将新媒体也关联到使用了原始图片的其他商品。
        if (!empty($product_ids) && count($product_ids) > 1) {
            foreach (array_slice($product_ids, 1) as $pid) {
                wp_update_post([
                    'ID'          => $attachment_id,
                    'post_parent' => $pid,
                ]);
            }
        }

        return $attachment_id;
    }

    /**
     * 在以下位置将原始附件替换为新的：
     *  - 主图 (_thumbnail_id)
     *  - 商品画廊 (_product_image_gallery)
     *  - 商品描述 (post_content)
     *
     * @return array 替换摘要。
     */
    protected function replace_product_image(
        int $old_attachment_id,
        int $new_attachment_id,
        string $new_url,
        array $task_data = []
    ): array {
        $replacements = [
            'featured' => 0,
            'gallery'  => 0,
            'content'  => 0,
        ];

        $old_url = wp_get_attachment_url($old_attachment_id);
        if (!$old_url) {
            $old_url = $task_data['original_url'] ?? '';
        }

        $product_ids = $task_data['product_ids'] ?? $this->find_product_ids_by_attachment($old_attachment_id);

        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }

            // 1. 主图。
            if ((int) $product->get_image_id() === $old_attachment_id) {
                $product->set_image_id($new_attachment_id);
                $product->save();
                $replacements['featured']++;
            }

            // 2. 画廊。
            $gallery_ids = $product->get_gallery_image_ids();
            $updated_gallery = [];
            $gallery_changed = false;
            foreach ($gallery_ids as $gid) {
                if ((int) $gid === $old_attachment_id) {
                    $updated_gallery[] = (string) $new_attachment_id;
                    $gallery_changed = true;
                    $replacements['gallery']++;
                } else {
                    $updated_gallery[] = (string) $gid;
                }
            }
            if ($gallery_changed) {
                $product->set_gallery_image_ids($updated_gallery);
                $product->save();
            }

            // 3. 描述中的图片。
            if (!empty($old_url)) {
                $content = $product->get_description();
                $old_url_escaped = preg_quote($old_url, '/');
                $updated_content = preg_replace(
                    '/' . $old_url_escaped . '/',
                    $new_url,
                    $content,
                    -1,
                    $count
                );
                if ($count > 0) {
                    $product->set_description($updated_content);
                    $product->save();
                    $replacements['content'] += $count;
                }
            }
        }

        return $replacements;
    }

    /**
     * 查找引用了指定附件的所有商品 ID。
     */
    protected function find_product_ids_by_attachment(int $attachment_id): array {
        global $wpdb;

        $product_ids = [];

        // 主图。
        $featured = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_thumbnail_id' AND meta_value = %d",
            $attachment_id
        ));
        foreach ($featured as $pid) {
            $post_type = get_post_type($pid);
            if (in_array($post_type, ['product', 'product_variation'], true)) {
                $product_ids[] = (int) $pid;
            }
        }

        // 画廊图片（以逗号分隔存储）。
        $gallery_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_product_image_gallery'
               AND meta_value LIKE %s",
            '%' . $wpdb->esc_like((string) $attachment_id) . '%'
        ));
        foreach ($gallery_rows as $row) {
            $ids = explode(',', $row->meta_value);
            if (in_array((string) $attachment_id, array_map('trim', $ids), true)) {
                $product_ids[] = (int) $row->post_id;
            }
        }

        // 内容图片 — 在 post_content 中搜索附件 URL。
        $attachment_url = wp_get_attachment_url($attachment_id);
        if ($attachment_url) {
            $content_rows = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type IN ('product', 'product_variation')
                   AND post_content LIKE %s",
                '%' . $wpdb->esc_like($attachment_url) . '%'
            ));
            foreach ($content_rows as $pid) {
                $product_ids[] = (int) $pid;
            }
        }

        return array_unique($product_ids);
    }

    /**
     * 检测附件对应的图片类型。
     * 返回 'featured'、'gallery'、'content' 或 'unknown'。
     */
    protected function detect_image_type(int $attachment_id): string {
        global $wpdb;

        // 检查主图。
        $is_featured = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_thumbnail_id' AND meta_value = %d
             LIMIT 1",
            $attachment_id
        ));
        if ($is_featured) {
            return 'featured';
        }

        // 检查画廊。
        $is_gallery = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_product_image_gallery'
               AND meta_value LIKE %s
             LIMIT 1",
            '%' . $wpdb->esc_like((string) $attachment_id) . '%'
        ));
        if ($is_gallery) {
            return 'gallery';
        }

        // 检查内容。
        $url = wp_get_attachment_url($attachment_id);
        if ($url) {
            $is_content = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type IN ('product', 'product_variation')
                   AND post_content LIKE %s
                 LIMIT 1",
                '%' . $wpdb->esc_like($url) . '%'
            ));
            if ($is_content) {
                return 'content';
            }
        }

        return 'unknown';
    }
}
