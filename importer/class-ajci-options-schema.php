<?php

/**
 * Allow-list and whole-file validation for ACF options-page CSV imports.
 */
class AJCI_Options_Schema
{
    const RESERVED_COLUMNS = array('options_page_id', '_ajci_options_page', '_ajci_origin');
    const DISPLAY_FIELD_TYPES = array('message', 'tab', 'accordion');
    const COMPOSITE_FIELD_TYPES = array('group', 'repeater', 'flexible_content');
    const ARRAY_FIELD_TYPES = array('gallery', 'checkbox', 'relationship', 'taxonomy');
    const UNSUPPORTED_POST_ID_PREFIXES = array('user_', 'term_', 'post_');

    /**
     * Return registered ACF options pages for use by the importer UI.
     *
     * @return array
     */
    public static function get_registered_pages()
    {
        if (!function_exists('acf_get_options_pages')) {
            return array();
        }

        $pages = acf_get_options_pages();
        if (!is_array($pages)) {
            return array();
        }

        $normalized = array();
        foreach ($pages as $key => $page) {
            if (!is_array($page)) {
                continue;
            }

            $menu_slug = isset($page['menu_slug']) && is_string($page['menu_slug'])
                ? sanitize_key($page['menu_slug'])
                : sanitize_key((string) $key);
            $post_id = isset($page['post_id']) && is_scalar($page['post_id'])
                ? (string) $page['post_id']
                : 'options';

            if ($menu_slug === '' || $post_id === '') {
                continue;
            }

            $page['menu_slug'] = $menu_slug;
            $page['post_id'] = $post_id;
            if (empty($page['capability']) || !is_string($page['capability'])) {
                $page['capability'] = 'manage_options';
            }
            $normalized[$menu_slug] = $page;
        }

        return $normalized;
    }

    /**
     * Build a server-side schema from one registered options page.
     *
     * @param string $menu_slug
     * @return array|WP_Error
     */
    public static function get_schema($menu_slug)
    {
        if (!is_string($menu_slug) || $menu_slug === '') {
            return new WP_Error('options_page_required', 'オプションCSVの対象ページを選択してください。');
        }

        $pages = self::get_registered_pages();
        if (!isset($pages[$menu_slug])) {
            return new WP_Error('options_page_invalid', '登録済みのACFオプションページを選択してください。');
        }

        $page = $pages[$menu_slug];
        if (!current_user_can('manage_options') || !current_user_can($page['capability'])) {
            return new WP_Error('options_page_forbidden', '選択したオプションページを操作する権限がありません。');
        }

        if (!self::is_supported_post_id($page['post_id'])) {
            return new WP_Error('options_post_id_unsupported', '数値・ユーザー・ターム等の特殊な保存先は未対応です。');
        }

        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields') || !function_exists('update_field')) {
            return new WP_Error('acf_required', 'オプションCSVには有効なACFが必要です。');
        }

        $groups = acf_get_field_groups();
        if (!is_array($groups)) {
            return new WP_Error('options_groups_missing', '対象ページのACFフィールドグループを取得できません。');
        }

        $fields_by_name = array();
        $fields_by_key = array();
        $matched_group_count = 0;
        foreach ($groups as $group) {
            if (!is_array($group) || !self::group_applies_to_page($group, $menu_slug, $page['post_id'])) {
                continue;
            }

            $matched_group_count++;
            $group_key = isset($group['key']) ? $group['key'] : $group;
            $fields = acf_get_fields($group_key);
            if (!is_array($fields)) {
                continue;
            }

            foreach ($fields as $field) {
                $field_error = self::register_field($field, $fields_by_name, $fields_by_key);
                if (is_wp_error($field_error)) {
                    return $field_error;
                }
            }
        }

        if ($matched_group_count < 1 || empty($fields_by_name)) {
            return new WP_Error('options_fields_missing', '対象ページに許可できるACFフィールドがありません。');
        }

