<?php

namespace Modules\Platform\Scanning;

use RuntimeException;

class ClamAvClient
{
    /** @param resource $stream */
    public function scan($stream): string
    {
        $cfg = (array) config('evidence.scan');
        $socket = @stream_socket_client('tcp://'.(string) $cfg['host'].':'.(int) $cfg['port'], $errno, $error, (float) $cfg['connect_timeout']);
        if (! is_resource($socket)) {
            throw new RuntimeException("ClamAV connection failed ($errno).");
        }
        stream_set_timeout($socket, (int) $cfg['read_timeout'], (int) (($cfg['read_timeout'] - (int) $cfg['read_timeout']) * 1_000_000));
        try {
            $this->writeAll($socket, "zINSTREAM\0");
            $chunkSize = max(1, (int) $cfg['chunk_bytes']);
            while (! feof($stream)) {
                $chunk = fread($stream, $chunkSize);
                if ($chunk === false) {
                    throw new RuntimeException('The evidence stream could not be read.');
                }
                if ($chunk === '') {
                    break;
                }
                $this->writeAll($socket, pack('N', strlen($chunk)).$chunk);
            }
            $this->writeAll($socket, pack('N', 0));
            $reply = fgets($socket);
            $meta = stream_get_meta_data($socket);
            if (! is_string($reply) || $meta['timed_out']) {
                throw new RuntimeException('ClamAV returned no result.');
            }
            $reply = trim($reply);
            if (str_ends_with($reply, 'OK')) {
                return 'clean';
            }
            if (str_contains($reply, 'FOUND')) {
                return 'infected';
            }
            throw new RuntimeException('ClamAV returned an unexpected result.');
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private function writeAll($socket, string $data): void
    {
        $offset = 0;
        while ($offset < strlen($data)) {
            $written = fwrite($socket, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('The ClamAV connection closed while scanning.');
            }
            $offset += $written;
        }
    }
}
