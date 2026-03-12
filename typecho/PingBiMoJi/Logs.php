<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

if (!defined('__TYPECHO_ADMIN__')) {
    require_once __TYPECHO_ROOT_DIR__ . __TYPECHO_ADMIN_DIR__ . 'common.php';
}

require_once __TYPECHO_ROOT_DIR__ . __TYPECHO_ADMIN_DIR__ . 'header.php';
require_once __TYPECHO_ROOT_DIR__ . __TYPECHO_ADMIN_DIR__ . 'menu.php';

$user = \Typecho\Widget::widget('Widget_User');
if (!$user->pass('administrator', true)) {
    die('无权限访问');
}
?>

<style type="text/css">
    .xiangming-pingbimoji-page {
        box-sizing: border-box;
        width: 100%;
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
    }
    .xiangming-pingbimoji-content {
        padding: 20px;
    }
    .xiangming-log-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }
    .xiangming-log-table th, .xiangming-log-table td {
        border: 1px solid #ddd;
        padding: 10px 8px;
        text-align: center;
    }
    .xiangming-log-table th {
        padding-top: 12px;
        padding-bottom: 12px;
        background-color: #f2f2f2;
        font-weight: bold;
    }
    .xiangming-log-table td {
        vertical-align: middle;
    }
    .xiangming-log-table tr:hover {
        background-color: #f9f9f9;
    }
    .xiangming-status-dot {
        height: 10px;
        width: 10px;
        border-radius: 50%;
        display: inline-block;
        vertical-align: middle;
        box-shadow: 0 0 5px rgba(0, 0, 0, 0.3);
    }
    .xiangming-status-dot-success {
        background-color: #05d305;
    }
    .xiangming-status-dot-failure {
        background-color: #e74c3c;
    }
    .xiangming-status-dot-debug {
        background-color: #3498db;
    }
    .xiangming-status-dot-skip {
        background-color: #f39c12;
    }
    .xiangming-log-title {
        max-width: 200px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        text-align: left;
    }
    .xiangming-log-url {
        max-width: 300px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        text-align: left;
    }
    .xiangming-log-url a {
        color: #467b96;
        text-decoration: none;
    }
    .xiangming-log-url a:hover {
        text-decoration: underline;
    }
    .xiangming-log-message {
        max-width: 250px;
        color: #666;
        font-size: 0.9em;
        text-align: left;
    }
    .xiangming-log-time {
        color: #888;
        font-size: 0.9em;
        white-space: nowrap;
    }
    .xiangming-empty-log {
        text-align: center;
        color: #999;
        padding: 40px;
    }
    .xiangming-clear-btn {
        margin-top: 20px;
    }
    .xiangming-clear-btn button {
        background-color: #e74c3c;
        color: white;
        border: none;
        padding: 8px 16px;
        cursor: pointer;
        border-radius: 4px;
    }
    .xiangming-clear-btn button:hover {
        background-color: #c0392b;
    }
    .xiangming-log-stats {
        margin-bottom: 20px;
        padding: 15px;
        background-color: #f8f9fa;
        border-radius: 4px;
    }
    .xiangming-log-stats span {
        margin-right: 20px;
    }
    .xiangming-stat-success {
        color: #05d305;
    }
    .xiangming-stat-failure {
        color: #e74c3c;
    }
    .xiangming-raw-log {
        margin-top: 20px;
        padding: 15px;
        background-color: #f5f5f5;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-family: monospace;
        font-size: 12px;
        white-space: pre-wrap;
        word-break: break-all;
        max-height: 300px;
        overflow-y: auto;
    }
</style>

