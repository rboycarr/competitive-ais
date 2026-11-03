<?php

namespace Modules\Platform\Tests\Feature;

use Modules\Platform\Scanning\ClamAvClient;
use RuntimeException;
use Tests\TestCase;

/**
 * P1-32b / ADR-0003 (the in-house clamd INSTREAM client, no package). CI has no ClamAV, so these tests speak the exact
 * clamd protocol against a fake server forked for the one connection (as `Tests\Support\Concurrently` forks for
 * parallel-request tests): the client never knows the difference. A manual smoke run against the real ClamAV is
 * recorded separately (session history).
 */
class ClamAvClientTest extends TestCase
{
    public function test_a_clean_reply_is_clean(): void
    {
        $result = $this->scanAgainst(fn (): string => "stream: OK\0", 'Synthetic clean content.');
        $this->assertSame(ClamAvClient::CLEAN, $result);
    }

    public function test_an_infected_reply_is_infected(): void
    {
        $result = $this->scanAgainst(fn (): string => "stream: Eicar-Signature FOUND\0", 'Synthetic infected content.');
        $this->assertSame(ClamAvClient::INFECTED, $result);
    }

    public function test_the_full_content_reaches_the_server_in_length_prefixed_chunks(): void
    {
        config(['services.clamav.chunk_bytes' => 4]); // forces several chunks for an 11-byte payload
        // The reply callback runs inside the forked server, a separate process: capture through a file, not a
        // by-reference variable (which would only change that process's own copy of memory).
        $capture = tempnam(sys_get_temp_dir(), 'clamd-received-');
        if ($capture === false) {
            $this->fail('Could not create a capture file.');
        }
        $this->scanAgainst(function (string $bytes) use ($capture): string {
            file_put_contents($capture, $bytes);

            return "stream: OK\0";
        }, 'Synthetic!!');
        $received = file_get_contents($capture);
        unlink($capture);
        $this->assertSame('Synthetic!!', $received);
    }

    public function test_an_error_reply_is_a_scan_error_not_a_verdict(): void
    {
        $this->expectException(RuntimeException::class);
        $this->scanAgainst(fn (): string => "INSTREAM size limit exceeded. ERROR\0", 'x');
    }

    public function test_an_unrecognised_reply_is_a_scan_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->scanAgainst(fn (): string => "garbage\0", 'x');
    }

    public function test_an_unreachable_server_is_a_scan_error(): void
    {
        config(['services.clamav.host' => '127.0.0.1', 'services.clamav.port' => 1, 'services.clamav.connect_timeout' => 0.3]);
        $this->expectException(RuntimeException::class);
        app(ClamAvClient::class)->scan($this->streamOf('x'));
    }

    public function test_a_server_that_never_answers_is_a_scan_error_not_a_hang(): void
    {
        $this->expectException(RuntimeException::class);
        $this->scanAgainst(function (): string {
            usleep(600_000); // outlasts the client's read_timeout below, so this proves the timeout, not a lucky reply

            return '';
        }, 'x', readTimeout: 0.2);
    }

    /**
     * Forks a one-connection fake clamd: it accepts, reads the INSTREAM payload, and writes whatever $reply returns
     * from the received bytes. The child never returns into PHPUnit (Tests\Support\Concurrently's convention): no
     * shutdown handlers or destructors run twice.
     *
     * @param  callable(string): string  $reply
     * @return ClamAvClient::CLEAN|ClamAvClient::INFECTED
     */
    private function scanAgainst(callable $reply, string $content, float $readTimeout = 5.0): string
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (! is_resource($server)) {
            $this->fail("Could not start the fake clamd ($error).");
        }
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Could not fork the fake clamd.');
        }
        if ($pid === 0) {
            $conn = @stream_socket_accept($server, 10.0);
            if (is_resource($conn)) {
                $bytes = self::drainInstream($conn);
                @fwrite($conn, $reply($bytes));
                fclose($conn);
            }
            fclose($server);
            posix_kill(posix_getpid(), SIGKILL);
            exit(1); // not reached
        }
        fclose($server); // only the forked child accepts

        config(['services.clamav.host' => '127.0.0.1', 'services.clamav.port' => $port, 'services.clamav.read_timeout' => $readTimeout]);
        try {
            return app(ClamAvClient::class)->scan($this->streamOf($content));
        } finally {
            pcntl_waitpid($pid, $status);
        }
    }

    /** @return resource */
    private function streamOf(string $content)
    {
        $stream = fopen('php://memory', 'r+');
        if (! is_resource($stream)) {
            $this->fail('Could not open a memory stream.');
        }
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }

    /**
     * Reads "zINSTREAM\0" then every length-prefixed chunk up to the zero-length terminator; returns the bytes sent.
     *
     * @param  resource  $conn
     */
    private static function drainInstream($conn): string
    {
        self::readExactly($conn, 10); // "zINSTREAM\0"
        $received = '';
        while (true) {
            $prefix = self::readExactly($conn, 4);
            if (strlen($prefix) < 4) {
                break; // the client closed early
            }
            /** @var array{1: int} $length */
            $length = unpack('N', $prefix);
            if ($length[1] === 0) {
                break;
            }
            $received .= self::readExactly($conn, $length[1]);
        }

        return $received;
    }

    /** @param  resource  $conn */
    private static function readExactly($conn, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($conn, max(1, $length - strlen($data)));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }

        return $data;
    }
}
