<?php
/*
 * Importer Module (Consolidated under ACF JSON CSV Integrator)
 */

if ( !defined('WP_LOAD_IMPORTERS') && !(defined('DOING_AJAX') && DOING_AJAX) )
	return;

if ( defined('DOING_AJAX') && DOING_AJAX && !defined('WP_LOAD_IMPORTERS') ) {
	define('WP_LOAD_IMPORTERS', true);
}

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( !class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require_once $class_wp_importer;
}

// Load Helpers
require dirname( __FILE__ ) . '/class-rs_csv_helper.php';
require dirname( __FILE__ ) . '/class-rscsv_import_post_helper.php';

/**
 * CSV Importer
 *
 * @package WordPress
 * @subpackage Importer
 */
if ( class_exists( 'WP_Importer' ) ) {
class RS_CSV_Importer extends WP_Importer {
	
	/** Sheet columns
	* @value array
	*/
	public $column_indexes = array();
	public $column_keys = array();

 	// User interface wrapper start
	function header() {
		echo '<div class="wrap">';
		echo '<h2>'.__('Import CSV', 'really-simple-csv-importer').'</h2>';
	}

	// User interface wrapper end
	function footer() {
		echo '</div>';
	}
	
	// Step 1
	function greet() {
		echo '<p>'.__( 'Choose a CSV (.csv) file to upload, then click Upload file and import.', 'really-simple-csv-importer' ).'</p>';
		echo '<p>'.__( 'Excel-style CSV file is unconventional and not recommended. LibreOffice has enough export options and recommended for most users.', 'really-simple-csv-importer' ).'</p>';
		echo '<p>'.__( 'Requirements:', 'really-simple-csv-importer' ).'</p>';
		echo '<ol>';
		echo '<li>'.__( 'Select UTF-8 as charset.', 'really-simple-csv-importer' ).'</li>';
		echo '<li>'.sprintf( __( 'You must use field delimiter as "%s"', 'really-simple-csv-importer'), RS_CSV_Helper::DELIMITER ).'</li>';
		echo '<li>'.__( 'You must quote all text cells.', 'really-simple-csv-importer' ).'</li>';
		echo '</ol>';
		echo '<p>'.__( 'Download example CSV files:', 'really-simple-csv-importer' );
		echo ' <a href="'.plugin_dir_url( __FILE__ ).'sample/sample.csv">'.__( 'csv', 'really-simple-csv-importer' ).'</a>,';
		echo ' <a href="'.plugin_dir_url( __FILE__ ).'sample/sample.ods">'.__( 'ods', 'really-simple-csv-importer' ).'</a>';
		echo ' '.__('(OpenDocument Spreadsheet file format for LibreOffice. Please export as csv before import)', 'really-simple-csv-importer' );
		echo '</p>';

		$bytes = wp_max_upload_size();
		$size = size_format( $bytes );
		$action = add_query_arg('step', 1);
		?>
		<form enctype="multipart/form-data" id="import-upload-form" method="post" class="wp-upload-form" action="<?php echo esc_url(wp_nonce_url($action, 'import-upload')); ?>">
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="upload"><?php _e( 'Choose a file from your computer:' ); ?></label></th>
						<td>
							<input type="file" id="upload" name="import" size="25" />
							<span class="description">(<?php printf( __( 'Maximum size: %s' ), $size ); ?>)</span>
							<input type="hidden" name="action" value="save" />
							<input type="hidden" name="max_file_size" value="<?php echo $bytes; ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="basic_auth_user">Basic認証 ユーザー名 (任意)</label></th>
						<td>
							<input type="text" id="basic_auth_user" name="basic_auth_user" class="regular-text" placeholder="例: admin" />
							<p class="description">インポート元サーバーにBasic認証がかかっている場合に入力します。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="basic_auth_pass">Basic認証 パスワード (任意)</label></th>
						<td>
							<input type="password" id="basic_auth_pass" name="basic_auth_pass" class="regular-text" placeholder="" />
						</td>
					</tr>
				</tbody>
			</table>
			<?php submit_button( __( 'Upload file and import' ) ); ?>
		</form>
		<?php
	}

	// Step 2
	function import() {
		$file = wp_import_handle_upload();

		if ( isset( $file['error'] ) ) {
			echo '<p><strong>' . __( 'Sorry, there has been an error.', 'really-simple-csv-importer' ) . '</strong><br />';
			echo esc_html( $file['error'] ) . '</p>';
			return false;
		} else if ( ! file_exists( $file['file'] ) ) {
			echo '<p><strong>' . __( 'Sorry, there has been an error.', 'really-simple-csv-importer' ) . '</strong><br />';
			printf( __( 'The export file could not be found at <code>%s</code>. It is likely that this was caused by a permissions problem.', 'really-simple-csv-importer' ), esc_html( $file['file'] ) );
			echo '</p>';
			return false;
		}
		
		$this->id = (int) $file['id'];
		$this->file = get_attached_file($this->id);

		// フォームから渡されたBasic認証情報を一時保存
		$basic_auth_user = isset($_POST['basic_auth_user']) ? sanitize_text_field($_POST['basic_auth_user']) : '';
		$basic_auth_pass = isset($_POST['basic_auth_pass']) ? sanitize_text_field($_POST['basic_auth_pass']) : '';

		// AJAXバッチ処理用のUIとJSを出力する
		$this->render_batch_ui($this->id, $basic_auth_user, $basic_auth_pass);
	}

	function render_batch_ui($attachment_id, $basic_auth_user, $basic_auth_pass) {
		// Count total lines in CSV
		$h = new RS_CSV_Helper;
		$handle = $h->fopen($this->file, 'r');
		$total_rows = 0;
		if ($handle !== false) {
			while (($data = $h->fgetcsv($handle)) !== FALSE) {
				$total_rows++;
			}
			$h->fclose($handle);
		}
		$total_data_rows = max(0, $total_rows - 1); // Exclude header
		
		echo '<div id="rs-csv-batch-import-wrap" style="max-width:800px; margin-top:20px;">';
		echo '<h2>インポートを実行中...</h2>';
		echo '<p>総件数: <strong id="rs-csv-total">'.$total_data_rows.'</strong>件 / 完了: <strong id="rs-csv-processed">0</strong>件</p>';
		echo '<div style="width:100%; background:#e5e5e5; height:24px; border-radius:3px; margin-bottom:20px; overflow:hidden;"><div id="rs-csv-progress" style="width:0%; background:#2271b1; height:100%; transition:width 0.3s ease-in-out;"></div></div>';
		echo '<div style="background:#fff; border:1px solid #ccd0d4; padding:10px; max-height:300px; overflow-y:auto;">';
		echo '<ol id="rs-csv-log" style="margin:0; padding-left:20px;"></ol>';
		echo '</div>';
		echo '<h3 id="rs-csv-complete-msg" style="display:none; color:#007017; margin-top:20px;">'.__('All Done.', 'really-simple-csv-importer').'</h3>';
		echo '</div>';
		?>
		<script type="text/javascript">
		jQuery(document).ready(function($){
			var attachment_id = <?php echo (int) $attachment_id; ?>;
			var total_rows = <?php echo (int) $total_data_rows; ?>;
			var basic_auth_user = <?php echo json_encode($basic_auth_user); ?>;
			var basic_auth_pass = <?php echo json_encode($basic_auth_pass); ?>;
			var processed = 0;
			var offset = 1; // Start after header
			var limit = 5; // Rows per batch

			function run_batch() {
				if (offset > total_rows || total_rows === 0) {
					// Done
					$.post(ajaxurl, {
						action: 'rs_csv_import_cleanup',
						attachment_id: attachment_id
					}, function(){
						$('#rs-csv-complete-msg').show();
					});
					return;
				}

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'rs_csv_import_chunk',
						attachment_id: attachment_id,
						offset: offset,
						limit: limit,
						basic_auth_user: basic_auth_user,
						basic_auth_pass: basic_auth_pass
					},
					dataType: 'json',
					success: function(response) {
						if(response.success) {
							if (response.data.log) {
								$('#rs-csv-log').append(response.data.log);
								var logDiv = $('#rs-csv-log').parent();
								logDiv.scrollTop(logDiv[0].scrollHeight);
							}
							processed += response.data.processed;
							offset = response.data.next_offset;
							
							var percent = total_rows > 0 ? Math.min(100, Math.round((processed / total_rows) * 100)) : 100;
							$('#rs-csv-progress').css('width', percent + '%');
							$('#rs-csv-processed').text(processed);

							run_batch();
						} else {
							alert('Error: ' + (response.data || 'Unknown error'));
						}
					},
					error: function(xhr, status, error) {
						alert('Ajax Error: ' + error);
					}
				});
			}

			if (total_rows > 0) {
				run_batch();
			} else {
				$('#rs-csv-complete-msg').show().text('CSVファイルにデータがありません。');
			}
		});
		</script>
		<?php
	}
	
	/**
	* Insert post and postmeta using `RSCSV_Import_Post_Helper` class.
	*
	* @param array $post
	* @param array $meta
	* @param array $terms
	* @param string $thumbnail The uri or path of thumbnail image.
	* @param bool $is_update
	* @return RSCSV_Import_Post_Helper
	*/
	public function save_post($post,$meta,$terms,$thumbnail,$is_update) {
		
		// Separate the post tags from $post array
		if (isset($post['post_tags']) && !empty($post['post_tags'])) {
			$post_tags = $post['post_tags'];
			unset($post['post_tags']);
		}

		// Special handling of attachments
		if (!empty($thumbnail) && $post['post_type'] == 'attachment') {
			$post['media_file'] = $thumbnail;
			$thumbnail = null;
		}

		// Add or update the post
		if ($is_update) {
			$h = RSCSV_Import_Post_Helper::getByID($post['ID']);
			$h->update($post);
		} else {
			$h = RSCSV_Import_Post_Helper::add($post);
		}
		
		// Set post tags
		if (isset($post_tags)) {
			$h->setPostTags($post_tags);
		}
		
		// Set meta data
		$h->setMeta($meta);
		
		// Set terms
		foreach ($terms as $key => $value) {
			$h->setObjectTerms($key, $value);
		}
		
		// Add thumbnail
		if ($thumbnail) {
			$h->addThumbnail($thumbnail);
		}
		
		return $h;
	}

	/**
	* Insert term and termmeta using `RSCSV_Import_Post_Helper` class.
	*
	* @param array $term_data
	* @param array $meta
	* @param bool $is_update
	* @return RSCSV_Import_Post_Helper
	*/
	public function save_term($term_data, $meta, $is_update) {
		if ($is_update) {
			$h = RSCSV_Import_Post_Helper::getTermByID($term_data['term_id'], $term_data['taxonomy']);
			$h->updateTerm($term_data);
		} else {
			$h = RSCSV_Import_Post_Helper::addTerm($term_data);
		}
		
		// Set term meta data
		$h->setTermMeta($meta);
		
		return $h;
	}

	// process parse csv ind insert posts
	function process_posts() {
		$h = new RS_CSV_Helper;

		$handle = $h->fopen($this->file, 'r');
		if ( $handle == false ) {
			echo '<p><strong>'.__( 'Failed to open file.', 'really-simple-csv-importer' ).'</strong></p>';
			wp_import_cleanup($this->id);
			return false;
		}
		
		$is_first = true;
		$post_statuses = get_post_stati();
		$is_term_import = false;
		
		echo '<ol>';
		
		while (($data = $h->fgetcsv($handle)) !== FALSE) {
			if ($is_first) {
				$h->parse_columns( $this, $data );
				$is_first = false;
				// CSVに taxonomy 列が含まれている場合はターム（タクソノミー）インポートとして扱う
				$is_term_import = in_array('taxonomy', $this->column_keys);
			} else {
				$this->process_single_row($data, $h, $is_term_import, $post_statuses);
			}
		}
		
		echo '</ol>';

		$h->fclose($handle);
		
		wp_import_cleanup($this->id);
		
		echo '<h3>'.__('All Done.', 'really-simple-csv-importer').'</h3>';
	}

		public function process_single_row($data, $h, $is_term_import, $post_statuses) {
				echo '<li>';
				
				$post = array();
				$is_update = false;
				$error = new WP_Error();
				
				if ($is_term_import) {
					$term_data = array();
					
					$taxonomy = $h->get_data($this, $data, 'taxonomy');
					if (!$taxonomy) {
						$error->add('taxonomy_empty', 'taxonomy列が空です。');
					} else {
						$term_data['taxonomy'] = $taxonomy;
					}

					$term_id = $h->get_data($this, $data, 'term_id');
					if ($term_id) {
						$term_exist = get_term($term_id, $taxonomy);
						if (!is_wp_error($term_exist) && !is_null($term_exist)) {
							$term_data['term_id'] = $term_id;
							$is_update = true;
						} else {
							$term_data['term_id'] = $term_id;
						}
					}

					$name = $h->get_data($this, $data, 'name');
					if ($name) {
						$term_data['name'] = $name;
					} else {
						$error->add('term_name_empty', 'name（ターム名）が空です。');
					}

					$slug = $h->get_data($this, $data, 'slug');
					if ($slug) {
						$term_data['slug'] = $slug;
					}

					$description = $h->get_data($this, $data, 'description');
					if ($description) {
						$term_data['description'] = $description;
					}

					$parent = $h->get_data($this, $data, 'parent');
					if ($parent) {
						// parent が数値以外（スラッグ/名前）の場合は、同タクソノミー内の既存タームを
						// 検索して term_id に解決する。これにより親IDを知らなくても、親を先頭に並べた
						// 1ファイルで親子階層を構築できる（親→子の順で取り込まれる前提）。
						if (!is_numeric($parent)) {
							$parent_term = get_term_by('slug', $parent, $taxonomy);
							if (!$parent_term) {
								$parent_term = get_term_by('name', $parent, $taxonomy);
							}
							if ($parent_term && !is_wp_error($parent_term)) {
								$parent = $parent_term->term_id;
							}
						}
						$term_data['parent'] = $parent;
					}

					$meta = array();
					// カスタムメタの抽出
					foreach ($data as $key => $value) {
						if ($value !== false && isset($this->column_keys[$key])) {
							$col_key = $this->column_keys[$key];
							// 標準フィールド以外をメタデータとして扱う
							if (!in_array($col_key, array('term_id', 'name', 'slug', 'description', 'parent', 'taxonomy'))) {
								$meta[$col_key] = $value;
							}
						}
					}

					if (!$error->get_error_codes()) {
						$result = $this->save_term($term_data, $meta, $is_update);
						if ($result->isError()) {
							$error = $result->getError();
						} else {
							echo esc_html(sprintf('ターム "%s" の処理が完了しました。', $term_data['name']));
						}
					}
				} else {
					// (string) (required) post type
					$post_type = $h->get_data($this,$data,'post_type');
					if ($post_type) {
						if (post_type_exists($post_type)) {
							$post['post_type'] = $post_type;
						} else {
							$error->add( 'post_type_exists', sprintf(__('Invalid post type "%s".', 'really-simple-csv-importer'), $post_type) );
						}
					} else {
						echo __('Note: Please include post_type value if that is possible.', 'really-simple-csv-importer').'<br>';
					}
					
					// (int) post id
					$post_id = $h->get_data($this,$data,'ID');
					$post_id = ($post_id) ? $post_id : $h->get_data($this,$data,'post_id');
					if ($post_id) {
						$post_exist = get_post($post_id);
						if ( is_null( $post_exist ) ) { // if the post id is not exists
							$post['import_id'] = $post_id;
						} else {
							if ( !$post_type || $post_exist->post_type == $post_type ) {
								$post['ID'] = $post_id;
								$is_update = true;
							} else {
								$error->add( 'post_type_check', sprintf(__('The post type value from your csv file does not match the existing data in your database. post_id: %d, post_type(csv): %s, post_type(db): %s', 'really-simple-csv-importer'), $post_id, $post_type, $post_exist->post_type) );
							}
						}
					}
					
					// (string) post slug
					$post_name = $h->get_data($this,$data,'post_name');
					if ($post_name) {
						$post['post_name'] = $post_name;
					}
					
					// (login or ID) post_author
					$post_author = $h->get_data($this,$data,'post_author');
					if ($post_author) {
						if (is_numeric($post_author)) {
							$user = get_user_by('id',$post_author);
						} else {
							$user = get_user_by('login',$post_author);
						}
						if (isset($user) && is_object($user)) {
							$post['post_author'] = $user->ID;
							unset($user);
						}
					}
					
					// (string) publish date
					$post_date = $h->get_data($this,$data,'post_date');
					if ($post_date) {
						$post['post_date'] = date("Y-m-d H:i:s", strtotime($post_date));
					}
					$post_date_gmt = $h->get_data($this,$data,'post_date_gmt');
					if ($post_date_gmt) {
						$post['post_date_gmt'] = date("Y-m-d H:i:s", strtotime($post_date_gmt));
					}
					
					// (string) post status
					$post_status = $h->get_data($this,$data,'post_status');
					if ($post_status) {
						if (in_array($post_status, $post_statuses)) {
							$post['post_status'] = $post_status;
						}
					}
					
					// (string) post password
					$post_password = $h->get_data($this,$data,'post_password');
					if ($post_password) {
						$post['post_password'] = $post_password;
					}
					
					// (string) post title
					$post_title = $h->get_data($this,$data,'post_title');
					if ($post_title) {
						$post['post_title'] = $post_title;
					}
					
					// (string) post content
					$post_content = $h->get_data($this,$data,'post_content');
					if ($post_content) {
						$post['post_content'] = $post_content;
					}
					
					// (string) post excerpt
					$post_excerpt = $h->get_data($this,$data,'post_excerpt');
					if ($post_excerpt) {
						$post['post_excerpt'] = $post_excerpt;
					}
					
					// (int) post parent
					$post_parent = $h->get_data($this,$data,'post_parent');
					if ($post_parent) {
						$post['post_parent'] = $post_parent;
					}
					
					// (int) menu order
					$menu_order = $h->get_data($this,$data,'menu_order');
					if ($menu_order) {
						$post['menu_order'] = $menu_order;
					}
					
					// (string) comment status
					$comment_status = $h->get_data($this,$data,'comment_status');
					if ($comment_status) {
						$post['comment_status'] = $comment_status;
					}
					
					// (string, comma separated) slug of post categories
					$post_category = $h->get_data($this,$data,'post_category');
					if ($post_category) {
						$categories = preg_split("/,+/", $post_category);
						if ($categories) {
							$post['post_category'] = wp_create_categories($categories);
						}
					}
					
					// (string, comma separated) name of post tags
					$post_tags = $h->get_data($this,$data,'post_tags');
					if ($post_tags) {
						$post['post_tags'] = $post_tags;
					}
					
					// (string) post thumbnail image uri
					$post_thumbnail = $h->get_data($this,$data,'post_thumbnail');
					
					$meta = array();
					$tax = array();
	
					// add any other data to post meta
					foreach ($data as $key => $value) {
						if ($value !== false && isset($this->column_keys[$key])) {
							// check if meta is custom taxonomy
							if (substr($this->column_keys[$key], 0, 4) == 'tax_') {
								// (string, comma divided) name of custom taxonomies 
								$customtaxes = preg_split("/,+/", $value);
								$taxname = substr($this->column_keys[$key], 4);
								$tax[$taxname] = array();
								foreach($customtaxes as $key => $value ) {
									$tax[$taxname][] = $value;
								}
							}
							else {
								$meta[$this->column_keys[$key]] = $value;
							}
						}
					}
					
					/**
					 * Filter post data.
					 *
					 * @param array $post (required)
					 * @param bool $is_update
					 */
					$post = apply_filters( 'really_simple_csv_importer_save_post', $post, $is_update );
					/**
					 * Filter meta data.
					 *
					 * @param array $meta (required)
					 * @param array $post
					 * @param bool $is_update
					 */
					$meta = apply_filters( 'really_simple_csv_importer_save_meta', $meta, $post, $is_update );
					/**
					 * Filter taxonomy data.
					 *
					 * @param array $tax (required)
					 * @param array $post
					 * @param bool $is_update
					 */
					$tax = apply_filters( 'really_simple_csv_importer_save_tax', $tax, $post, $is_update );
					/**
					 * Filter thumbnail URL or path.
					 *
					 * @since 1.3
					 *
					 * @param string $post_thumbnail (required)
					 * @param array $post
					 * @param bool $is_update
					 */
					$post_thumbnail = apply_filters( 'really_simple_csv_importer_save_thumbnail', $post_thumbnail, $post, $is_update );
	
					/**
					 * Option for dry run testing
					 *
					 * @since 0.5.7
					 *
					 * @param bool false
					 */
					$dry_run = apply_filters( 'really_simple_csv_importer_dry_run', false );
					
					if (!$error->get_error_codes() && $dry_run == false) {
						
						/**
						 * Get Alternative Importer Class name.
						 *
						 * @since 0.6
						 *
						 * @param string Class name to override Importer class. Default to null (do not override).
						 */
						$class = apply_filters( 'really_simple_csv_importer_class', null );
						
						// save post data
						if ($class && class_exists($class,false)) {
							$importer = new $class;
							$result = $importer->save_post($post,$meta,$tax,$post_thumbnail,$is_update);
						} else {
							$result = $this->save_post($post,$meta,$tax,$post_thumbnail,$is_update);
						}
						
						if ($result->isError()) {
							$error = $result->getError();
						} else {
							$post_object = $result->getPost();
							
							if (is_object($post_object)) {
								/**
								 * Fires adter the post imported.
								 *
								 * @since 1.0
								 *
								 * @param WP_Post $post_object
								 */
								do_action( 'really_simple_csv_importer_post_saved', $post_object );
							}
							
							echo esc_html(sprintf(__('Processing "%s" done.', 'really-simple-csv-importer'), $post_title));
						}
					}
				}
				
				// show error messages
				foreach ($error->get_error_messages() as $message) {
					echo esc_html($message).'<br>';
				}
				
				echo '</li>';
	}
	// dispatcher
	function dispatch() {
		$this->header();
		
		if (empty ($_GET['step']))
			$step = 0;
		else
			$step = (int) $_GET['step'];

		switch ($step) {
			case 0 :
				$this->greet();
				break;
			case 1 :
				check_admin_referer('import-upload');
				set_time_limit(0);
				$result = $this->import();
				if ( is_wp_error( $result ) )
					echo $result->get_error_message();
				break;
		}
		
		$this->footer();
	}
	
}

