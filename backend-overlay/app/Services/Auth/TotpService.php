<?php

namespace App\Services\Auth;

use InvalidArgumentException;

/** RFC 6238 TOTP (SHA-1, 6 digits, 30 seconds) compatible with Google Authenticator. */
final class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function provisioningUri(string $secret, string $email): string
    {
        $issuer = (string) config('app.name', 'Atlazddesk');
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$email)
            .'?secret='.rawurlencode($secret)
            .'&issuer='.rawurlencode($issuer)
            .'&algorithm=SHA1&digits=6&period=30';
    }

    /** Returns a matched time step, or null for invalid codes or a previously used step. */
    public function matchStep(string $secret, string $code, ?int $afterStep = null, ?int $timestamp = null): ?int
    {
        if (! preg_match('/^\d{6}$/D', $code)) return null;
        $key = $this->base32Decode($secret);
        $step = intdiv($timestamp ?? time(), 30);
        foreach ([0, -1, 1] as $offset) {
            $candidate = $step + $offset;
            if ($candidate < 0 || ($afterStep !== null && $candidate <= $afterStep)) continue;
            $digest = hash_hmac('sha1', pack('N2', ($candidate >> 32) & 0xffffffff, $candidate & 0xffffffff), $key, true);
            $position = ord($digest[19]) & 0x0f;
            $binary = ((ord($digest[$position]) & 0x7f) << 24)
                | (ord($digest[$position + 1]) << 16)
                | (ord($digest[$position + 2]) << 8)
                | ord($digest[$position + 3]);
            if (hash_equals(str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT), $code)) {
                return $candidate;
            }
        }
        return null;
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $encoded;
    }

    private function base32Decode(string $secret): string
    {
        $secret = strtoupper(str_replace([' ', '-'], '', $secret));
        if ($secret === '' || strspn($secret, self::ALPHABET) !== strlen($secret)) {
            throw new InvalidArgumentException('Invalid TOTP secret.');
        }
        $bits = '';
        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $octet) {
            $bytes .= chr(bindec($octet));
        }
        return $bytes;
    }
}
