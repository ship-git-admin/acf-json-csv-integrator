<?php
/*
Plugin Name: ACF JSON CSV Integrator
Plugin URI: https://github.com/aurora-ship-sato/acf-json-csv-integrator
Description: An integrated tool to export and import ACF (Advanced Custom Fields) flexible content and repeaters seamlessly as JSON-formatted strings via CSV.
Author: Gemini
Version: 1.0.34
License: GPLv2 or later
Text Domain: acf-json-csv-integrator
Update URI: false
*/

if (!defined('ABSPATH')) {
  exit;
}

// 定数の定義
define('AJCI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AJCI_PLUGIN_URL', plugin_dir_url(__FILE__));

// ==========================================
// 1. GitHub プライベートリポジトリとのアップデート連携
// ==========================================
if (file_exists(AJCI_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php')) {
  require_once AJCI_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
  $myUpdateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/aurora-ship-sato/acf-json-csv-integrator',
    __FILE__,
    'acf-json-csv-integrator'
  );
  // flex-bannerと同じトークンで認証
  $myUpdateChecker->setAuthentication('REDACTED_GITHUB_TOKEN');
  $myUpdateChecker->setBranch('main');

  // クエリパラメータ ?force_update_check=1 が指定された場合、キャッシュを強制クリアしてGitHubに再問い合わせする
  if (isset($_GET['force_update_check']) && is_admin()) {
    if (function_exists('opcache_reset')) {
      @opcache_reset();
    }
    $myUpdateChecker->getUpdateState()->setLastCheckToZero();
    $result = $myUpdateChecker->checkForUpdates();

    add_action('admin_notices', function () use ($myUpdateChecker, $result) {
      echo '<div class="notice notice-warning" style="padding: 15px; border-left-color: #ffb900;">';
      echo '<h3 style="margin-top:0;">ACF JSON CSV Integrator - 自動更新デバッグ</h3>';
      echo '<p><strong>更新検出結果:</strong> ' . ($result ? '<span style="color:green;font-weight:bold;">検出あり</span>' : '<span style="color:red;font-weight:bold;">検出なし</span>') . '</p>';

      $state = $myUpdateChecker->getUpdateState();
      echo '<p><strong>最終チェック時間:</strong> ' . date('Y-m-d H:i:s', $state->getLastCheck()) . '</p>';

      $vcs = $myUpdateChecker->getVcsApi();
      if ($vcs) {
        echo '<p><strong>GitHubリポジトリ:</strong> ' . esc_html($vcs->getRepositoryUrl()) . '</p>';
        try {
          $latestTag = $vcs->getLatestTag();
          if ($latestTag) {
            echo '<p><strong>最新取得タグ:</strong> ' . esc_html($latestTag->name) . ' (バージョン: ' . esc_html($latestTag->version) . ')</p>';
          } else {
            echo '<p><strong>最新取得タグ:</strong> 取得失敗、または無し</p>';
          }
        } catch (\Exception $e) {
          echo '<p><strong>タグ取得エラー:</strong> ' . esc_html($e->getMessage()) . '</p>';
        }

        // WP通信の直接テスト
        $url = 'https://api.github.com/repos/aurora-ship-sato/acf-json-csv-integrator/tags';
        $options = array(
          'headers' => array(
            'Authorization' => 'Basic ' . base64_encode('aurora-ship-sato:REDACTED_GITHUB_TOKEN')
          ),
          'timeout' => 10
        );
        $response = wp_remote_get($url, $options);
        if (is_wp_error($response)) {
          echo '<p style="color:red;"><strong>WordPress HTTP通信エラー:</strong> ' . esc_html($response->get_error_message()) . '</p>';
        } else {
          $code = wp_remote_retrieve_response_code($response);
          $body = wp_remote_retrieve_body($response);
          echo '<p><strong>HTTP ステータスコード:</strong> ' . intval($code) . '</p>';
          if ($code !== 200) {
            echo '<pre style="background:#f4f4f4; padding:10px; border:1px solid #ccc; font-size:12px; max-height:200px; overflow:auto;">' . esc_html($body) . '</pre>';
          }
        }
      }
      echo '</div>';
    });
  }
}

// ==========================================
// 2. 各プラグインモジュールのロード
// ==========================================

// エクスポート側モジュール
if (file_exists(AJCI_PLUGIN_DIR . 'exporter/wp-csv-exporter.php')) {
  require_once AJCI_PLUGIN_DIR . 'exporter/wp-csv-exporter.php';
}

// インポート側モジュール
if (file_exists(AJCI_PLUGIN_DIR . 'importer/ajci-csv-importer.php')) {
  if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(AJCI_PLUGIN_DIR . 'importer/ajci-csv-importer.php', true);
    @opcache_invalidate(AJCI_PLUGIN_DIR . 'importer/class-ajci_csv_helper.php', true);
    @opcache_invalidate(AJCI_PLUGIN_DIR . 'importer/class-ajci_import_post_helper.php', true);
  }
  require_once AJCI_PLUGIN_DIR . 'importer/ajci-csv-importer.php';
}

// アップデート完了時にOPcacheをクリア
add_action('upgrader_process_complete', function ($upgrader_object, $options) {
  if (function_exists('opcache_reset')) {
    @opcache_reset();
  }
}, 10, 2);

// ==========================================
// DB内の破損したACFデータのクリーンアップ処理 (一時的)
// ==========================================
add_action('init', function () {
  if (isset($_GET['cleanup_acf_error']) && is_admin() && current_user_can('manage_options')) {
    // 1. オプションページの破損メタの削除
    delete_option('menu_archive_options_archive_lp_content');
    delete_option('_menu_archive_options_archive_lp_content');
    delete_option('options_archive_lp_content');
    delete_option('_options_archive_lp_content');

    // 2. ターム (ID: 379) の破損メタの削除
    delete_term_meta(379, 'archive_lp_content');
    delete_term_meta(379, '_archive_lp_content');

    // 3. 他のターム全般に破損メタが入ってしまった場合のための汎用クリーンアップ
    global $wpdb;
    $term_metas = $wpdb->get_results("SELECT term_id, meta_value FROM $wpdb->termmeta WHERE meta_key = 'archive_lp_content'");
    foreach ($term_metas as $tm) {
      $val = maybe_unserialize($tm->meta_value);
      if (is_array($val) && isset($val[0]) && is_array($val[0]) && isset($val[0]['acf_fc_layout'])) {
        delete_term_meta($tm->term_id, 'archive_lp_content');
        delete_term_meta($tm->term_id, '_archive_lp_content');
      }
    }

    wp_die('<h3>ACFの破損データのクリーンアップが完了しました。</h3><p>ブラウザの「戻る」ボタンで管理画面に戻り、通常通りアクセスできるかご確認ください。</p>');
  }
});
