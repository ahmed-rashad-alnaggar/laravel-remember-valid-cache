<?php

namespace Alnaggar\Laravel;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;
use UnexpectedValueException;
use UnitEnum;

use function Illuminate\Support\enum_value;

class RememberValidCacheServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $attemptedKeyPrefix = 'illuminate:cache:remember-valid:attempted:';
        $lockKeyPrefix = 'illuminate:cache:remember-valid:lock:';

        Repository::macro('rememberValid', function (UnitEnum|string $key, DateTimeInterface|DateInterval|Closure|int|null $ttl, Closure $callback, Closure $validator, ?array $lock = null) use ($attemptedKeyPrefix, $lockKeyPrefix) {
            $key = enum_value($key);

            $attemptedKey = $attemptedKeyPrefix.$key;
            $value = $this->get($key);
            $attempted = $this->get($attemptedKey);

            // Like Cache::flexible(), cold-cache requests are not coordinated by a lock.
            if (is_null($value)) {
                $newValue = $callback();

                throw_if(
                    is_null($newValue) || ! $validator($newValue),
                    UnexpectedValueException::class,
                    "The callback returned an invalid value for cache key [{$key}], and no cached value is available."
                );

                $ttl = value($ttl, $newValue);

                if ($this->forever($key, $newValue)) {
                    $this->put($attemptedKey, Carbon::now()->getTimestamp(), $ttl);
                }

                return $newValue;
            }

            // The last refresh attempt is still within its freshness/cooldown period.
            if (! is_null($attempted)) {
                return $value;
            }

            $result = $this->getStore()
                ->lock(
                    $lockKeyPrefix.$this->itemKey($key),
                    $lock['seconds'] ?? 0,
                    $lock['owner'] ?? null
                )
                ->get(
                    // Wrap the callback result to distinguish it from Lock::get() returning
                    // false when the lock could not be acquired.
                    function () use ($key, $attemptedKey, $attempted, $value, $ttl, $callback, $validator): array {
                        // Another request may have completed a refresh attempt while this request
                        // was attempting to acquire the lock.
                        if ($attempted !== $this->get($attemptedKey)) {
                            return [$this->get($key) ?? $value];
                        }

                        $newValue = $callback();
                        $ttl = value($ttl, $newValue);

                        if (! is_null($newValue) && $validator($newValue)) {
                            if ($this->forever($key, $newValue)) {
                                $this->put($attemptedKey, Carbon::now()->getTimestamp(), $ttl);
                            }

                            return [$newValue];
                        }

                        // Keep the stale value and use the TTL as a cooldown before trying again.
                        $this->put($attemptedKey, Carbon::now()->getTimestamp(), $ttl);

                        return [$this->get($key) ?? $value];
                    }
                );

            return $result === false
                ? ($this->get($key) ?? $value) // The non-blocking lock could not be acquired, so return the latest cached value.
                : $result[0];
        });
    }
}
