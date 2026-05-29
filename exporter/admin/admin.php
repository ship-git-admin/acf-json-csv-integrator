<?php
// ダウンロードフォルダ
$filename = WCE_PLUGIN_DIR . '/download/';

// 投稿タイプを取得
$post_types = get_post_types(array(), 'objects');

// 特定の投稿タイプを除外
unset(
  $post_types['attachment'],
  $post_types['revision'],
  $post_types['nav_menu_item'],
  $post_types['acf'],
  $post_types['wpcf7_contact_form']
);

// タクソノミーを取得
$post_taxonomies = get_taxonomies(array(), 'objects');

// 特定のタクソノミーを除外
unset(
  $post_taxonomies['post_tag'],
  $post_taxonomies['nav_menu'],
  $post_taxonomies['link_category'],
  $post_taxonomies['post_format']
);

// ACF オプションページ一覧を取得（汎用）
$acf_options_pages = $this->get_acf_options_pages_list();

if ($wce_options = get_option('wce_options')) {
  $wce_post_type = $wce_options['post_type'];
}
?>
<script type="text/javascript">
  jQuery(function($) {

    <?php foreach ($post_types as $post_type) : ?>
      // 投稿タイプフォームのバリデーション
      $('#form_<?php echo esc_attr($post_type->name) ?>').submit(function() {
        if ($("#form_<?php echo esc_attr($post_type->name) ?> input.post_status:checked").length == 0) {
          alert('<?php $this->e('"Status" is a required field.', '"ステータス"は必須項目です') ?>');
          return false;
        }
        if (!$('#form_<?php echo esc_attr($post_type->name) ?> input.limit').val().match(/^[0-9]+$/)) {
          alert('<?php $this->e('The number of posts must be entered in numerical format.', '記事数は数値のみが入力可能です。') ?>');
          return false;
        }
      });
    <?php endforeach; ?>

  });
</script>

