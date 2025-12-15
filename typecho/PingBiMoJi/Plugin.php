<?php

namespace TypechoPlugin\PingBiMoJi;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Radio;
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
 * @version 1.1.1
 * @link https://blogscn.fun
 */
class Plugin implements PluginInterface
{
    /**
     * 激活插件
     */
    public static function activate()
    {
        // 挂载到文章发布完成后的钩子（兼容 Typecho 1.x 和 2.x）
        \Typecho\Plugin::factory('Widget\Contents\Post\Edit')->finishPublish = __CLASS__ . '::pushToBiMoJi';

        // 添加后台日志面板
        Helper::addPanel(3, 'PingBiMoJi/Logs.php', '笔墨迹推送日志', '查看笔墨迹推送日志', 'administrator');

        return _t('插件已激活，发布文章时将自动通知笔墨迹抓取');
    }

    /**
     * 禁用插件
     */
    public static function deactivate()
    {
        // 移除后台面板
        Helper::removePanel(3, 'PingBiMoJi/Logs.php');

        return _t('插件已禁用');
    }

    /**
     * 插件配置面板
     *
     * @param Form $form
     */
    public static function config(Form $form)
    {
        // 笔墨迹地址
        $apiUrl = new Text('apiUrl', null, 'https://blogscn.fun/blogs/api/ping',
            _t('笔墨迹 Ping 接口地址'),
            _t('默认为 https://blogscn.fun/blogs/api/ping，如果是私有部署请修改'));
        $form->addInput($apiUrl->addRule('url', _t('请输入正确的URL地址')));

        // RSS 地址
        $rssUrl = new Text('rssUrl', null, '',
            _t('你的 RSS 地址'),
            _t('你在笔墨迹登记的 RSS 地址，如：https://yourblog.com/feed/'));
        $form->addInput($rssUrl->addRule('required', _t('RSS 地址不能为空')));

        // 验证 Token（可选）
        $token = new Text('token', null, '',
            _t('验证 Token（可选）'),
            _t('如果笔墨迹为你分配了专属 Token，请填写'));
        $form->addInput($token);

        // 是否启用
        $enabled = new Radio('enabled',
            ['1' => _t('启用'), '0' => _t('禁用')],
            '1',
            _t('是否启用推送'),
            _t('禁用后发布文章将不会通知笔墨迹'));
        $form->addInput($enabled);

        // 推送时机
        $pushTiming = new Radio('pushTiming',
            ['publish' => _t('仅新发布时'), 'all' => _t('发布和更新时')],
            'all',
            _t('推送时机'),
            _t('选择何时触发推送通知'));
        $form->addInput($pushTiming);

        // 调试模式
        $debug = new Radio('debug',
            ['1' => _t('开启'), '0' => _t('关闭')],
            '0',
            _t('调试模式'),
            _t('开启后会在日志中记录更多详情'));
        $form->addInput($debug);
    }

    /**
     * 个人用户配置
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 文章发布后推送到笔墨迹
     *
     * @param array $contents 文章内容
     * @param object $class 文章编辑对象
     */
    public static function pushToBiMoJi($contents, $class)
    {
        // 先记录一条日志，确认钩子被触发
        self::saveLog('钩子触发', $class->permalink ?? 'unknown', '调试', '钩子已触发，开始处理');

        try {
            // 获取插件配置
            $options = Options::alloc();
            $pluginConfig = $options->plugin('PingBiMoJi');

            // 检查是否启用
            if ($pluginConfig->enabled != '1') {
                self::saveLog('跳过', $class->permalink ?? '', '跳过', '插件未启用');
                return;
            }

            // 检查是否为文章（排除页面）
            // finishPublish 钩子只在发布完成后触发，所以这里直接检查 $class 的状态
            $postType = $contents['type'] ?? $class->type ?? 'post';
            if ($postType !== 'post') {
                self::saveLog('跳过', $class->permalink ?? '', '跳过', '非文章类型: ' . $postType);
                return;
            }

            // finishPublish 钩子只在发布成功后触发，不需要再检查状态
            // 直接进行推送

            // 获取配置
            $apiUrl = $pluginConfig->apiUrl;
            $rssUrl = $pluginConfig->rssUrl;
            $token = $pluginConfig->token;
            $debug = $pluginConfig->debug == '1';

            if (empty($apiUrl) || empty($rssUrl)) {
                self::saveLog('配置错误', $class->permalink ?? '', '失败', 'API地址或RSS地址未配置');
                return;
            }

            // 构建请求参数
            $params = [
                'rss' => $rssUrl,
                'title' => $contents['title'] ?? '',
            ];

            if (!empty($token)) {
                $params['token'] = $token;
            }

            // 发送 Ping 请求
            $result = self::sendPing($apiUrl, $params, $debug);

            // 记录日志到文件
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

    /**
     * 发送 Ping 请求
     *
     * @param string $url API地址
     * @param array $params 参数
     * @param bool $debug 是否调试
     * @return array
     */
    private static function sendPing($url, $params, $debug = false)
    {
        $queryString = http_build_query($params);
        $fullUrl = $url . '?' . $queryString;

        if ($debug) {
            error_log('[PingBiMoJi] 发送请求: ' . $fullUrl);
        }

        // 使用 cURL
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $fullUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'PingBiMoJi/1.1');

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

            // 解析响应
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
            // 使用 file_get_contents
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'user_agent' => 'PingBiMoJi/1.1',
                ]
            ]);

            $response = @file_get_contents($fullUrl, false, $context);
            if ($response === false) {
                return ['success' => false, 'message' => '请求失败'];
            }

            return ['success' => true, 'message' => '已发送通知'];
        }
    }

    /**
     * 保存日志到文件
     *
     * @param string $title 文章标题
     * @param string $url 文章链接
     * @param string $status 状态（成功/失败）
     * @param string $message 消息
     */
    private static function saveLog($title, $url, $status, $message)
    {
        $logFile = __DIR__ . '/push_log.txt';
        $time = date('Y-m-d H:i:s');

        // 格式：[时间] 链接 状态 「标题」 消息
        $log = sprintf("[%s] %s %s 「%s」 %s", $time, $url, $status, $title, $message);

        @file_put_contents($logFile, $log . PHP_EOL, FILE_APPEND);
    }
}
