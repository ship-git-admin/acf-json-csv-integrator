<?php
$errors = array();

if (
  isset($_POST['options_page_id']) &&
  is_user_logged_in() &&
  isset($_POST['_wpnonce']) &&
  wp_verify_nonce($_POST['_wpnonce'], 'csv_exporter') &&
  current_user_can('manage_options') // オプションデータのため administrator 相当権限が必要
) {
  check_admin_referer('csv_exporter');

  $post_id     = sanitize_text_field($_POST['options_page_id']);
  $string_code = sanitize_text_field($_POST['string_code'] ?? 'UTF-8');

  // ── セキュリティ：$post_id を登録済みオプションページのホワイトリストで検証 ──
  // 任意の wp_option が読み取られないよう、ACF に登録されたページのみ許可する
  $allowed_pages  = $this->get_acf_options_pages_list();
  $allowed_post_ids = array();
  foreach ($allowed_pages as $slug => $page) {
    $allowed_post_ids[] = $page['post_id'] ?? $slug;
  }

  if (!in_array($post_id, $allowed_post_ids, true)) {
    wp_die('Invalid options page.', 403);
  }

  // ── セキュリティ：$cf_fields をサーバー側のホワイトリストと照合 ──
  // POSTされたフィールド名をそのまま信用せず、実際に存在するフィールドとの積集合のみ使用する
  $menu_slug = '';
  foreach ($allowed_pages as $slug => $page) {
    if (($page['post_id'] ?? $slug) === $post_id) {
      $menu_slug = $slug;
      break;
    }
  }
  $allowed_fields = $this->get_options_field_list($post_id, $menu_slug);
  $allowed_field_keys = array_column($allowed_fields, 'meta_key');

  $cf_fields = array();
  if (!empty($_POST['cf_fields']) && is_array($_POST['cf_fields'])) {
    foreach ($_POST['cf_fields'] as $f) {
      $sanitized = sanitize_text_field($f);
      // ホワイトリストに存在するフィールドのみ受け付ける
      if (in_array($sanitized, $allowed_field_keys, true)) {
        $cf_fields[] = $sanitized;
      }
    }
  }

  if (empty($cf_fields)) {
    $errors[] = 'エクスポートするフィールドを選択してください。';
  } else {
    $row = array();
    // どのオプションページのデータかを識別できるよう先頭に付与
    $row['options_page_id'] = $post_id;
    // 移行元サイトURL（インポート時の画像ID解決に自動利用される）
    $row['_ajci_origin'] = home_url();

    foreach ($cf_fields as $field_name) {
      $field_value = '';

      if (function_exists('get_field')) {
        // ACF で取得（フォーマットなし）
        $acf_value = get_field($field_name, $post_id, false);

        if ($acf_value !== null && $acf_value !== false) {
          $field_value = is_array($acf_value)
            ? json_encode($acf_value, JSON_UNESCAPED_UNICODE)
            : $acf_value;
        } else {
          // ACF で取れない場合：wp_options から直接取得
          $option_key  = $post_id . '_' . $field_name;
          $option_val  = get_option($option_key, '');
          $field_value = is_array($option_val)
            ? json_encode($option_val, JSON_UNESCAPED_UNICODE)
            : (string) $option_val;
        }
      } else {
        // ACF 未インストール時：wp_options から直接取得
        $option_key  = $post_id . '_' . $field_name;
        $option_val  = get_option($option_key, '');
        $field_value = is_array($option_val)
          ? json_encode($option_val, JSON_UNESCAPED_UNICODE)
          : (string) $option_val;
      }

      $row[$field_name] = $field_value;
    }

    // ヘッダー行 + データ行
    $head = array(array_keys($row));
    $list = array_merge($head, array($row));

    // CSV ファイルを一時保存してダウンロード
    $filename = 'export-options-' . sanitize_file_name($post_id) . '-' . date_i18n('Y-m-d_H-i-s') . '.csv';
    $filepath = WCE_PLUGIN_DIR . '/download/' . $filename;
    $fp = fopen($filepath, 'w');

    foreach ($list as $fields) {
      if (function_exists('mb_convert_variables')) {
        mb_convert_variables($string_code, 'UTF-8', $fields);
      }
      // RFC 4180準拠: escape="" で \ によるエスケープを無効化し "→"" のみで統一
      fputcsv($fp, $fields, ',', '"', '');
    }
    fclose($fp);

    header('Content-Type:application/octet-stream');
    header('Content-Disposition:filename=' . $filename);
    header('Content-Length:' . filesize($filepath));
    readfile($filepath);
    unlink($filepath);
    exit;
  }
} else {
  $errors[] = 'エラーが起きました。';
}

if (!empty($errors)) {
  foreach ($errors as $value) {
    echo wp_kses_post($value) . PHP_EOL;
  }
}
