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
    // ダウンロード処理
    require_once WCE_PLUGIN_DIR . '/admin/download.php';
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
