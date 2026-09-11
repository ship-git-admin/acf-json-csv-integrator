<?php

/**
 * Server-side security and state management for CSV imports.
 */
class AJCI_Import_Security
{
    const JOB_OPTION_PREFIX = 'ajci_import_job_';
    const LOCK_OPTION_PREFIX = 'ajci_import_lock_';
    const JOB_ATTACHMENT_META = '_ajci_import_job_id';
    const JOB_FILE_HASH_META = '_ajci_import_job_sha256';
    const JOB_TTL = 3600;
    const LOCK_TTL = 300;

    /**
     * Validate the AJAX request envelope before opening any file or loading media helpers.
     *
     * @param string $operation chunk or cleanup.
     * @return string
     */
    public static function require_ajax_request($operation)
    {
        if (!in_array($operation, array('chunk', 'cleanup'), true)) {
            wp_send_json_error(array('code' => 'invalid_operation'), 400);
        }

        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_send_json_error(array('code' => 'method_not_allowed'), 405);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('code' => 'forbidden'), 403);
        }

        if (!isset($_POST['job_id']) || !is_string($_POST['job_id'])) {
            wp_send_json_error(array('code' => 'invalid_job_id'), 400);
        }

        $job_id = wp_unslash($_POST['job_id']);
        if (!preg_match('/\A[a-f0-9]{32}\z/', $job_id)) {
            wp_send_json_error(array('code' => 'invalid_job_id'), 400);
        }

        if (!isset($_POST['_ajax_nonce']) || !is_string($_POST['_ajax_nonce'])) {
            wp_send_json_error(array('code' => 'invalid_nonce'), 403);
        }

        $nonce = wp_unslash($_POST['_ajax_nonce']);
        $action = 'ajci_csv_import:' . $operation . ':' . $job_id;
        if (false === wp_verify_nonce($nonce, $action)) {
            wp_send_json_error(array('code' => 'invalid_nonce'), 403);
        }

        return $job_id;
    }

    /**
     * Validate the original upload before WordPress creates its temporary attachment.
     *
     * @return true|WP_Error
     */
    public static function validate_upload_input()
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return new WP_Error('method_not_allowed', 'アップロードはPOSTで実行してください。');
        }

        if (!isset($_FILES['import']) || !is_array($_FILES['import'])) {
            return new WP_Error('missing_upload', 'CSVファイルが指定されていません。');
        }

        $upload = $_FILES['import'];
        if (!isset($upload['error']) || (int) $upload['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_error', 'CSVファイルのアップロードに失敗しました。');
        }

        if (!isset($upload['name']) || !is_string($upload['name']) || !preg_match('/\.csv\z/i', $upload['name'])) {
            return new WP_Error('invalid_extension', '拡張子が.csvのファイルを指定してください。');
        }

        if (!isset($upload['size']) || (int) $upload['size'] <= 0 || (int) $upload['size'] > wp_max_upload_size()) {
            return new WP_Error('invalid_size', 'CSVファイルのサイズが不正、または上限を超えています。');
        }

        if (!isset($upload['tmp_name']) || !is_string($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
            return new WP_Error('invalid_upload', 'アップロードされたファイルを確認できません。');
        }

        return true;
    }

    /**
     * Create a server-controlled import job for a newly uploaded attachment.
     * Secrets supplied for remote media access are deliberately not stored here.
     *
     * @param int    $attachment_id
     * @param string $file
     * @param array  $preflight
     * @return array|WP_Error
     */
    public static function create_job($attachment_id, $file, $preflight)
    {
        $attachment_error = self::validate_attachment_candidate($attachment_id, $file);
        if (is_wp_error($attachment_error)) {
            return $attachment_error;
        }

        $job_id = self::new_job_id();
        if (!$job_id) {
            return new WP_Error('job_id_failed', 'インポートジョブIDを生成できません。');
        }

        $sha256 = hash_file('sha256', $file);
        if (!is_string($sha256) || !preg_match('/\A[a-f0-9]{64}\z/', $sha256)) {
            return new WP_Error('file_hash_failed', 'CSVファイルの検証に失敗しました。');
        }
        if (!empty($preflight['file_sha256']) && (!is_string($preflight['file_sha256']) || !hash_equals($preflight['file_sha256'], $sha256))) {
            return new WP_Error('file_changed', '事前検証後にCSVファイルが変更されています。');
        }

        $now = time();
        $job = array(
            'job_id'          => $job_id,
            'user_id'         => (int) get_current_user_id(),
            'blog_id'         => function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1,
            'attachment_id'   => (int) $attachment_id,
            'file_path'       => $file,
            'file_sha256'     => $sha256,
            'created_at'      => $now,
            'expires_at'      => $now + self::JOB_TTL,
            'status'          => (isset($preflight['total_data_rows']) && (int) $preflight['total_data_rows'] === 0) ? 'complete' : 'ready',
            'next_offset'     => 1,
            'processed_total' => 0,
            'total_rows'      => isset($preflight['total_data_rows']) ? (int) $preflight['total_data_rows'] : 0,
            'mode'            => isset($preflight['mode']) ? $preflight['mode'] : 'post',
            'headers'         => isset($preflight['headers']) ? array_values($preflight['headers']) : array(),
            'schema_fingerprint' => isset($preflight['schema_fingerprint']) ? $preflight['schema_fingerprint'] : '',
            'options_page'    => isset($preflight['options_page']) ? $preflight['options_page'] : null,
        );

        if (!add_option(self::job_option_name($job_id), $job, '', 'no')) {
            return new WP_Error('job_store_failed', 'インポートジョブを保存できません。');
        }

        if (false === add_post_meta($attachment_id, self::JOB_ATTACHMENT_META, $job_id, true)
            || false === add_post_meta($attachment_id, self::JOB_FILE_HASH_META, $sha256, true)) {
            delete_option(self::job_option_name($job_id));
            return new WP_Error('attachment_binding_failed', '一時CSVとインポートジョブを紐付けできません。');
        }

        return $job;
    }

    /**
     * Load and authorize a job for the current request.
     *
     * @param string $job_id
     * @param string $operation
     * @return array|WP_Error
     */
    public static function authorize_job($job_id, $operation)
    {
        $job = self::get_job($job_id);
        if (is_wp_error($job)) {
            return $job;
        }

        $current_blog_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
        if ((int) $job['user_id'] !== (int) get_current_user_id()
            || (int) $job['blog_id'] !== $current_blog_id) {
            return new WP_Error('job_forbidden', 'インポートジョブを利用できません.');
        }

        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', '権限がありません。');
        }

        if (empty($job['expires_at']) || (int) $job['expires_at'] < time()) {
            return new WP_Error('job_expired', 'インポートジョブの有効期限が切れています。');
        }

        $allowed_statuses = $operation === 'chunk'
            ? array('ready')
            : array('complete');
        if (!in_array($job['status'], $allowed_statuses, true)) {
            return new WP_Error('invalid_job_state', 'インポートジョブの状態が不正です。');
        }

        $file_error = self::validate_bound_attachment($job);
        if (is_wp_error($file_error)) {
            return $file_error;
        }

        if (!empty($job['schema_fingerprint']) && $job['mode'] === 'options') {
            if (!class_exists('AJCI_Options_Schema') || empty($job['options_page']['menu_slug'])) {
                return new WP_Error('schema_missing', 'オプションページの検証情報がありません。');
            }

            $schema = AJCI_Options_Schema::get_schema($job['options_page']['menu_slug']);
            if (is_wp_error($schema) || !hash_equals($job['schema_fingerprint'], $schema['fingerprint'])) {
                return new WP_Error('schema_changed', 'ACFの登録情報が変更されたため、再検証が必要です。');
            }
        }

        return $job;
    }

    /**
     * Acquire an atomic per-job lock and move a job into processing state.
     *
     * @param array $job
     * @param int   $requested_offset
     * @return array|WP_Error Job and lock token.
     */
    public static function begin_chunk($job, $requested_offset)
    {
        if (!is_int($requested_offset) || $requested_offset < 1) {
            return new WP_Error('invalid_offset', '進捗位置が不正です。');
        }

        $current_job = self::get_job($job['job_id']);
        if (is_wp_error($current_job)) {
            return $current_job;
        }
        if ($current_job['status'] !== 'ready') {
            return new WP_Error('invalid_job_state', 'インポートジョブの状態が不正です。');
        }
        $job = $current_job;
        if ((int) $job['next_offset'] !== $requested_offset) {
            return new WP_Error('stale_offset', 'このインポートバッチは再実行できません。画面を再読み込みして再検証してください。');
        }

        $token = self::new_lock_token();
        if (!$token) {
            return new WP_Error('lock_failed', 'インポートの排他制御を開始できません。');
        }

        $lock_name = self::lock_option_name($job['job_id']);
        $existing = get_option($lock_name, false);
        if (is_array($existing) && !empty($existing['expires_at']) && (int) $existing['expires_at'] < time()) {
            delete_option($lock_name);
        }

        if (!add_option($lock_name, array('token' => $token, 'expires_at' => time() + self::LOCK_TTL), '', 'no')) {
            return new WP_Error('job_locked', '同じインポートが処理中です。');
        }

        // A request may have been authorized before another request completed.
        // Re-read after acquiring the lock so a stale request cannot replay a chunk.
        $current_job = self::get_job($job['job_id']);
        if (is_wp_error($current_job) || $current_job['status'] !== 'ready'
            || (int) $current_job['next_offset'] !== $requested_offset) {
            self::release_lock($job['job_id'], $token);
            return new WP_Error('stale_offset', 'このインポートバッチは再実行できません。');
        }
        $job = $current_job;
        $job['status'] = 'processing';
        if (!update_option(self::job_option_name($job['job_id']), $job, false)) {
            self::release_lock($job['job_id'], $token);
            return new WP_Error('job_update_failed', 'インポート状態を更新できません。');
        }

        return array('job' => $job, 'token' => $token);
    }

    /**
     * Persist a completed chunk. A lost response cannot be replayed with the old offset.
     *
     * @param array  $job
     * @param string $token
     * @param int    $next_offset
     * @param int    $processed
     * @return array|WP_Error
     */
    public static function complete_chunk($job, $token, $next_offset, $processed)
    {
        if (!self::has_lock($job['job_id'], $token)) {
            return new WP_Error('lock_lost', 'インポートの排他制御が失われました。');
        }

        $job['next_offset'] = (int) $next_offset;
        $job['processed_total'] = (int) $job['processed_total'] + (int) $processed;
        $job['status'] = $job['next_offset'] > (int) $job['total_rows'] ? 'complete' : 'ready';

        if (!update_option(self::job_option_name($job['job_id']), $job, false)) {
            $job['status'] = 'failed';
            update_option(self::job_option_name($job['job_id']), $job, false);
            self::release_lock($job['job_id'], $token);
            return new WP_Error('job_update_failed', 'インポート状態を保存できません。');
        }

        self::release_lock($job['job_id'], $token);
        return $job;
    }

    /**
     * Mark a failed chunk and release the lock. No automatic retry is enabled.
     *
     * @param array  $job
     * @param string $token
     * @return void
     */
    public static function fail_chunk($job, $token)
    {
        if (self::has_lock($job['job_id'], $token)) {
            $job['status'] = 'failed';
            update_option(self::job_option_name($job['job_id']), $job, false);
            self::release_lock($job['job_id'], $token);
        }
    }

    /**
     * Delete only the temporary attachment bound to a completed job.
     *
     * @param array $job
     * @return true|WP_Error
     */
    public static function cleanup_job($job)
    {
        if ($job['status'] !== 'complete' || (int) $job['next_offset'] <= (int) $job['total_rows']) {
            return new WP_Error('invalid_job_state', '完了していないインポートはcleanupできません。');
        }

        $lock_token = self::new_lock_token();
        if (!$lock_token) {
            return new WP_Error('lock_failed', 'cleanupの排他制御を開始できません。');
        }

        $lock_name = self::lock_option_name($job['job_id']);
        if (!add_option($lock_name, array('token' => $lock_token, 'expires_at' => time() + self::LOCK_TTL), '', 'no')) {
            return new WP_Error('job_locked', '同じインポートが処理中です。');
        }

        $job['status'] = 'cleanup_pending';
        update_option(self::job_option_name($job['job_id']), $job, false);

        $file_error = self::validate_bound_attachment($job);
        if (is_wp_error($file_error)) {
            $job['status'] = 'complete';
            update_option(self::job_option_name($job['job_id']), $job, false);
            self::release_lock($job['job_id'], $lock_token);
            return new WP_Error('file_changed', 'cleanup前に一時CSVが変更されています。');
        }

        if (function_exists('wp_import_cleanup')) {
            wp_import_cleanup((int) $job['attachment_id']);
        }

        $remaining = get_post((int) $job['attachment_id']);
        if ($remaining || file_exists($job['file_path'])) {
            $job['status'] = 'complete';
            update_option(self::job_option_name($job['job_id']), $job, false);
            self::release_lock($job['job_id'], $lock_token);
            return new WP_Error('cleanup_failed', '一時CSVを削除できませんでした。');
        }

        $job['status'] = 'cleaned';
        update_option(self::job_option_name($job['job_id']), $job, false);
        delete_option(self::job_option_name($job['job_id']));
        self::release_lock($job['job_id'], $lock_token);
        return true;
    }

    /**
     * Remove expired jobs using server-side records. The browser is not trusted for this task.
     *
     * @return void
     */
    public static function cleanup_expired_jobs()
    {
        global $wpdb;

        if (!function_exists('wp_import_cleanup') && defined('ABSPATH')) {
            require_once ABSPATH . 'wp-admin/includes/import.php';
        }

        $like = $wpdb->esc_like(self::JOB_OPTION_PREFIX) . '%';
        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $like
        ));

        foreach ((array) $names as $name) {
            $job = get_option($name);
            if (!is_array($job) || empty($job['job_id']) || empty($job['expires_at']) || (int) $job['expires_at'] >= time()) {
                continue;
            }

            $lock = get_option(self::lock_option_name($job['job_id']), false);
            if (is_array($lock) && !empty($lock['expires_at']) && (int) $lock['expires_at'] >= time()) {
                continue;
            }

            $attachment = get_post((int) ($job['attachment_id'] ?? 0));
            $bound_job_id = $attachment ? get_post_meta($attachment->ID, self::JOB_ATTACHMENT_META, true) : '';
            $file_path = isset($job['file_path']) && is_string($job['file_path']) ? $job['file_path'] : '';
            $file_sha256 = isset($job['file_sha256']) && is_string($job['file_sha256']) ? $job['file_sha256'] : '';
            $cleaned = false;
            if ($attachment && $bound_job_id === $job['job_id']
                && self::validate_bound_attachment($job) === true
                && function_exists('wp_import_cleanup')) {
                wp_import_cleanup($attachment->ID);
                $cleaned = !get_post($attachment->ID) && ($file_path === '' || !file_exists($file_path));
            } elseif (!$attachment && $file_path !== '' && self::validate_upload_path($file_path) === true
                && $file_sha256 !== '' && hash_file('sha256', $file_path) === $file_sha256) {
                $cleaned = @unlink($file_path) || !file_exists($file_path);
            }
            if ($cleaned) {
                delete_option($name);
                delete_option(self::lock_option_name($job['job_id']));
            }
        }
    }

    /**
     * @param string $job_id
     * @return array|WP_Error
     */
    public static function get_job($job_id)
    {
        $job = get_option(self::job_option_name($job_id), false);
        if (!is_array($job) || empty($job['job_id']) || $job['job_id'] !== $job_id) {
            return new WP_Error('job_not_found', 'インポートジョブが見つかりません。');
        }
        return $job;
    }

    /**
     * Re-check the bound attachment immediately after a chunk lock is acquired.
     *
     * @param array $job
     * @return true|WP_Error
     */
    public static function verify_job_file($job)
    {
        return self::validate_bound_attachment($job);
    }

    /**
     * @param int    $attachment_id
     * @param string $file
     * @return true|WP_Error
     */
    private static function validate_attachment_candidate($attachment_id, $file)
    {
        $attachment = get_post((int) $attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return new WP_Error('invalid_attachment', '一時CSVの添付情報が不正です。');
        }

        if (get_post_meta($attachment_id, self::JOB_ATTACHMENT_META, true)) {
            return new WP_Error('attachment_already_bound', '添付ファイルは既に別のジョブへ紐付いています。');
        }

        return self::validate_upload_path($file);
    }

    /**
     * @param array $job
     * @return true|WP_Error
     */
    private static function validate_bound_attachment($job)
    {
        $attachment_id = (int) ($job['attachment_id'] ?? 0);
        $attachment = get_post($attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return new WP_Error('attachment_missing', 'インポート用一時CSVが見つかりません。');
        }

        $bound_job_id = get_post_meta($attachment_id, self::JOB_ATTACHMENT_META, true);
        $bound_hash = get_post_meta($attachment_id, self::JOB_FILE_HASH_META, true);
        if (!is_string($bound_job_id) || !hash_equals($job['job_id'], $bound_job_id)
            || !is_string($bound_hash) || !hash_equals($job['file_sha256'], $bound_hash)) {
            return new WP_Error('attachment_mismatch', 'インポート用一時CSVの紐付けを確認できません。');
        }

        $file = get_attached_file($attachment_id);
        if (!is_string($file) || $file !== $job['file_path']) {
            return new WP_Error('file_mismatch', 'インポート用ファイルが変更されています。');
        }

        $path_error = self::validate_upload_path($file);
        if (is_wp_error($path_error)) {
            return $path_error;
        }

        $sha256 = hash_file('sha256', $file);
        if (!is_string($sha256) || !hash_equals($job['file_sha256'], $sha256)) {
            return new WP_Error('file_changed', 'CSVファイルが変更されています。');
        }

        return true;
    }

    /**
     * @param string $file
     * @return true|WP_Error
     */
    private static function validate_upload_path($file)
    {
        if (!is_string($file) || !is_readable($file) || !file_exists($file)) {
            return new WP_Error('file_not_found', 'CSVファイルを読み込めません。');
        }

        $real_file = realpath($file);
        $upload_dir = wp_upload_dir();
        $real_base = isset($upload_dir['basedir']) ? realpath($upload_dir['basedir']) : false;
        if (!$real_file || !$real_base) {
            return new WP_Error('invalid_upload_path', 'CSVファイルの保存場所を確認できません。');
        }

        $base_prefix = trailingslashit($real_base);
        if (strpos($real_file, $base_prefix) !== 0 || is_link($file)) {
            return new WP_Error('invalid_upload_path', 'CSVファイルが許可された保存領域外にあります。');
        }

        return true;
    }

    /**
     * @return string|false
     */
    private static function new_job_id()
    {
        for ($i = 0; $i < 5; $i++) {
            try {
                $id = bin2hex(random_bytes(16));
            } catch (Exception $e) {
                $id = function_exists('wp_generate_uuid4') ? str_replace('-', '', wp_generate_uuid4()) : '';
            }
            if (preg_match('/\A[a-f0-9]{32}\z/', $id) && false === get_option(self::job_option_name($id), false)) {
                return $id;
            }
        }
        return false;
    }

    /**
     * @return string|false
     */
    private static function new_lock_token()
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (Exception $e) {
            return function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : false;
        }
    }

    private static function job_option_name($job_id)
    {
        return self::JOB_OPTION_PREFIX . $job_id;
    }

    private static function lock_option_name($job_id)
    {
        return self::LOCK_OPTION_PREFIX . $job_id;
    }

    private static function has_lock($job_id, $token)
    {
        $lock = get_option(self::lock_option_name($job_id), false);
        return is_array($lock) && isset($lock['token']) && hash_equals((string) $lock['token'], (string) $token);
    }

    private static function release_lock($job_id, $token)
    {
        if (self::has_lock($job_id, $token)) {
            delete_option(self::lock_option_name($job_id));
        }
    }
}

add_action('ajci_cleanup_expired_import_jobs', array('AJCI_Import_Security', 'cleanup_expired_jobs'));
add_action('init', function () {
    if (!wp_next_scheduled('ajci_cleanup_expired_import_jobs')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'ajci_cleanup_expired_import_jobs');
    }
});
