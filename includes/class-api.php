<?php
/**
 * API communication handler for the Pixertor-ToAPIs GPT-Image-2 endpoints.
 *
 * Covers three operations:
 *  1. Upload a local image file → public URL.
 *  2. Create a generation (editing) task with reference images.
 *  3. Poll a task until completion and return the result image URL.
 *
 * @package WWR
 * @since  1.0.0
 */

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

class WWR_API {

    // ── Configuration ──────────────────────────────────────────────────

    /**
     * Retrieve the stored API key (or empty string).
     */
    public static function get_api_key(): string {
        $settings = get_option(WWR_SETTINGS_OPTION_KEY, []);
        return isset($settings['api_key']) ? trim($settings['api_key']) : '';
    }

    /**
     * Retrieve default resolution from settings.
     */
    public static function get_resolution(): string {
        $settings = get_option(WWR_SETTINGS_OPTION_KEY, []);
        return $settings['resolution'] ?? WWR_DEFAULT_RESOLUTION;
    }

    /**
     * Retrieve default quality from settings.
     */
    public static function get_quality(): string {
        $settings = get_option(WWR_SETTINGS_OPTION_KEY, []);
        return $settings['quality'] ?? WWR_DEFAULT_QUALITY;
    }

    /**
     * Retrieve model name from settings.
     */
    public static function get_model(): string {
        $settings = get_option(WWR_SETTINGS_OPTION_KEY, []);
        return $settings['model'] ?? WWR_DEFAULT_MODEL;
    }

    /**
     * Shared request headers for JSON calls.
     */
    protected static function json_headers(): array {
        return [
            'Authorization' => 'Bearer ' . self::get_api_key(),
            'Content-Type'  => 'application/json',
        ];
    }

    // ── 1. Image Upload ────────────────────────────────────────────────

    /**
     * Upload a local image file to Pixertor-ToAPIs and return its public URL.
     *
     * @param string $file_path Absolute path to a JPEG / PNG / WebP / GIF file.
     * @return array{success: bool, url?: string, error?: string}
     */
    public static function upload_image(string $file_path): array {
        $api_key = self::get_api_key();
        if (empty($api_key)) {
            return ['success' => false, 'error' => 'API Key 未配置。'];
        }

        if (!file_exists($file_path) || !is_readable($file_path)) {
            return ['success' => false, 'error' => '图片文件未找到或不可读。'];
        }

        // Validate file type.
        $mime = wp_check_filetype($file_path);
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($mime['type'], $allowed, true)) {
            return [
                'success' => false,
                'error'   => sprintf(
                    '不支持的图片类型：%s。仅允许：JPEG, PNG, WebP, GIF。',
                    $mime['type']
                ),
            ];
        }

        // Build multipart body for cURL (supports file streams natively).
        $curl_file = curl_file_create(
            $file_path,
            $mime['type'],
            basename($file_path)
        );

