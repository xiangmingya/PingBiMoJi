<?php
/**
 * Plugin Name: 笔墨迹主动推送
 * Description: 发布文章时自动通知笔墨迹抓取，实现文章实时同步
 * Version: 1.2.0
 * Author: xiangmingya
 * Author URI: https://blogscn.fun
 */

if (!defined('ABSPATH')) exit;

class PingBiMoJi {
    private static $instance = null;
    private $log_file;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->log_file = plugin_dir_path(__FILE__) . 'push_log.txt';
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('publish_post', [$this, 'push_to_bimoji'], 10, 2);
        add_action('post_updated', [$this, 'on_post_updated'], 10, 3);
    }

    public function add_menu() {
        add_options_page('笔墨迹推送设置', '笔墨迹推送', 'manage_options', 'ping-bimoji', [$this, 'settings_page']);
    }

    public function register_settings() {
        register_setting('ping_bimoji_options', 'ping_bimoji_api_url');
        register_setting('ping_bimoji_options', 'ping_bimoji_rss_url');
        register_setting('ping_bimoji_options', 'ping_bimoji_token');
        register_setting('ping_bimoji_options', 'ping_bimoji_enabled');
        register_setting('ping_bimoji_options', 'ping_bimoji_timing');
        register_setting('ping_bimoji_options', 'ping_bimoji_debug');
        register_setting('ping_bimoji_options', 'ping_bimoji_location_enabled');
        register_setting('ping_bimoji_options', 'ping_bimoji_latitude');
        register_setting('ping_bimoji_options', 'ping_bimoji_longitude');
    }

    public function settings_page() {
        if (isset($_POST['clear_log']) && check_admin_referer('ping_bimoji_clear_log')) {
            file_put_contents($this->log_file, '');
            echo '<div class="notice notice-success"><p>日志已清空</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>笔墨迹推送设置</h1>
            <form method="post" action="options.php">
                <?php settings_fields('ping_bimoji_options'); ?>
                <table class="form-table">
                    <tr>
                        <th>Ping 接口地址</th>
                        <td><input type="url" name="ping_bimoji_api_url" value="<?php echo esc_attr(get_option('ping_bimoji_api_url', 'https://blogscn.fun/blogs/api/ping')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>RSS 地址 *</th>
                        <td><input type="url" name="ping_bimoji_rss_url" id="ping_bimoji_rss_url" value="<?php echo esc_attr(get_option('ping_bimoji_rss_url')); ?>" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th>验证 Token</th>
                        <td><input type="text" name="ping_bimoji_token" value="<?php echo esc_attr(get_option('ping_bimoji_token')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>启用推送</th>
                        <td><label><input type="checkbox" name="ping_bimoji_enabled" value="1" <?php checked(get_option('ping_bimoji_enabled', '1'), '1'); ?>> 启用</label></td>
                    </tr>
                    <tr>
                        <th>推送时机</th>
                        <td>
                            <label><input type="radio" name="ping_bimoji_timing" value="publish" <?php checked(get_option('ping_bimoji_timing', 'all'), 'publish'); ?>> 仅新发布</label>
                            <label style="margin-left:15px;"><input type="radio" name="ping_bimoji_timing" value="all" <?php checked(get_option('ping_bimoji_timing', 'all'), 'all'); ?>> 发布和更新</label>
                        </td>
                    </tr>
                    <tr>
                        <th>参与博主地图</th>
                        <td>
                            <label><input type="radio" name="ping_bimoji_location_enabled" id="location_on" value="1" <?php checked(get_option('ping_bimoji_location_enabled', '0'), '1'); ?>> 参与</label>
                            <label style="margin-left:15px;"><input type="radio" name="ping_bimoji_location_enabled" id="location_off" value="0" <?php checked(get_option('ping_bimoji_location_enabled', '0'), '0'); ?>> 不参与</label>
                            <p class="description">开启后将获取您的大致位置用于博主地图展示，位置信息仅精确到城市级别</p>
                            <input type="hidden" name="ping_bimoji_latitude" id="ping_bimoji_latitude" value="<?php echo esc_attr(get_option('ping_bimoji_latitude')); ?>">
                            <input type="hidden" name="ping_bimoji_longitude" id="ping_bimoji_longitude" value="<?php echo esc_attr(get_option('ping_bimoji_longitude')); ?>">
                            <div id="location_status" style="margin-top:10px;padding:10px;background:#f5f5f5;border-radius:4px;display:none;"></div>
                        </td>
                    </tr>
                    <tr>
                        <th>调试模式</th>
                        <td><label><input type="checkbox" name="ping_bimoji_debug" value="1" <?php checked(get_option('ping_bimoji_debug'), '1'); ?>> 开启</label></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2>推送日志</h2>
            <?php $this->display_logs(); ?>

            <form method="post" style="margin-top:15px;">
                <?php wp_nonce_field('ping_bimoji_clear_log'); ?>
                <input type="hidden" name="clear_log" value="1">
                <button type="submit" class="button" onclick="return confirm('确定清空日志？')">清空日志</button>
            </form>
        </div>

        <?php echo $this->get_location_script(); ?>
        <?php
    }

    private function get_location_script() {
        return <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function() {
    var locationOn = document.getElementById('location_on');
    var locationOff = document.getElementById('location_off');
    var latInput = document.getElementById('ping_bimoji_latitude');
    var lngInput = document.getElementById('ping_bimoji_longitude');
    var rssInput = document.getElementById('ping_bimoji_rss_url');
    var statusDiv = document.getElementById('location_status');

    if (!locationOn || !latInput || !lngInput || !statusDiv) return;

    function setOff() {
        locationOff.checked = true;
        locationOn.checked = false;
    }

    function sendLocation(lat, lng) {
        var rss = rssInput ? rssInput.value : '';
        var apiUrl = document.querySelector('input[name="ping_bimoji_api_url"]').value;
        var url = apiUrl.replace('/ping', '/updateLocation') + '?rss=' + encodeURIComponent(rss) + '&lat=' + lat + '&lng=' + lng;
        fetch(url).then(function(r) { return r.json(); }).then(function(data) {
            if (data.code === 200) {
                statusDiv.innerHTML = '<span style="color:#05d305;">✓ 位置已同步到服务器</span> <button type="button" id="getLocationBtn" style="margin-left:10px;padding:2px 8px;cursor:pointer;">重新获取</button>';
            } else {
                statusDiv.innerHTML = '<span style="color:#e74c3c;">同步失败: ' + data.msg + '</span> <button type="button" id="getLocationBtn" style="margin-left:10px;padding:2px 8px;cursor:pointer;">重试</button>';
            }
            bindBtn();
        }).catch(function() {
            statusDiv.innerHTML = '<span style="color:#e74c3c;">网络错误</span> <button type="button" id="getLocationBtn" style="margin-left:10px;padding:2px 8px;cursor:pointer;">重试</button>';
            bindBtn();
        });
    }

    function getLocation() {
        if (!navigator.geolocation) { alert('您的浏览器不支持位置获取'); return; }
        if (confirm('笔墨迹希望获取您的位置信息\n\n用途：在博主地图上展示您的大致位置（城市级别）\n\n• 位置信息仅用于博主地图功能\n• 我们只保存粗略位置，不会精确到街道\n• 您可以随时关闭此功能\n\n是否允许获取位置？')) {
            statusDiv.innerHTML = '<span style="color:#3498db;">正在获取位置...</span>';
            navigator.geolocation.getCurrentPosition(
                function(pos) {
                    var lat = pos.coords.latitude.toFixed(2);
                    var lng = pos.coords.longitude.toFixed(2);
                    latInput.value = lat;
                    lngInput.value = lng;
                    statusDiv.innerHTML = '<span style="color:#05d305;">✓ 位置已更新，正在同步...</span>';
                    sendLocation(lat, lng);
                },
                function(err) {
                    var msg = err.code === 1 ? '您拒绝了位置授权' : err.code === 2 ? '无法获取位置信息' : '获取位置超时';
                    statusDiv.innerHTML = '<span style="color:#e74c3c;">' + msg + '</span> <button type="button" id="getLocationBtn" style="margin-left:10px;padding:2px 8px;cursor:pointer;">重试</button>';
                    bindBtn();
                },
                {enableHighAccuracy: false, timeout: 10000}
            );
        } else {
            setOff();
            statusDiv.style.display = 'none';
        }
    }

    function bindBtn() {
        var btn = document.getElementById('getLocationBtn');
        if (btn) btn.onclick = getLocation;
    }

    function updateStatus() {
        if (latInput.value && lngInput.value) {
            statusDiv.innerHTML = '<span style="color:#05d305;">✓ 已获取位置</span> <button type="button" id="getLocationBtn" style="margin-left:10px;padding:2px 8px;cursor:pointer;">重新获取</button>';
        } else {
            statusDiv.innerHTML = '<span style="color:#999;">未获取位置</span> <button type="button" id="getLocationBtn" style="margin-left:10px;padding:4px 12px;cursor:pointer;background:#3498db;color:#fff;border:none;border-radius:3px;">获取位置</button>';
        }
        bindBtn();
    }

    locationOn.addEventListener('click', function() {
        var rss = rssInput ? rssInput.value.trim() : '';
        if (!rss) {
            alert('请先填写RSS地址');
            setOff();
            return;
        }
        statusDiv.style.display = 'block';
        updateStatus();
    });

    locationOff.addEventListener('click', function() {
        statusDiv.style.display = 'none';
    });

    if (locationOn.checked) {
        statusDiv.style.display = 'block';
        updateStatus();
    }
});
</script>
HTML;
    }

    private function display_logs() {
        if (!file_exists($this->log_file) || filesize($this->log_file) == 0) {
            echo '<p>暂无日志</p>';
            return;
        }
        $logs = array_reverse(array_slice(file($this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -50));
        echo '<table class="widefat"><thead><tr><th>状态</th><th>标题</th><th>链接</th><th>时间</th><th>备注</th></tr></thead><tbody>';
        $hasLogs = false;
        foreach ($logs as $log) {
            if (preg_match('/^\[(.+?)\]\s+(\S+)\s+(成功|失败)\s+「(.+?)」\s*(.*)$/u', $log, $m)) {
                $hasLogs = true;
                $color = $m[3] === '成功' ? '#05d305' : '#e74c3c';
                echo "<tr><td><span style='color:{$color}'>●</span></td><td>" . esc_html($m[4]) . "</td><td><a href='" . esc_url($m[2]) . "' target='_blank'>" . esc_html($m[2]) . "</a></td><td>{$m[1]}</td><td>" . esc_html($m[5]) . "</td></tr>";
            }
        }
        if (!$hasLogs) {
            echo '<tr><td colspan="5" style="text-align:center;color:#999;padding:20px;">暂无推送日志，发布文章后将自动记录</td></tr>';
        }
        echo '</tbody></table>';
    }

    public function on_post_updated($post_id, $post_after, $post_before) {
        if ($post_before->post_status === 'publish' && $post_after->post_status === 'publish') {
            if (get_option('ping_bimoji_timing', 'all') === 'all') {
                $this->do_push($post_after);
            } else {
                $this->save_log($post_after->post_title, get_permalink($post_after), '跳过', '文章更新，仅新发布时推送');
            }
        }
    }

    public function push_to_bimoji($post_id, $post) {
        $this->do_push($post);
    }

    private function do_push($post) {
        if (get_option('ping_bimoji_enabled', '1') !== '1') {
            $this->save_log($post->post_title, get_permalink($post), '跳过', '插件未启用');
            return;
        }
        if ($post->post_type !== 'post' || $post->post_status !== 'publish') {
            return;
        }

        $api_url = get_option('ping_bimoji_api_url', 'https://blogscn.fun/blogs/api/ping');
        $rss_url = get_option('ping_bimoji_rss_url');
        if (empty($api_url) || empty($rss_url)) {
            $this->save_log($post->post_title, get_permalink($post), '失败', '配置不完整');
            return;
        }

        $params = ['rss' => $rss_url, 'title' => $post->post_title];
        $token = get_option('ping_bimoji_token');
        if ($token) $params['token'] = $token;

        // 添加位置信息
        if (get_option('ping_bimoji_location_enabled') === '1') {
            $lat = get_option('ping_bimoji_latitude');
            $lng = get_option('ping_bimoji_longitude');
            if (!empty($lat) && !empty($lng)) {
                $params['lat'] = $lat;
                $params['lng'] = $lng;
            }
        }

        $response = wp_remote_get($api_url . '?' . http_build_query($params), ['timeout' => 10, 'sslverify' => false]);

        if (is_wp_error($response)) {
            $this->save_log($post->post_title, get_permalink($post), '失败', $response->get_error_message());
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && isset($body['code']) && $body['code'] == 200) {
            $msg = '推送成功';
            if (isset($body['data']['new_posts'])) $msg .= '，新增 ' . $body['data']['new_posts'] . ' 篇';
            $this->save_log($post->post_title, get_permalink($post), '成功', $msg);
        } else {
            $this->save_log($post->post_title, get_permalink($post), '失败', $body['msg'] ?? "HTTP {$code}");
        }
    }

    private function save_log($title, $url, $status, $message) {
        $log = sprintf("[%s] %s %s 「%s」 %s\n", date('Y-m-d H:i:s'), $url, $status, $title, $message);
        file_put_contents($this->log_file, $log, FILE_APPEND);
    }
}

PingBiMoJi::instance();
