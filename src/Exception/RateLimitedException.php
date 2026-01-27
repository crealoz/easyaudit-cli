<?php

namespace EasyAudit\Exception;

/**
 * Thrown when the API returns HTTP 429 Too Many Requests.
 *
 * This can indicate:
 * - Rate limiting due to excessive requests
 * - CI identity has been suspended/banned
 * - Account-level throttling
 */
class RateLimitedException extends \RuntimeException
{
    private ?int $retryAfter;

    /**
     * @param int|null $retryAfter Seconds to wait before retrying (from Retry-After header)
     * @param string $message Error message from API
     */
    public function __construct(?int $retryAfter = null, string $message = '')
    {
        $this->retryAfter = $retryAfter;

        if ($message === '') {
            if ($retryAfter !== null) {
                $message = "Rate limited. Please try again in {$retryAfter} seconds.";
            } else {
                $message = 'Rate limited. Please try again later.';
            }
        }

        parent::__construct($message);
    }

    /**
     * Get the number of seconds to wait before retrying.
     *
     * @return int|null Seconds to wait, or null if not specified
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
