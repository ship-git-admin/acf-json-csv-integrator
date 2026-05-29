jQuery(function($) {

    // 各タブグループを独立して初期化
    $('.tab-group').each(function() {
        var $group = $(this);
        // 各グループの最初のタブだけ選択状態にする
        $group.find('.plugin_tab li:first-child').addClass('select');
        // 各グループの2番目以降のコンテンツを非表示にする
        $group.find('.plugin_content:not(:first-child)').addClass('hide');
    });

    // タブクリック：同じグループ内だけを操作
    $('.plugin_tab li').click(function() {
        var $group = $(this).closest('.tab-group');
        var index = $group.find('.plugin_tab li').index(this);

        // グループ内のコンテンツを全て非表示
        $group.find('.plugin_contents .plugin_content').css('display', 'none');
        // クリックされたタブと同順のコンテンツを表示
        $group.find('.plugin_contents .plugin_content').eq(index).css('display', 'block');

        // グループ内のselectクラスをリセット
        $group.find('.plugin_tab li').removeClass('select');
        $(this).addClass('select');
    });

    // チェックボックス
    $('.all_checked').click(function() {
        var target = $(this).attr('data-target');
        $(target).prop('checked', true);
    });
    $('.all_checkout').click(function() {
        var target = $(this).attr('data-target');
        $(target).prop('checked', false);
    });

    // カレンダー
    $('.post_date-datepicker').datepicker({
        'dateFormat': 'yy-m-d'
    });

    // 設定を反映する（投稿タイプのみ）
    set_settings();

    function set_settings() {
        var wce_settings = $.cookie("wce-settings");
        if (!wce_settings) {
            return false;
        }

        // 一旦全部チェックを外す
        $('.js-csv-content').find('input[type="checkbox"]').prop('checked', false);

        wce_settings = JSON.parse(wce_settings);
        $.each(wce_settings, function(index, setting) {
            var $content = null;
            $('.js-csv-content').each(function() {
                if ($(this).attr('data-post-type') == setting.post_type) {
                    $content = $(this);
                }
            });

            if (!$content) return;

            $.each(setting.values, function(key, value) {
                if (key == 'posts_values' || key == 'post_status' || key == 'taxonomies' || key == 'cf_fields') {
                    $.each(value, function(subkey, val) {
                        $content.find('input[name="' + key + '[]"]').each(function() {
                            if ($(this).attr('value') == val) {
                                $(this).prop('checked', true);
                            }
                        });
                    });
                } else {
                    $content.find('input[name="' + value + '"]').prop('checked', true);
                }
            });
        });
    }

    // 設定を保存する（投稿タイプのみ）
    $('.js-csv-content').each(function() {
        var $checkboxes = $(this).find('input[type="checkbox"]');
        $checkboxes.change(function() {
            save_settings();
        });
    });

    function save_settings() {
        var settings = {};
        $('.js-csv-content').each(function(index) {
            var post_type = $(this).attr('data-post-type');
            var $checkboxes = $(this).find('input[type="checkbox"]');
            var values = {};
            var posts_values = [];
            var post_status = [];
            var taxonomies = [];
            var cf_fields = [];
            $checkboxes.each(function() {
                if ($(this).prop('checked')) {
                    var checkbox_name = $(this).attr('name');
                    var checkbox_value = $(this).attr('value');

                    if (checkbox_name.match(/\[\]/)) {
                        var checkbox_names = checkbox_name.replace(/\[\]/g, "");
                        switch (checkbox_names) {
                            case 'posts_values':
                                posts_values.push(checkbox_value);
                                break;
                            case 'post_status':
                                post_status.push(checkbox_value);
                                break;
                            case 'taxonomies':
                                taxonomies.push(checkbox_value);
                                break;
                            case 'cf_fields':
                                cf_fields.push(checkbox_value);
                                break;
                        }
                    } else {
                        values[checkbox_name] = checkbox_value;
                    }
                }
            });
            values['posts_values'] = posts_values;
            values['post_status'] = post_status;
            values['taxonomies'] = taxonomies;
            values['cf_fields'] = cf_fields;
            settings[index] = {
                post_type: post_type,
                values: values
            };
        });
        $.cookie("wce-settings", JSON.stringify(settings));
    }

    // offsetの表示（投稿タイプ）
    $('input.limit').on({
        'change': function() {
            var target = $(this).attr('data-target');
            if ($(this).val() > 0) {
                $(target).fadeIn();
            } else {
                $(target).fadeOut();
            }
        }
    });

});
