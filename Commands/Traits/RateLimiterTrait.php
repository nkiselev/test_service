<?php

namespace LaravelInterbus\Commands\Traits;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;

trait RateLimiterTrait
{
    private function getRateLimiter(string $name, string $throughput, ?Closure $coolDown = null): callable
    {
        if (!$throughput || '0/0' === $throughput) {
            return fn(callable $callback) => call_user_func($callback);
        }
        $rateLimiter = new RateLimiter(Cache::store());
        [$maxAttempts, $decay] = explode('/', $throughput);

        $key = 'hits:' . $name;

        return static function (callable $callback) use ($rateLimiter, $key, $maxAttempts, $decay, $coolDown) {
            if ($rateLimiter->tooManyAttempts($key, $maxAttempts)) {
                if (is_null($coolDown)) {
                    return null;
                }
                do {
                    call_user_func($coolDown);
                } while ($rateLimiter->tooManyAttempts($key, $maxAttempts));
            }
            try {
                return call_user_func($callback);
            } finally {
                $rateLimiter->hit($key, $decay);
            }
        };
    }
}