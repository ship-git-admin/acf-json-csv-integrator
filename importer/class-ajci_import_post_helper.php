<?php

/**
 * A helper class for insert or update post data.
 *
 * @package Really Simple CSV Importer
 */
class AJCI_Import_Post_Helper
{
    const CFS_PREFIX = 'cfs_';
    const SCF_PREFIX = 'scf_';
    
    /**
     * @var string Basic Auth Username
     */
    public static $basic_auth_user;

    /**
     * @var string Basic Auth Password
     */
    public static $basic_auth_pass;

    /**
     * @var string Import Origin URL (input from user)
     */
    public static $import_origin_url;

    /**
     * @var array フィールド名→フィールドキーのマップキャッシュ（コンテキスト別）
     */
    private static $field_key_map_cache = array();

    /**
     * @var $post WP_Post object
     */
    private $post;

    /**
     * @var $term WP_Term object
     */
    private $term;

    /**
     * Set WP_Term object
     *
     * @param (int) $term_id Term ID
     * @param (string) $taxonomy Taxonomy slug
     */
    protected function setTerm($term_id, $taxonomy)
    {
        $term = get_term($term_id, $taxonomy);
        if (is_object($term) && !is_wp_error($term)) {
            $this->term = $term;
        } else {
            $this->addError('term_id_not_found', __('Provided Term ID not found.', 'really-simple-csv-importer'));
        }
    }

    /**
     * Get WP_Term object
     *
     * @return (WP_Term|null)
     */
    public function getTerm()
    {
        return $this->term;
    }

    /**
     * Get object by term id and taxonomy.
     *
     * @param (int) $term_id Term ID
     * @param (string) $taxonomy Taxonomy slug
     * @return (AJCI_Import_Post_Helper)
     */
    public static function getTermByID($term_id, $taxonomy)
    {
        $object = new AJCI_Import_Post_Helper();
        $object->setTerm($term_id, $taxonomy);
        return $object;
    }

    /**
     * Add a term
     *
     * @param (array) $data An associative array of the term data
     * @return (AJCI_Import_Post_Helper)
     */
    public static function addTerm($data)
    {
        $object = new AJCI_Import_Post_Helper();
        $taxonomy = isset($data['taxonomy']) ? $data['taxonomy'] : 'category';
        $name = isset($data['name']) ? $data['name'] : '';

        $args = array();
        if (isset($data['slug'])) $args['slug'] = $data['slug'];
        if (isset($data['description'])) $args['description'] = $data['description'];
        if (isset($data['parent'])) $args['parent'] = $data['parent'];

        $term_info = wp_insert_term($name, $taxonomy, $args);

        if (is_wp_error($term_info)) {
            if ($term_info->get_error_code() === 'term_exists') {
                $existing_term_id = $term_info->get_error_data();
                $object->setTerm($existing_term_id, $taxonomy);
                $object->updateTerm($data);
            } else {
                $object->addError($term_info->get_error_code(), $term_info->get_error_message());
            }
        } else {
            $term_id = $term_info['term_id'];
            // term_idの強制一致化（DB直更新）
            if (isset($data['term_id']) && (int)$data['term_id'] !== (int)$term_id) {
                global $wpdb;
                $old_term_id = (int)$term_id;
                $new_term_id = (int)$data['term_id'];

                $check = $wpdb->get_var($wpdb->prepare("SELECT term_id FROM $wpdb->terms WHERE term_id = %d", $new_term_id));
                if (!$check) {
                    $wpdb->update($wpdb->terms, array('term_id' => $new_term_id), array('term_id' => $old_term_id));
                    $wpdb->update($wpdb->term_taxonomy, array('term_id' => $new_term_id), array('term_id' => $old_term_id));
                    $wpdb->update($wpdb->termmeta, array('term_id' => $new_term_id), array('term_id' => $old_term_id));
                    $term_id = $new_term_id;
                }
            }
            $object->setTerm($term_id, $taxonomy);
        }
        return $object;
    }

    /**
     * Update term
     *
     * @param (array) $data An associative array of the term data
     */
    public function updateTerm($data)
    {
        $term = $this->getTerm();
        if ($term instanceof WP_Term) {
            $taxonomy = $term->taxonomy;
            $args = array();
            if (isset($data['name'])) $args['name'] = $data['name'];
            if (isset($data['slug'])) $args['slug'] = $data['slug'];
            if (isset($data['description'])) $args['description'] = $data['description'];
            if (isset($data['parent'])) $args['parent'] = $data['parent'];

            $term_info = wp_update_term($term->term_id, $taxonomy, $args);
            if (is_wp_error($term_info)) {
                $this->addError($term_info->get_error_code(), $term_info->get_error_message());
            } else {
                $this->setTerm($term->term_id, $taxonomy);
            }
        }
    }