// テキストドメインを init で読み込む（WP 6.7+ の too-early 警告を回避）
function really_simple_csv_importer_load_textdomain() {
    load_plugin_textdomain( 'really-simple-csv-importer', false, dirname( plugin_basename(__FILE__) ) . '/languages' );
}
add_action( 'init', 'really_simple_csv_importer_load_textdomain' );

// インポーターを admin_init で登録（init 後なので翻訳が確実に読み込まれた後に実行される）
function really_simple_csv_importer() {
    $rs_csv_importer = new RS_CSV_Importer();
    register_importer('csv', __('CSV', 'really-simple-csv-importer'), __('Import posts, categories, tags, custom fields from simple csv file.', 'really-simple-csv-importer'), array ($rs_csv_importer, 'dispatch'));
}
add_action( 'admin_init', 'really_simple_csv_importer' );

// 公式ディレクトリからの更新通知を完全にブロック
add_filter('site_transient_update_plugins', function($transient) {
    $plugin_basename = plugin_basename(__FILE__);
    if (isset($transient->response[$plugin_basename])) {
        unset($transient->response[$plugin_basename]);
    }
    return $transient;
});


// AJAX バッチ処理のハンドラー
add_action('wp_ajax_rs_csv_import_chunk', 'rs_csv_import_chunk_handler');
function rs_csv_import_chunk_handler() {
	// Debug handler
	set_error_handler(function($errno, $errstr, $errfile, $errline) {
		if (!(error_reporting() & $errno)) return;
		throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
	});

	try {
		// 必要な管理画面用ファイルを読み込む
		require_once(ABSPATH . 'wp-admin/includes/taxonomy.php');
		require_once(ABSPATH . 'wp-admin/includes/image.php');
		require_once(ABSPATH . 'wp-admin/includes/file.php');
		require_once(ABSPATH . 'wp-admin/includes/media.php');

		if (!current_user_can('import')) {
		wp_send_json_error('Permission denied');
	}

	$attachment_id = isset($_POST['attachment_id']) ? (int) $_POST['attachment_id'] : 0;
	$offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 1;
	$limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 5;
	$basic_auth_user = isset($_POST['basic_auth_user']) ? sanitize_text_field($_POST['basic_auth_user']) : '';
	$basic_auth_pass = isset($_POST['basic_auth_pass']) ? sanitize_text_field($_POST['basic_auth_pass']) : '';

	if (!$attachment_id) wp_send_json_error('No attachment ID');

	$file = get_attached_file($attachment_id);
	if (!$file || !file_exists($file)) {
		wp_send_json_error('File not found');
	}

	if (class_exists('RSCSV_Import_Post_Helper')) {
		RSCSV_Import_Post_Helper::$basic_auth_user = $basic_auth_user;
		RSCSV_Import_Post_Helper::$basic_auth_pass = $basic_auth_pass;
	}

	$importer = new RS_CSV_Importer();
	$importer->id = $attachment_id;
	$importer->file = $file;

	$h = new RS_CSV_Helper;
	$handle = $h->fopen($file, 'r');
	if ($handle == false) {
		wp_send_json_error('Failed to open file');
	}

	// Read header
	$header = $h->fgetcsv($handle);
	$importer->parse_columns($importer, $header);
	$is_term_import = in_array('taxonomy', $importer->column_keys);

	// Seek to offset
	$current_row = 1;
	while ($current_row < $offset && ($data = $h->fgetcsv($handle)) !== FALSE) {
		$current_row++;
	}

	$processed = 0;
	$post_statuses = get_post_stati();
	
	ob_start();
	while ($processed < $limit && ($data = $h->fgetcsv($handle)) !== FALSE) {
		$importer->process_single_row($data, $h, $is_term_import, $post_statuses);
		$processed++;
		$current_row++;
	}
	$log = ob_get_clean();

	$h->fclose($handle);

	wp_send_json_success(array(
		'processed' => $processed,
		'next_offset' => $current_row,
		'log' => $log
	));
	} catch (Exception $e) {
		wp_send_json_error('Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
	} catch (Error $e) {
		wp_send_json_error('Fatal Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
	} finally {
		restore_error_handler();
	}
}

add_action('wp_ajax_rs_csv_import_cleanup', 'rs_csv_import_cleanup_handler');
function rs_csv_import_cleanup_handler() {
	if (!current_user_can('import')) wp_send_json_error('Permission denied');
	$attachment_id = isset($_POST['attachment_id']) ? (int) $_POST['attachment_id'] : 0;
	if ($attachment_id) {
		wp_import_cleanup($attachment_id);
	}
	wp_send_json_success();
}
} // class_exists( 'WP_Importer' )