        $schema = array(
            'menu_slug' => $menu_slug,
            'post_id' => $page['post_id'],
            'capability' => $page['capability'],
            'fields_by_name' => $fields_by_name,
            'fields_by_key' => $fields_by_key,
        );
        $schema['fingerprint'] = self::fingerprint($schema);
        return $schema;
    }

    /**
     * Validate the whole CSV before any business data or remote media is touched.
     *
     * @param string $file
     * @param string $selected_menu_slug
     * @return array|WP_Error
     */
    public static function preflight_file($file, $selected_menu_slug = '')
    {
        if (!is_string($file) || !is_readable($file) || !file_exists($file)) {
            return new WP_Error('csv_unreadable', 'CSVファイルを読み込めません。');
        }
        $file_size = filesize($file);
        if ($file_size === false || $file_size <= 0 || $file_size > wp_max_upload_size()) {
            return new WP_Error('csv_size_invalid', 'CSVファイルのサイズが不正、または上限を超えています。');
        }

        $handle = fopen($file, 'rb');
        if (!$handle) {
            return new WP_Error('csv_open_failed', 'CSVファイルを開けません。');
        }

        $helper = new AJCI_CSV_Helper();
        $header = $helper->fgetcsv($handle);
        if (!is_array($header) || empty($header)) {
            fclose($handle);
            return new WP_Error('csv_header_missing', 'CSVのヘッダー行がありません。');
        }

        $header = self::normalize_header($header);
        $header_error = self::validate_headers($header);
        if (is_wp_error($header_error)) {
            fclose($handle);
            return $header_error;
        }

        $mode = self::detect_mode($header);
        if (is_wp_error($mode)) {
            fclose($handle);
            return $mode;
        }

        $options_page = null;
        $schema = null;
        if ($mode === 'options') {
            $schema = self::get_schema($selected_menu_slug);
            if (is_wp_error($schema)) {
                fclose($handle);
                return $schema;
            }
            $options_page = array(
                'menu_slug' => $schema['menu_slug'],
                'post_id' => $schema['post_id'],
                'capability' => $schema['capability'],
            );
        }

        $total_data_rows = 0;
        while (($row = $helper->fgetcsv($handle)) !== false) {
            if (!is_array($row) || count($row) !== count($header)) {
                fclose($handle);
                return new WP_Error('csv_column_count', 'CSVのすべての行で列数をヘッダーと一致させてください。');
            }

            if (self::is_empty_row($row)) {
                fclose($handle);
                return new WP_Error('csv_empty_row', 'CSVに空のデータ行があります。');
            }

            if ($mode === 'options') {
                $row_error = self::validate_options_row($row, $header, $schema);
                if (is_wp_error($row_error)) {
                    fclose($handle);
                    return $row_error;
                }
            }

            $total_data_rows++;
        }

        fclose($handle);

        if ($mode === 'options' && $total_data_rows < 1) {
            return new WP_Error('options_data_missing', 'オプションCSVにデータ行がありません。');
        }

        $file_sha256 = hash_file('sha256', $file);
        if (!is_string($file_sha256) || !preg_match('/\A[a-f0-9]{64}\z/', $file_sha256)) {
            return new WP_Error('csv_hash_failed', 'CSVファイルの内容を固定できません。');
        }

        $result = array(
            'mode' => $mode,
            'headers' => $header,
            'total_data_rows' => $total_data_rows,
            'options_page' => $options_page,
            'schema_fingerprint' => $schema ? $schema['fingerprint'] : '',
            'file_sha256' => $file_sha256,
        );
        return $result;
    }

    /**
     * Validate one row again immediately before saving.
     *
     * @param array $row
     * @param array $headers
     * @param array $schema
     * @return true|WP_Error
     */
    public static function validate_options_row($row, $headers, $schema)
    {
        if (!is_array($row) || !is_array($headers) || count($row) !== count($headers)) {
            return new WP_Error('csv_column_count', 'CSVの列数が不正です。');
        }

        $page_id = null;
        foreach ($headers as $index => $column) {
            $value = array_key_exists($index, $row) ? $row[$index] : false;
            if (!is_scalar($value) && $value !== null && $value !== false) {
                return new WP_Error('csv_value_invalid', 'CSVの値が不正です。');
            }

            if ($column === 'options_page_id') {
                if (!is_string($value) || $value === '') {
                    return new WP_Error('options_page_id_empty', 'options_page_id列が空です。');
                }
                $page_id = $value;
                continue;
            }

            if ($column === '_ajci_options_page') {
                if (!is_string($value) || $value === '' || $value !== $schema['menu_slug']) {
                    return new WP_Error('options_page_mismatch', 'CSVのオプション画面識別子が選択画面と一致しません。');
                }
                continue;
            }

            if (in_array($column, self::RESERVED_COLUMNS, true)) {
                continue;
            }

            $field = self::resolve_field($column, $schema);
            if (is_wp_error($field)) {
                return $field;
            }

            $value_error = self::validate_field_value($value, $field, $column);
            if (is_wp_error($value_error)) {
                return $value_error;
            }
        }

        if ($page_id !== $schema['post_id']) {
            return new WP_Error('options_page_mismatch', 'CSVの保存先が選択した登録済みオプションページと一致しません。');
        }

        return true;
    }

    /**
     * Decode only JSON arrays/objects. Strings such as "0", "false", and "null" remain strings.
     *
     * @param mixed $value
     * @param bool  $is_json
     * @return mixed
     */
    public static function decode_value($value, &$is_json = false)
    {
        $is_json = false;
        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || !(($trimmed[0] === '[' && substr($trimmed, -1) === ']') || ($trimmed[0] === '{' && substr($trimmed, -1) === '}'))) {
            return $value;
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $value;
        }

        $is_json = true;
        return $decoded;
    }

    /**
     * @param string $column
     * @param array  $schema
     * @return array|WP_Error
     */
    public static function resolve_field($column, $schema)
    {
        if (isset($schema['fields_by_name'][$column])) {
            return $schema['fields_by_name'][$column];
        }
        if (isset($schema['fields_by_key'][$column])) {
            return $schema['fields_by_key'][$column];
        }
        return new WP_Error('field_not_allowed', sprintf('登録済みの対象ページにないフィールド「%s」です。', $column));
    }

    private static function normalize_header($header)
    {
        $bom = pack('CCC', 0xef, 0xbb, 0xbf);
        if (isset($header[0]) && is_string($header[0]) && 0 === strncmp($header[0], $bom, 3)) {
            $header[0] = substr($header[0], 3);
        }
        return array_map(function ($value) {
            return is_string($value) ? trim($value) : $value;
        }, $header);
    }

    private static function validate_headers($header)
    {
        $seen = array();
        foreach ($header as $column) {
            if (!is_string($column) || $column === '') {
                return new WP_Error('csv_header_invalid', 'CSVに空の列名があります。');
            }
            if (isset($seen[$column])) {
                return new WP_Error('csv_header_duplicate', sprintf('CSVの列名「%s」が重複しています。', $column));
            }
            $seen[$column] = true;
        }

        return true;
    }

    private static function detect_mode($header)
    {
        $has_options = in_array('options_page_id', $header, true) || in_array('_ajci_options_page', $header, true);
        $has_terms = in_array('taxonomy', $header, true);
        $post_columns = array('post_type', 'post_id', 'ID', 'post_title', 'post_content', 'post_name');
        $has_posts = (bool) array_intersect($post_columns, $header);

        $mode_count = ($has_options ? 1 : 0) + ($has_terms ? 1 : 0) + ($has_posts ? 1 : 0);
        if ($mode_count > 1) {
            return new WP_Error('csv_mixed_mode', '投稿・ターム・オプションの列を同じCSVへ混在させることはできません。');
        }
        if ($has_options) {
            if (!in_array('options_page_id', $header, true)) {
                return new WP_Error('options_page_id_missing', 'オプションCSVにはoptions_page_id列が必要です。');
            }
            return 'options';
        }
        if ($has_terms) {
            return 'term';
        }
        return 'post';
    }

    private static function is_empty_row($row)
    {
        foreach ($row as $value) {
            if ($value !== '' && $value !== null && $value !== false) {
                return false;
            }
        }
        return true;
    }

    private static function is_supported_post_id($post_id)
    {
        if (!is_string($post_id) || $post_id === '' || ctype_digit($post_id)) {
            return false;
        }
        foreach (self::UNSUPPORTED_POST_ID_PREFIXES as $prefix) {
            if (strpos($post_id, $prefix) === 0) {
                return false;
            }
        }
        return true;
    }

    private static function group_applies_to_page($group, $menu_slug, $post_id)
    {
        if (empty($group['location']) || !is_array($group['location'])) {
            return false;
        }

        // Only the options_page rule is accepted here. Unknown/custom rules are
        // rejected instead of falling back to allowing every field group.
        foreach ($group['location'] as $location_group) {
            if (!is_array($location_group) || empty($location_group)) {
                continue;
            }

            $matches = true;
            foreach ($location_group as $rule) {
                if (!is_array($rule) || ($rule['param'] ?? '') !== 'options_page') {
                    $matches = false;
                    break;
                }
                $operator = isset($rule['operator']) ? $rule['operator'] : '==';
                $value = isset($rule['value']) ? (string) $rule['value'] : '';
                $is_match = ($value === $menu_slug || $value === $post_id);
                if ($operator === '==' && !$is_match) {
                    $matches = false;
                    break;
                }
                if ($operator === '!=' && $is_match) {
                    $matches = false;
                    break;
                }
                if ($operator !== '==' && $operator !== '!=') {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    private static function register_field($field, &$fields_by_name, &$fields_by_key)
    {
        if (!is_array($field) || empty($field['name']) || empty($field['key']) || empty($field['type'])) {
            return new WP_Error('field_definition_invalid', 'ACFフィールド定義が不正です。');
        }

        if (in_array($field['type'], self::DISPLAY_FIELD_TYPES, true)) {
            return true;
        }
        if ($field['type'] === 'clone') {
            return new WP_Error('field_type_unsupported', 'Cloneフィールドは安全な構造検証が未対応です。');
        }

        $normalized = self::normalize_field($field);
        $name = (string) $normalized['name'];
        $key = (string) $normalized['key'];
        if (isset($fields_by_name[$name])) {
            return new WP_Error('field_name_ambiguous', sprintf('フィールド名「%s」が複数の保存先に対応するため拒否しました。', $name));
        }
        if (isset($fields_by_key[$key])) {
            return new WP_Error('field_key_ambiguous', sprintf('フィールドキー「%s」が重複しています。', $key));
        }

        $fields_by_name[$name] = $normalized;
        $fields_by_key[$key] = $normalized;
        return true;
    }

    private static function normalize_field($field)
    {
        $normalized = array(
            'name' => (string) $field['name'],
            'key' => (string) $field['key'],
            'type' => (string) $field['type'],
            'multiple' => !empty($field['multiple']),
        );

        if (in_array($normalized['type'], array('group', 'repeater'), true)) {
            $children = isset($field['sub_fields']) && is_array($field['sub_fields']) ? $field['sub_fields'] : array();
            if (empty($children) && function_exists('acf_get_fields')) {
                $children = acf_get_fields($field['key']);
            }
            $normalized['sub_fields'] = self::normalize_children($children);
        } elseif ($normalized['type'] === 'flexible_content') {
            $layouts = isset($field['layouts']) && is_array($field['layouts']) ? $field['layouts'] : array();
            $normalized['layouts'] = array();
            foreach ($layouts as $layout) {
                if (!is_array($layout) || empty($layout['name'])) {
                    continue;
                }
                if (isset($normalized['layouts'][(string) $layout['name']])) {
                    $normalized['layouts'][(string) $layout['name']] = array('unsupported' => true, 'type' => 'ambiguous');
                    continue;
                }
                $children = isset($layout['sub_fields']) && is_array($layout['sub_fields']) ? $layout['sub_fields'] : array();
                $normalized['layouts'][(string) $layout['name']] = self::normalize_children($children);
            }
            if (empty($normalized['layouts'])) {
                $normalized['layouts'] = array();
            }
        }

        return $normalized;
    }

    private static function normalize_children($children)
    {
        $normalized = array();
        foreach ((array) $children as $child) {
            if (!is_array($child) || empty($child['name']) || empty($child['key']) || empty($child['type'])) {
                continue;
            }
            if (in_array($child['type'], self::DISPLAY_FIELD_TYPES, true)) {
                continue;
            }
            if ($child['type'] === 'clone') {
                if (isset($normalized[(string) $child['name']])) {
                    $normalized[(string) $child['name']] = array('unsupported' => true, 'type' => 'ambiguous');
                    continue;
                }
                $normalized[(string) $child['name']] = array('unsupported' => true, 'type' => 'clone');
                continue;
            }
            if (isset($normalized[(string) $child['name']])) {
                $normalized[(string) $child['name']] = array('unsupported' => true, 'type' => 'ambiguous');
                continue;
            }
            $normalized[(string) $child['name']] = self::normalize_field($child);
        }
        return $normalized;
    }

    private static function validate_field_value($value, $field, $column)
    {
        $is_json = false;
        $decoded = self::decode_value($value, $is_json);
        if (!$is_json && is_array($value)) {
            // Nested values have already been decoded by their parent object.
            $decoded = $value;
            $is_json = true;
        }
        if (!$is_json) {
            if (in_array($field['type'], array_merge(self::COMPOSITE_FIELD_TYPES, self::ARRAY_FIELD_TYPES), true)
                && trim((string) $value) !== '') {
                return new WP_Error('field_value_invalid', sprintf('複合フィールド「%s」のJSON値が不正です。', $column));
            }
            return true;
        }

        if (!is_array($decoded)) {
            return new WP_Error('field_value_invalid', sprintf('フィールド「%s」のJSON値が不正です。', $column));
        }

        return self::validate_complex_value($decoded, $field, $column);
    }

    private static function validate_complex_value($value, $field, $path)
    {
        $type = isset($field['type']) ? $field['type'] : '';
        if ($type === 'clone' || !empty($field['unsupported'])) {
            return new WP_Error('field_type_unsupported', sprintf('フィールド「%s」は未対応の構造です。', $path));
        }

        if ($type === 'group') {
            if (!empty($value) && !self::is_assoc($value)) {
                return new WP_Error('field_value_invalid', sprintf('グループ「%s」はオブジェクト形式で指定してください。', $path));
            }
            return self::validate_children($value, $field['sub_fields'] ?? array(), $path);
        }

        if ($type === 'repeater' || $type === 'flexible_content') {
            if (!self::is_list($value)) {
                return new WP_Error('field_value_invalid', sprintf('複合フィールド「%s」は配列形式で指定してください。', $path));
            }
            foreach ($value as $index => $row) {
                if (!is_array($row)) {
                    return new WP_Error('field_value_invalid', sprintf('フィールド「%s」の%d行目が不正です。', $path, $index + 1));
                }
                if ($type === 'flexible_content') {
                    $layout_name = isset($row['acf_fc_layout']) ? $row['acf_fc_layout'] : '';
                    if (!is_string($layout_name) || !isset($field['layouts'][$layout_name])) {
                        return new WP_Error('field_layout_invalid', sprintf('フィールド「%s」のレイアウトが未登録です。', $path));
                    }
                    $children = $field['layouts'][$layout_name];
                } else {
                    $children = $field['sub_fields'] ?? array();
                }
                $child_error = self::validate_children($row, $children, $path . '[' . $index . ']');
                if (is_wp_error($child_error)) {
                    return $child_error;
                }
            }
            return true;
        }

        if (in_array($type, self::ARRAY_FIELD_TYPES, true)) {
            if (!self::is_list($value)) {
                return new WP_Error('field_value_invalid', sprintf('配列フィールド「%s」は配列形式で指定してください。', $path));
            }
            foreach ($value as $item) {
                if (!is_scalar($item) && $item !== null) {
                    return new WP_Error('field_value_invalid', sprintf('配列フィールド「%s」の値が不正です。', $path));
                }
            }
            return true;
        }

        if (!empty($field['multiple'])) {
            if (!self::is_list($value)) {
                return new WP_Error('field_value_invalid', sprintf('複数選択フィールド「%s」は配列形式で指定してください。', $path));
            }
            foreach ($value as $item) {
                if (!is_scalar($item) && $item !== null) {
                    return new WP_Error('field_value_invalid', sprintf('複数選択フィールド「%s」の値が不正です。', $path));
                }
            }
            return true;
        }

        // A JSON array/object is not valid for a scalar ACF field. This blocks
        // raw arbitrary arrays from being forwarded to update_field().
        return new WP_Error('field_value_invalid', sprintf('スカラーのフィールド「%s」に配列値は指定できません。', $path));
    }

    private static function validate_children($value, $children, $path)
    {
        foreach ($value as $name => $child_value) {
            if ($name === 'acf_fc_layout') {
                continue;
            }
            if (!isset($children[$name])) {
                return new WP_Error('child_field_not_allowed', sprintf('フィールド「%s」の子フィールド「%s」は未登録です。', $path, $name));
            }
            $child_error = self::validate_field_value($child_value, $children[$name], $path . '.' . $name);
            if (is_wp_error($child_error)) {
                return $child_error;
            }
        }
        return true;
    }

    private static function is_assoc($value)
    {
        if (!is_array($value)) {
            return false;
        }
        if (empty($value)) {
            return false;
        }
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private static function is_list($value)
    {
        if (!is_array($value)) {
            return false;
        }
        if (empty($value)) {
            return true;
        }
        return array_keys($value) === range(0, count($value) - 1);
    }

    private static function fingerprint($schema)
    {
        $fingerprint_data = array(
            'menu_slug' => $schema['menu_slug'],
            'post_id' => $schema['post_id'],
            'fields_by_name' => $schema['fields_by_name'],
        );
        return hash('sha256', serialize($fingerprint_data));
    }
}
