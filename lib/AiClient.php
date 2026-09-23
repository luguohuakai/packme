<?php

/**
 * DeepSeek(OpenAI 兼容) /chat/completions 客户端。
 * 只依赖 PackmeHttp, 传输层可注入, 便于离线测试。
 */

class PackmeAiException extends RuntimeException
{
}

class PackmeAiClient
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private float $temperature;
    private int $maxTokens;
    private bool $stream;
    /** @var callable|null */
    private $transport;
    private array $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];

    /**
     * @param array $config api_key / base_url / model / temperature / max_tokens / stream / timeout
     * @param callable|null $transport function(string $url, array $payload, array $headers, ?callable $onChunk): array
     */
    public function __construct(array $config, ?callable $transport = null)
    {
        $this->apiKey = (string)($config['api_key'] ?? '');
        $this->baseUrl = rtrim((string)($config['base_url'] ?? 'https://api.deepseek.com'), '/');
        $this->model = (string)($config['model'] ?? 'deepseek-flash');
        $this->temperature = (float)($config['temperature'] ?? 0.2);
        $this->maxTokens = (int)($config['max_tokens'] ?? 4096);
        $this->stream = (bool)($config['stream'] ?? true);
        $this->transport = $transport;
        if ($this->transport === null) {
            $http = new PackmeHttp((int)($config['timeout'] ?? 60), (int)($config['connect_timeout'] ?? 10));
            $this->transport = [$http, 'postJson'];
        }
    }

    public function model(): string
    {
        return $this->model;
    }

    public function usage(): array
    {
        return $this->usage;
    }

    /**
     * 发起一轮对话
     * @param array $messages
     * @param array $tools
     * @param callable|null $onDelta 流式文本增量回调
     * @return array{content:?string,tool_calls:array,finish_reason:?string}
     */
    public function chat(array $messages, array $tools = [], ?callable $onDelta = null): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
        ];
        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $url = $this->baseUrl . '/chat/completions';
        $headers = ['Authorization: Bearer ' . $this->apiKey];

        if ($this->stream) {
            $payload['stream'] = true;
            $payload['stream_options'] = ['include_usage' => true];
            return $this->chatStream($url, $payload, $headers, $onDelta);
        }

        $payload['stream'] = false;
        $result = ($this->transport)($url, $payload, $headers, null);
        $this->assertOk($result, false);

        $data = json_decode((string)$result['body'], true);
        if (!is_array($data)) {
            throw new PackmeAiException('invalid JSON response: ' . substr((string)$result['body'], 0, 200));
        }
        if (isset($data['usage']) && is_array($data['usage'])) {
            $this->mergeUsage($data['usage']);
        }
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        return [
            'content' => isset($message['content']) && $message['content'] !== '' ? (string)$message['content'] : null,
            'tool_calls' => $this->normalizeToolCalls($message['tool_calls'] ?? []),
            'finish_reason' => $choice['finish_reason'] ?? null,
        ];
    }

    private function chatStream(string $url, array $payload, array $headers, ?callable $onDelta): array
    {
        $acc = ['content' => '', 'tool_calls' => [], 'finish_reason' => null, 'usage' => null];
        $buffer = '';

        $result = ($this->transport)($url, $payload, $headers, function ($chunk) use (&$buffer, &$acc, $onDelta) {
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $this->consumeSseLine(trim($line), $acc, $onDelta);
            }
        });

        $this->assertOk($result, true);
        if (is_array($acc['usage'])) {
            $this->mergeUsage($acc['usage']);
        }

        ksort($acc['tool_calls']);
        return [
            'content' => $acc['content'] !== '' ? $acc['content'] : null,
            'tool_calls' => array_values($acc['tool_calls']),
            'finish_reason' => $acc['finish_reason'],
        ];
    }

    private function consumeSseLine(string $line, array &$acc, ?callable $onDelta): void
    {
        if ($line === '' || strpos($line, ':') === 0) return;
        if (strpos($line, 'data:') !== 0) return;

        $data = trim(substr($line, 5));
        if ($data === '[DONE]') return;

        $json = json_decode($data, true);
        if (!is_array($json)) return;

        if (isset($json['usage']) && is_array($json['usage'])) {
            $acc['usage'] = $json['usage'];
        }

        $choice = $json['choices'][0] ?? null;
        if (!is_array($choice)) return;

        $delta = $choice['delta'] ?? [];
        if (isset($delta['content']) && $delta['content'] !== '') {
            $acc['content'] .= $delta['content'];
            if ($onDelta) $onDelta((string)$delta['content']);
        }

        foreach (($delta['tool_calls'] ?? []) as $tc) {
            $idx = (int)($tc['index'] ?? 0);
            if (!isset($acc['tool_calls'][$idx])) {
                $acc['tool_calls'][$idx] = ['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];
            }
            if (!empty($tc['id'])) $acc['tool_calls'][$idx]['id'] = (string)$tc['id'];
            if (!empty($tc['function']['name'])) $acc['tool_calls'][$idx]['function']['name'] .= (string)$tc['function']['name'];
            if (isset($tc['function']['arguments'])) $acc['tool_calls'][$idx]['function']['arguments'] .= (string)$tc['function']['arguments'];
        }

        if (!empty($choice['finish_reason'])) $acc['finish_reason'] = $choice['finish_reason'];
    }

    private function normalizeToolCalls($toolCalls): array
    {
        if (!is_array($toolCalls)) return [];
        $result = [];
        foreach ($toolCalls as $tc) {
            $result[] = [
                'id' => (string)($tc['id'] ?? ''),
                'type' => 'function',
                'function' => [
                    'name' => (string)($tc['function']['name'] ?? ''),
                    'arguments' => (string)($tc['function']['arguments'] ?? ''),
                ],
            ];
        }
        return $result;
    }

    private function mergeUsage(array $usage): void
    {
        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $key) {
            if (isset($usage[$key])) $this->usage[$key] += (int)$usage[$key];
        }
    }

    private function assertOk(array $result, bool $stream): void
    {
        $status = (int)($result['status'] ?? 0);
        if ($status === 0 && !empty($result['error'])) {
            throw new PackmeAiException('HTTP error: ' . $result['error']);
        }
        if ($status < 200 || $status >= 300) {
            $apiMessage = '';
            if (!$stream) {
                $decoded = json_decode((string)($result['body'] ?? ''), true);
                if (is_array($decoded) && isset($decoded['error']['message'])) {
                    $apiMessage = (string)$decoded['error']['message'];
                }
            }
            throw new PackmeAiException('API error HTTP ' . $status . ($apiMessage !== '' ? ': ' . $apiMessage : ''));
        }
        if (!$stream && (string)($result['body'] ?? '') === '') {
            throw new PackmeAiException('empty response from API');
        }
    }
}
