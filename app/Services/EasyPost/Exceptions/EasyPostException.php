<?php

namespace App\Services\EasyPost\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

class EasyPostException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?array $body = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $body = $response->json();
        $message = is_array($body) ? data_get($body, 'error.message') : null;

        return new self(
            is_string($message) && $message !== '' ? $message : 'EasyPost request failed.',
            $response->status(),
            is_array($body) ? $body : null,
        );
    }

    public static function transport(Throwable $previous): self
    {
        return new self('Could not reach EasyPost.', null, null, $previous);
    }

    /**
     * 4xx caused by the request payload. 401/403 mean our key is wrong, not the user's input.
     */
    public function isClientError(): bool
    {
        return $this->status !== null
            && $this->status >= 400
            && $this->status < 500
            && ! in_array($this->status, [401, 403], true);
    }
}
