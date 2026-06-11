<?php
/*
Plugin Name: ACF JSON CSV Integrator
Plugin URI: https://github.com/aurora-ship-sato/acf-json-csv-integrator
Description: An integrated tool to export and import ACF (Advanced Custom Fields) flexible content and repeaters seamlessly as JSON-formatted strings via CSV.
Author: Gemini
Version: 1.0.13
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
}

// ==========================================
// 2. 各プラグインモジュールのロード
// ==========================================

// エクスポート側モジュール
if (file_exists(AJCI_PLUGIN_DIR . 'exporter/wp-csv-exporter.php')) {
  require_once AJCI_PLUGIN_DIR . 'exporter/wp-csv-exporter.php';
}

// インポート側モジュール
if (file_exists(AJCI_PLUGIN_DIR . 'importer/rs-csv-importer.php')) {
  require_once AJCI_PLUGIN_DIR . 'importer/rs-csv-importer.php';
}
