<?php

namespace Modules\Platform\Scanning;

use RuntimeException;

/**
 * A minimal clamd client for the INSTREAM command (ADR-0003: in-house rather than a stale package; ADR-0007). It streams
 * the bytes in length-prefixed chunks over TCP to the `clamav` service (config `services.clamav`) and reads one reply:
 * - "stream: OK" means clean;
 * - "stream: <signature> FOUND" means infected;
 * - anything else (an "ERROR" reply such as a size limit, a timeout, a closed connection, an unexpected text) throws.
 *   The caller must treat a throw as a scan error to retry, never as clean.
 * Uploads are untrusted (docs/07 T7): the reply is parsed strictly, and the signature name is never returned.
 */
class ClamAvClient
{
    public const CLEAN = 'clean';

    public const INFECTED = 'infected';

    /**
     * @param  resource  $stream  readable from its current position
     * @return self::CLEAN|self::INFECTED
     */
    public function scan($stream): string
    {
        /** @var array{host: string, port: int, connect_timeout: float, read_timeout: float, chunk_bytes: int} $config */
        $config = config('services.clamav');
        $socket = @stream_socket_client("tcp://{$config['host']}:{$config['port']}", $errno, $error, (float) $config['connect_timeout']);
        if (! is_resource($socket)) {
            throw new RuntimeException("The virus scanner could not be reached ($errno).");
        }
        $seconds = (float) $config['read_timeout'];
        stream_set_timeout($socket, (int) $seconds, (int) (($seconds - (int) $seconds) * 1_000_000));

        try {
            // "z" prefix: the command and the reply end with a NUL byte.
            $this->write($socket, "zINSTREAM\0");
            $chunk = max(1, (int) $config['chunk_bytes']);
            while (! feof($stream)) {
                $data = fread($stream, $chunk);
                if ($data === false) {
                    throw new RuntimeException('The file to scan could not be read.');
                }
                if ($data !== '') {
                    $this->write($socket, pack('N', strlen($data)).$data);
                }
            }
            $this->write($socket, pack('N', 0));

            return self::verdict($this->read($socket));
        } finally {
            fclose($socket);
        }
    }

    /**
     * The meaning of one INSTREAM reply (without its terminator).
     *
     * @return self::CLEAN|self::INFECTED
     */
    public static function verdict(string $reply): string
    {
        if ($reply === 'stream: OK') {
            return self::CLEAN;
        }
        if (preg_match('/^stream: \S.{0,250} FOUND$/', $reply) === 1) {
            return self::INFECTED;
        }

        // e.g. "INSTREAM size limit exceeded. ERROR": an error to retry, never a verdict.
        throw new RuntimeException('The virus scanner did not return a verdict.');
    }

    /** @param resource $socket */
    private function write($socket, string $data): void
    {
        for ($offset = 0, $length = strlen($data); $offset < $length; $offset += $written) {
            $written = @fwrite($socket, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('The virus scanner closed the connection.');
            }
        }
    }

    /**
     * The reply up to its NUL terminator (or the end of the connection), at most 1 KB.
     *
     * @param  resource  $socket
     */
    private function read($socket): string
    {
        $reply = '';
        while (! str_contains($reply, "\0") && strlen($reply) < 1024 && ! feof($socket)) {
            $part = fread($socket, 1024);
            if ($part === false || stream_get_meta_data($socket)['timed_out']) {
                throw new RuntimeException('The virus scanner did not answer in time.');
            }
            $reply .= $part;
        }

        return trim(explode("\0", $reply, 2)[0]);
    }
}
