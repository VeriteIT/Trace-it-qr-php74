<?php

/*
 * GENERATED — PHP 7.4 build. Do not edit.
 * Source: src/ApiError.php  ·  Regenerate: php tools/build-php74.php
 */

declare (strict_types=1);
namespace VeriteIt\TraceItQr;

/**
 * Trace-It answered with an error.
 *
 * Carries the machine-readable code so callers can branch without string-matching
 * a message. Codes seen in practice: unauthorized, invalid_post_id,
 * invalid_target_url, post_id_conflict, id_conflict, rate_limited,
 * quota_exceeded, server_misconfigured, internal_error.
 */
final class ApiError extends TraceItException
{
    public int $status;
    public string $errorCode;
    public ?int $retryAfter;
    public function __construct(string $message, int $status = 0, string $errorCode = '', ?int $retryAfter = null)
    {
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->retryAfter = $retryAfter;
        parent::__construct($message);
    }
    /** True when waiting and retrying is the right response. */
    public function isTransient(): bool
    {
        return $this->status === 429 || $this->status >= 500;
    }
    /** True when the monthly creation quota is exhausted. */
    public function isQuotaExceeded(): bool
    {
        return $this->errorCode === 'quota_exceeded';
    }
}
