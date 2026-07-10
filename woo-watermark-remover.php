<?php
/**
 * Plugin Name: Pixertor - WooCommerce Watermark Remover
 * Plugin URI: https://github.com/Li-NingBo/Pixertor-WooCommerce-Watermark-Remover
 * Description: AI-powered watermark removal for WooCommerce product images. Uses GPT-Image-2 via Pixertor-ToAPIs to intelligently remove watermarks from product featured images, gallery images, and content images.
 * Version: 1.3.0
 * Author:
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * License: GPL v2 or later
 * Text Domain: wwr
 * Domain Path: /languages
 */

// ── Prevent direct access ───────────────────────────────────────────────
if (!defined('ABSPATH')) {
    exit;
}

// ── Plugin constants ─────────────────────────────────────────────────────
define('WWR_VERSION', '1.3.0');
define('WWR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WWR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WWR_TOAPIS_API_BASE', 'https://toapis.com/v1');
define('WWR_SETTINGS_OPTION_KEY', 'wwr_settings');
define('WWR_DEFAULT_RESOLUTION', '1k');
define('WWR_DEFAULT_QUALITY', 'high');
define('WWR_DEFAULT_MODEL', 'gpt-image-2');
define('WWR_POLL_INTERVAL', 3);       // seconds between status polls
define('WWR_POLL_MAX_ATTEMPTS', 40);  // max ~2 min timeout

// ── Autoloader ───────────────────────────────────────────────────────────
spl_autoload_register(function ($class) {
    $prefix = 'WWR_';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $slug = strtolower(
        str_replace('_', '-', substr($class, strlen($prefix)))
    );

    $file = WWR_PLUGIN_DIR . 'includes/class-' . $slug . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// ── Bootstrap ────────────────────────────────────────────────────────────
add_action('plugins_loaded', 'wwr_init');

function wwr_init(): void {
    // WooCommerce is required — bail with a notice if missing.
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html__(
                    'WooCommerce Watermark Remover 需要安装并激活 WooCommerce 才能使用。',
                    'wwr'
                )
            );
        });
        return;
    }

    // Load text domain for i18n.
    load_plugin_textdomain('wwr', false, dirname(plugin_basename(__FILE__)) . '/languages');

    // Boot classes.
    new WWR_Admin_Settings();
    new WWR_Product_Page();
}

// ── Activation guard ─────────────────────────────────────────────────────
register_activation_hook(__FILE__, function () {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__(
                'WooCommerce Watermark Remover 需要安装并激活 WooCommerce 才能使用。',
                'wwr'
            )
        );
    }
});
