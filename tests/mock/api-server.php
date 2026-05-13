<?php
/**
 * Mock AI API Server
 * Simulates an OpenAI-compatible chat completions endpoint.
 * 用法: php -S 0.0.0.0:11434 -t /mock api-server.php
 *
 * query-ai.sh 的默认 endpoint 是 http://localhost:11434/v1/chat/completions
 * 这个 mock server 直接监听 11434 端口，query-ai.sh 无需改任何配置
 */

// 只处理 /v1/chat/completions 路由
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($uri === '/v1/chat/completions' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // 读取请求体（query-ai.sh 发送的 system stats JSON）
    $input = json_decode(file_get_contents('php://input'), true);

    // 提取请求中的系统状态摘要，用于验证数据脱敏
    $messages = $input['messages'] ?? [];
    $systemContent = '';
    foreach ($messages as $msg) {
        if (($msg['role'] ?? '') === 'system') {
            $systemContent = $msg['content'] ?? '';
            break;
        }
    }

    // 构造模拟的 AI 建议响应
    $response = [
        'id' => 'chatcmpl-mock-' . date('YmdHis'),
        'object' => 'chat.completion',
        'created' => time(),
        'model' => $input['model'] ?? 'mock-model',
        'choices' => [
            [
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode([
                        [
                            'severity' => 'high',
                            'category' => 'performance',
                            'title' => 'CPU 负载偏高',
                            'description' => '系统平均负载 4.5，超过核心数(4)的 100%',
                            'suggestion' => '检查是否有异常进程占用 CPU，考虑升级 CPU 或增加节点'
                        ],
                        [
                            'severity' => 'medium',
                            'category' => 'storage',
                            'title' => '磁盘使用率接近阈值',
                            'description' => '阵列使用率已达 75%，预计 30 天内耗尽',
                            'suggestion' => '建议规划硬盘扩容，或清理不再需要的历史数据'
                        ],
                        [
                            'severity' => 'low',
                            'category' => 'security',
                            'title' => 'Docker 容器有更新可用',
                            'description' => 'nginx:1.25 有新版本 nginx:1.27',
                            'suggestion' => '定期更新容器镜像以获得安全补丁'
                        ]
                    ])
                ],
                'finish_reason' => 'stop'
            ]
        ],
        'usage' => [
            'prompt_tokens' => strlen($systemContent) / 4,
            'completion_tokens' => 180,
            'total_tokens' => strlen($systemContent) / 4 + 180
        ]
    ];

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// 健康检查端点（方便测试 connectivity）
if ($uri === '/health' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'service' => 'mock-ai-api']);
    exit;
}

// 其他路由返回 404
http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'not found', 'path' => $uri]);
