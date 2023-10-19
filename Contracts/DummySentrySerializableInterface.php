<?php

namespace LaravelInterbus\Contracts;

/**
 * @internal
 */
interface DummySentrySerializableInterface
{
    /**
     * Returns an array representation of the object for Sentry.
     *
     * @return array|null
     */
    public function toSentry(): ?array;
}