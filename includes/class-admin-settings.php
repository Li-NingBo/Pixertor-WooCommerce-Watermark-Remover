<?php
/**
 * 插件配置页面 — 独立仪表盘菜单
 *
 * 在 WordPress 后台创建独立的顶级菜单"水印移除"，
 * 包含"API 配置"和"任务处理"两个子页面。
 *
 * @package WWR
 * @since  1.3.0
 */

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

class WWR_Admin_Settings {

    /**
     * 顶级菜单 slug
     */
    private string $main_slug = 'wwr-dashboard';

    /**
     * 配置页面 slug
     */
    private string $page_slug = 'wwr-settings';

    /**
     * Option group name used by register_setting / settings_fields.
     */
    private string $option_group = 'wwr_settings_group';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_pages'], 99);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_wwr_test_api', [$this, 'ajax_test_api']);
    }

    // ── 菜单注册 ─────────────────────────────────────────────────────

    public function add_menu_pages(): void {
        // 顶级菜单 — 独立仪表盘
        add_menu_page(
            '水印移除',                           // page title
            '水印移除',                           // menu title
            'manage_woocommerce',                 // capability
            $this->main_slug,                      // menu slug
            [$this, 'render_page'],                // callback (配置页作为默认)
            'dashicons-format-image',              // icon
            56                                     // position (after Products)
        );

        // 子菜单 — API 配置（默认页）
        add_submenu_page(
            $this->main_slug,
            'API 配置',
            'API 配置',
            'manage_woocommerce',
            $this->main_slug,
            [$this, 'render_page']
        );

        // 子菜单 — 任务处理
        add_submenu_page(
            $this->main_slug,
            '任务处理',
            '任务处理',
            'manage_woocommerce',
            'wwr-remove-watermarks',
            function () {
                $product_page = new WWR_Product_Page();
                $product_page->render_page();
            }
        );
    }

    // ── Enqueue assets ────────────────────────────────────────────────

    public function enqueue_assets(string $hook): void {
        // 只在插件页加载
        $page = $_GET['page'] ?? '';
        if (!in_array($page, [$this->main_slug, 'wwr-remove-watermarks'], true)) {
            return;
        }
    }

    // ── 注册设置 ─────────────────────────────────────────────────────

    public function register_settings(): void {
        register_setting(
            $this->option_group,
            WWR_SETTINGS_OPTION_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default'           => [
                    'api_key'        => '',
                    'model'          => WWR_DEFAULT_MODEL,
                    'resolution'     => WWR_DEFAULT_RESOLUTION,
                    'quality'        => WWR_DEFAULT_QUALITY,
                    'parallel_tasks' => 10,
                ],
            ]
        );

        // API Key 配置区域
        add_settings_section(
            'wwr_api_section',
            'Pixertor-ToAPIs API 配置',
            [$this, 'render_api_section_description'],
            $this->page_slug
        );

        add_settings_field(
            'wwr_api_key',
            'API Key',
            [$this, 'render_api_key_field'],
            $this->page_slug,
            'wwr_api_section'
        );

        add_settings_field(
            'wwr_model',
            '模型名称',
            [$this, 'render_model_field'],
            $this->page_slug,
            'wwr_api_section'
        );

        // 生成默认参数区域
        add_settings_section(
            'wwr_defaults_section',
            '生成默认参数',
            [$this, 'render_defaults_section_description'],
            $this->page_slug
        );

        add_settings_field(
            'wwr_resolution',
            '默认分辨率',
            [$this, 'render_resolution_field'],
            $this->page_slug,
            'wwr_defaults_section'
        );

        add_settings_field(
            'wwr_quality',
            '默认画质',
            [$this, 'render_quality_field'],
            $this->page_slug,
            'wwr_defaults_section'
        );

        add_settings_field(
            'wwr_parallel_tasks',
            '最大并发任务数',
            [$this, 'render_parallel_tasks_field'],
            $this->page_slug,
            'wwr_defaults_section'
        );
    }

    // ── 数据清理 ────────────────────────────────────────────────────

    public function sanitize_settings(array $input): array {
        $sanitized = [];

        $sanitized['api_key']    = sanitize_text_field($input['api_key'] ?? '');
        $sanitized['model']      = sanitize_text_field($input['model'] ?? WWR_DEFAULT_MODEL);
        if (empty($sanitized['model'])) {
            $sanitized['model'] = WWR_DEFAULT_MODEL;
        }
        $sanitized['resolution'] = in_array($input['resolution'] ?? '', ['1k', '2k', '4k'], true)
            ? $input['resolution']
            : WWR_DEFAULT_RESOLUTION;
        $sanitized['quality']    = in_array($input['quality'] ?? '', ['low', 'medium', 'high'], true)
            ? $input['quality']
            : WWR_DEFAULT_QUALITY;
        $sanitized['parallel_tasks'] = min(20, max(1, (int) ($input['parallel_tasks'] ?? 10)));

        return $sanitized;
    }

    // ── 区域描述 ─────────────────────────────────────────────────────

    public function render_api_section_description(): void {
        echo '<p>请输入您的 Pixertor-ToAPIs API Key。您可以从 Pixertor-ToAPIs 控制台获取。</p>';
        echo '<p style="margin-top:8px;">';
        echo '还没有 Token？';
        echo ' <a href="https://toapis.com/login?aff=rmQP" target="_blank" rel="noopener noreferrer" class="button button-small" style="vertical-align:middle;">快速购买 Token</a>';
        echo '</p>';
    }

    public function render_defaults_section_description(): void {
        echo '<p>以下默认参数将在处理图片时使用，您可以在任务页面按需调整。</p>';
    }

    // ── 字段渲染 ─────────────────────────────────────────────────────

    public function render_api_key_field(): void {
        $settings = get_option(WWR_SETTINGS_OPTION_KEY, []);
        $api_key  = esc_attr($settings['api_key'] ?? '');
        ?>
        <div class="wwr-api-key-row">
            <input
                type="password"
                id="wwr_api_key"
                name="<?php echo esc_attr(WWR_SETTINGS_OPTION_KEY . '[api_key]'); ?>"
                value="<?php echo $api_key; ?>"
                class="regular-text"
                style="width: 380px;"
                placeholder="sk-..."
                autocomplete="off"
            />
            <button
                type="button"
                id="wwr-test-api-btn"
                class="button button-secondary"
                style="margin-left: 8px;"
            >
                测试 API 连接
            </button>
            <span id="wwr-test-result" style="margin-left: 10px; font-weight: 500;"></span>
        </div>
        <p class="description">
            API Key 加密存储在数据库中，不会公开暴露。
        </p>
        <script>
        jQuery(function($) {
            $('#wwr-test-api-btn').on('click', function() {
                var $btn    = $(this);
                var $result = $('#wwr-test-result');

                $btn.prop('disabled', true).text('测试中...');
                $result.text('').removeClass('wwr-success wwr-error');

                $.post(ajaxurl, {
                    action: 'wwr_test_api',
                    _ajax_nonce: '<?php echo wp_create_nonce('wwr_test_api'); ?>'
                })
                .done(function(res) {
                    if (res.success) {
                        $result.text(res.data.message).addClass('wwr-success').css('color', '#008a20');
                    } else {
                        $result.text(res.data.message).addClass('wwr-error').css('color', '#d63638');
                    }
                })
                .fail(function() {
                    $result.text('请求失败，请重试。')
                           .addClass('wwr-error').css('color', '#d63638');
                })
                .always(function() {
                    $btn.prop('disabled', false).text('测试 API 连接');
                });
            });
        });
        </script>
        <?php
    }

    public function render_model_field(): void {
        $settings = get_option(WWR_SETTINGS_OPTION_KEY, []);
        $model    = esc_attr($settings['model'] ?? WWR_DEFAULT_MODEL);
        ?>
        <input
            type="text"
            id="wwr_model"
            name="<?php echo esc_attr(WWR_SETTINGS_OPTION_KEY . '[model]'); ?>"
            value="<?php echo $model; ?>"
            class="regular-text"
            style="width: 380px;"
            placeholder="<?php echo esc_attr(WWR_DEFAULT_MODEL); ?>"
        />
        <p class="description">
            默认模型为 <code>gpt-image-2</code>，可根据需要更改为其他支持的模型名称。
        </p>
        <?php
    }

    public function render_resolution_field(): void {
        $settings   = get_option(WWR_SETTINGS_OPTION_KEY, []);
        $resolution = $settings['resolution'] ?? WWR_DEFAULT_RESOLUTION;
        $options    = [
            '1k' => '1K — 标准（最快）',
            '2k' => '2K — 高清',
            '4k' => '4K — 超高清',
        ];
        ?>
        <select
            id="wwr_resolution"
            name="<?php echo esc_attr(WWR_SETTINGS_OPTION_KEY . '[resolution]'); ?>"
        >
            <?php foreach ($options as $val => $label): ?>
                <option value="<?php echo esc_attr($val); ?>" <?php selected($resolution, $val); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function render_quality_field(): void {
        $settings = get_option(WWR_SETTINGS_OPTION_KEY, []);
        $quality  = $settings['quality'] ?? WWR_DEFAULT_QUALITY;
        $options  = [
            'low'    => '低 — 更快生成',
            'medium' => '中 — 平衡',
            'high'   => '高 — 最佳画质（推荐）',
        ];
        ?>
        <select
            id="wwr_quality"
            name="<?php echo esc_attr(WWR_SETTINGS_OPTION_KEY . '[quality]'); ?>"
        >
            <?php foreach ($options as $val => $label): ?>
                <option value="<?php echo esc_attr($val); ?>" <?php selected($quality, $val); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function render_parallel_tasks_field(): void {
        $settings       = get_option(WWR_SETTINGS_OPTION_KEY, []);
        $parallel_tasks = (int) ($settings['parallel_tasks'] ?? 10);
        ?>
        <input
            type="number"
            id="wwr_parallel_tasks"
            name="<?php echo esc_attr(WWR_SETTINGS_OPTION_KEY . '[parallel_tasks]'); ?>"
            value="<?php echo esc_attr($parallel_tasks); ?>"
            min="1"
            max="20"
            step="1"
            style="width: 80px;"
        />
        <p class="description">
            同时处理的图片数量（1–20）。数值越高处理速度越快，但会消耗更多 API 资源。
        </p>
        <?php
    }

    // ── 页面渲染 ─────────────────────────────────────────────────────

    public function render_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('您没有足够的权限访问此页面。');
        }
        ?>
        <div class="wrap">
            <h1>水印移除 — API 配置</h1>

            <form method="post" action="options.php">
                <?php
                settings_fields($this->option_group);
                do_settings_sections($this->page_slug);
                submit_button('保存设置');
                ?>
            </form>

            <hr style="margin-top: 30px;" />
            <h2>使用说明</h2>
            <ol style="max-width: 720px; line-height: 1.8;">
                <li>前往 <strong>水印移除 → 任务处理</strong> 查看所有商品图片。</li>
                <li>勾选需要处理的图片。</li>
                <li>点击<strong>"开始移除水印"</strong> — 插件会将图片上传至 API，调用 AI 模型进行编辑，并下载处理结果。</li>
                <li>处理后的图片将保存到 WordPress 媒体库，并替换原始商品图片。</li>
            </ol>

            <p style="margin-top: 16px;">
                <strong>当前模型：</strong>
                <code><?php echo esc_html(get_option(WWR_SETTINGS_OPTION_KEY, [])['model'] ?? WWR_DEFAULT_MODEL); ?></code>
            </p>
        </div>
        <?php
    }

    // ── AJAX: 测试 API 连接 ──────────────────────────────────────────

    public function ajax_test_api(): void {
        check_ajax_referer('wwr_test_api');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => '权限不足。']);
        }

        $result = WWR_API::test_connection();
        $result['success'] ? wp_send_json_success($result) : wp_send_json_error($result);
    }
}