        $post_fields = [
            'file'    => $curl_file,
            'purpose' => 'generation',
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => WWR_TOAPIS_API_BASE . '/uploads/images',
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post_fields,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $api_key],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            return ['success' => false, 'error' => $curl_error];
        }

        $body = json_decode($response, true);

        if ($http_code !== 200 || empty($body['success']) || empty($body['data']['url'])) {
            $message = $body['message'] ?? $body['error']['message'] ?? '未知上传错误。';
            return ['success' => false, 'error' => $message];
        }

        return [
            'success' => true,
            'url'     => $body['data']['url'],
        ];
    }

    // ── 2. Create Generation Task ──────────────────────────────────────

    /**
     * Create an image editing task (watermark removal) via GPT-Image-2.
     *
     * @param string $reference_url Public URL of the image to edit.
     * @param string $prompt        Editing instruction for the model.
     * @param string $size          Aspect ratio, e.g. "1:1", "4:3", or "auto".
     * @param string $resolution    "1k", "2k", or "4k".
     * @param string $quality       "low", "medium", or "high".
     * @return array{success: bool, task_id?: string, error?: string}
     */
    public static function create_task(
        string $reference_url,
        string $prompt,
        string $size = 'auto',
        ?string $resolution = null,
        ?string $quality = null
    ): array {
        $api_key = self::get_api_key();
        if (empty($api_key)) {
            return ['success' => false, 'error' => 'API Key 未配置。'];
        }

        $resolution = $resolution ?? self::get_resolution();
        $quality    = $quality ?? self::get_quality();

        $payload = [
            'model'           => self::get_model(),
            'prompt'          => $prompt,
            'image_urls'      => [$reference_url],
            'size'            => $size,
            'resolution'      => $resolution,
            'quality'         => $quality,
            'n'               => 1,
            'output_format'   => 'png',
            'response_format' => 'url',
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => WWR_TOAPIS_API_BASE . '/images/generations',
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => wp_json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            return ['success' => false, 'error' => $curl_error];
        }

        $body = json_decode($response, true);

        if ($http_code !== 200 || empty($body['id'])) {
            $message = $body['error']['message'] ?? '创建生成任务失败。';
            return ['success' => false, 'error' => $message];
        }

        return [
            'success' => true,
            'task_id' => $body['id'],
        ];
    }

    // ── 3. Poll Task Status ────────────────────────────────────────────

    /**
     * Query a generation task by its ID.
     *
     * @param string $task_id The task ID returned by create_task().
     * @return array{
     *     success: bool,
     *     status?: string,
     *     progress?: int,
     *     image_url?: string,
     *     error?: string
     * }
     */
    public static function query_task(string $task_id): array {
        $api_key = self::get_api_key();
        if (empty($api_key)) {
            return ['success' => false, 'error' => 'API Key 未配置。'];
        }

        $url = WWR_TOAPIS_API_BASE . '/images/generations/' . rawurlencode($task_id);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $api_key],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            return ['success' => false, 'error' => $curl_error];
        }

        $body = json_decode($response, true);

        if ($http_code !== 200) {
            $message = $body['error']['message'] ?? '查询任务状态失败。';
            return ['success' => false, 'error' => $message];
        }

        $status = $body['status'] ?? 'unknown';

        if ($status === 'completed') {
            $image_url = $body['result']['data'][0]['url'] ?? '';
            if (empty($image_url)) {
                return ['success' => false, 'error' => '任务已完成但未返回图片 URL。'];
            }
            return [
                'success'   => true,
                'status'    => 'completed',
                'progress'  => 100,
                'image_url' => $image_url,
            ];
        }

        if ($status === 'failed') {
            $error_msg = $body['error']['message'] ?? '未知生成失败。';
            return ['success' => false, 'status' => 'failed', 'error' => $error_msg];
        }

        // Still processing: queued or in_progress.
        return [
            'success'  => true,
            'status'   => $status,
            'progress' => (int) ($body['progress'] ?? 0),
        ];
    }

    // ── 4. Full Pipeline (Upload → Create → Poll → Download URL) ──────

    /**
     * Run the full watermark-removal pipeline for a single attachment.
     *
     * Steps:
     *  1. Upload the attachment file to Pixertor-ToAPIs.
     *  2. Create a generation / editing task.
     *  3. Poll until completion (or failure / timeout).
     *
     * @param int    $attachment_id WordPress attachment ID.
     * @param string $size          Aspect ratio override (empty = auto-detect).
     * @return array{success: bool, image_url?: string, task_id?: string, error?: string}
     */
    public static function process_image(int $attachment_id, string $size = 'auto'): array {
        // Step 1 — upload.
        $file_path = get_attached_file($attachment_id);
        if (!$file_path) {
            return ['success' => false, 'error' => '无法定位附件文件。'];
        }

        $upload = self::upload_image($file_path);
        if (!$upload['success']) {
            return $upload;
        }

        $reference_url = $upload['url'];

        // Determine aspect ratio from actual image dimensions if auto.
        if ($size === 'auto') {
            $size = self::guess_aspect_ratio($file_path);
        }

        // Step 2 — create task.
        $prompt = self::build_prompt();

        $task = self::create_task($reference_url, $prompt, $size);
        if (!$task['success']) {
            return $task;
        }

        $task_id = $task['task_id'];

        // Step 3 — poll until done.
        for ($i = 0; $i < WWR_POLL_MAX_ATTEMPTS; $i++) {
            sleep(WWR_POLL_INTERVAL);

            $result = self::query_task($task_id);

            if (!$result['success']) {
                // Terminal failure from the API.
                return array_merge($result, ['task_id' => $task_id]);
            }

            if ($result['status'] === 'completed') {
                return [
                    'success'   => true,
                    'image_url' => $result['image_url'],
                    'task_id'   => $task_id,
                ];
            }

            // Otherwise keep polling (queued / in_progress).
        }

        return [
            'success' => false,
            'error'   => '任务超时 — AI 未在预期时间内完成处理。',
            'task_id' => $task_id,
        ];
    }

    // ── 5. Test API Key ────────────────────────────────────────────────

    /**
     * Perform a lightweight API call to verify the key works.
     *
     * Simply uploads a tiny blank PNG (generated in memory) and
     * asserts that the upload endpoint accepts the key.
     *
     * @return array{success: bool, message: string}
     */
    public static function test_connection(): array {
        $api_key = self::get_api_key();
        if (empty($api_key)) {
            return ['success' => false, 'message' => '请先输入 API Key。'];
        }

        // Create a minimal 1×1 PNG in a temp file.
        // 最小的 1×1 透明 PNG
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg=='
        );

        $tmp = wp_tempnam('wwr_test_');
        if (!$tmp) {
            return ['success' => false, 'message' => '无法创建临时文件。'];
        }
        file_put_contents($tmp, $png);

        $result = self::upload_image($tmp);
        @unlink($tmp);

        if ($result['success']) {
            return ['success' => true, 'message' => 'API 连接成功！Key 有效。'];
        }

        return [
            'success' => false,
            'message' => sprintf(
                /* translators: %s: error detail from API */
                'API 测试失败：%s',
                $result['error']
            ),
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /**
     * Map image dimensions to the closest supported aspect-ratio string.
     */
    public static function guess_aspect_ratio(string $file_path): string {
        $info = @getimagesize($file_path);
        if (!$info) {
            return '1:1';
        }

        $w = (int) $info[0];
        $h = (int) $info[1];
        if ($w <= 0 || $h <= 0) {
            return '1:1';
        }

        $ratio = $w / $h;

        // Supported ratios and their numeric values.
        $ratios = [
            '1:1'  => 1.0,
            '3:2'  => 1.5,
            '2:3'  => 0.6667,
            '4:3'  => 1.3333,
            '3:4'  => 0.75,
            '5:4'  => 1.25,
            '4:5'  => 0.8,
            '16:9' => 1.7778,
            '9:16' => 0.5625,
            '2:1'  => 2.0,
            '1:2'  => 0.5,
            '21:9' => 2.3333,
            '9:21' => 0.4286,
        ];

        $closest = '1:1';
        $smallest_diff = PHP_FLOAT_MAX;

        foreach ($ratios as $key => $val) {
            $diff = abs($ratio - $val);
            if ($diff < $smallest_diff) {
                $smallest_diff = $diff;
                $closest = $key;
            }
        }

        return $closest;
    }

    /**
     * Build the watermark-removal prompt for the AI.
     */
    protected static function build_prompt(): string {
        return trim(
            'Remove ALL watermarks, logos, text overlays, copyright marks, and stamps from this image. ' .
            'Keep every other detail — the product, colors, textures, lighting, background — EXACTLY as-is. ' .
            'Do NOT crop, resize, or change the composition. ' .
            'The output must be indistinguishable from the original except watermarks are gone.'
        );
    }
}
