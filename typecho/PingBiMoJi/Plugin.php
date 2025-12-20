<?php

namespace TypechoPlugin\PingBiMoJi;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Hidden;
use Widget\Options;
use Utils\Helper;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 笔墨迹主动推送插件 - 发布文章时自动通知笔墨迹抓取
 *
 * @package PingBiMoJi
 * @author xiangmingya
 * @version 1.2.0
 * @link https://blogscn.fun
 */
class Plugin implements PluginInterface
{
    public static function activate()
    {
        \Typecho\Plugin::factory('Widget\Contents\Post\Edit')->finishPublish = __CLASS__ . '::pushToBiMoJi';
        Helper::addPanel(3, 'PingBiMoJi/Logs.php', '笔墨迹推送日志', '查看笔墨迹推送日志', 'administrator');
        return _t('插件已激活，发布文章时将自动通知笔墨迹抓取');
    }

    public static function deactivate()
    {
        Helper::removePanel(3, 'PingBiMoJi/Logs.php');
        return _t('插件已禁用');
    }

    public static function config(Form $form)
    {
        $apiUrl = new Text('apiUrl', null, 'https://blogscn.fun/blogs/api/ping',
            _t('笔墨迹 Ping 接口地址'),
            _t('默认为 https://blogscn.fun/blogs/api/ping，如果是私有部署请修改'));
        $form->addInput($apiUrl->addRule('url', _t('请输入正确的URL地址')));

        $rssUrl = new Text('rssUrl', null, '',
            _t('你的 RSS 地址'),
            _t('你在笔墨迹登记的 RSS 地址，如：https://yourblog.com/feed/'));
        $form->addInput($rssUrl->addRule('required', _t('RSS 地址不能为空')));

        $token = new Text('token', null, '',
            _t('验证 Token（可选）'),
            _t('如果笔墨迹为你分配了专属 Token，请填写'));
        $form->addInput($token);

        $enabled = new Radio('enabled',
            ['1' => _t('启用'), '0' => _t('禁用')],
            '1',
            _t('是否启用推送'),
            _t('禁用后发布文章将不会通知笔墨迹'));
        $form->addInput($enabled);

        $pushTiming = new Radio('pushTiming',
            ['publish' => _t('仅新发布时'), 'all' => _t('发布和更新时')],
            'all',
            _t('推送时机'),
            _t('选择何时触发推送通知'));
        $form->addInput($pushTiming);

        // 博主地图功能
        $locationEnabled = new Radio('locationEnabled',
            ['1' => _t('参与'), '0' => _t('不参与')],
            '0',
            _t('参与博主地图'),
            _t('开启后将获取您的大致位置用于博主地图展示，位置信息仅精确到城市级别'));
        $form->addInput($locationEnabled);

        $latitude = new Hidden('latitude', null, '');
        $form->addInput($latitude);

        $longitude = new Hidden('longitude', null, '');
        $form->addInput($longitude);

        $debug = new Radio('debug',
            ['1' => _t('开启'), '0' => _t('关闭')],
            '0',
            _t('调试模式'),
            _t('开启后会在日志中记录更多详情'));
        $form->addInput($debug);

        // 注入位置获取JS
        echo self::getLocationScript();
    }

    public static function personalConfig(Form $form)
    {
    }

