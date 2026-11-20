<?php

namespace Tests\Support;

/** A local HTTP response from a test's PHP router, with isolated session files. */
final class LocalPhpServer
{
    private $process = null;
    private string $directory;
    private int $port;

    public function __construct(string $routerSource)
    {
        $this->directory = sys_get_temp_dir() . '/keeplore-http-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        mkdir($this->directory . '/sessions');
        file_put_contents($this->directory . '/router.php', $routerSource);
        try {
            $socket = stream_socket_server('tcp://127.0.0.1:0');
            if ($socket === false) {
                throw new \RuntimeException('Could not allocate a local PHP server port.');
            }
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $this->port = (int) substr(strrchr($address, ':'), 1);
            $this->process = proc_open(
                [PHP_BINARY, '-d', 'session.save_path=' . $this->directory . '/sessions',
                    '-S', '127.0.0.1:' . $this->port, $this->directory . '/router.php'],
                [0 => ['pipe', 'r'], 1 => ['file', $this->directory . '/server.log', 'a'],
                    2 => ['file', $this->directory . '/server.log', 'a']],
                $pipes,
                PROJECT_PATH
            );
            if (!is_resource($this->process)) {
                throw new \RuntimeException('Could not start the local PHP server.');
            }
            fclose($pipes[0]);
            $this->waitUntilServing();
        } catch (\Throwable $failure) {
            $this->stop();
            throw $failure;
        }
    }

    public function url(string $path): string
    {
        return 'http://127.0.0.1:' . $this->port . $path;
    }

    /** @return array{status: int, headers: string, body: string} */
    public function request(string $path, array $headers = [], string $method = 'GET', ?string $body = null): array
    {
        if ($body !== null && !$this->hasHeader($headers, 'Expect')) {
            $headers[] = 'Expect:';
        }
        $handle = curl_init($this->url($path));
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 10,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($handle, $options);
        $raw = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($raw === false) {
            throw new \RuntimeException($error . "\n" . $this->serverLog());
        }
        $responseHeaders = rtrim(substr($raw, 0, $headerSize), "\r\n");
        $responseBody = substr($raw, $headerSize);
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody];
    }

    /** @return array{status: int, headers: string, body: string} */
    public function requestChunked(
        string $path,
        array $headers = [],
        string $method = 'POST',
        string $body = '',
        int $chunkSize = 65536
    ): array {
        $socket = fsockopen('127.0.0.1', $this->port, $errno, $errstr, 5);
        if (!is_resource($socket)) {
            throw new \RuntimeException("Could not connect to local PHP server: $errstr ($errno)\n" . $this->serverLog());
        }
        try {
            $lines = ["$method $path HTTP/1.1", "Host: 127.0.0.1:{$this->port}"];
            foreach ($headers as $header) {
                $lines[] = $header;
            }
            $lines[] = 'Transfer-Encoding: chunked';
            $lines[] = 'Connection: close';
            fwrite($socket, implode("\r\n", $lines) . "\r\n\r\n");
            if ($body !== '') {
                foreach (str_split($body, $chunkSize) as $chunk) {
                    fwrite($socket, dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n");
                }
            }
            fwrite($socket, "0\r\n\r\n");
            $raw = stream_get_contents($socket);
        } finally {
            fclose($socket);
        }
        if (!is_string($raw) || !str_contains($raw, "\r\n\r\n")) {
            throw new \RuntimeException("Malformed HTTP response from local PHP server.\n" . $this->serverLog());
        }
        [$responseHeaders, $responseBody] = explode("\r\n\r\n", $raw, 2);
        preg_match('#^HTTP/\S+\s+(\d{3})#', $responseHeaders, $match);
        return ['status' => (int) ($match[1] ?? 0), 'headers' => $responseHeaders, 'body' => $responseBody];
    }

    private function hasHeader(array $headers, string $name): bool
    {
        $prefix = strtolower($name) . ':';
        foreach ($headers as $header) {
            if (str_starts_with(strtolower(ltrim((string) $header)), $prefix)) {
                return true;
            }
        }
        return false;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
        foreach (glob($this->directory . '/sessions/*') ?: [] as $sessionFile) {
            unlink($sessionFile);
        }
        foreach (['router.php', 'server.log'] as $file) {
            if (is_file($this->directory . '/' . $file)) {
                unlink($this->directory . '/' . $file);
            }
        }
        if (is_dir($this->directory . '/sessions')) {
            rmdir($this->directory . '/sessions');
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    private function waitUntilServing(): void
    {
        $deadline = microtime(true) + 3;
        do {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        throw new \RuntimeException("Local PHP server did not start.\n" . $this->serverLog());
    }

    private function serverLog(): string
    {
        $path = $this->directory . '/server.log';
        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
