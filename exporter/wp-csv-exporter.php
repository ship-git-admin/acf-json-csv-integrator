<?php
/*
 * Exporter Module (Consolidated under ACF JSON CSV Integrator)
 */
require('classes/wce-base.php');

define('WCE_VERSION', '2.0.0');
define('WCE_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('WCE_PLUGIN_NAME', trim(dirname(WCE_PLUGIN_BASENAME), '/'));
define('WCE_PLUGIN_DIR', untrailingslashit(dirname(__FILE__)));
define('WCE_PLUGIN_URL', untrailingslashit(plugins_url('', __FILE__)));

class WP_CSV_Exporter extends WCEBase
{
  protected $textdomain = 'wp-csv-exporter';

  public function __construct()
  {
    $this->init();

    // 管理メニューに追加するフック
    add_action('admin_menu', array(&$this, 'admin_menu',));

    // css, js
    add_action('admin_print_styles', array(&$this, 'head_css',));
    add_action('admin_print_scripts', array(&$this, "head_js",));

    // Ajax
    add_action('wp_head', array(&$this, 'generate_js_params'));
    add_action('wp_ajax_download', array(&$this, 'ajax_download'));
    add_action('wp_ajax_nopriv_download', array(&$this, 'ajax_download'));
    // タームエクスポート用 Ajax（認証済みユーザーのみ）
    add_action('wp_ajax_download_term', array(&$this, 'ajax_download_term'));
    // オプションページエクスポート用 Ajax（認証済みユーザーのみ）
    add_action('wp_ajax_download_options', array(&$this, 'ajax_download_options'));

    // プラグインの有効・無効時
    register_activation_hook(__FILE__, array($this, 'activationHook'));
  }


  public function init()
  {
    //他言語化
    load_plugin_textdomain($this->textdomain, false, basename(dirname(__FILE__)) . '/languages/');
  }

  /**
   * メニューを表示
   */
  public function admin_menu()
  {
    add_submenu_page('tools.php', $this->_('CSV Export', 'CSVエクスポート'), $this->_('CSV Export', 'CSVエクスポート'), 'level_7', WCE_PLUGIN_NAME, array(&$this, 'show_options_page',));
  }

  /**
   * プラグインのメインページ
   */
  public function show_options_page()
  {
    require_once WCE_PLUGIN_DIR . '/admin/index.php';
  }

  /**
   * Get admin panel URL
   */
  public function setting_url($view = '')
  {
    $query = array(
      'page' => 'wp-csv-exporter',
    );
    if ($view) {
      $query['view'] = $view;
    }
    return admin_url('tools.php?' . http_build_query($query));
  }

  /**
   * 管理画面CSS追加
   */
  public function head_css()
  {
    if (isset($_REQUEST["page"]) && $_REQUEST["page"] == WCE_PLUGIN_NAME) {
      wp_enqueue_style("wce_css", WCE_PLUGIN_URL . '/css/style.css');
      wp_enqueue_style('jquery-ui-style', WCE_PLUGIN_URL . '/css/jquery-ui.css');
    }
  }

  /*
     * 管理画面JS追加
     */
  public function head_js()
  {
    if (isset($_REQUEST["page"]) && $_REQUEST["page"] == WCE_PLUGIN_NAME) {
      wp_enqueue_script('jquery');
      wp_enqueue_script("jquery-ui-core");
      wp_enqueue_script("jquery-ui-datepicker");
      wp_enqueue_script("wce_cookie_js", WCE_PLUGIN_URL . '/js/jquery.cookie.js', array('jquery'), '', true);
      wp_enqueue_script("wce_admin_js", WCE_PLUGIN_URL . '/js/admin.js', array('jquery'), time(), true);
    }
  }

  /**
   * カスタムフィールドリストを取得
   */
  public function get_custom_field_list($type)
  {
    global $wpdb;
    $value_parameter = $type;
    $pattern = "\_%";
    $query = <<<EOL
SELECT DISTINCT meta_key
FROM $wpdb->postmeta
INNER JOIN $wpdb->posts
        ON $wpdb->posts.ID = $wpdb->postmeta.post_id
WHERE $wpdb->posts.post_type = '%s'
AND $wpdb->postmeta.meta_key NOT LIKE '%s'
EOL;
    $results = $wpdb->get_results($wpdb->prepare($query, array($value_parameter, $pattern)), ARRAY_A);

    if (is_array($results)) {
      $results = array_filter($results, function ($item) {
        // ACFの柔軟コンテンツや繰り返しフィールドのインデックス付きサブフィールドを除外
        // 例: faq_answer_supplements_0_text_content, faq_answer_supplements_1_list_items など
        return !preg_match('/^.+_\d+(_|$)/', $item['meta_key']);
      });
      $results = array_values($results);
    }

    return $results;
  }

  /**
   * Summary of generate_js_params
   * @return void
   */
  function generate_js_params()
  {
?>
    <script>
      var ajaxUrl = '<?php echo esc_html(admin_url('admin-ajax.php')); ?>';
    </script>
<?php
  }

  function ajax_download()
  {
    // 投稿タイプのダウンロード処理
    require_once WCE_PLUGIN_DIR . '/admin/download.php';
  }

  function ajax_download_term()
  {
    // タクソノミータームのダウンロード処理
    require_once WCE_PLUGIN_DIR . '/admin/download_term.php';
  }

  function ajax_download_options()
  {
    // オプションページのダウンロード処理
    require_once WCE_PLUGIN_DIR . '/admin/download_options.php';
  }

  /**
   * タクソノミーのタームメタキー一覧を取得（汎用）
   * wp_termmeta から動的に取得し、ACF内部キーを除外する
   */
  public function get_term_field_list($taxonomy)
  {
    global $wpdb;

    $results = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT DISTINCT tm.meta_key
         FROM {$wpdb->termmeta} tm
         INNER JOIN {$wpdb->term_taxonomy} tt ON tm.term_id = tt.term_id
         WHERE tt.taxonomy = %s
         AND tm.meta_key NOT LIKE %s",
        $taxonomy,
        '\_%'
      ),
      ARRAY_A
    );

    if (is_array($results)) {
      // ACFのインデックス付きサブフィールドを除外（例: field_0_sub, list_1_item など）
      $results = array_filter($results, function ($item) {
        return !preg_match('/^.+_\d+(_|$)/', $item['meta_key']);
      });
      $results = array_values($results);
    }

    return $results;
  }

  /**
   * ACFオプションページに紐づくフィールド一覧を取得（汎用）
   * ACFフィールドグループのロケーションルールから動的に解決する
   * ACFが無い場合は wp_options から参照キーで検出する
   *
   * @param string $post_id  ACFオプションページの post_id（例: menu_archive_options）
   * @param string $menu_slug ACFオプションページの menu_slug（例: menu-archive-settings）
   */
  public function get_options_field_list($post_id, $menu_slug = '')
  {
    $fields = array();

    // ACF が有効な場合：フィールドグループのロケーションルールから取得
    if (function_exists('acf_get_field_groups') && function_exists('acf_get_fields')) {
      $all_groups = acf_get_field_groups();
      foreach ($all_groups as $group) {
        $matched = false;
        foreach ($group['location'] as $location_group) {
          foreach ($location_group as $rule) {
            // menu_slug か post_id でマッチ
            if ($rule['param'] === 'options_page' && $rule['operator'] === '==') {
              if ($rule['value'] === $menu_slug || $rule['value'] === $post_id) {
                $matched = true;
                break 2;
              }
            }
          }
        }
        if (!$matched) continue;

        $group_fields = acf_get_fields($group['key']);
        if (!is_array($group_fields)) continue;

        foreach ($group_fields as $field) {
          // サブフィールド（繰り返し・フレキシブル内）ではないトップレベルのみ
          if (!preg_match('/^.+_\d+(_|$)/', $field['name'])) {
            $fields[] = array('meta_key' => $field['name']);
          }
        }
      }
    }

    // ACF が無い or フィールドが取得できない場合：wp_options の参照キーから検出
    if (empty($fields)) {
      global $wpdb;
      $like     = $wpdb->esc_like($post_id . '_') . '%';
      $not_like = $wpdb->esc_like($post_id . '__') . '%'; // _post_id_xxx 形式の内部キーを除外

      $results = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT o1.option_name
           FROM {$wpdb->options} o1
           WHERE o1.option_name LIKE %s
           AND o1.option_name NOT LIKE %s
           AND EXISTS (
             SELECT 1 FROM {$wpdb->options} o2
             WHERE o2.option_name = CONCAT('_', o1.option_name)
           )",
          $like,
          $not_like
        ),
        ARRAY_A
      );

      $prefix_len = strlen($post_id) + 1;
      foreach ($results as $item) {
        $field_name = substr($item['option_name'], $prefix_len);
        if (!preg_match('/^.+_\d+(_|$)/', $field_name)) {
          $fields[] = array('meta_key' => $field_name);
        }
      }
    }

    return $fields;
  }

  /**
   * 登録済み ACF オプションページの一覧を取得（汎用）
   * ACF が無い場合は空配列を返す
   */
  public function get_acf_options_pages_list()
  {
    if (!function_exists('acf_get_options_pages')) {
      return array();
    }

    $pages = acf_get_options_pages();
    if (empty($pages) || !is_array($pages)) {
      return array();
    }

    return $pages;
  }

  /**
   * プラグインが有効化されたときに実行
   */
  function activationHook()
  {
    // CSVを格納するDir
    $directory_path = WCE_PLUGIN_DIR . '/download/';
    if (!file_exists($directory_path)) {
      mkdir($directory_path, 0770);
    }
    chmod($directory_path, 0770);
  }
}
$wp_csv_exporter = new WP_CSV_Exporter();

// 公式ディレクトリからの更新通知を完全にブロック
add_filter('site_transient_update_plugins', function ($transient) {
  if (isset($transient->response[WCE_PLUGIN_BASENAME])) {
    unset($transient->response[WCE_PLUGIN_BASENAME]);
  }
  return $transient;
});