    private static function getLocationScript()
    {
        return <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function() {
    var locationOn = document.querySelector('input[name="locationEnabled"][value="1"]');
    var locationOff = document.querySelector('input[name="locationEnabled"][value="0"]');
    var latInput = document.querySelector('input[name="latitude"]');
    var lngInput = document.querySelector('input[name="longitude"]');
    var rssInput = document.querySelector('input[name="rssUrl"]');
    var apiInput = document.querySelector('input[name="apiUrl"]');

    if (!locationOn || !latInput || !lngInput) return;

    var statusDiv = document.createElement('div');
    statusDiv.style.cssText = 'margin:10px 0;padding:10px;background:#f5f5f5;border-radius:4px;display:none;';
    locationOn.closest('li').appendChild(statusDiv);

    function setOff() {
        locationOff.checked = true;
        locationOn.checked = false;
    }

    function sendLocation(lat, lng) {
        var rss = rssInput ? rssInput.value : '';
        var api = apiInput ? apiInput.value : '';
        var url = api.replace('/ping', '/updateLocation') + '?rss=' + encodeURIComponent(rss) + '&lat=' + lat + '&lng=' + lng;
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

    public static function pushToBiMoJi($contents, $class)
    {
        self::saveLog('钩子触发', $class->permalink ?? 'unknown', '调试', '钩子已触发，开始处理');

        try {
            $options = Options::alloc();
            $pluginConfig = $options->plugin('PingBiMoJi');

            if ($pluginConfig->enabled != '1') {
                self::saveLog('跳过', $class->permalink ?? '', '跳过', '插件未启用');
                return;
            }

            $postType = $contents['type'] ?? $class->type ?? 'post';
            if ($postType !== 'post') {
                self::saveLog('跳过', $class->permalink ?? '', '跳过', '非文章类型: ' . $postType);
                return;
            }

            // 判断推送时机
            $pushTiming = $pluginConfig->pushTiming ?? 'all';
            $cid = $contents['cid'] ?? $class->cid ?? null;

            if ($pushTiming === 'publish' && $cid) {
                // 检查文章是否已存在（通过判断创建时间和修改时间是否相近）
                $created = $contents['created'] ?? $class->created ?? 0;
                $modified = $contents['modified'] ?? $class->modified ?? time();

                // 如果修改时间比创建时间晚超过60秒，认为是更新操作
                if ($modified - $created > 60) {
                    self::saveLog('跳过', $class->permalink ?? '', '跳过', '文章更新，仅新发布时推送');
                    return;
                }
            }

            $apiUrl = $pluginConfig->apiUrl;
            $rssUrl = $pluginConfig->rssUrl;
            $token = $pluginConfig->token;
            $debug = $pluginConfig->debug == '1';

            if (empty($apiUrl) || empty($rssUrl)) {
                self::saveLog('配置错误', $class->permalink ?? '', '失败', 'API地址或RSS地址未配置');
                return;
            }

            $params = [
                'rss' => $rssUrl,
                'title' => $contents['title'] ?? '',
            ];

            if (!empty($token)) {
                $params['token'] = $token;
            }

            // 添加位置信息
            if ($pluginConfig->locationEnabled == '1') {
                $lat = $pluginConfig->latitude;
                $lng = $pluginConfig->longitude;
                if (!empty($lat) && !empty($lng)) {
                    $params['lat'] = $lat;
                    $params['lng'] = $lng;
                }
            }

            $result = self::sendPing($apiUrl, $params, $debug);

            self::saveLog(
                $contents['title'] ?? '无标题',
                $class->permalink ?? '',
                $result['success'] ? '成功' : '失败',
                $result['message']
            );

        } catch (\Exception $e) {
            self::saveLog('异常', $class->permalink ?? '', '失败', '异常: ' . $e->getMessage());
        }
    }

    private static function sendPing($url, $params, $debug = false)
    {
        $queryString = http_build_query($params);
        $fullUrl = $url . '?' . $queryString;

        if ($debug) {
            error_log('[PingBiMoJi] 发送请求: ' . $fullUrl);
        }

        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $fullUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'PingBiMoJi/1.2');

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                return ['success' => false, 'message' => 'cURL错误: ' . $error];
            }

            if ($httpCode !== 200) {
                return ['success' => false, 'message' => 'HTTP错误: ' . $httpCode];
            }

            $data = json_decode($response, true);
            if ($data && isset($data['code'])) {
                if ($data['code'] == 200) {
                    $msg = '推送成功';
                    if (isset($data['data']['new_posts'])) {
                        $msg .= '，新增 ' . $data['data']['new_posts'] . ' 篇';
                    }
                    return ['success' => true, 'message' => $msg];
                } else {
                    return ['success' => false, 'message' => $data['msg'] ?? '未知错误'];
                }
            }

            return ['success' => true, 'message' => '已发送通知'];

        } else {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'user_agent' => 'PingBiMoJi/1.2',
                ]
            ]);

            $response = @file_get_contents($fullUrl, false, $context);
            if ($response === false) {
                return ['success' => false, 'message' => '请求失败'];
            }

            return ['success' => true, 'message' => '已发送通知'];
        }
    }

    private static function saveLog($title, $url, $status, $message)
    {
        $logFile = __DIR__ . '/push_log.txt';
        $time = date('Y-m-d H:i:s');
        $log = sprintf("[%s] %s %s 「%s」 %s", $time, $url, $status, $title, $message);
        @file_put_contents($logFile, $log . PHP_EOL, FILE_APPEND);
    }
}
