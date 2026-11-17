# Laravel Remember Valid

![I Stand With Palestine Badge](./arts/PalestineBadge.svg)

![I Stand With Palestine Banner](./arts/PalestineBanner.svg)

[![Latest Stable Version](https://img.shields.io/packagist/v/alnaggar/laravel-remember-valid-cache)](https://packagist.org/packages/alnaggar/laravel-remember-valid-cache)
[![Total Downloads](https://img.shields.io/packagist/dt/alnaggar/laravel-remember-valid-cache)](https://packagist.org/packages/alnaggar/laravel-remember-valid-cache)
[![License](https://img.shields.io/packagist/l/alnaggar/laravel-remember-valid-cache)](https://packagist.org/packages/alnaggar/laravel-remember-valid-cache)

A small Laravel cache **macro** that keeps the last valid cached value until a valid replacement can be fetched.

## Requirements

- PHP 8.2+
- Laravel 12+

## Installation

```bash
composer require alnaggar/laravel-remember-valid-cache
```

## Usage

```php
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

$data = Cache::rememberValid(
    key: 'external-data',
    ttl: 300,
    callback: fn () => Http::get($url)->throw()->json(),
    validator: fn ($value) => is_array($value) && isset($value['data']),
);
```

The arguments are:

| Argument    | Type                                                  | Description                                                                                                                                                                 |
| ----------- | ----------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `key`       | `UnitEnum\|string`                                    | The cache key.                                                                                                                                                              |
| `ttl`       | `DateTimeInterface\|DateInterval\|Closure\|int\|null` | The freshness period and retry cooldown. A TTL Closure receives the `callback` result, just like Laravel's other cache methods.                                             |
| `callback`  | `Closure(): mixed`                                    | The callback that retrieves the value.                                                                                                                                      |
| `validator` | `Closure(mixed): bool`                                | Receives the `callback` result. Returning `true` caches the result and resets the TTL; returning `false` retains the previously cached value and starts the retry cooldown. |
| `lock`      | `array{seconds?: int, owner?: string}\|null`          | Optional lock configuration. `seconds` controls lock expiration, while `owner` specifies a custom lock-owner identifier.                                                    |

You can also use a specific store:

```php
Cache::store('redis')->rememberValid(
    key: 'external-data',
    ttl: 300,
    callback: fn () => fetchExternalData(),
    validator: fn ($value) => is_array($value) && isset($value['data']),
);
```

### Lock configuration

The `lock` argument configures the atomic lock used during stale-cache refreshes. Omitting it does not disable locking.

```php
Cache::rememberValid(
    key: 'external-data',
    ttl: 300,
    callback: fn () => fetchExternalData(),
    validator: fn ($value) => is_array($value),
    lock: ['seconds' => 120],
);
```

- `seconds`: Lock expiration in seconds. Defaults to `0`, leaving expiration behavior to the underlying cache store.
- `owner`: Custom lock-owner identifier. Defaults to `null`, allowing Laravel to generate one.

The lock is acquired non-blockingly. If another request already owns it, the existing cached value is returned immediately without waiting.

Choose a finite lock duration long enough for the retrieval callback to complete. If the lock expires while the callback is still running, another request may acquire it and start another refresh.

## Behavior

- While the freshness marker exists, the cached value is returned without executing either callback.
- When the marker expires, a refresh is attempted.
- A non-`null` result accepted by the validator replaces the cached value and resets the freshness period.
- A rejected result does not replace the cached value. When a stale value exists, the TTL also becomes the cooldown before another refresh attempt.
- The last valid value is stored indefinitely by this method. It remains available until a valid replacement is stored or the application explicitly removes it.
- `null` is treated as a missing or invalid value and is never cached as a valid replacement.
- When there is no cached value, the callback runs without a refresh lock. An invalid initial result is returned but not cached, so the next call tries again. Concurrent cold-cache requests may therefore execute the callback independently.
- Refreshes of an existing stale value use a non-blocking atomic cache lock, following Laravel's `flexible()` approach. Only the lock owner refreshes; concurrent requests that cannot acquire the lock immediately receive the latest available cached value.
- The refresh marker is checked again after acquiring the lock to avoid repeating a refresh completed by another request.
- Exceptions from the retrieval callback or validator propagate and are not reported or swallowed by the package. An exception does not start the retry cooldown.
- If stale fallback is desired for an API exception, catch it inside the callback, report it, and return an invalid value such as `null`.

The lock behavior follows the configured cache store's locking implementation, just as Laravel's `flexible()` does. Use a store with atomic locks when cross-process coordination is required.

## Reporting failures while retaining stale data

Exception handling remains the application's responsibility:

```php
$data = Cache::rememberValid(
    key: 'external-data',
    ttl: 300,
    callback: function () use ($url) {
        try {
            return Http::get($url)->throw()->json();
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    },
    validator: function ($value) {
        if ($value === null) {
            return false;
        }

        $valid = is_array($value) && isset($value['data']);

        if (! $valid) {
            report(new \UnexpectedValueException(
                'The external service returned an unexpected response.'
            ));
        }

        return $valid;
    },
);
```

When a previous value exists, returning `null` causes the previous value to be retained and starts the cooldown. On a cold cache, `null` is returned and is not cached.

## Testing

```bash
composer install
composer test
composer lint
```

## Contributing

If you find any issues or have suggestions for improvements, feel free to open an issue or submit a pull request on the GitHub repository.

## Credits

- Palestine banner and badge by [Safouene1](https://github.com/Safouene1/support-palestine-banner).

## License

**Laravel Remember Valid Cache** is open-sourced software licensed under the [MIT license](LICENSE).
