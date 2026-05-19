jQuery(function($) {
    //1つ目以外を見えなくする
    $('.plugin_tab li:first-child').addClass('select');
    $('.plugin_content:not(:first-child)').addClass('hide');

    //クリックしたときのファンクションをまとめて指定
    $('.plugin_tab li').click(function() {

        //.index()を使いクリックされたタブが何番目かを調べ、
        //indexという変数に代入します。
        var index = $('.plugin_tab li').index(this);

        //コンテンツを一度すべて非表示にし、
        $('.plugin_contents .plugin_content').css('display', 'none');

        //クリックされたタブと同じ順番のコンテンツを表示します。
        $('.plugin_contents .plugin_content').eq(index).css('display', 'block');

        //一度タブについているクラスselectを消し、
        $('.plugin_tab li').removeClass('select');

        //クリックされたタブのみにクラスselectをつけます。
        $(this).addClass('select')
    });

    //カレンダー
    $('.post_date-datepicker').datepicker({
        'dateFormat': 'yy-m-d'
    });

    // 設定を反映する
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
            })

            // checkする
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

    // 設定を保存する
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
                        // 配列
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
            var data = {
                post_type: post_type,
                values: values
            }
            settings[index] = data;
        });
        // Jsonにして保存
        var json_settings = JSON.stringify(settings);
        $.cookie("wce-settings", json_settings);
    }



});