<?php

namespace LaravelInterbus\Exceptions;

use Exception;
use JetBrains\PhpStorm\Pure;
use Throwable;

class OffloadedPayloadException extends Exception
{
    #[Pure] public function __construct(private string $payloadHash, ?Throwable $previous = null)
    {
        parent::__construct('Payload hash "' . $payloadHash . '" not found', previous: $previous);
    }

    /**
     * @return string
     */
    public function getPayloadHash(): string
    {
        return $this->payloadHash;
    }

}