<?php

// 必要な管理画面用ファイルを読み込む
if (defined('ABSPATH')) {
  require_once ABSPATH . 'wp-admin/includes/taxonomy.php';
  require_once ABSPATH . 'wp-admin/includes/image.php';
  require_once ABSPATH . 'wp-admin/includes/file.php';
  require_once ABSPATH . 'wp-admin/includes/media.php';
}

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

    if (is_wp_error($term_info) && ($term_info->get_error_code() === 'missing_parent' || $term_info->get_error_code() === 'parent_does_not_exist')) {
      echo esc_html(sprintf('⚠️ 警告: ターム "%s" の親タームID "%s" が見つからないため、親なし（0）として作成しました。<br>', $name, $args['parent']));
      $args['parent'] = 0;
      $term_info = wp_insert_term($name, $taxonomy, $args);
    }

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
      if (is_wp_error($term_info) && ($term_info->get_error_code() === 'parent_does_not_exist' || $term_info->get_error_code() === 'missing_parent')) {
        echo esc_html(sprintf('⚠️ 警告: ターム "%s" の親タームID "%s" が見つからないため、親なし（0）として更新しました。<br>', $term->name, $args['parent']));
        $args['parent'] = 0;
        $term_info = wp_update_term($term->term_id, $taxonomy, $args);
      }
      // スラッグが他のタームで使用済みの場合、スラッグ以外（名前・説明・親）だけ更新する
      if (is_wp_error($term_info) && $term_info->get_error_code() === 'duplicate_term_slug' && isset($args['slug'])) {
        echo esc_html(sprintf('⚠️ 警告: スラッグ "%s" は他のタームで使用済みのため、既存スラッグ "%s" を維持して名前等のみ更新しました。<br>', $args['slug'], $term->slug));
        unset($args['slug']);
        $term_info = wp_update_term($term->term_id, $taxonomy, $args);
      }
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
          // タクソノミーをコンテキストとしてフィールドキーを解決
          $field_key = $this->getFieldKey($key, array('taxonomy' => $term->taxonomy));

          if (strpos($field_key, 'field_') === 0) {
            // 配列内の画像をローカルIDに置換
            $decoded_value = $this->processAcfArrayImages($decoded_value);
            // 正しいフィールドキーで update_field を実行（柔軟コンテンツ・リピーターを正しく保存）
            // ※ update_field は値が不変の場合も false を返すため、戻り値は失敗判定に使わない
            update_field($field_key, $decoded_value, 'term_' . $term->term_id);
          } elseif ($this->hasParentColumn($key, array_keys($data))) {
            // 親グループ列が存在する場合はそちら経由で取り込まれるため黙ってスキップ
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
      } elseif (strpos($key, self::SCF_PREFIX) === 0) {
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
            $field_key = $this->getFieldKey($key);
            if (strpos($field_key, 'field_') === 0) {
              // 配列内の画像URLを自動でダウンロードしてメディア登録しIDに置換
              $decoded_value = $this->processAcfArrayImages($decoded_value);
              // 正しいフィールドキーで保存（柔軟コンテンツ・リピーターを正しく格納）
              $this->acfUpdateField($field_key, $decoded_value);
            } elseif ($this->hasParentColumn($key, array_keys($data))) {
              // 親グループ列が存在する場合、この子フラット列のデータは親列経由で
              // 取り込まれるため黙ってスキップする（重複インポート・警告ノイズ防止）
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

    $new_array = array();
    // 2. 通常の再帰処理
    foreach ($array as $key => $value) {
      // 現在のフィールドのタイプをACF設定から取得
      $current_type = '';
      $new_key = $key;
      if (function_exists('acf_get_field') && is_string($key)) {
        $field_info = null;
        if (strpos($key, 'field_') === 0) {
          $field_info = acf_get_field($key);
          if (is_array($field_info) && isset($field_info['name']) && $field_info['name'] !== '') {
            $new_key = $field_info['name'];
          }
        }

        if (!$field_info) {
          if (strpos($key, 'field_') === 0) {
            $field_info = acf_get_field($key);
          } elseif (function_exists('acf_get_field_by_name')) {
            $field_info = acf_get_field_by_name($key);
          }
        }
        if (is_array($field_info) && isset($field_info['type'])) {
          $current_type = $field_info['type'];
        }
      }

      if (is_array($value)) {
        $new_array[$new_key] = $this->processAcfArrayImages($value, $current_type ? $current_type : $parent_type);
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
            $new_array[$new_key] = $new_id;
          } else {
            // 解決できなかった場合も整数型で保持する（ACFの型互換性を維持）
            $new_array[$new_key] = (int) $value;
          }
        }
        // URLの場合
        elseif ($is_image_field && is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
          $attachment_id = $this->addMediaFile($value);
          if ($attachment_id) {
            $new_array[$new_key] = $attachment_id;
          } else {
            $found_id = attachment_url_to_postid($value);
            if ($found_id) {
              $new_array[$new_key] = $found_id;
            } else {
              $new_array[$new_key] = $value;
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
                $new_array[$new_key] = $attachment_id;
              } else {
                $new_array[$new_key] = $value;
              }
            } else {
              $new_array[$new_key] = $value;
            }
          } else {
            $new_array[$new_key] = $value;
          }
        } else {
          $new_array[$new_key] = $value;
        }
      }
    }

    return $new_array;
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
      } elseif (is_array($data)) {
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
  /**
   * $data 配列から旧ID（ID / post_id / import_id）を取り出す
   *
   * @param (array|null) $data
   * @return (int|null)
   */
  protected function extractOldId($data)
  {
    if (!is_array($data)) {
      return null;
    }
    if (isset($data['ID'])) {
      return $data['ID'];
    }
    if (isset($data['post_id'])) {
      return $data['post_id'];
    }
    if (isset($data['import_id'])) {
      return $data['import_id'];
    }
    return null;
  }

  public function addMediaFile($file, $data = null)
  {
    $url = '';
    if (parse_url($file, PHP_URL_SCHEME)) {
      $url = $file;
      $old_id = $this->extractOldId($data);

      // ① 同じURLのメディアが既に存在する場合はそのIDを返す（重複ダウンロード防止）+ 強制修復
      $existing_id = attachment_url_to_postid($url);
      if ($existing_id) {
        if ($old_id) {
          update_post_meta($existing_id, '_really_simple_csv_importer_old_id', $old_id);
        }
        $this->repairAttachment($existing_id, $url);
        return $existing_id;
      }

      // ①b 過去に同じURLからダウンロード済みのメディアを _source_url メタから検索（再インポート時の重複防止）+ 強制修復
      global $wpdb;
      $existing_id = $wpdb->get_var($wpdb->prepare("
                SELECT post_id
                FROM $wpdb->postmeta
                WHERE meta_key = '_source_url' AND meta_value = %s
                LIMIT 1
            ", $url));
      if ($existing_id && $this->attachmentFileExists($existing_id)) {
        if ($old_id) {
          update_post_meta($existing_id, '_really_simple_csv_importer_old_id', $old_id);
        }
        $this->repairAttachment($existing_id, $url);
        return (int) $existing_id;
      }

      // ①c 年月パスとファイル名、および拡張子が一致する既存メディアがあれば紐付け + 強制修復
      $pos = strpos($url, '/wp-content/uploads/');
      if ($pos !== false) {
        $relative_path = substr($url, $pos + strlen('/wp-content/uploads/'));

        if (!empty($relative_path)) {
          // jpeg/jpg の表記揺れに対応するため、検索パターンを作成
          $paths_to_check = array($relative_path);
          if (preg_match('/\.jpe?g$/i', $relative_path)) {
            $paths_to_check[] = preg_replace('/\.jpe?g$/i', '.jpg', $relative_path);
            $paths_to_check[] = preg_replace('/\.jpe?g$/i', '.jpeg', $relative_path);
          }
          $paths_to_check = array_unique($paths_to_check);

          // プレースホルダーとクエリの作成
          $placeholders = implode(',', array_fill(0, count($paths_to_check), '%s'));
          $query = $wpdb->prepare("
            SELECT DISTINCT post_id 
            FROM $wpdb->postmeta 
            WHERE meta_key = '_wp_attached_file' AND meta_value IN ($placeholders)
          ", $paths_to_check);

          $found_ids = $wpdb->get_col($query);
          $all_found_ids = array_unique((array)$found_ids);

          if (!empty($all_found_ids)) {
            $return_id = 0;
            foreach ($all_found_ids as $fid) {
              $fid = (int) $fid;
              if ($old_id) {
                update_post_meta($fid, '_really_simple_csv_importer_old_id', $old_id);
              }
              $this->repairAttachment($fid, $url); // 拡張子が一致する正しい画像のみを修復
              if (!$return_id) {
                $return_id = $fid;
              }
            }
            return $return_id;
          }
        }
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
      $old_id = $this->extractOldId($data);
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
      // addMediaFile 経由にすることでURL重複チェック（既存メディアの再利用）を効かせる
      $thumbnail_id = $this->addMediaFile($file);
      if ($thumbnail_id && !is_wp_error($thumbnail_id)) {
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
    if ($file && file_exists($file)) {
      $filename       = basename($file);
      $wp_filetype    = wp_check_filetype_and_ext($file, $filename);
      $ext            = empty($wp_filetype['ext']) ? '' : $wp_filetype['ext'];
      $type           = empty($wp_filetype['type']) ? '' : $wp_filetype['type'];
      $proper_filename = empty($wp_filetype['proper_filename']) ? '' : $wp_filetype['proper_filename'];
      $filename       = ($proper_filename) ? $proper_filename : $filename;
      $filename       = sanitize_file_name($filename);

      $upload_dir     = wp_upload_dir();

      // --- WebP競合を防ぐための拡張子無視ファイル名一意化処理 ---
      $dest_dir = $upload_dir['path'];
      $filename_only = pathinfo($filename, PATHINFO_FILENAME);
      $ext_only = pathinfo($filename, PATHINFO_EXTENSION);

      $new_filename = $filename;
      $suffix = 1;

      // ディレクトリ内に拡張子を無視して同じベース名を持つファイルが存在するかチェック
      while (true) {
        $pattern = $dest_dir . '/' . $filename_only . '.*';
        $matches = glob($pattern);
        if (empty($matches)) {
          break; // 競合がなければOK
        }

        // 競合がある場合、ベース名に枝番を付与して再試行
        $filename_only = pathinfo($filename, PATHINFO_FILENAME) . '-' . $suffix;
        $new_filename = $filename_only . ($ext_only ? '.' . $ext_only : '');
        $suffix++;
      }

      // もしファイル名が変更された場合、一時ファイルをリネーム
      if ($new_filename !== $filename) {
        $new_file = dirname($file) . '/' . $new_filename;
        if (@rename($file, $new_file)) {
          $file = $new_file;
          $filename = $new_filename;
        }
      }
      // -----------------------------------------------------

      $guid           = $upload_dir['baseurl'] . '/' . _wp_relative_upload_path($file);

      $attachment = array_merge(array(
        'post_mime_type'    => $type,
        'guid'              => $guid,
        'post_title'        => $filename,
        'post_content'      => '',
        'post_status'       => 'inherit'
      ), $data);
      $attachment_id          = wp_insert_attachment($attachment, $file, ($post instanceof WP_Post) ? $post->ID : null);
      // 挿入失敗（WP_Error または 0）の場合は 0 を返す
      if (!$attachment_id || is_wp_error($attachment_id)) {
        return 0;
      }
      $attachment_metadata    = wp_generate_attachment_metadata($attachment_id, $file);
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

        if ($body && $wp_filesystem->put_contents($filepath, $body, FS_CHMOD_FILE)) {
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
   * 移行元のWordPressベースURL候補をすべて取得する
   * ユーザー指定 → _source_url メタ由来 → guid 由来 の優先順で重複なしのリストを返す
   *
   * @return array ベースURLの配列（末尾スラッシュなし）
   */
  public function getImportOriginCandidates()
  {
    static $cache = null;
    if ($cache !== null) {
      return $cache;
    }

    $candidates = array();

    // 1. ユーザー指定URL（最優先・パス込み）
    if (!empty(self::$import_origin_url)) {
      $candidates[] = rtrim(self::$import_origin_url, '/');
    }

    global $wpdb;

    // 2. _source_url メタ由来のベースURL（過去のインポートで記録された移行元）
    $source_urls = $wpdb->get_col("
            SELECT DISTINCT meta_value
            FROM $wpdb->postmeta
            WHERE meta_key = '_source_url' AND meta_value LIKE 'http%'
            LIMIT 10
        ");
    foreach ((array) $source_urls as $u) {
      $pos = strpos($u, '/wp-content/uploads/');
      if ($pos !== false) {
        $base = rtrim(substr($u, 0, $pos), '/');
        if (!in_array($base, $candidates, true)) {
          $candidates[] = $base;
        }
      }
    }

    // 3. guid 由来（現在のホストとは異なるアタッチメントguid）
    $current_host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : parse_url(home_url(), PHP_URL_HOST);
    if ($current_host) {
      $guid_urls = $wpdb->get_col($wpdb->prepare("
                SELECT DISTINCT guid
                FROM $wpdb->posts
                WHERE post_type = 'attachment' AND guid LIKE 'http%' AND guid NOT LIKE %s
                LIMIT 10
            ", '%' . $wpdb->esc_like($current_host) . '%'));
      foreach ((array) $guid_urls as $u) {
        $pos = strpos($u, '/wp-content/uploads/');
        if ($pos !== false) {
          $base = rtrim(substr($u, 0, $pos), '/');
          if (!in_array($base, $candidates, true)) {
            $candidates[] = $base;
          }
        }
      }
    }

    $cache = $candidates;
    return $candidates;
  }

  /**
   * 壊れたアタッチメント（投稿はあるが実ファイルがない）を指定URLから再ダウンロードして修復する
   *
   * @param int    $attachment_id 修復対象のアタッチメントID
   * @param string $url           ダウンロード元URL
   * @return bool 修復に成功したら true
   */
  public function repairAttachment($attachment_id, $url)
  {
    $file = $this->remoteGet($url);
    if (!$file || !file_exists($file)) {
      return false;
    }

    // 古いWebPファイルが存在する場合は物理的に削除（WebP変換処理がスキップされるのを防ぐため）
    $old_file = get_attached_file($attachment_id);
    if ($old_file) {
      $dir = dirname($old_file);
      $basename_only = pathinfo($old_file, PATHINFO_FILENAME);

      // 同じベース名を持つすべてのWebPファイルを一括検索して物理削除（サイズ違いも根こそぎ削除）
      $webp_pattern = $dir . '/' . $basename_only . '*.webp';
      $matched_webps = glob($webp_pattern);
      if (is_array($matched_webps)) {
        foreach ($matched_webps as $webp_file) {
          if (file_exists($webp_file)) {
            @unlink($webp_file);
          }
        }
      }
    }

    // 実ファイルをアタッチメントに紐付け直す
    update_attached_file($attachment_id, $file);

    // MIMEタイプを補正
    $filetype = wp_check_filetype(basename($file));
    if (!empty($filetype['type'])) {
      wp_update_post(array(
        'ID'             => $attachment_id,
        'post_mime_type' => $filetype['type'],
      ));
    }

    // サムネイル等のメタデータを生成
    $metadata = wp_generate_attachment_metadata($attachment_id, $file);
    if (!is_wp_error($metadata) && $metadata) {
      wp_update_attachment_metadata($attachment_id, $metadata);
    }
    update_post_meta($attachment_id, '_source_url', $url);

    return true;
  }

  /**
   * 移行元サーバーから旧IDに対応する画像ファイルURLを取得する（REST API → HTMLスクレイピング）
   *
   * @param int    $old_id        旧サーバーの画像ID
   * @param string $origin_domain 移行元ベースURL
   * @return string 画像ファイルURL。見つからない場合は空文字
   */
  public function findRemoteSourceUrl($old_id, $origin_domain)
  {
    // REST API無効が判明している移行元はスキップする（同一実行内のキャッシュ）
    static $rest_disabled = array();

    $args = array('timeout' => 15);

    // Basic 認証情報の付与
    $user = self::$basic_auth_user;
    $pass = self::$basic_auth_pass;
    if (!empty($user) && !empty($pass)) {
      $args['headers'] = array(
        'Authorization' => 'Basic ' . base64_encode($user . ':' . $pass)
      );
    }

    // 1. REST API
    if (empty($rest_disabled[$origin_domain])) {
      $api_url = rtrim($origin_domain, '/') . '/wp-json/wp/v2/media/' . (int) $old_id;
      $response = wp_safe_remote_get($api_url, $args);
      if (is_wp_error($response)) {
        $response = wp_remote_get($api_url, $args);
      }
      if (!is_wp_error($response)) {
        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
          $body = json_decode(wp_remote_retrieve_body($response), true);
          if (is_array($body) && isset($body['source_url'])) {
            return $body['source_url'];
          }
        } elseif ($code === 401 || $code === 403) {
          // REST API自体が無効化されている場合は以降このオリジンへのREST問い合わせを省略
          $body = wp_remote_retrieve_body($response);
          if (strpos($body, 'rest_disabled') !== false || strpos($body, 'rest_forbidden') !== false) {
            $rest_disabled[$origin_domain] = true;
          }
        }
      }
    }

    // 2. アタッチメントページのHTMLスクレイピング（REST API無効時のフォールバック）
    $html_url = rtrim($origin_domain, '/') . '/?attachment_id=' . (int) $old_id;
    $args['redirection'] = 10;
    $html_response = wp_remote_get($html_url, $args);
    if (!is_wp_error($html_response) && wp_remote_retrieve_response_code($html_response) === 200) {
      $html_body = wp_remote_retrieve_body($html_response);
      // 2a. 標準テンプレートのアタッチメント表示 <p class="attachment"><a href="(画像URL)">
      if (preg_match('/<p class="attachment"><a href=[\'\"]([^\'\"]+)[\'\"]/i', $html_body, $matches)) {
        return $matches[1];
      }
      // 2b. og:image メタタグ（uploads配下のURLのみ採用）
      if (preg_match('/property=[\'\"]og:image[\'\"] content=[\'\"]([^\'\"]+wp-content\/uploads\/[^\'\"]+)[\'\"]/i', $html_body, $matches)) {
        return $matches[1];
      }
      // 2c. フォールバック: アタッチメントページと判別できる場合のみ、uploads配下へのリンクを採用
      // （トップページ等へのリダイレクト先で無関係な画像を拾わないようガード）
      if (
        strpos($html_body, 'attachment') !== false
        && preg_match('/href=[\'\"]([^\'\"]+wp-content\/uploads\/[^\'\"]+\.(jpg|jpeg|png|gif|webp|pdf|zip))[\'\"]/i', $html_body, $matches)
      ) {
        return $matches[1];
      }
    }

    return '';
  }

  /**
   * 同一サーバー上の移行元WordPressからファイルを直接コピーしてアタッチメント登録する
   *
   * @param int    $old_id        旧サーバーの画像ID
   * @param string $origin_domain 移行元ベースURL
   * @return int 新しいアタッチメントID。失敗時は0
   */
  public function resolveViaLocalCopy($old_id, $origin_domain)
  {
    $local_wp_path = $this->resolveLocalWpPath($origin_domain);
    if (!$local_wp_path) {
      return 0;
    }
    $local_conn = $this->getSourceDbConnection($local_wp_path);
    if (!$local_conn) {
      return 0;
    }

    // 移行元DBから _wp_attached_file と post_title を取得
    $config_content = file_get_contents($local_wp_path . '/wp-config.php');
    preg_match("/\\\$table_prefix\s*=\s*'([^']+)'/i", $config_content, $m_prefix);
    $prefix = isset($m_prefix[1]) ? $m_prefix[1] : 'wp_';

    $q_file = mysqli_query($local_conn, "SELECT meta_value FROM {$prefix}postmeta WHERE post_id = " . (int)$old_id . " AND meta_key = '_wp_attached_file' LIMIT 1");
    $row_file = $q_file ? mysqli_fetch_assoc($q_file) : null;
    $attached_file = isset($row_file['meta_value']) ? $row_file['meta_value'] : '';

    $q_post = mysqli_query($local_conn, "SELECT post_title, post_content, post_mime_type FROM {$prefix}posts WHERE ID = " . (int)$old_id . " LIMIT 1");
    $row_post = $q_post ? mysqli_fetch_assoc($q_post) : null;

    mysqli_close($local_conn);

    if (!$attached_file || !$row_post) {
      return 0;
    }

    $source_file_path = rtrim($local_wp_path, '/') . '/wp-content/uploads/' . $attached_file;
    if (!file_exists($source_file_path)) {
      return 0;
    }

    // 移行先のアップロードディレクトリへコピー
    $wp_uploads = wp_upload_dir();
    $dest_file_name = basename($source_file_path);
    $dest_sub_dir = dirname($attached_file);

    $dest_dir = $wp_uploads['basedir'] . '/' . $dest_sub_dir;
    if (!file_exists($dest_dir)) {
      wp_mkdir_p($dest_dir);
    }

    $dest_file_path = $dest_dir . '/' . wp_unique_filename($dest_dir, $dest_file_name);
    if (!copy($source_file_path, $dest_file_path)) {
      return 0;
    }

    // アタッチメントを挿入
    $attachment_data = array(
      'post_mime_type' => $row_post['post_mime_type'],
      'guid'           => $wp_uploads['baseurl'] . '/' . $dest_sub_dir . '/' . basename($dest_file_path),
      'post_title'     => $row_post['post_title'],
      'post_content'   => $row_post['post_content'],
      'post_status'    => 'inherit'
    );
    $attachment_id = wp_insert_attachment($attachment_data, $dest_file_path);
    if (!$attachment_id || is_wp_error($attachment_id)) {
      return 0;
    }

    $attachment_metadata = wp_generate_attachment_metadata($attachment_id, $dest_file_path);
    wp_update_attachment_metadata($attachment_id, $attachment_metadata);

    // 移行元URLと旧IDをメタデータとして保存
    update_post_meta($attachment_id, '_really_simple_csv_importer_old_id', $old_id);
    $source_url = rtrim($origin_domain, '/') . '/wp-content/uploads/' . $attached_file;
    update_post_meta($attachment_id, '_source_url', $source_url);

    return $attachment_id;
  }

  /**
   * 旧画像IDから新画像IDを解決する。
   * 解決チェーン: マッピング → 同一ID健全チェック → 壊れたアタッチメントの修復 → 各移行元候補からの取得
   *
   * @param int|string $old_id 旧サーバーの画像ID
   * @return int 新しいアタッチメントID。失敗時は0
   */
  public function resolveImageId($old_id)
  {
    // 同一実行内の解決結果キャッシュ（成功・失敗とも記憶し、重複した問い合わせを防ぐ）
    static $resolved_cache = array();

    if (empty($old_id) || !is_numeric($old_id)) {
      return 0;
    }
    $old_id = (int) $old_id;

    if (isset($resolved_cache[$old_id])) {
      return $resolved_cache[$old_id];
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
      $new_id = (int) $new_id;
      // マッピング先が健全（実ファイルあり）か確認してから採用する
      if ($this->attachmentFileExists($new_id)) {
        $resolved_cache[$old_id] = $new_id;
        return $new_id;
      }
      // 壊れている場合は _source_url から修復を試みる
      $src = get_post_meta($new_id, '_source_url', true);
      if ($src && filter_var($src, FILTER_VALIDATE_URL) && $this->repairAttachment($new_id, $src)) {
        $resolved_cache[$old_id] = $new_id;
        return $new_id;
      }
      // 修復失敗時は以降の解決チェーンへ進む
    }

    // 2. 同一IDのローカルアタッチメントをチェック
    $local = get_post($old_id);
    $local_is_attachment = ($local && $local->post_type === 'attachment');
    if ($local_is_attachment) {
      // 2a. 健全（実ファイルがディスク上に存在）ならそのまま採用
      if ($this->attachmentFileExists($old_id)) {
        $resolved_cache[$old_id] = $old_id;
        return $old_id;
      }
      // 2b. 壊れている（DBレコードのみ・ファイル欠損）場合: _source_url から再ダウンロードして修復
      $src = get_post_meta($old_id, '_source_url', true);
      if ($src && filter_var($src, FILTER_VALIDATE_URL)) {
        if ($this->repairAttachment($old_id, $src)) {
          $resolved_cache[$old_id] = $old_id;
          return $old_id;
        }
      }
      // 修復失敗時は以降の解決チェーンへ進む
    }

    // 3. 各移行元候補に対して解決を試みる
    $candidates = $this->getImportOriginCandidates();
    foreach ($candidates as $origin_domain) {
      // 3a. 同一サーバー上の移行元からファイルを直接コピー
      if (!$local_is_attachment) {
        $copied_id = $this->resolveViaLocalCopy($old_id, $origin_domain);
        if ($copied_id) {
          $resolved_cache[$old_id] = $copied_id;
          return $copied_id;
        }
      }

      // 3b. REST API → HTMLスクレイピングで画像URLを特定しダウンロード
      $source_url = $this->findRemoteSourceUrl($old_id, $origin_domain);
      if ($source_url) {
        if ($local_is_attachment) {
          // 同一IDの壊れたアタッチメントが存在する場合はその場で修復（ID維持）
          if ($this->repairAttachment($old_id, $source_url)) {
            $resolved_cache[$old_id] = $old_id;
            return $old_id;
          }
        }
        // import_id（推奨ID）として渡す: IDが空いていれば旧IDのまま新規作成される。
        // ※ 'ID' を渡すと既存投稿の「更新」と解釈され、存在しないIDでは 0 が返り失敗するため不可
        $attachment_id = $this->addMediaFile($source_url, array('import_id' => $old_id));
        if ($attachment_id && !is_wp_error($attachment_id)) {
          $resolved_cache[$old_id] = (int) $attachment_id;
          return (int) $attachment_id;
        }
      }
    }

    $resolved_cache[$old_id] = 0;
    return 0;
  }

  /**
   * アタッチメントが健全（実ファイルがディスク上に存在する）かを判定する
   *
   * @param int $attachment_id アタッチメントID
   * @return bool
   */
  public function attachmentFileExists($attachment_id)
  {
    $file = get_attached_file($attachment_id);
    return ($file && file_exists($file));
  }

  /**
   * 移行元ドメインURLから同じサーバー上の物理ディレクトリパスを解決する
   *
   * @param string $origin_url 移行元URL
   * @return string 物理パス（末尾スラッシュなし）。解決できない場合は空文字
   */
  private function resolveLocalWpPath($origin_url)
  {
    $parsed = parse_url($origin_url);
    if (!isset($parsed['host'])) {
      return '';
    }
    $host = $parsed['host'];
    $path = isset($parsed['path']) ? trim($parsed['path'], '/') : '';

    $current_path = ABSPATH;
    $user_base = '';
    if (strpos($current_path, '/home/') === 0) {
      $parts = explode('/', trim($current_path, '/'));
      if (count($parts) >= 2) {
        $user_base = '/home/' . $parts[1];
      }
    }

    if (empty($user_base)) {
      return '';
    }

    // 候補1: Xserver方式 (username/domain/public_html/path)
    $candidate1 = rtrim($user_base, '/') . '/' . $host . '/public_html';
    if (!empty($path)) {
      $candidate1 .= '/' . $path;
    }
    if (file_exists($candidate1 . '/wp-config.php')) {
      return $candidate1;
    }

    // 候補2: ドメインディレクトリ直下 (username/domain/path)
    $candidate2 = rtrim($user_base, '/') . '/' . $host;
    if (!empty($path)) {
      $candidate2 .= '/' . $path;
    }
    if (file_exists($candidate2 . '/wp-config.php')) {
      return $candidate2;
    }

    // 候補3: Heteml方式 (username/web/domain/path)
    if (strpos($current_path, '/web/') !== false) {
      $web_pos = strpos($current_path, '/web/');
      $heteml_base = substr($current_path, 0, $web_pos + 5);
      $candidate3 = rtrim($heteml_base, '/') . '/' . $host;
      if (!empty($path)) {
        $candidate3 .= '/' . $path;
      }
      if (file_exists($candidate3 . '/wp-config.php')) {
        return $candidate3;
      }
    }

    return '';
  }

  /**
   * 指定されたWordPressディレクトリの wp-config.php を読み取りDBコネクションを取得する
   *
   * @param string $wp_path WordPress物理パス
   * @return mysqli|null DBコネクション
   */
  private function getSourceDbConnection($wp_path)
  {
    $config_path = rtrim($wp_path, '/') . '/wp-config.php';
    if (!file_exists($config_path)) {
      return null;
    }
    $config_content = file_get_contents($config_path);

    preg_match("/define\(\s*'DB_NAME'\s*,\s*'([^']+)'\s*\)/i", $config_content, $m_name);
    preg_match("/define\(\s*'DB_USER'\s*,\s*'([^']+)'\s*\)/i", $config_content, $m_user);
    preg_match("/define\(\s*'DB_PASSWORD'\s*,\s*'([^']+)'\s*\)/i", $config_content, $m_pass);
    preg_match("/define\(\s*'DB_HOST'\s*,\s*'([^']+)'\s*\)/i", $config_content, $m_host);

    $db_name = isset($m_name[1]) ? $m_name[1] : '';
    $db_user = isset($m_user[1]) ? $m_user[1] : '';
    $db_pass = isset($m_pass[1]) ? $m_pass[1] : '';
    $db_host = isset($m_host[1]) ? $m_host[1] : 'localhost';

    if (empty($db_name) || empty($db_user)) {
      return null;
    }

    $conn = @mysqli_connect($db_host, $db_user, $db_pass, $db_name);
    if (!$conn) {
      return null;
    }
    mysqli_set_charset($conn, 'utf8');
    return $conn;
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
            if (
              isset($context['options_page']) && $rule['param'] === 'options_page'
              && ($rule['value'] === $context['options_page']
                || (isset($context['menu_slug']) && $rule['value'] === $context['menu_slug']))
            ) {
              $is_match = true;
              break 2;
            }
            if (
              isset($context['taxonomy']) && $rule['param'] === 'taxonomy'
              && $rule['value'] === $context['taxonomy']
            ) {
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
   * 指定キーの「親グループ列」が同じデータセット内に存在するか判定する。
   * エクスポーターは group 型の親列（JSON）と子のフラット列（{親}_{子}）を両方出力するため、
   * 子列が解決できない場合でも、親列があればデータは親列経由で取り込まれる（=子列はスキップしてよい）。
   *
   * @param string $key      対象の列名
   * @param array  $all_keys データセット内の全列名
   * @return bool 親列が存在すれば true
   */
  public function hasParentColumn($key, $all_keys)
  {
    foreach ($all_keys as $other) {
      if ($other !== $key && strpos($key, $other . '_') === 0) {
        return true;
      }
    }
    return false;
  }

  /**
   * Unset WP_Post object
   */
  public function __destruct()
  {
    unset($this->post);
  }
}