    /**
     * Set term meta fields by array
     *
     * @param (array) $data An associative array of metadata
     */
    public function setTermMeta($data)
    {
        $term = $this->getTerm();
        if (!$term instanceof WP_Term) {
            return;
        }

        if (empty($data) || !is_array($data)) {
            return;
        }

        foreach ($data as $key => $value) {
            $decoded_value = null;
            $is_json_array = false;
            if (is_string($value) && !empty($value)) {
                $trimmed = trim($value);
                if ((strpos($trimmed, '[') === 0 && substr($trimmed, -1) === ']') || (strpos($trimmed, '{') === 0 && substr($trimmed, -1) === '}')) {
                    $decoded = json_decode($trimmed, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $decoded_value = $decoded;
                        $is_json_array = true;
                    }
                }
            }

            $is_acf = 0;
            if (function_exists('get_field_object')) {
                if (strpos($key, 'field_') === 0) {
                    $fobj = get_field_object($key);
                    if (is_array($fobj) && isset($fobj['key']) && $fobj['key'] == $key) {
                        if (isset($fobj['type']) && ($fobj['type'] === 'image' || $fobj['type'] === 'file')) {
                            if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
                                $attachment_id = $this->addMediaFile($value);
                                if ($attachment_id) {
                                    $value = $attachment_id;
                                }
                            } elseif (is_numeric($value) || (is_string($value) && ctype_digit($value))) {
                                $new_id = $this->resolveImageId($value);
                                if ($new_id) {
                                    $value = $new_id;
                                }
                            }
                        }
                        update_field($key, $value, 'term_' . $term->term_id);
                        $is_acf = 1;
                    }
                } elseif ($is_json_array) {
                    // 配列内の画像をローカルIDに置換
                    $decoded_value = $this->processAcfArrayImages($decoded_value);
                    // タクソノミーをコンテキストとしてフィールドキーを解決
                    $field_key = $this->getFieldKey($key, array('taxonomy' => $term->taxonomy));

                    if (strpos($field_key, 'field_') === 0) {
                        // 正しいフィールドキーで update_field を実行（柔軟コンテンツ・リピーターを正しく保存）
                        // ※ update_field は値が不変の場合も false を返すため、戻り値は失敗判定に使わない
                        update_field($field_key, $decoded_value, 'term_' . $term->term_id);
                    } else {
                        // キーが解決できない配列値を生のまま term_meta に書くとACFのデータ構造を破壊する。
                        // データ破損を避けるためスキップし、原因（フィールドグループ未同期）を通知する。
                        echo esc_html(sprintf('⚠️ 警告: フィールド "%s" のACFフィールドキーを解決できなかったため、データ破損を避けてスキップしました。インポート先でACFフィールドグループが同期・有効化されているかご確認ください。<br>', $key));
                    }
                    $is_acf = 1;
                }
            }

            if (!$is_acf) {
                update_term_meta($term->term_id, $key, $value);
            }
        }
    }
    
    /**
     * @var $error WP_Error object
     */
    private $error;
    
    /**
     * Add an error or append additional message to this object.
     *
     * @param string|int $code Error code.
     * @param string $message Error message.
     * @param mixed $data Optional. Error data.
     */
    public function addError($code, $message, $data = '')
    {
        if (!$this->isError()) {
            $e = new WP_Error();
            $this->error = $e;
        }
        $this->error->add($code, $message, $data);
    }
    
    /**
     * Get the error of this object
     *
     * @return (WP_Error)
     */
    public function getError()
    {
        if (!$this->isError()) {
            $e = new WP_Error();
            return $e;
        }
        return $this->error;
    }
    
    /**
     * Check the object has some Errors.
     *
     * @return (bool)
     */
    public function isError()
    {
        return is_wp_error($this->error);
    }
    
    /**
     * Set WP_Post object
     *
     * @param (int) $post_id Post ID
     */
    protected function setPost($post_id)
    {
        $post = get_post($post_id);
        if (is_object($post)) {
            $this->post = $post;
        } else {
            $this->addError('post_id_not_found', __('Provided Post ID not found.', 'really-simple-csv-importer'));
        }
    }
    
    /**
     * Get WP_Post object
     *
     * @return (WP_Post|null)
     */
    public function getPost()
    {
        return $this->post;
    }
    
    /**
     * Get object by post id.
     *
     * @param (int) $post_id Post ID
     * @return (AJCI_Import_Post_Helper)
     */
    public static function getByID($post_id)
    {
        $object = new AJCI_Import_Post_Helper();
        $object->setPost($post_id);
        return $object;
    }
    
    /**
     * Add a post
     *
     * @param (array) $data An associative array of the post data
     * @return (AJCI_Import_Post_Helper)
     */
    public static function add($data)
    {
        $object = new AJCI_Import_Post_Helper();

        if ($data['post_type'] == 'attachment') {
            $post_id = $object->addMediaFile($data['media_file'], $data);
        } else {
            $post_id = wp_insert_post($data, true);
        }
        if (is_wp_error($post_id)) {
            $object->addError($post_id->get_error_code(), $post_id->get_error_message());
        } else {
            $object->setPost($post_id);
        }
        return $object;
    }
    
    /**
     * Update post
     *
     * @param (array) $data An associative array of the post data
     */
    public function update($data)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            $data['ID'] = $post->ID;
        }
        if ($data['post_type'] == 'attachment' && !empty($data['media_file'])) {
            $this->updateAttachment($data['media_file']);
            unset($data['media_file']);
        }
        $post_id = wp_update_post($data, true);
        if (is_wp_error($post_id)) {
            $this->addError($post_id->get_error_code(), $post_id->get_error_message());
        } else {
            $this->setPost($post_id);
        }
    }
    
    /**
     * Set meta fields by array
     *
     * @param (array) $data An associative array of metadata
     */
    public function setMeta($data)
    {
        if (empty($data) || !is_array($data)) {
            return;
        }
        $scf_array = array();
        foreach ($data as $key => $value) {
            $is_cfs = 0;
            $is_scf = 0;
            $is_acf = 0;

            // 値がJSON配列またはオブジェクトであるか判定
            $decoded_value = null;
            $is_json_array = false;
            if (is_string($value) && !empty($value)) {
                $trimmed = trim($value);
                if ((strpos($trimmed, '[') === 0 && substr($trimmed, -1) === ']') || (strpos($trimmed, '{') === 0 && substr($trimmed, -1) === '}')) {
                    $decoded = json_decode($trimmed, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $decoded_value = $decoded;
                        $is_json_array = true;
                    }
                }
            }

            if (strpos($key, self::CFS_PREFIX) === 0) {
                $this->cfsSave(substr($key, strlen(self::CFS_PREFIX)), $value);
                $is_cfs = 1;
            } elseif(strpos($key, self::SCF_PREFIX) === 0) {
                $scf_key = substr($key, strlen(self::SCF_PREFIX));
                $scf_array[$scf_key][] = $value;
                $is_scf = 1;
            } else {
                if (function_exists('get_field_object')) {
                    if (strpos($key, 'field_') === 0) {
                        $fobj = get_field_object($key);
                        if (is_array($fobj) && isset($fobj['key']) && $fobj['key'] == $key) {
                            if (isset($fobj['type']) && ($fobj['type'] === 'image' || $fobj['type'] === 'file')) {
                                if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
                                    $attachment_id = $this->addMediaFile($value);
                                    if ($attachment_id) {
                                        $value = $attachment_id;
                                    }
                                } elseif (is_numeric($value) || (is_string($value) && ctype_digit($value))) {
                                    $new_id = $this->resolveImageId($value);
                                    if ($new_id) {
                                        $value = $new_id;
                                    }
                                }
                            }
                            $this->acfUpdateField($key, $value);
                            $is_acf = 1;
                        }
                    } elseif ($is_json_array) {
                        // 配列内の画像URLを自動でダウンロードしてメディア登録しIDに置換
                        $decoded_value = $this->processAcfArrayImages($decoded_value);
                        $field_key = $this->getFieldKey($key);
                        if (strpos($field_key, 'field_') === 0) {
                            // 正しいフィールドキーで保存（柔軟コンテンツ・リピーターを正しく格納）
                            $this->acfUpdateField($field_key, $decoded_value);
                        } else {
                            // キー未解決の配列値を生メタに書くとACF構造を破壊するためスキップして通知
                            echo esc_html(sprintf('⚠️ 警告: フィールド "%s" のACFフィールドキーを解決できなかったため、データ破損を避けてスキップしました。インポート先でACFフィールドグループが同期・有効化されているかご確認ください。<br>', $key));
                        }
                        $is_acf = 1;
                    }
                }
            }
            if (!$is_acf && !$is_cfs && !$is_scf) {
                $this->updateMeta($key, $value);
            }
        }
        $this->scfSave($scf_array);
    }
    
    /**
     * ACFの配列構造から画像を再帰的に処理・ダウンロードし、アタッチメントIDに置換する。
     *
     * @param array  $array        ACFの配列データ
     * @param string $parent_type  親フィールドタイプ
     * @return array 処理後の配列データ
     */
    public function processAcfArrayImages($array, $parent_type = '')
    {
        if (!is_array($array)) {
            return $array;
        }

        // 1. もしこの配列自体がACFの「画像オブジェクト（連想配列）」の構造を持っている場合
        // （例：array('ID' => 123, 'url' => 'https://...', 'sizes' => ...） の場合、
        // そのURLをダウンロードしてローカルアタッチメントID値に丸ごと置換する。
        if (isset($array['url']) && filter_var($array['url'], FILTER_VALIDATE_URL)) {
            $path_info = pathinfo(parse_url($array['url'], PHP_URL_PATH));
            if (isset($path_info['extension'])) {
                $ext = strtolower($path_info['extension']);
                if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'zip'))) {
                    $attachment_id = $this->addMediaFile($array['url']);
                    if ($attachment_id) {
                        return $attachment_id;
                    }
                }
            }
        }

        // 2. 通常の再帰処理
        foreach ($array as $key => $value) {
            // 現在のフィールドのタイプをACF設定から取得
            $current_type = '';
            if (function_exists('acf_get_field') && !is_numeric($key)) {
                $field_info = acf_get_field($key);
                // field_ 始まりのフィールド名はACFがキーとして検索するが実際には名前の場合があるため、
                // キー検索に失敗した場合は名前検索にフォールバックする
                if (!is_array($field_info) && strpos($key, 'field_') === 0 && function_exists('acf_get_field_by_name')) {
                    $field_info = acf_get_field_by_name($key);
                }
                if (is_array($field_info) && isset($field_info['type'])) {
                    $current_type = $field_info['type'];
                }
            }

            if (is_array($value)) {
                $array[$key] = $this->processAcfArrayImages($value, $current_type ? $current_type : $parent_type);
            } else {
                $is_image_field = false;

                // 親がギャラリー、画像、ファイル、またはこのフィールド自体が画像・ファイルの場合
                if ($parent_type === 'gallery' || $parent_type === 'image' || $parent_type === 'file') {
                    $is_image_field = true;
                } elseif ($current_type === 'image' || $current_type === 'file') {
                    $is_image_field = true;
                }

                // 数値または数値文字列（旧画像ID）の場合
                if ($is_image_field && (is_numeric($value) || (is_string($value) && ctype_digit($value)))) {
                    $new_id = $this->resolveImageId($value);
                    if ($new_id) {
                        $array[$key] = $new_id;
                    } else {
                        // 解決できなかった場合も整数型で保持する（ACFの型互換性を維持）
                        $array[$key] = (int) $value;
                    }
                }
                // URLの場合
                elseif ($is_image_field && is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
                    $attachment_id = $this->addMediaFile($value);
                    if ($attachment_id) {
                        $array[$key] = $attachment_id;
                    } else {
                        $found_id = attachment_url_to_postid($value);
                        if ($found_id) {
                            $array[$key] = $found_id;
                        }
                    }
                }
                // フォールバック: 値が画像の拡張子を持つURLの場合
                elseif (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
                    $path_info = pathinfo(parse_url($value, PHP_URL_PATH));
                    if (isset($path_info['extension'])) {
                        $ext = strtolower($path_info['extension']);
                        if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'zip'))) {
                            $attachment_id = $this->addMediaFile($value);
                            if ($attachment_id) {
                                $array[$key] = $attachment_id;
                            }
                        }
                    }
                }
            }
        }

        return $array;
    }

    /**
     * A wrapper of update_post_meta
     *
     * @param (string) $key
     * @param (string/array) $value
     */
    protected function updateMeta($key, $value)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            update_post_meta($post->ID, $key, $value);
        } else {
            $this->addError('post_is_not_set', __('WP_Post object is not set.', 'really-simple-csv-importer'));
        }
    }
    
    /**
     * A wrapper of update_field of Advanced Custom Fields
     *
     * @param (string) $key
     * @param (string/array) $value
     */
    protected function acfUpdateField($key, $value)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            if (function_exists('update_field')) {
                $field_key = $this->getFieldKey($key);
                update_field($field_key, $value, $post->ID);
            } else {
                $this->updateMeta($key, $value);
            }
        } else {
            $this->addError('post_is_not_set', __('WP_Post object is not set.', 'really-simple-csv-importer'));
        }
    }
    
    /**
     * A wrapper of CFS()->save()
     *
     * @param (string) $key
     * @param (string/array) $value
     */
    protected function cfsSave($key, $value)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            if (function_exists('CFS')) {
                $field_data = array($key => $value);
                $post_data = array('ID' => $post->ID);
                CFS()->save($field_data, $post_data);
            } else {
                $this->updateMeta($key, $value);
            }
        } else {
            $this->addError('post_is_not_set', __('WP_Post object is not set.', 'really-simple-csv-importer'));
        }
    }
    
    /**
     * A wrapper of Smart_Custom_Fields_Meta()->save()
     *
     * @param (array) $data
     */
    protected function scfSave($data)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            if (class_exists('Smart_Custom_Fields_Meta') && is_array($data)) {
                $_data = array();
                $_data['smart-custom-fields'] = $data;
                $meta = new Smart_Custom_Fields_Meta($post);
                $meta->save($_data);
            } elseif(is_array($data)) {
                foreach ($data as $key => $array) {
                    foreach ((array) $array as $value) {
                        $this->updateMeta($key, $value);
                    }
                }
            }
        } else {
            $this->addError('post_is_not_set', __('WP_Post object is not set.', 'really-simple-csv-importer'));
        }
    }
    
    /**
     * A wrapper of wp_set_post_tags
     *
     * @param (array) $tags
     */
    public function setPostTags($tags)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            wp_set_post_tags($post->ID, $tags);
        } else {
            $this->addError('post_is_not_set', __('WP_Post object is not set.', 'really-simple-csv-importer'));
        }
    }
    
    /**
     * A wrapper of wp_set_object_terms
     *
     * @param (array/string) $taxonomy The context in which to relate the term to the object
     * @param (array/int/string) $terms The slug or id of the term
     */
    public function setObjectTerms($taxonomy, $terms)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            wp_set_object_terms($post->ID, $terms, $taxonomy);
        } else {
            $this->addError('post_is_not_set', __('WP_Post object is not set.', 'really-simple-csv-importer'));
        }
    }
    
    /**
     * Add attachment file. Automatically get remote file
     *
     * @param (string) $file
     * @param (array) $data
     * @return (boolean) True on success, false on failure.
     */
    public function addMediaFile($file, $data = null)
    {
        $url = '';
        if (parse_url($file, PHP_URL_SCHEME)) {
            $url = $file;

            // ① 同じURLのメディアが既に存在する場合はそのIDを返す（重複ダウンロード防止）
            $existing_id = attachment_url_to_postid($url);
            if ($existing_id) {
                $old_id = null;
                if (is_array($data)) {
                    if (isset($data['ID'])) {
                        $old_id = $data['ID'];
                    } elseif (isset($data['post_id'])) {
                        $old_id = $data['post_id'];
                    } elseif (isset($data['import_id'])) {
                        $old_id = $data['import_id'];
                    }
                }
                if ($old_id) {
                    update_post_meta($existing_id, '_really_simple_csv_importer_old_id', $old_id);
                }
                return $existing_id;
            }

            // ② リモートからダウンロード
            $file = $this->remoteGet($url);

            // ③ ダウンロード失敗時: ファイル名で既存メディアを検索してフォールバック
            if (!$file) {
                $basename = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);
                if ($basename) {
                    $query = new WP_Query(array(
                        'post_type'      => 'attachment',
                        'post_status'    => 'inherit',
                        'posts_per_page' => 1,
                        'title'          => sanitize_file_name($basename),
                    ));
                    if ($query->have_posts()) {
                        $found_id = $query->posts[0]->ID;
                        $old_id = null;
                        if (is_array($data)) {
                            if (isset($data['ID'])) {
                                $old_id = $data['ID'];
                            } elseif (isset($data['post_id'])) {
                                $old_id = $data['post_id'];
                            } elseif (isset($data['import_id'])) {
                                $old_id = $data['import_id'];
                            }
                        }
                        if ($old_id) {
                            update_post_meta($found_id, '_really_simple_csv_importer_old_id', $old_id);
                        }
                        return $found_id;
                    }
                }
                return false;
            }
        }
        $id = $this->setAttachment($file, $data);
        if ($id) {
            if ($url) {
                update_post_meta($id, '_source_url', $url);
            }
            $old_id = null;
            if (is_array($data)) {
                if (isset($data['ID'])) {
                    $old_id = $data['ID'];
                } elseif (isset($data['post_id'])) {
                    $old_id = $data['post_id'];
                } elseif (isset($data['import_id'])) {
                    $old_id = $data['import_id'];
                }
            }
            if ($old_id) {
                update_post_meta($id, '_really_simple_csv_importer_old_id', $old_id);
            }
            return $id;
        }

        return false;
    }
    
    /**
     * Add attachment file and set as thumbnail. Automatically get remote file
     *
     * @param (string) $file
     * @return (boolean) True on success, false on failure.
     */
    public function addThumbnail($file)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            if (parse_url($file, PHP_URL_SCHEME)) {
                $file = $this->remoteGet($file);
            }
            $thumbnail_id = $this->setAttachment($file);
            if ($thumbnail_id) {
                $meta_id = set_post_thumbnail($post, $thumbnail_id);
                if ($meta_id) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * A wrapper of wp_insert_attachment and wp_update_attachment_metadata
     *
     * @param (string) $file
     * @param (array) $data
     * @return (int) Return the attachment id on success, 0 on failure.
     */
    public function setAttachment($file, $data = array())
    {
        $data = is_array($data) ? $data : array();
        $post = $this->getPost();
        if ( $file && file_exists($file) ) {
            $filename       = basename($file);
            $wp_filetype    = wp_check_filetype_and_ext($file, $filename);
            $ext            = empty( $wp_filetype['ext'] ) ? '' : $wp_filetype['ext'];
            $type           = empty( $wp_filetype['type'] ) ? '' : $wp_filetype['type'];
            $proper_filename= empty( $wp_filetype['proper_filename'] ) ? '' : $wp_filetype['proper_filename'];
            $filename       = ($proper_filename) ? $proper_filename : $filename;
            $filename       = sanitize_file_name($filename);

            $upload_dir     = wp_upload_dir();
            $guid           = $upload_dir['baseurl'] . '/' . _wp_relative_upload_path($file);

            $attachment = array_merge(array(
                'post_mime_type'    => $type,
                'guid'              => $guid,
                'post_title'        => $filename,
                'post_content'      => '',
                'post_status'       => 'inherit'
            ), $data);
            $attachment_id          = wp_insert_attachment($attachment, $file, ($post instanceof WP_Post) ? $post->ID : null);
            $attachment_metadata    = wp_generate_attachment_metadata( $attachment_id, $file );
            wp_update_attachment_metadata($attachment_id, $attachment_metadata);
            return $attachment_id;
        }
        // On failure
        return 0;
    }

    /**
     * A wrapper of update_attached_file
     *
     * @param (string) $value
     */
    protected function updateAttachment($value)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            update_attached_file($post->ID, $value);
        } else {
            $this->addError('post_is_not_set', __('WP_Post object is not set.', 'really-simple-csv-importer'));
        }
    }

    /**
     * A wrapper of wp_safe_remote_get
     *
     * @param (string) $url
     * @param (array) $args
     * @return (string) file path
     */
    public function remoteGet($url, $args = array())
    {
        global $wp_filesystem;
        if (!is_object($wp_filesystem)) {
            WP_Filesystem();
        }
        
        if ($url && is_object($wp_filesystem)) {
            // URL自体に埋め込まれている認証情報の解析
            $parsed_url = parse_url($url);
            $auth_user = '';
            $auth_pass = '';
            if (isset($parsed_url['user']) && isset($parsed_url['pass'])) {
                $auth_user = urldecode($parsed_url['user']);
                $auth_pass = urldecode($parsed_url['pass']);
                // 認証情報を除いたきれいなURLに再構成
                $url_scheme   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
                $url_host     = isset($parsed_url['host']) ? $parsed_url['host'] : '';
                $url_port     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
                $url_path     = isset($parsed_url['path']) ? $parsed_url['path'] : '';
                $url_query    = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
                $url_fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';
                $url = $url_scheme . $url_host . $url_port . $url_path . $url_query . $url_fragment;
            }
            
            // 入力されたBasic認証情報またはURLから取得した情報を使用
            $user = !empty(self::$basic_auth_user) ? self::$basic_auth_user : $auth_user;
            $pass = !empty(self::$basic_auth_pass) ? self::$basic_auth_pass : $auth_pass;
            
            if (!empty($user) && !empty($pass)) {
                if (!isset($args['headers'])) {
                    $args['headers'] = array();
                }
                $args['headers']['Authorization'] = 'Basic ' . base64_encode($user . ':' . $pass);
            }

            $response = wp_safe_remote_get($url, $args);
            if (is_wp_error($response)) {
                // wp_safe_remote_getがエラーの場合はwp_remote_getで再試行（ローカルIP制限対策）
                $response = wp_remote_get($url, $args);
            }

            if (!is_wp_error($response) && isset($response['response']['code']) && $response['response']['code'] === 200) {
                $destination = wp_upload_dir();
                $filename = basename($url);
                $filepath = $destination['path'] . '/' . wp_unique_filename($destination['path'], $filename);
                
                $body = wp_remote_retrieve_body($response);
                
                if ( $body && $wp_filesystem->put_contents($filepath , $body, FS_CHMOD_FILE) ) {
                    return $filepath;
                } else {
                    $this->addError('remote_get_failed', __('Could not get remote file.', 'really-simple-csv-importer'));
                }
            } elseif (is_wp_error($response)) {
                $this->addError($response->get_error_code(), $response->get_error_message());
            } else {
                $status_code = isset($response['response']['code']) ? $response['response']['code'] : 'Unknown';
                $this->addError('remote_get_failed_status', sprintf('Could not get remote file. HTTP Status Code: %s', $status_code));
            }
        }
        
        return '';
    }
    /**
     * 移行元のWordPressベースURLを特定する（サブディレクトリインストール対応）
     *
     * @return string ベースURL（末尾スラッシュなし）
     */
    public function getImportOriginDomain()
    {
        // ユーザーが直接指定した場合はパスを含む完全なURLをそのまま使用する
        // （サブディレクトリインストール例: https://example.com/wp/ にも対応）
        if (!empty(self::$import_origin_url)) {
            return rtrim(self::$import_origin_url, '/');
        }

        global $wpdb;

        // DBフォールバック: _source_url メタからWPベースURLを推定する
        // wp-content/uploads/ のパスを手掛かりにサブディレクトリも正しく取得する
        $source_url = $wpdb->get_var("
            SELECT meta_value
            FROM $wpdb->postmeta
            WHERE meta_key = '_source_url' AND meta_value LIKE 'http%'
            LIMIT 1
        ");
        if ($source_url) {
            $uploads_pos = strpos($source_url, '/wp-content/uploads/');
            if ($uploads_pos !== false) {
                return rtrim(substr($source_url, 0, $uploads_pos), '/');
            }
            $parsed = parse_url($source_url);
            if (isset($parsed['scheme']) && isset($parsed['host'])) {
                $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
                return $parsed['scheme'] . '://' . $parsed['host'] . $port;
            }
        }

        // DBフォールバック: 現在のホストとは異なる guid を持つアタッチメントを検索
        $current_host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        if ($current_host) {
            $guid_url = $wpdb->get_var($wpdb->prepare("
                SELECT guid
                FROM $wpdb->posts
                WHERE post_type = 'attachment' AND guid LIKE 'http%' AND guid NOT LIKE %s
                LIMIT 1
            ", '%' . $wpdb->esc_like($current_host) . '%'));
            if ($guid_url) {
                $uploads_pos = strpos($guid_url, '/wp-content/uploads/');
                if ($uploads_pos !== false) {
                    return rtrim(substr($guid_url, 0, $uploads_pos), '/');
                }
                $parsed = parse_url($guid_url);
                if (isset($parsed['scheme']) && isset($parsed['host'])) {
                    $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
                    return $parsed['scheme'] . '://' . $parsed['host'] . $port;
                }
            }
        }

        return '';
    }

    /**
     * 旧画像IDから新画像IDを解決する。見つからない場合は移行元サーバーからダウンロードを試みる
     * 
     * @param int|string $old_id 旧サーバーの画像ID
     * @return int 新しいアタッチメントID。失敗時は0
     */
    public function resolveImageId($old_id)
    {
        if (empty($old_id) || !is_numeric($old_id)) {
            return 0;
        }

        // 0. 同一サイトまたは同一IDでインポートした場合: 同じIDのアタッチメントがすでにあればそのまま使用
        $local = get_post((int) $old_id);
        if ($local && $local->post_type === 'attachment' && $local->post_status === 'inherit') {
            return (int) $old_id;
        }

        global $wpdb;

        // 1. すでにインポート済みのマッピングテーブルから検索
        $new_id = $wpdb->get_var($wpdb->prepare("
            SELECT post_id 
            FROM $wpdb->postmeta 
            WHERE meta_key = '_really_simple_csv_importer_old_id' AND meta_value = %s 
            LIMIT 1
        ", $old_id));

        if ($new_id) {
            return (int) $new_id;
        }

        // 2. マッピングがない場合、移行元サーバーの REST API を叩いて画像URLを取得し、ダウンロードを試みる
        $origin_domain = $this->getImportOriginDomain();
        if ($origin_domain) {
            $api_url = $origin_domain . '/wp-json/wp/v2/media/' . (int)$old_id;
            
            $args = array(
                'timeout' => 15,
            );

            // Basic 認証情報の付与
            $user = self::$basic_auth_user;
            $pass = self::$basic_auth_pass;
            if (!empty($user) && !empty($pass)) {
                $args['headers'] = array(
                    'Authorization' => 'Basic ' . base64_encode($user . ':' . $pass)
                );
            }

            $response = wp_safe_remote_get($api_url, $args);
            if (is_wp_error($response)) {
                $response = wp_remote_get($api_url, $args);
            }

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (is_array($body) && isset($body['source_url'])) {
                    $source_url = $body['source_url'];
                    
                    // 旧IDを紐付け情報として渡すために $data を用意
                    $data = array(
                        'ID' => (int) $old_id
                    );
                    $attachment_id = $this->addMediaFile($source_url, $data);
                    if ($attachment_id) {
                        return $attachment_id;
                    }
                }
            }
        }

        return 0;
    }
    
    /**
     * フィールド名（またはキー）からACFのフィールドキーを解決する
     *
     * @param string $selector フィールド名またはフィールドキー
     * @param array  $context  解決の手掛かり array('options_page'=>id) / array('taxonomy'=>slug)
     * @return string フィールドキー（解決できない場合は元の値）
     */
    public function getFieldKey($selector, $context = array())
    {
        // すでにフィールドキー形式（field_xxxxx）ならそのまま返す
        if (strpos($selector, 'field_') === 0) {
            return $selector;
        }

        // 1. ACF標準APIで名前から取得を試みる（環境によっては名前解決が効く）
        if (function_exists('acf_get_field')) {
            $field = acf_get_field($selector);
            if (is_array($field) && isset($field['key']) && strpos($field['key'], 'field_') === 0) {
                return $field['key'];
            }
        }

        // 2. フィールドグループを走査してフィールド名→キーを解決（確実なフォールバック）
        $map = $this->buildFieldKeyMap($context);
        if (isset($map[$selector])) {
            return $map[$selector];
        }

        return $selector;
    }

    /**
     * ACFフィールドグループを走査し、トップレベルのフィールド名→フィールドキーのマップを構築する。
     * コンテキスト（オプションページ／タクソノミー）に一致するグループを優先採用し、同名衝突に強くする。
     *
     * @param array $context array('options_page'=>id, 'menu_slug'=>slug) または array('taxonomy'=>slug)
     * @return array name => key のマップ
     */
    public function buildFieldKeyMap($context = array())
    {
        $cache_key = md5(serialize($context));
        if (isset(self::$field_key_map_cache[$cache_key])) {
            return self::$field_key_map_cache[$cache_key];
        }

        $map = array();
        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            self::$field_key_map_cache[$cache_key] = $map;
            return $map;
        }

        $groups = acf_get_field_groups();
        if (!is_array($groups)) {
            self::$field_key_map_cache[$cache_key] = $map;
            return $map;
        }

        // コンテキスト一致グループと非一致グループに振り分ける
        $matched_groups = array();
        $other_groups   = array();
        foreach ($groups as $group) {
            $is_match = false;
            if (!empty($context) && isset($group['location']) && is_array($group['location'])) {
                foreach ($group['location'] as $location_group) {
                    foreach ($location_group as $rule) {
                        if (!isset($rule['operator']) || $rule['operator'] !== '==') {
                            continue;
                        }
                        if (isset($context['options_page']) && $rule['param'] === 'options_page'
                            && ($rule['value'] === $context['options_page']
                                || (isset($context['menu_slug']) && $rule['value'] === $context['menu_slug']))) {
                            $is_match = true;
                            break 2;
                        }
                        if (isset($context['taxonomy']) && $rule['param'] === 'taxonomy'
                            && $rule['value'] === $context['taxonomy']) {
                            $is_match = true;
                            break 2;
                        }
                    }
                }
            }
            if ($is_match) {
                $matched_groups[] = $group;
            } else {
                $other_groups[] = $group;
            }
        }

        // 非一致グループを先に、一致グループを後に処理する。
        // 同名フィールドがある場合は後勝ちとなり、コンテキスト一致グループのキーが優先される。
        $ordered = array_merge($other_groups, $matched_groups);
        foreach ($ordered as $group) {
            $fields = acf_get_fields($group['key']);
            if (!is_array($fields)) {
                continue;
            }
            foreach ($fields as $field) {
                if (isset($field['name'], $field['key']) && $field['name'] !== '') {
                    $map[$field['name']] = $field['key'];
                }
            }
        }

        self::$field_key_map_cache[$cache_key] = $map;
        return $map;
    }

    /**
     * Unset WP_Post object
     */
    public function __destruct()
    {
        unset($this->post);
    }
}