<div class="main">
    <div class="xiangming-pingbimoji-page">
        <div class="typecho-page-title">
            <h2>笔墨迹推送日志</h2>
        </div>
        <div class="xiangming-pingbimoji-content">
            <?php
            $logFile = __DIR__ . '/push_log.txt';

            // 处理清空日志请求
            if (isset($_POST['clear_log']) && $_POST['clear_log'] === '1') {
                if (file_exists($logFile)) {
                    file_put_contents($logFile, '');
                }
                echo '<div class="message success">日志已清空</div>';
            }

            // 读取日志
            $logs = [];
            $successCount = 0;
            $failureCount = 0;

            if (file_exists($logFile) && filesize($logFile) > 0) {
                $allLogs = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $logs = array_reverse(array_slice($allLogs, -50)); // 最近50条，倒序

                // 统计
                foreach ($allLogs as $log) {
                    if (strpos($log, '成功') !== false) {
                        $successCount++;
                    } elseif (strpos($log, '失败') !== false) {
                        $failureCount++;
                    }
                }
            }

            // 显示统计
            if ($successCount > 0 || $failureCount > 0) {
                echo '<div class="xiangming-log-stats">';
                echo '<span>总计: <strong>' . count($allLogs) . '</strong> 条</span>';
                echo '<span class="xiangming-stat-success">成功: <strong>' . $successCount . '</strong> 条</span>';
                echo '<span class="xiangming-stat-failure">失败: <strong>' . $failureCount . '</strong> 条</span>';
                echo '</div>';
            }

            // 获取调试模式配置
            $debugMode = false;
            try {
                $pluginConfig = \Widget\Options::alloc()->plugin('PingBiMoJi');
                $debugMode = ($pluginConfig->debug == '1');
            } catch (\Exception $e) {}

            // 显示日志表格
            echo '<table class="xiangming-log-table">';
            echo '<tr><th style="width:60px;">状态</th><th>文章标题</th><th>文章链接</th><th style="width:160px;">推送时间</th><th>备注</th></tr>';

            $hasValidLogs = false;
            if (!empty($logs)) {
                foreach ($logs as $log) {
                    // 解析日志格式：[时间] 链接 状态 「标题」 消息
                    if (preg_match('/^\[(.+?)\]\s+(\S+)\s+(成功|失败|调试|跳过)\s+「(.+?)」\s*(.*)$/u', $log, $matches)) {
                        $time = $matches[1];
                        $url = $matches[2];
                        $status = $matches[3];
                        $title = $matches[4];
                        $message = $matches[5];

                        // 表格只显示成功和失败，调试和跳过不显示
                        if ($status !== '成功' && $status !== '失败') {
                            continue;
                        }

                        $statusClass = ($status === '成功') ? 'xiangming-status-dot-success' : 'xiangming-status-dot-failure';

                        $hasValidLogs = true;
                        echo "<tr>
                                <td><span class='xiangming-status-dot {$statusClass}' title='{$status}'></span></td>
                                <td class='xiangming-log-title' title='" . htmlspecialchars($title) . "'>" . htmlspecialchars($title) . "</td>
                                <td class='xiangming-log-url'><a href='" . htmlspecialchars($url) . "' target='_blank' title='" . htmlspecialchars($url) . "'>" . htmlspecialchars($url) . "</a></td>
                                <td class='xiangming-log-time'>{$time}</td>
                                <td class='xiangming-log-message'>" . htmlspecialchars($message) . "</td>
                              </tr>";
                    }
                }
            }

            if (!$hasValidLogs) {
                echo '<tr><td colspan="5" class="xiangming-empty-log">暂无推送日志，发布文章后将自动记录</td></tr>';
            }

            echo '</table>';

            // 只有开启调试模式才显示原始日志
            if ($debugMode && !empty($logs)) {
                echo '<div class="xiangming-raw-log">';
                echo '<strong>原始日志内容（调试）：</strong><br><br>';
                foreach ($logs as $log) {
                    echo htmlspecialchars($log) . '<br>';
                }
                echo '</div>';
            }

            // 清空按钮
            if (!empty($logs)) {
                echo '<div class="xiangming-clear-btn">';
                echo '<form method="post" onsubmit="return confirm(\'确定要清空所有日志吗？\');">';
                echo '<input type="hidden" name="clear_log" value="1">';
                echo '<button type="submit">清空日志</button>';
                echo '</form>';
                echo '</div>';
            }
            ?>
        </div>
    </div>
</div>

<?php
require_once __TYPECHO_ROOT_DIR__ . __TYPECHO_ADMIN_DIR__ . 'copyright.php';
require_once __TYPECHO_ROOT_DIR__ . __TYPECHO_ADMIN_DIR__ . 'common-js.php';
require_once __TYPECHO_ROOT_DIR__ . __TYPECHO_ADMIN_DIR__ . 'footer.php';
?>
