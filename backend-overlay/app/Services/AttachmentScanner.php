<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AttachmentScanner
{
    public function scan(UploadedFile $file): void
    {
        $socket = $input = null;
        try {
            $socket = $this->connect();
            stream_set_timeout($socket, 8);
            $input = fopen($file->getRealPath(), 'rb');
            if ($input === false) {
                throw new \RuntimeException('Unreadable upload');
            }
            $deadline = microtime(true) + 10;
            $write = static function (string $bytes) use ($socket, $deadline): void {
                while ($bytes !== '') {
                    if (microtime(true) > $deadline) {
                        throw new \RuntimeException('Scan deadline exceeded');
                    }
                    $count = @fwrite($socket, $bytes);
                    if ($count === false || $count === 0) {
                        throw new \RuntimeException('Scan write failed');
                    }
                    $bytes = substr($bytes, $count);
                }
            };
            $write("zINSTREAM\0");
            while (!feof($input)) {
                $chunk = fread($input, 8192);
                if ($chunk === false) {
                    throw new \RuntimeException('Upload read failed');
                }
                if ($chunk !== '') {
                    $write(pack('N', strlen($chunk)).$chunk);
                }
            }
            $write(pack('N', 0));
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) throw new \RuntimeException('Scan deadline exceeded');
            stream_set_timeout($socket, (int) $remaining, (int) (($remaining - (int) $remaining) * 1000000));
            $reply = stream_get_line($socket, 4096, "\0");
            if (is_string($reply) && preg_match('/^stream: .+ FOUND$/D', $reply)) {
                throw new HttpException(422, 'This file was blocked by the security check. Choose a different file or contact your administrator.');
            }
            if ($reply !== 'stream: OK' || stream_get_meta_data($socket)['timed_out']) {
                throw new \RuntimeException('Scan did not confirm a clean file');
            }
        } catch (HttpException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new HttpException(503, 'File uploads are temporarily unavailable because the security check could not be completed. Try again shortly; contact your administrator if this continues.');
        } finally {
            if (is_resource($input)) fclose($input);
            if (is_resource($socket)) fclose($socket);
        }
    }

    protected function connect()
    {
        $host = (string) config('amc.scanner_host', 'clamav');
        $port = (int) config('amc.scanner_port', 3310);
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, 2);
        if ($socket === false) throw new \RuntimeException('Scanner unavailable');
        return $socket;
    }
}
