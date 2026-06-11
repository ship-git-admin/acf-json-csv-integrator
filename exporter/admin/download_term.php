<?php
$errors = array();

if (
  isset($_POST['taxonomy']) &&
  is_user_logged_in() &&
  isset($_POST['_wpnonce']) &&
  wp_verify_nonce($_POST['_wpnonce'], 'csv_exporter') &&
  (current_user_can('administrator') || current_user_can('editor'))
) {
  check_admin_referer('csv_exporter');

  $taxonomy    = sanitize_text_field($_POST['taxonomy']);
  $string_code = sanitize_text_field($_POST['string_code'] ?? 'UTF-8');

  // 選択された標準フィールド
  $term_fields = array();
  if (!empty($_POST['term_fields']) && is_array($_POST['term_fields'])) {
    foreach ($_POST['term_fields'] as $f) {
      $term_fields[] = sanitize_text_field($f);
    }
  }

  // 選択されたカスタムフィールド
  $cf_fields = array();
  if (!empty($_POST['cf_fields']) && is_array($_POST['cf_fields'])) {
    foreach ($_POST['cf_fields'] as $f) {
      $cf_fields[] = sanitize_text_field($f);
    }
  }

  // タクソノミー検証
  $taxonomy_obj = get_taxonomy($taxonomy);
  if (!$taxonomy_obj) {
    $errors[] = '指定されたタクソノミーが存在しません。';
  } else {
    // 全ターム取得（階層を保持するため parent 順でソート）
    $terms = get_terms(array(
      'taxonomy'   => $taxonomy,
      'hide_empty' => false,
      'number'     => 0,
      'orderby'    => 'term_id',
      'order'      => 'ASC',
    ));

    if (is_wp_error($terms) || empty($terms)) {
      $errors[] = '登録されているタームがありません。';
    } else {
      $results = array();

      foreach ($terms as $term) {
        $row = array();

        $row['taxonomy'] = $taxonomy;
        // 移行元サイトURL（インポート時の画像ID解決に自動利用される）
        $row['_ajci_origin'] = home_url();

        // 標準フィールド
        if (in_array('term_id', $term_fields))    $row['term_id']     = $term->term_id;
        if (in_array('name', $term_fields))        $row['name']        = $term->name;
        if (in_array('slug', $term_fields))        $row['slug']        = $term->slug;
        if (in_array('description', $term_fields)) $row['description'] = $term->description;
        if (in_array('parent', $term_fields))      $row['parent']      = $term->parent;

        // カスタムフィールド（ACF 対応 + フォールバック）
        foreach ($cf_fields as $field_name) {
          $field_value = '';

          if (function_exists('get_field')) {
            // ACF で取得（フォーマットなし）
            $acf_value = get_field($field_name, 'term_' . $term->term_id, false);

            if ($acf_value !== null && $acf_value !== false) {
              if (is_array($acf_value)) {
                // 配列（リピーター・フレキシブルコンテンツ等）は JSON 文字列に変換
                $field_value = json_encode($acf_value, JSON_UNESCAPED_UNICODE);
              } else {
                $field_value = $acf_value;
              }
            } else {
              // ACF で値が取れない場合は直接 term_meta から取得
              $raw = get_term_meta($term->term_id, $field_name, true);
              $field_value = is_array($raw)
                ? json_encode($raw, JSON_UNESCAPED_UNICODE)
                : (string) $raw;
            }
          } else {
            // ACF 未インストール時：直接 term_meta から取得
            $raw = get_term_meta($term->term_id, $field_name, true);
            $field_value = is_array($raw)
              ? json_encode($raw, JSON_UNESCAPED_UNICODE)
              : (string) $raw;
          }

          $row[$field_name] = $field_value;
        }

        $results[] = $row;
      }

      if (!empty($results)) {
        // ヘッダー行 + データ行を結合
        $head   = array(array_keys($results[0]));
        $list   = array_merge($head, $results);

        // CSV ファイルを一時保存してダウンロード
        $filename = 'export-term-' . $taxonomy . '-' . date_i18n('Y-m-d_H-i-s') . '.csv';
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
      } else {
        $errors[] = 'エクスポートするデータがありません。';
      }
    }
  }
} else {
  $errors[] = 'エラーが起きました。';
}

if (!empty($errors)) {
  foreach ($errors as $value) {
    echo wp_kses_post($value) . PHP_EOL;
  }
}
