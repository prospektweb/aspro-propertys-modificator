<?php

namespace Prospektweb\PropModificator\Infrastructure\Http;

final class RequestThrottler
{
    private const SESSION_KEY = 'pmod_calc_last_request_at';
    private const IP_RATE_DIR = 'pmod_calc_throttle';

    public function __construct(
        private readonly SessionStorage $sessionStorage,
        private readonly int $minIntervalMs = 300,
        private readonly string $tmpDir = ''
    ) {
    }

    /** @return array{ok:bool,retryAfterMs:int} */
    public function allow(string $clientIp, string $sessionId): array
    {
        $nowMs = (int)floor(microtime(true) * 1000);
        $lastSessionAt = (int)$this->sessionStorage->get(self::SESSION_KEY, 0);
        $lastIpAt = $this->readLastIpRequestAt($clientIp, $sessionId);
        $lastRequestAt = max($lastSessionAt, $lastIpAt);

        $diff = $nowMs - $lastRequestAt;
        if ($lastRequestAt > 0 && $diff < $this->minIntervalMs) {
            return ['ok' => false, 'retryAfterMs' => $this->minIntervalMs - $diff];
        }

        $this->sessionStorage->set(self::SESSION_KEY, $nowMs);
        $this->writeLastIpRequestAt($clientIp, $sessionId, $nowMs);

        return ['ok' => true, 'retryAfterMs' => 0];
    }

    private function readLastIpRequestAt(string $clientIp, string $sessionId): int
    {
        $file = $this->buildIpFilePath($clientIp, $sessionId);
        if (!is_file($file)) {
            return 0;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || !is_numeric(trim($raw))) {
            return 0;
        }

        return (int)$raw;
    }

    private function writeLastIpRequestAt(string $clientIp, string $sessionId, int $value): void
    {
        $file = $this->buildIpFilePath($clientIp, $sessionId);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($file, (string)$value, LOCK_EX);
    }

    private function buildIpFilePath(string $clientIp, string $sessionId): string
    {
        $tmpDir = $this->tmpDir !== '' ? $this->tmpDir : sys_get_temp_dir();
        $dir = rtrim($tmpDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::IP_RATE_DIR;
        $fingerprint = sha1($clientIp . '|' . $sessionId);

        return $dir . DIRECTORY_SEPARATOR . $fingerprint . '.txt';
    }
}
