<?php
include 'common.php';
include 'header.php';
include 'menu.php';

$user = \Typecho\Widget::widget('Widget_User');
if (!$user->pass('administrator', true)) {
    die('无权限访问');
}
?>

<style type="text/css">
    .main-content {
        padding: 20px;
    }
    .log-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }
    .log-table th, .log-table td {
        border: 1px solid #ddd;
        padding: 10px 8px;
        text-align: center;
    }
    .log-table th {
        padding-top: 12px;
        padding-bottom: 12px;
        background-color: #f2f2f2;
        font-weight: bold;
    }
    .log-table td {
        vertical-align: middle;
    }
    .log-table tr:hover {
        background-color: #f9f9f9;
    }
    .status-dot {
        height: 10px;
        width: 10px;
        border-radius: 50%;
        display: inline-block;
        vertical-align: middle;
        box-shadow: 0 0 5px rgba(0, 0, 0, 0.3);
    }
    .status-dot-success {
        background-color: #05d305;
    }
    .status-dot-failure {
        background-color: #e74c3c;
    }
    .status-dot-debug {
        background-color: #3498db;
    }
    .status-dot-skip {
        background-color: #f39c12;
    }
    .log-title {
        max-width: 200px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        text-align: left;
    }
    .log-url {
        max-width: 300px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        text-align: left;
    }
    .log-url a {
        color: #467b96;
        text-decoration: none;
    }
    .log-url a:hover {
        text-decoration: underline;
    }
    .log-message {
        max-width: 250px;
        color: #666;
        font-size: 0.9em;
        text-align: left;
    }
    .log-time {
        color: #888;
        font-size: 0.9em;
        white-space: nowrap;
    }
    .empty-log {
        text-align: center;
        color: #999;
        padding: 40px;
    }
    .clear-btn {
        margin-top: 20px;
    }
    .clear-btn button {
        background-color: #e74c3c;
        color: white;
        border: none;
        padding: 8px 16px;
        cursor: pointer;
        border-radius: 4px;
    }
    .clear-btn button:hover {
        background-color: #c0392b;
    }
    .log-stats {
        margin-bottom: 20px;
        padding: 15px;
        background-color: #f8f9fa;
        border-radius: 4px;
    }
    .log-stats span {
        margin-right: 20px;
    }
    .stat-success {
        color: #05d305;
    }
    .stat-failure {
        color: #e74c3c;
    }
    .raw-log {
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
    <div class="body container">
        <div class="typecho-page-title">
            <h2>笔墨迹推送日志</h2>
        </div>
        <div class="main-content">
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
                echo '<div class="log-stats">';
                echo '<span>总计: <strong>' . count($allLogs) . '</strong> 条</span>';
                echo '<span class="stat-success">成功: <strong>' . $successCount . '</strong> 条</span>';
                echo '<span class="stat-failure">失败: <strong>' . $failureCount . '</strong> 条</span>';
                echo '</div>';
            }

            // 获取调试模式配置
            $debugMode = false;
            try {
                $pluginConfig = \Widget\Options::alloc()->plugin('PingBiMoJi');
                $debugMode = ($pluginConfig->debug == '1');
            } catch (\Exception $e) {}

            // 显示日志表格
            echo '<table class="log-table">';
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

                        $statusClass = ($status === '成功') ? 'status-dot-success' : 'status-dot-failure';

                        $hasValidLogs = true;
                        echo "<tr>
                                <td><span class='status-dot {$statusClass}' title='{$status}'></span></td>
                                <td class='log-title' title='" . htmlspecialchars($title) . "'>" . htmlspecialchars($title) . "</td>
                                <td class='log-url'><a href='" . htmlspecialchars($url) . "' target='_blank' title='" . htmlspecialchars($url) . "'>" . htmlspecialchars($url) . "</a></td>
                                <td class='log-time'>{$time}</td>
                                <td class='log-message'>" . htmlspecialchars($message) . "</td>
                              </tr>";
                    }
                }
            }

            if (!$hasValidLogs) {
                echo '<tr><td colspan="5" class="empty-log">暂无推送日志，发布文章后将自动记录</td></tr>';
            }

            echo '</table>';

            // 只有开启调试模式才显示原始日志
            if ($debugMode && !empty($logs)) {
                echo '<div class="raw-log">';
                echo '<strong>原始日志内容（调试）：</strong><br><br>';
                foreach ($logs as $log) {
                    echo htmlspecialchars($log) . '<br>';
                }
                echo '</div>';
            }

            // 清空按钮
            if (!empty($logs)) {
                echo '<div class="clear-btn">';
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
include 'copyright.php';
include 'common-js.php';
include 'footer.php';
?>
