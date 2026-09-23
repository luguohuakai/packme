<?php

/**
 * 极简 HTTP 客户端, 仅用于调用 OpenAI 兼容的 /chat/completions。
 * 优先 curl(支持流式回调), 没有 curl 时退回 stream 封装。
 */
class PackmeHttp
{
    private int $timeout;
    private int $connectTimeout;

    public function __construct(int $timeout = 60, int $connectTimeout = 10)
    {
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * POST JSON
     * @param string $url
     * @param array $payload
     * @param array $headers 形如 ['Authorization: Bearer xxx']
     * @param callable|null $onChunk 传值时走流式, 每个原始数据块回调一次
     * @return array{status:int, body:string, error:string}
     */
    public function postJson(string $url, array $payload, array $headers = [], ?callable $onChunk = null): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['status' => 0, 'body' => '', 'error' => 'json encode failed: ' . json_last_error_msg()];
        }

        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
        ], $headers);

        if (function_exists('curl_init')) {
            return $this->viaCurl($url, $json, $headers, $onChunk);
        }
        return $this->viaStream($url, $json, $headers, $onChunk);
    }

    private function viaCurl(string $url, string $json, array $headers, ?callable $onChunk): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        if ($onChunk !== null) {
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use ($onChunk) {
                $onChunk($chunk);
                return strlen($chunk);
            });
            $ok = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = $ok === false ? (string)curl_error($ch) : '';
            return ['status' => $status, 'body' => '', 'error' => $error];
        }

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = $body === false ? (string)curl_error($ch) : '';
        return ['status' => $status, 'body' => $body === false ? '' : (string)$body, 'error' => $error];
    }

    private function viaStream(string $url, string $json, array $headers, ?callable $onChunk): array
    {
        if (!ini_get('allow_url_fopen')) {
            return ['status' => 0, 'body' => '', 'error' => 'curl 与 allow_url_fopen 均不可用'];
        }

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $json,
            'timeout' => $this->timeout,
            'ignore_errors' => true,
        ]]);

        $fp = @fopen($url, 'rb', false, $context);
        if ($fp === false) {
            return ['status' => 0, 'body' => '', 'error' => 'connection failed'];
        }

        $body = '';
        while (!feof($fp)) {
            $chunk = fread($fp, 8192);
            if ($chunk === false) break;
            if ($onChunk !== null) {
                $onChunk($chunk);
            } else {
                $body .= $chunk;
            }
        }
        fclose($fp);

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }

        return ['status' => $status, 'body' => $body, 'error' => ''];
    }
}
