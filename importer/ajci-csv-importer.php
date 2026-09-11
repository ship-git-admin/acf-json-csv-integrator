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
require dirname( __FILE__ ) . '/class-ajci_csv_helper.php';
require dirname( __FILE__ ) . '/class-ajci_import_post_helper.php';
require_once dirname( __FILE__ ) . '/class-ajci-options-schema.php';
require_once dirname( __FILE__ ) . '/class-ajci-import-security.php';

/**
 * CSV Importer
 *
 * @package WordPress
 * @subpackage Importer
 */
if ( class_exists( 'WP_Importer' ) ) {
class AJCI_CSV_Importer extends WP_Importer {
	
	/** Sheet columns
	* @value array
	*/
	public $column_indexes = array();
	public $column_keys = array();
	public $options_page_slug = '';

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
		echo '<li>'.sprintf( __( 'You must use field delimiter as "%s"', 'really-simple-csv-importer'), AJCI_CSV_Helper::DELIMITER ).'</li>';
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
					<tr>
						<th scope="row"><label for="import_origin_url">移行元サイトURL (任意)</label></th>
						<td>
							<input type="text" id="import_origin_url" name="import_origin_url" class="regular-text" placeholder="例: https://example.com" />
							<p class="description">画像IDから画像のダウンロードを試みる際の移行元サーバーのアドレス。本プラグイン（v1.0.27以降）でエクスポートしたCSVには移行元URLが自動で埋め込まれているため、通常は未入力のままで構いません（入力した場合はそちらを優先します）。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ajci_options_page_slug">オプションCSVの対象ページ</label></th>
						<td>
							<select id="ajci_options_page_slug" name="ajci_options_page_slug">
								<option value="">オプションCSV以外／未選択</option>
								<?php foreach (AJCI_Options_Schema::get_registered_pages() as $page) : ?>
									<option value="<?php echo esc_attr($page['menu_slug']); ?>"><?php echo esc_html($page['page_title'] ?? $page['menu_slug']); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">オプションCSVの場合は、保存先を必ず選択してください。CSV内のoptions_page_idは選択値との照合にのみ使用します。</p>
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
		$upload_error = AJCI_Import_Security::validate_upload_input();
		if (is_wp_error($upload_error)) {
			echo '<p><strong>' . esc_html($upload_error->get_error_message()) . '</strong></p>';
			return false;
		}

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

		$selected_options_page = '';
		if (isset($_POST['ajci_options_page_slug']) && is_string($_POST['ajci_options_page_slug'])) {
			$selected_options_page = sanitize_key(wp_unslash($_POST['ajci_options_page_slug']));
		}
		$this->options_page_slug = $selected_options_page;

		// 業務データの更新・画像取得より先にCSV全体を検証する。
		$preflight = AJCI_Options_Schema::preflight_file($this->file, $selected_options_page);
		if (is_wp_error($preflight)) {
			wp_import_cleanup($this->id);
			echo '<p><strong>CSVの事前検証に失敗しました。</strong><br>' . esc_html($preflight->get_error_message()) . '</p>';
			return false;
		}

		$job = AJCI_Import_Security::create_job($this->id, $this->file, $preflight);
		if (is_wp_error($job)) {
			wp_import_cleanup($this->id);
			echo '<p><strong>インポートジョブを作成できませんでした。</strong><br>' . esc_html($job->get_error_message()) . '</p>';
			return false;
		}

		// フォームから渡されたBasic認証情報と移行元URLを一時保存
		$basic_auth_user = (isset($_POST['basic_auth_user']) && is_string($_POST['basic_auth_user']))
			? sanitize_text_field(wp_unslash($_POST['basic_auth_user'])) : '';
		$basic_auth_pass = (isset($_POST['basic_auth_pass']) && is_string($_POST['basic_auth_pass']))
			? sanitize_text_field(wp_unslash($_POST['basic_auth_pass'])) : '';
		$import_origin_url = (isset($_POST['import_origin_url']) && is_string($_POST['import_origin_url']))
			? esc_url_raw(wp_unslash($_POST['import_origin_url'])) : '';

		// AJAXバッチ処理用のUIとJSを出力する
		$this->render_batch_ui($job, $basic_auth_user, $basic_auth_pass, $import_origin_url);
	}

	function render_batch_ui($job, $basic_auth_user, $basic_auth_pass, $import_origin_url = '') {
		$total_data_rows = (int) $job['total_rows'];
		$client_config = array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'jobId' => $job['job_id'],
			'chunkNonce' => wp_create_nonce('ajci_csv_import:chunk:' . $job['job_id']),
			'cleanupNonce' => wp_create_nonce('ajci_csv_import:cleanup:' . $job['job_id']),
			'totalRows' => $total_data_rows,
			'basicAuthUser' => $basic_auth_user,
			'basicAuthPass' => $basic_auth_pass,
			'importOriginUrl' => $import_origin_url,
		);
		
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
			var ajciImport = <?php echo wp_json_encode($client_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
			var total_rows = ajciImport.totalRows;
			var processed = 0;
			var offset = 1; // Start after header
			var stopped = false;

			function stopWithError(message) {
				stopped = true;
				$('#rs-csv-complete-msg').show().css('color', '#b32d2e').text(message || 'インポートを停止しました。');
				alert(message || 'インポートを停止しました。');
			}

			function cleanup() {
				$.ajax({
					url: ajciImport.ajaxUrl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'ajci_csv_import_cleanup',
						job_id: ajciImport.jobId,
						_ajax_nonce: ajciImport.cleanupNonce
					}
				}).done(function(response) {
					if (response && response.success) {
						$('#rs-csv-complete-msg').show();
					} else {
						stopWithError('cleanupに失敗したため、完了扱いにできません。');
					}
				}).fail(function() {
					stopWithError('cleanup通信に失敗したため、完了扱いにできません。');
				});
			}

			function run_batch() {
				if (stopped) {
					return;
				}
				if (offset > total_rows || total_rows === 0) {
					cleanup();
					return;
				}

				$.ajax({
					url: ajciImport.ajaxUrl,
					type: 'POST',
					data: {
						action: 'ajci_csv_import_chunk',
						job_id: ajciImport.jobId,
						_ajax_nonce: ajciImport.chunkNonce,
						offset: offset,
						basic_auth_user: ajciImport.basicAuthUser,
						basic_auth_pass: ajciImport.basicAuthPass,
						import_origin_url: ajciImport.importOriginUrl
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
							if (response.data.next_offset <= offset || response.data.next_offset > total_rows + 1) {
								stopWithError('サーバーから不正な進捗が返されたため、インポートを停止しました。');
								return;
							}
							offset = response.data.next_offset;
							
							var percent = total_rows > 0 ? Math.min(100, Math.round((processed / total_rows) * 100)) : 100;
							$('#rs-csv-progress').css('width', percent + '%');
							$('#rs-csv-processed').text(processed);

							run_batch();
						} else {
							stopWithError('インポートに失敗しました。');
						}
					},
					error: function(xhr, status, error) {
						stopWithError('インポート通信に失敗しました。');
					}
				});
			}

			run_batch();
		});
		</script>
		<?php
	}
	
	/**
	* Insert post and postmeta using `AJCI_Import_Post_Helper` class.
	*
	* @param array $post
	* @param array $meta
	* @param array $terms
	* @param string $thumbnail The uri or path of thumbnail image.
	* @param bool $is_update
	* @return AJCI_Import_Post_Helper
	*/
	public function save_post($post,$meta,$terms,$thumbnail,$is_update) {
		
		// 移行元URLが設定されている場合、コンテンツ内のドメインを置換する
		$origin_url = AJCI_Import_Post_Helper::$import_origin_url;
		if (!empty($origin_url)) {
			$origin_host = parse_url($origin_url, PHP_URL_HOST);
			$current_host = parse_url(home_url(), PHP_URL_HOST);
			if ($origin_host && $current_host && $origin_host !== $current_host) {
				$replace_domain = function($data) use ($origin_host, $current_host, &$replace_domain) {
					if (is_array($data)) {
						foreach ($data as $k => $v) {
							$data[$k] = $replace_domain($v);
						}
					} elseif (is_string($data)) {
						$data = str_ireplace(
							array('http://' . $origin_host, 'https://' . $origin_host),
							array('http://' . $current_host, 'https://' . $current_host),
							$data
						);
						$data = str_ireplace($origin_host, $current_host, $data);
					}
					return $data;
				};

				$post = $replace_domain($post);
				// ACFメタとサムネイルには移行元の画像URLが含まれるため、
				// 画像のダウンロード・メディアID変換が完了する前にドメインを置換しない。
				// ここで置換すると、移行先の未登録URLを取得して404になる。
			}
		}

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
			$h = AJCI_Import_Post_Helper::getByID($post['ID']);
			$h->update($post);
		} else {
			$h = AJCI_Import_Post_Helper::add($post);
		}
		
		// Set post tags
		if (isset($post_tags)) {
			$h->setPostTags($post_tags);
		}
		
		// Set meta data
		// 画像URLは移行元のまま処理し、画像以外の文字列だけドメインを置換する。
		$h->setMeta($meta, isset($replace_domain) ? $replace_domain : null);
		
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
	* Insert term and termmeta using `AJCI_Import_Post_Helper` class.
	*
	* @param array $term_data
	* @param array $meta
	* @param bool $is_update
	* @return AJCI_Import_Post_Helper
	*/
	public function save_term($term_data, $meta, $is_update) {
		if ($is_update) {
			$h = AJCI_Import_Post_Helper::getTermByID($term_data['term_id'], $term_data['taxonomy']);
			$h->updateTerm($term_data);
		} else {
			$h = AJCI_Import_Post_Helper::addTerm($term_data);
		}
		
		// Set term meta data
		$h->setTermMeta($meta);
		
		return $h;
	}

	// process parse csv ind insert posts
	function process_posts() {
		$h = new AJCI_CSV_Helper;
		$preflight = AJCI_Options_Schema::preflight_file($this->file, $this->options_page_slug);
		if (is_wp_error($preflight)) {
			echo '<p><strong>CSVの事前検証に失敗しました。</strong><br>' . esc_html($preflight->get_error_message()) . '</p>';
			wp_import_cleanup($this->id);
			return false;
		}

		$handle = $h->fopen($this->file, 'r');
		if ( $handle == false ) {
			echo '<p><strong>'.__( 'Failed to open file.', 'really-simple-csv-importer' ).'</strong></p>';
			wp_import_cleanup($this->id);
			return false;
		}
		
		$is_first = true;
		$post_statuses = get_post_stati();
		$is_term_import = false;
		$is_options_import = false;
		$options_schema = null;
		if ($preflight['mode'] === 'options') {
			$options_schema = AJCI_Options_Schema::get_schema($preflight['options_page']['menu_slug']);
			if (is_wp_error($options_schema)) {
				echo '<p><strong>ACFの登録情報を確認できません。</strong><br>' . esc_html($options_schema->get_error_message()) . '</p>';
				$h->fclose($handle);
				wp_import_cleanup($this->id);
				return false;
			}
		}
		
		echo '<ol>';
		
		while (($data = $h->fgetcsv($handle)) !== FALSE) {
			if ($is_first) {
				$h->parse_columns( $this, $data );
				$is_first = false;
				// CSVに taxonomy 列が含まれている場合はターム（タクソノミー）インポートとして扱う
				$is_term_import = in_array('taxonomy', $this->column_keys);
				$is_options_import = in_array('options_page_id', $this->column_keys);
			} else {
				$this->process_single_row(
					$data,
					$h,
					$is_term_import,
					$is_options_import,
					$post_statuses,
					$options_schema
				);
			}
		}
		
		echo '</ol>';

		$h->fclose($handle);
		
		wp_import_cleanup($this->id);
		
		echo '<h3>'.__('All Done.', 'really-simple-csv-importer').'</h3>';
	}

		public function process_single_row($data, $h, $is_term_import, $is_options_import, $post_statuses, $options_schema = null) {
				echo '<li>';

				$post = array();
				$is_update = false;
				$error = new WP_Error();

				// CSVに埋め込まれた移行元サイトURL（_ajci_origin列）を抽出する。
				// オプション行の全体検証前に、検証対象の配列を変更しない。
				$origin_data = $data;
				$csv_origin = $h->get_data($this, $origin_data, '_ajci_origin');
				if (!$is_options_import) {
					$h->get_data($this, $data, '_ajci_origin');
				}
				if ($csv_origin && class_exists('AJCI_Import_Post_Helper') && empty(AJCI_Import_Post_Helper::$import_origin_url)) {
					AJCI_Import_Post_Helper::$import_origin_url = esc_url_raw($csv_origin);
				}
				
				if ($is_options_import) {
					if (!is_array($options_schema)) {
						$error->add('options_schema_invalid', 'オプションページの許可リストを確認できません。');
					} else {
						$row_error = AJCI_Options_Schema::validate_options_row($data, array_values($this->column_keys), $options_schema);
						if (is_wp_error($row_error)) {
							$error = $row_error;
						}
					}

					if (!$error->get_error_codes() && is_array($options_schema)) {
						$options_page_id = $options_schema['post_id'];
						$helper = new AJCI_Import_Post_Helper();

						// 各カラムは事前に許可リストで解決済みの実フィールドへだけ保存する。
						foreach ($this->column_keys as $key => $col_key) {
							if (!array_key_exists($key, $data) || $data[$key] === false || in_array($col_key, AJCI_Options_Schema::RESERVED_COLUMNS, true)) {
								continue;
							}

							$field = AJCI_Options_Schema::resolve_field($col_key, $options_schema);
							if (is_wp_error($field)) {
								$error = $field;
								break;
							}

							$is_json = false;
							$final_value = AJCI_Options_Schema::decode_value($data[$key], $is_json);
							if (in_array($field['type'], array('image', 'file'), true)) {
								if (is_string($final_value) && filter_var($final_value, FILTER_VALIDATE_URL)) {
									$attachment_id = $helper->addMediaFile($final_value);
									if ($attachment_id) {
										$final_value = $attachment_id;
									}
								} elseif (is_numeric($final_value) || (is_string($final_value) && ctype_digit($final_value))) {
									$new_id = $helper->resolveImageId($final_value);
									if ($new_id) {
										$final_value = $new_id;
									}
								}
							} elseif ($is_json && is_array($final_value)) {
								$final_value = $helper->processAcfArrayImages($final_value);
							}

							// 戻り値falseは変更なしの場合もあるため、失敗判定には使わない。
							update_field($field['key'], $final_value, $options_page_id);
						}
						if (!$error->get_error_codes()) {
							echo esc_html(sprintf('オプションページ "%s" の設定を更新しました。', $options_page_id));
						}
					}
				} else if ($is_term_import) {
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
		if (!current_user_can('manage_options')) {
			wp_die(__('Permission denied'));
		}
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
    if (!current_user_can('manage_options')) {
        return;
    }
    $rs_csv_importer = new AJCI_CSV_Importer();
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
add_action('wp_ajax_ajci_csv_import_chunk', 'ajci_csv_import_chunk_handler');
function ajci_csv_import_chunk_handler() {
	$job_id = AJCI_Import_Security::require_ajax_request('chunk');
	$offset = (isset($_POST['offset']) && is_scalar($_POST['offset']) && ctype_digit((string) wp_unslash($_POST['offset'])))
		? (int) wp_unslash($_POST['offset'])
		: 0;
	if ($offset < 1) {
		wp_send_json_error(array('code' => 'invalid_offset'), 400);
	}

	$job = null;
	$lock_token = null;
	$handle = false;
	$buffer_started = false;
	$error_handler_set = false;
	try {
		// The request envelope has already been checked before this handler is installed.
		set_error_handler(function ($errno, $errstr, $errfile, $errline) {
			if (!(error_reporting() & $errno)) {
				return false;
			}
			throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
		});
		$error_handler_set = true;

		$job = AJCI_Import_Security::authorize_job($job_id, 'chunk');
		if (is_wp_error($job)) {
			wp_send_json_error(array('code' => $job->get_error_code()), 403);
		}
		$chunk = AJCI_Import_Security::begin_chunk($job, $offset);
		if (is_wp_error($chunk)) {
			wp_send_json_error(array('code' => $chunk->get_error_code()), 409);
		}

		$job = $chunk['job'];
		$lock_token = $chunk['token'];

		// 必要な管理画面用ファイルは、リクエスト検証とジョブ照合の後に読み込む。
		require_once(ABSPATH . 'wp-admin/includes/taxonomy.php');
		require_once(ABSPATH . 'wp-admin/includes/image.php');
		require_once(ABSPATH . 'wp-admin/includes/file.php');
		require_once(ABSPATH . 'wp-admin/includes/media.php');
		$file_error = AJCI_Import_Security::verify_job_file($job);
		if (is_wp_error($file_error)) {
			throw new RuntimeException('インポート用ファイルが変更されています。');
		}

		$basic_auth_user = (isset($_POST['basic_auth_user']) && is_string($_POST['basic_auth_user']))
			? sanitize_text_field(wp_unslash($_POST['basic_auth_user'])) : '';
		$basic_auth_pass = (isset($_POST['basic_auth_pass']) && is_string($_POST['basic_auth_pass']))
			? sanitize_text_field(wp_unslash($_POST['basic_auth_pass'])) : '';
		$import_origin_url = (isset($_POST['import_origin_url']) && is_string($_POST['import_origin_url']))
			? esc_url_raw(wp_unslash($_POST['import_origin_url'])) : '';

		AJCI_Import_Post_Helper::$basic_auth_user = $basic_auth_user;
		AJCI_Import_Post_Helper::$basic_auth_pass = $basic_auth_pass;
		AJCI_Import_Post_Helper::$import_origin_url = $import_origin_url;

		$importer = new AJCI_CSV_Importer();
		$importer->id = (int) $job['attachment_id'];
		$importer->file = $job['file_path'];

		$h = new AJCI_CSV_Helper;
		$handle = $h->fopen($job['file_path'], 'r');
		if ($handle === false) {
			throw new RuntimeException('CSVファイルを開けません。');
		}

		$header = $h->fgetcsv($handle);
		if (!is_array($header)) {
			throw new RuntimeException('CSVヘッダーを読み込めません。');
		}
		$header_for_compare = $header;
		$bom = pack('CCC', 0xef, 0xbb, 0xbf);
		if (isset($header_for_compare[0]) && is_string($header_for_compare[0]) && 0 === strncmp($header_for_compare[0], $bom, 3)) {
			$header_for_compare[0] = substr($header_for_compare[0], 3);
		}
		$header_for_compare = array_map(function ($value) {
			return is_string($value) ? trim($value) : $value;
		}, $header_for_compare);
		if ($header_for_compare !== $job['headers']) {
			throw new RuntimeException('CSVヘッダーが検証済みの内容と一致しません。');
		}
		$h->parse_columns($importer, $header);
		$is_term_import = $job['mode'] === 'term';
		$is_options_import = $job['mode'] === 'options';
		$options_schema = null;
		if ($is_options_import) {
			$options_schema = AJCI_Options_Schema::get_schema($job['options_page']['menu_slug']);
			if (is_wp_error($options_schema) || $options_schema['fingerprint'] !== $job['schema_fingerprint']) {
				throw new RuntimeException('ACFの登録情報が変更されたため、再検証が必要です。');
			}
		}

		$current_row = 1;
		while ($current_row < $offset && ($data = $h->fgetcsv($handle)) !== false) {
			$current_row++;
		}
		if ($current_row !== $offset) {
			throw new RuntimeException('CSVの進捗位置が不正です。');
		}

		$processed = 0;
		$post_statuses = get_post_stati();
		$limit = 5;
		ob_start();
		$buffer_started = true;
		while ($processed < $limit && ($data = $h->fgetcsv($handle)) !== false) {
			if (!is_array($data) || count($data) !== count($job['headers'])) {
				throw new RuntimeException('CSVの行構造が検証済みの内容と一致しません。');
			}
			$importer->process_single_row($data, $h, $is_term_import, $is_options_import, $post_statuses, $options_schema);
			$processed++;
			$current_row++;
		}
		$log = ob_get_clean();
		$buffer_started = false;

		if ($handle !== false) {
			$h->fclose($handle);
			$handle = false;
		}

		$updated_job = AJCI_Import_Security::complete_chunk($job, $lock_token, $current_row, $processed);
		if (is_wp_error($updated_job)) {
			wp_send_json_error(array('code' => $updated_job->get_error_code()), 500);
		}

		wp_send_json_success(array(
			'processed' => $processed,
			'next_offset' => $current_row,
			'log' => $log,
		));
	} catch (Exception $e) {
		if ($buffer_started && ob_get_level() > 0) {
			ob_end_clean();
		}
		if ($handle !== false) {
			fclose($handle);
		}
		if (is_array($job) && is_string($lock_token)) {
			AJCI_Import_Security::fail_chunk($job, $lock_token);
		}
		wp_send_json_error(array('code' => 'import_failed'), 500);
	} catch (Error $e) {
		if ($buffer_started && ob_get_level() > 0) {
			ob_end_clean();
		}
		if ($handle !== false) {
			fclose($handle);
		}
		if (is_array($job) && is_string($lock_token)) {
			AJCI_Import_Security::fail_chunk($job, $lock_token);
		}
		wp_send_json_error(array('code' => 'import_failed'), 500);
	} finally {
		if ($error_handler_set) {
			restore_error_handler();
		}
	}
}

add_action('wp_ajax_ajci_csv_import_cleanup', 'ajci_csv_import_cleanup_handler');
function ajci_csv_import_cleanup_handler() {
	$job_id = AJCI_Import_Security::require_ajax_request('cleanup');
	$job = AJCI_Import_Security::authorize_job($job_id, 'cleanup');
	if (is_wp_error($job)) {
		wp_send_json_error(array('code' => $job->get_error_code()), 403);
	}

	if (!function_exists('wp_import_cleanup')) {
		require_once ABSPATH . 'wp-admin/includes/import.php';
	}
	$result = AJCI_Import_Security::cleanup_job($job);
	if (is_wp_error($result)) {
		wp_send_json_error(array('code' => $result->get_error_code()), 500);
	}
	wp_send_json_success(array('cleaned' => true));
}
} // class_exists( 'WP_Importer' )
