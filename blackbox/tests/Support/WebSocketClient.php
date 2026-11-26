<?php

declare(strict_types=1);

namespace blackbox\tests\Support;

use RuntimeException;

/**
 * Minimal blocking RFC 6455 client (text frames, client masking, close codes).
 * Used by the Smoke suite and the MCP server to observe the relay directly,
 * without a browser.
 */
final class WebSocketClient
{
    /** @var resource */
    private $stream;
    private string $buffer = '';
    public ?int $closeCode = null;

    private function __construct($stream)
    {
        $this->stream = $stream;
    }

    /**
     * Performs the HTTP upgrade. Returns the client; throws if the server does
     * not answer "101 Switching Protocols".
     */
    public static function connect(string $host, int $port, string $path = '/', float $timeout = 5.0): self
    {
        $stream = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
        if ($stream === false) {
            throw new RuntimeException("WebSocket TCP connect to {$host}:{$port} failed: {$errstr}");
        }
        stream_set_timeout($stream, (int) ceil($timeout));

        $key = base64_encode(random_bytes(16));
        fwrite($stream, "GET {$path} HTTP/1.1\r\nHost: {$host}:{$port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\nOrigin: http://{$host}\r\n\r\n");

        $head = '';
        while (!str_contains($head, "\r\n\r\n")) {
            $chunk = fread($stream, 1024);
            if ($chunk === '' || $chunk === false) {
                throw new RuntimeException('WebSocket handshake: connection closed before response headers');
            }
            $head .= $chunk;
        }
        [$headers, $rest] = explode("\r\n\r\n", $head, 2);
        if (!preg_match('#^HTTP/1\.1 101#', $headers)) {
            throw new RuntimeException('WebSocket handshake refused: ' . strtok($headers, "\r\n"));
        }
        $expected = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!str_contains($headers, $expected)) {
            throw new RuntimeException('WebSocket handshake: bad Sec-WebSocket-Accept');
        }

        $client = new self($stream);
        $client->buffer = $rest;

        return $client;
    }

    public function send(string $text): void
    {
        $len = strlen($text);
        $header = chr(0x81);
        if ($len < 126) {
            $header .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $header .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $header .= chr(0x80 | 127) . pack('J', $len);
        }
        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $text[$i] ^ $mask[$i % 4];
        }
        fwrite($this->stream, $header . $mask . $masked);
    }

    /**
     * Next text frame decoded as JSON, or null on timeout / close.
     *
     * @return array<string, mixed>|null
     */
    public function receiveJson(float $timeout = 5.0): ?array
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $frame = $this->readFrame($deadline);
            if ($frame === null) {
                return null;
            }
            [$opcode, $payload] = $frame;
            if ($opcode === 0x8) {
                $this->closeCode = strlen($payload) >= 2 ? unpack('n', substr($payload, 0, 2))[1] : 1005;

                return null;
            }
            if ($opcode === 0x9) { // ping -> pong
                fwrite($this->stream, chr(0x8A) . chr(0x80) . random_bytes(4));
                continue;
            }
            if ($opcode === 0x1) {
                return json_decode($payload, true);
            }
        }

        return null;
    }

    /**
     * Wait for the first frame matching the predicate.
     *
     * @param callable(array<string, mixed>): bool $match
     * @return array<string, mixed>|null
     */
    public function waitFor(callable $match, float $timeout = 8.0): ?array
    {
        $deadline = microtime(true) + $timeout;
        while (($left = $deadline - microtime(true)) > 0) {
            $frame = $this->receiveJson($left);
            if ($frame === null) {
                return null;
            }
            if ($match($frame)) {
                return $frame;
            }
        }

        return null;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fwrite($this->stream, chr(0x88) . chr(0x82) . random_bytes(4) . "\x03\xE8");
            fclose($this->stream);
        }
    }

    /**
     * @return array{0:int, 1:string}|null
     */
    private function readFrame(float $deadline): ?array
    {
        $head = $this->read(2, $deadline);
        if ($head === null) {
            return null;
        }
        $opcode = ord($head[0]) & 0x0F;
        $len = ord($head[1]) & 0x7F;
        if ($len === 126) {
            $ext = $this->read(2, $deadline);
            $len = $ext === null ? 0 : unpack('n', $ext)[1];
        } elseif ($len === 127) {
            $ext = $this->read(8, $deadline);
            $len = $ext === null ? 0 : unpack('J', $ext)[1];
        }
        $payload = $len > 0 ? $this->read($len, $deadline) : '';

        return $payload === null ? null : [$opcode, $payload];
    }

    private function read(int $n, float $deadline): ?string
    {
        while (strlen($this->buffer) < $n) {
            if (microtime(true) >= $deadline || feof($this->stream)) {
                return null;
            }
            $read = [$this->stream];
            $w = $e = null;
            $wait = max(0.0, $deadline - microtime(true));
            if (@stream_select($read, $w, $e, (int) $wait, (int) (($wait - (int) $wait) * 1e6)) < 1) {
                return null;
            }
            $chunk = fread($this->stream, 8192);
            if ($chunk === '' || $chunk === false) {
                if (feof($this->stream)) {
                    return null;
                }
                continue;
            }
            $this->buffer .= $chunk;
        }
        $out = substr($this->buffer, 0, $n);
        $this->buffer = substr($this->buffer, $n);

        return $out;
    }
}