<div class="wrap plugin-wrap">
  <div class="plugin-main-area">
    <h2><?php $this->e('WP CSV Exporter', 'WP CSV Exporter') ?></h2>
    <p><?php $this->e('Please set the fields you would like to export with CSV.', 'CSVでエクスポートする項目を設定してください。') ?></p>

    <?php if (!is_writable($filename)) : ?>
      <div class="error">
        <p>
          <?php $this->e('Please adjust your permissions so that you are able to edit the below directory.', '以下のディクレトリに書き込みができるようにパーミッションを変更してください。') ?><br>
          <strong><?php echo esc_html($filename); ?></strong>
        </p>
      </div>
    <?php endif; ?>

    <!-- ========================================
         投稿タイプ エクスポート
    ======================================== -->
    <div class="tab-group" id="tab-group-post">
      <h3><?php $this->e('Post Types', '投稿タイプ') ?></h3>
      <ul class="plugin_tab">
        <?php foreach ($post_types as $post_type) : ?>
          <li class="plugin_tab-<?php echo esc_attr($post_type->name); ?>"><?php echo esc_html($post_type->labels->name); ?></li>
        <?php endforeach; ?>
      </ul>

      <div class="plugin_contents">
        <?php foreach ($post_types as $post_type) : ?>
          <div class="plugin_content js-csv-content" data-post-type="<?php echo esc_attr($post_type->name) ?>">
            <form action="<?php echo admin_url('admin-ajax.php'); ?>" method="post" id="form_<?php echo esc_attr($post_type->name) ?>" target="_blank">
              <input type="hidden" name="action" value="download">
              <?php wp_nonce_field('csv_exporter'); ?>

              <div class="tool-box">
                <h3><?php echo esc_html($post_type->labels->name); ?> <?php $this->e('Settings', '設定') ?></h3>
                <ul class="setting_list">
                  <li><label><input type="radio" name="post_id" value="post_id" checked="checked" required>*<?php $this->e('Post ID', '投稿ID') ?></label></li>
                  <li><label><input type="radio" name="type" value="<?php echo esc_attr($post_type->name) ?>" checked="checked" required>*<?php $this->e('Post Type', '投稿タイプ') ?></label></li>
                  <li><label><input type="checkbox" name="posts_values[]" value="post_name" checked="checked"><?php $this->e('Slug', 'スラッグ') ?></label></li>
                  <li><label><input type="checkbox" name="posts_values[]" value="post_title" checked="checked"><?php $this->e('Post Title', '記事タイトル') ?></label></li>
                  <li><label><input type="checkbox" name="posts_values[]" value="post_content" checked="checked"><?php $this->e('Post Content', '記事本文') ?></label></li>
                  <li><label><input type="checkbox" name="posts_values[]" value="post_excerpt"><?php $this->e('Post Excerpt', '抜粋') ?></label></li>
                  <li><label><input type="checkbox" name="post_thumbnail" value="post_thumbnail"><?php $this->e('Thumbnail', 'アイキャッチ画像') ?></label></li>
                  <li><label><input type="checkbox" name="post_parent" value="post_parent"><?php $this->e('post_parent', 'post_parent') ?></label></li>
                  <li><label><input type="checkbox" name="menu_order" value="menu_order"><?php $this->e('menu_order', 'menu_order') ?></label></li>
                  <li><?php $this->e('Status', 'ステータス') ?>
                    <ul>
                      <li><label><input type="checkbox" name="post_status[]" value="publish" class="post_status" checked="checked"><?php $this->e('Publish', '公開済み（publish）') ?></label></li>
                      <li><label><input type="checkbox" name="post_status[]" value="pending" class="post_status"><?php $this->e('Pending', 'レビュー待ち（pending）') ?></label></li>
                      <li><label><input type="checkbox" name="post_status[]" value="draft" class="post_status"><?php $this->e('Draft', '下書き（draft）') ?></label></li>
                      <li><label><input type="checkbox" name="post_status[]" value="future" class="post_status"><?php $this->e('Future', 'スケジュール済み（future）') ?></label></li>
                      <li><label><input type="checkbox" name="post_status[]" value="private" class="post_status"><?php $this->e('Private', '非公開（private）') ?></label></li>
                      <li><label><input type="checkbox" name="post_status[]" value="trash" class="post_status"><?php $this->e('Trash', 'ゴミ箱入り（trash）') ?></label></li>
                      <li><label><input type="checkbox" name="post_status[]" value="inherit" class="post_status"><?php $this->e('Inherit', 'inherit') ?></label></li>
                    </ul>
                  </li>
                  <li><label><input type="checkbox" name="posts_values[]" value="post_author"><?php $this->e('Author', '投稿者') ?></label></li>
                  <li><label><input type="checkbox" name="posts_values[]" value="post_date"><?php $this->e('Post Date', '公開日時') ?></label></li>
                  <li><label><input type="checkbox" name="posts_values[]" value="post_modified"><?php $this->e('Date Modified', '変更日時') ?></label></li>
                  <li><label><input type="checkbox" name="post_tags" value="post_tags"><?php $this->e('Tags', 'タグ') ?></label></li>
                </ul>
              </div>

              <hr>

              <div class="tool-box">
                <h3><?php $this->e('Taxonomies', 'タクソノミー') ?>
                  <span class="all_checks">
                    [
                    <a href="javascript:void(0);" class="all_checked" data-target="#form_<?php echo esc_attr($post_type->name) ?> .cf_taxonomy"><?php $this->e('Select all', '全選択') ?></a>
                    <a href="javascript:void(0);" class="all_checkout" data-target="#form_<?php echo esc_attr($post_type->name) ?> .cf_taxonomy"><?php $this->e('Unselect all', '全解除') ?></a>
                    ]
                  </span>
                </h3>
                <ul class="setting_list">
                  <?php
                  $num = 0;
                  foreach ($post_taxonomies as $post_taxonomy) :
                    if (!is_object_in_taxonomy($post_type->name, $post_taxonomy->name)) continue;
                  ?>
                    <li><label><input type="checkbox" name="taxonomies[]" class="cf_taxonomy" checked="checked" value="<?php echo esc_attr($post_taxonomy->name); ?>"> <?php echo esc_html($post_taxonomy->labels->name); ?></label></li>
                  <?php
                    $num++;
                  endforeach;
                  if ($num == 0) :
                  ?>
                    <li><?php $this->e('There are no registered custom taxonomies.', '登録されているカスタムタクソノミーはありません。') ?></li>
                  <?php endif; ?>
                </ul>
              </div>

              <hr>

              <div class="tool-box">
                <h3><?php $this->e('Custom Fields', 'カスタムフィールド') ?>
                  <span class="all_checks">
                    [
                    <a href="javascript:void(0);" class="all_checked" data-target="#form_<?php echo esc_attr($post_type->name) ?> .cf_checkbox"><?php $this->e('Select all', '全選択') ?></a>
                    <a href="javascript:void(0);" class="all_checkout" data-target="#form_<?php echo esc_attr($post_type->name) ?> .cf_checkbox"><?php $this->e('Unselect all', '全解除') ?></a>
                    ]
                  </span>
                </h3>
                <?php $cf_results = $this->get_custom_field_list($post_type->name); ?>
                <ul class="setting_list">
                  <?php if (!empty($cf_results)) : ?>
                    <?php foreach ($cf_results as $key => $value) : ?>
                      <li><label><input type="checkbox" name="cf_fields[]" class="cf_checkbox" checked="checked" value="<?php echo esc_attr($value['meta_key']); ?>"> <?php echo esc_html($value['meta_key']); ?></label></li>
                    <?php endforeach; ?>
                  <?php else : ?>
                    <li><?php $this->e('There are not registered custom fields.', '登録されているカスタムフィールドはありません。') ?></li>
                  <?php endif; ?>
                </ul>
              </div>

              <hr>

              <div class="tool-box">
                <h3><?php $this->e('Others', 'その他') ?></h3>
                <table class="setting_table">
                  <tbody>
                    <tr>
                      <th style="vertical-align:top;"><?php $this->e('Number of posts to download.', 'ダウンロードする記事件数') ?></th>
                      <td>
                        <input type="number" name="limit" class="limit" value="0" data-target=".offset-<?php echo esc_attr($post_type->name) ?>"> <?php $this->e('*All downloaded if "0" selected.', '※0の場合はすべてダウンロード') ?>
                        <div class="offset offset-<?php echo esc_attr($post_type->name) ?>" style="display:none;">
                          <br><input type="number" name="offset" class="offset_input" value="0"> <?php $this->e('*Number of posts to start', '※ダウンロードする記事の開始位置') ?>
                        </div>
                      </td>
                    </tr>
                    <tr>
                      <th><?php $this->e('Sorting by date.') ?></th>
                      <td class="vt">
                        <label style="margin-right:3em;"><input type="radio" name="order_by" value="DESC" checked="checked"> <?php $this->e('DESC') ?></label>
                        <label><input type="radio" name="order_by" value="ASC"> <?php $this->e('ASC') ?></label>
                      </td>
                    </tr>
                    <tr>
                      <th><?php $this->e('Select period to display.', '公開日の期間指定') ?></th>
                      <td id="post_date-datepicker-wrap">
                        <label>From</label>
                        <input type="text" name="post_date_from" class="post_date-datepicker" placeholder="yyyy-mm-dd" />
                        <label>To</label>
                        <input type="text" name="post_date_to" class="post_date-datepicker" placeholder="yyyy-mm-dd" />
                      </td>
                    </tr>
                    <tr>
                      <th><?php $this->e('Select date modified.', '変更日の期間指定') ?></th>
                      <td id="post_modified-datepicker-wrap">
                        <label>From</label>
                        <input type="text" name="post_modified_from" class="post_date-datepicker" placeholder="yyyy-mm-dd" />
                        <label>To</label>
                        <input type="text" name="post_modified_to" class="post_date-datepicker" placeholder="yyyy-mm-dd" />
                      </td>
                    </tr>
                    <tr class="vt">
                      <th><?php $this->e('Character Code', '文字コード') ?></th>
                      <td>
                        <ul class="setting_list">
                          <li><label><input type="radio" name="string_code" value="UTF-8" checked="checked"> UTF-8</label></li>
                          <li><label><input type="radio" name="string_code" value="SJIS"> Shift_JIS</label></li>
                        </ul>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <p class="submit">
                <input type="submit" class="button-primary"
                  value="<?php $this->e('Export', 'エクスポート') ?> <?php echo esc_attr($post_type->labels->name); ?> CSV"
                  <?php if (!is_writable($filename)) : ?>disabled<?php endif; ?> />
              </p>
            </form>
          </div>
        <?php endforeach; ?>
      </div><!-- /.plugin_contents (post) -->
    </div><!-- /.tab-group#tab-group-post -->


    <!-- ========================================
         タクソノミータームエクスポート
    ======================================== -->
    <?php if (!empty($post_taxonomies)) : ?>
    <hr style="margin: 30px 0;">
    <div class="tab-group" id="tab-group-taxonomy">
      <h3><?php $this->e('Taxonomy Terms Export', 'タクソノミータームエクスポート') ?></h3>

      <ul class="plugin_tab">
        <?php foreach ($post_taxonomies as $taxonomy) : ?>
          <li><?php echo esc_html($taxonomy->labels->name); ?></li>
        <?php endforeach; ?>
      </ul>

      <div class="plugin_contents">
        <?php foreach ($post_taxonomies as $taxonomy) : ?>
          <?php
          // このタクソノミーのカスタムフィールド一覧を動的取得
          $term_cf_list = $this->get_term_field_list($taxonomy->name);
          ?>
          <div class="plugin_content">
            <form action="<?php echo admin_url('admin-ajax.php'); ?>" method="post"
                  id="form_term_<?php echo esc_attr($taxonomy->name) ?>" target="_blank">
              <input type="hidden" name="action" value="download_term">
              <input type="hidden" name="taxonomy" value="<?php echo esc_attr($taxonomy->name); ?>">
              <?php wp_nonce_field('csv_exporter'); ?>

              <div class="tool-box">
                <h3><?php echo esc_html($taxonomy->labels->name); ?> <?php $this->e('Settings', '設定') ?></h3>
                <ul class="setting_list">
                  <li><label><input type="checkbox" name="term_fields[]" value="term_id" checked="checked"> term_id</label></li>
                  <li><label><input type="checkbox" name="term_fields[]" value="name" checked="checked"><?php $this->e('Name', '名前') ?></label></li>
                  <li><label><input type="checkbox" name="term_fields[]" value="slug" checked="checked"><?php $this->e('Slug', 'スラッグ') ?></label></li>
                  <li><label><input type="checkbox" name="term_fields[]" value="description"><?php $this->e('Description', '説明') ?></label></li>
                  <li><label><input type="checkbox" name="term_fields[]" value="parent"><?php $this->e('Parent', '親ターム') ?></label></li>
                </ul>
              </div>

              <hr>

              <div class="tool-box">
                <h3><?php $this->e('Custom Fields', 'カスタムフィールド') ?>
                  <span class="all_checks">
                    [
                    <a href="javascript:void(0);" class="all_checked"
                       data-target="#form_term_<?php echo esc_attr($taxonomy->name) ?> .cf_checkbox"><?php $this->e('Select all', '全選択') ?></a>
                    <a href="javascript:void(0);" class="all_checkout"
                       data-target="#form_term_<?php echo esc_attr($taxonomy->name) ?> .cf_checkbox"><?php $this->e('Unselect all', '全解除') ?></a>
                    ]
                  </span>
                </h3>
                <ul class="setting_list">
                  <?php if (!empty($term_cf_list)) : ?>
                    <?php foreach ($term_cf_list as $cf) : ?>
                      <li>
                        <label>
                          <input type="checkbox" name="cf_fields[]" class="cf_checkbox" checked="checked"
                                 value="<?php echo esc_attr($cf['meta_key']); ?>">
                          <?php echo esc_html($cf['meta_key']); ?>
                        </label>
                      </li>
                    <?php endforeach; ?>
                  <?php else : ?>
                    <li><?php $this->e('There are not registered custom fields.', '登録されているカスタムフィールドはありません。') ?></li>
                  <?php endif; ?>
                </ul>
              </div>

              <hr>

              <div class="tool-box">
                <h3><?php $this->e('Others', 'その他') ?></h3>
                <table class="setting_table">
                  <tbody>
                    <tr class="vt">
                      <th><?php $this->e('Character Code', '文字コード') ?></th>
                      <td>
                        <ul class="setting_list">
                          <li><label><input type="radio" name="string_code" value="UTF-8" checked="checked"> UTF-8</label></li>
                          <li><label><input type="radio" name="string_code" value="SJIS"> Shift_JIS</label></li>
                        </ul>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <p class="submit">
                <input type="submit" class="button-primary"
                  value="<?php $this->e('Export', 'エクスポート') ?> <?php echo esc_attr($taxonomy->labels->name); ?> CSV"
                  <?php if (!is_writable($filename)) : ?>disabled<?php endif; ?> />
              </p>
            </form>
          </div>
        <?php endforeach; ?>
      </div><!-- /.plugin_contents (taxonomy) -->
    </div><!-- /.tab-group#tab-group-taxonomy -->
    <?php endif; ?>


    <!-- ========================================
         オプションページエクスポート（ACF必須）
    ======================================== -->
    <?php if (!empty($acf_options_pages)) : ?>
    <hr style="margin: 30px 0;">
    <div class="tab-group" id="tab-group-options">
      <h3><?php $this->e('Options Pages Export', 'オプションページエクスポート') ?></h3>

      <ul class="plugin_tab">
        <?php foreach ($acf_options_pages as $slug => $page) : ?>
          <li><?php echo esc_html($page['page_title'] ?? $slug); ?></li>
        <?php endforeach; ?>
      </ul>

      <div class="plugin_contents">
        <?php foreach ($acf_options_pages as $slug => $page) :
          $op_post_id    = $page['post_id']    ?? $slug;
          $op_page_title = $page['page_title'] ?? $slug;
          $op_cf_list    = $this->get_options_field_list($op_post_id, $slug);
        ?>
          <div class="plugin_content">
            <form action="<?php echo admin_url('admin-ajax.php'); ?>" method="post"
                  id="form_options_<?php echo esc_attr(sanitize_key($op_post_id)) ?>" target="_blank">
              <input type="hidden" name="action" value="download_options">
              <input type="hidden" name="options_page_id" value="<?php echo esc_attr($op_post_id); ?>">
              <?php wp_nonce_field('csv_exporter'); ?>

              <div class="tool-box">
                <h3><?php echo esc_html($op_page_title); ?> <?php $this->e('Settings', '設定') ?></h3>
                <ul class="setting_list">
                  <?php if (!empty($op_cf_list)) : ?>
                    <li style="margin-bottom:6px;">
                      <span class="all_checks">
                        [
                        <a href="javascript:void(0);" class="all_checked"
                           data-target="#form_options_<?php echo esc_attr(sanitize_key($op_post_id)) ?> .cf_checkbox"><?php $this->e('Select all', '全選択') ?></a>
                        <a href="javascript:void(0);" class="all_checkout"
                           data-target="#form_options_<?php echo esc_attr(sanitize_key($op_post_id)) ?> .cf_checkbox"><?php $this->e('Unselect all', '全解除') ?></a>
                        ]
                      </span>
                    </li>
                    <?php foreach ($op_cf_list as $cf) : ?>
                      <li>
                        <label>
                          <input type="checkbox" name="cf_fields[]" class="cf_checkbox" checked="checked"
                                 value="<?php echo esc_attr($cf['meta_key']); ?>">
                          <?php echo esc_html($cf['meta_key']); ?>
                        </label>
                      </li>
                    <?php endforeach; ?>
                  <?php else : ?>
                    <li><?php $this->e('There are not registered custom fields.', '登録されているカスタムフィールドはありません。') ?></li>
                  <?php endif; ?>
                </ul>
              </div>

              <hr>

              <div class="tool-box">
                <h3><?php $this->e('Others', 'その他') ?></h3>
                <table class="setting_table">
                  <tbody>
                    <tr class="vt">
                      <th><?php $this->e('Character Code', '文字コード') ?></th>
                      <td>
                        <ul class="setting_list">
                          <li><label><input type="radio" name="string_code" value="UTF-8" checked="checked"> UTF-8</label></li>
                          <li><label><input type="radio" name="string_code" value="SJIS"> Shift_JIS</label></li>
                        </ul>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <p class="submit">
                <input type="submit" class="button-primary"
                  value="<?php $this->e('Export', 'エクスポート') ?> <?php echo esc_attr($op_page_title); ?> CSV"
                  <?php if (!is_writable($filename) || empty($op_cf_list)) : ?>disabled<?php endif; ?> />
              </p>
            </form>
          </div>
        <?php endforeach; ?>
      </div><!-- /.plugin_contents (options) -->
    </div><!-- /.tab-group#tab-group-options -->
    <?php endif; ?>

  </div><!-- /.plugin-main-area -->

  <!-- サイドエリア -->
  <div class="plugin-side-area">
    <div class="plugin-side">
      <div class="inner">
        <div class="box">
          <?php $this->e('The detailed explanation of this plugin is this url.(Japanese Only)'); ?>
          <a href="http://www.kigurumi.asia/imake/3972/" target="_blank">http://www.kigurumi.asia/imake/3972/</a>
        </div>
      </div>
    </div>
  </div><!-- /.plugin-side-area -->

</div><!-- /.wrap -->
