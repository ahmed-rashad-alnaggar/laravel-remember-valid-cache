<?php

namespace Alnaggar\Laravel\RememberValidCache\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class RememberValidCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_returns_the_cached_value_while_fresh(): void
    {
        $calls = 0;

        $value = Cache::rememberValid(
            'external-data',
            300,
            function () use (&$calls) {
                $calls++;

                return ['status' => 'valid'];
            },
            fn ($value) => isset($value['status']),
        );

        $this->assertSame(['status' => 'valid'], $value);

        $value = Cache::rememberValid(
            'external-data',
            300,
            function () use (&$calls) {
                $calls++;

                return ['status' => 'unexpected'];
            },
            fn ($value) => false,
        );

        $this->assertSame(['status' => 'valid'], $value);
        $this->assertSame(1, $calls);
    }

    public function test_it_retains_the_last_valid_value_and_applies_the_ttl_as_a_cooldown(): void
    {
        $calls = 0;

        $callback = function () use (&$calls) {
            $calls++;

            return $calls === 1 ? 'valid' : 'invalid';
        };

        Cache::rememberValid(
            'external-data',
            300,
            $callback,
            fn ($value) => $value === 'valid',
        );

        Carbon::setTestNow(Carbon::now()->addSeconds(301));

        $this->assertSame(
            'valid',
            Cache::rememberValid(
                'external-data',
                300,
                $callback,
                fn ($value) => $value === 'valid',
            ),
        );

        $this->assertSame(2, $calls);

        Carbon::setTestNow(Carbon::now()->addSeconds(299));

        $this->assertSame(
            'valid',
            Cache::rememberValid(
                'external-data',
                300,
                $callback,
                fn ($value) => $value === 'valid',
            ),
        );

        $this->assertSame(2, $calls);

        Carbon::setTestNow(Carbon::now()->addSecond());

        $this->assertSame(
            'valid',
            Cache::rememberValid(
                'external-data',
                300,
                $callback,
                fn ($value) => $value === 'valid',
            ),
        );

        $this->assertSame(3, $calls);
    }

    public function test_it_replaces_the_cached_value_after_a_valid_refresh(): void
    {
        Cache::rememberValid(
            'external-data',
            60,
            fn () => 'old',
            fn ($value) => true,
        );

        Carbon::setTestNow(Carbon::now()->addSeconds(61));

        $this->assertSame(
            'new',
            Cache::rememberValid(
                'external-data',
                60,
                fn () => 'new',
                fn ($value) => true,
            ),
        );

        $this->assertSame(
            'new',
            Cache::rememberValid(
                'external-data',
                60,
                fn () => $this->fail('A fresh value should not be refreshed.'),
                fn ($value) => false,
            ),
        );
    }

    public function test_it_does_not_cache_an_invalid_initial_value(): void
    {
        $calls = 0;

        $callback = function () use (&$calls) {
            $calls++;

            return 'invalid';
        };

        $this->assertSame(
            'invalid',
            Cache::rememberValid(
                'external-data',
                60,
                $callback,
                fn ($value) => false,
            ),
        );

        $this->assertNull(Cache::get('external-data'));

        Cache::rememberValid(
            'external-data',
            60,
            $callback,
            fn ($value) => false,
        );

        $this->assertSame(2, $calls);
    }

    public function test_it_returns_the_stale_value_when_another_request_holds_the_refresh_lock(): void
    {
        $repository = Cache::store('array');

        $repository->rememberValid(
            'external-data',
            60,
            fn () => 'stale',
            fn ($value) => true,
        );

        Carbon::setTestNow(Carbon::now()->addSeconds(61));

        $lock = $repository->getStore()->lock(
            'illuminate:cache:remember-valid:lock:external-data',
            0,
        );

        $this->assertTrue($lock->get());

        $callbackCalled = false;

        $value = $repository->rememberValid(
            'external-data',
            60,
            function () use (&$callbackCalled) {
                $callbackCalled = true;

                return 'fresh';
            },
            fn ($value) => true,
        );

        $this->assertSame('stale', $value);
        $this->assertFalse($callbackCalled);

        $lock->release();
    }

    public function test_it_uses_the_configured_lock_options(): void
    {
        $store = new class extends ArrayStore
        {
            public ?array $lockArguments = null;

            public function lock($name, $seconds = 0, $owner = null)
            {
                $this->lockArguments = [
                    'name' => $name,
                    'seconds' => $seconds,
                    'owner' => $owner,
                ];

                return parent::lock($name, $seconds, $owner);
            }
        };

        $repository = new Repository($store);

        // Initial cache misses should not acquire a refresh lock.
        $repository->rememberValid(
            'external-data',
            60,
            fn () => 'stale',
            fn ($value) => true,
        );

        $this->assertNull($store->lockArguments);

        Carbon::setTestNow(Carbon::now()->addSeconds(61));

        $repository->rememberValid(
            'external-data',
            60,
            fn () => 'fresh',
            fn ($value) => true,
            lock: [
                'seconds' => 120,
                'owner' => 'custom-owner',
            ],
        );

        $this->assertSame(120, $store->lockArguments['seconds']);
        $this->assertSame('custom-owner', $store->lockArguments['owner']);
        $this->assertStringEndsWith(
            'external-data',
            $store->lockArguments['name'],
        );
    }

    public function test_it_propagates_callback_exceptions_and_releases_the_refresh_lock(): void
    {
        $repository = Cache::store('array');

        $repository->rememberValid(
            'external-data',
            60,
            fn () => 'stale',
            fn ($value) => true,
        );

        Carbon::setTestNow(Carbon::now()->addSeconds(61));

        try {
            $repository->rememberValid(
                'external-data',
                60,
                fn () => throw new RuntimeException('Refresh failed.'),
                fn ($value) => true,
            );

            $this->fail('The callback exception should propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Refresh failed.', $exception->getMessage());
        }

        $lock = $repository->getStore()->lock(
            'illuminate:cache:remember-valid:lock:external-data',
            0,
        );

        $this->assertTrue($lock->get());

        $lock->release();
    }

    public function test_it_propagates_validator_exceptions_and_releases_the_refresh_lock(): void
    {
        $repository = Cache::store('array');

        $repository->rememberValid(
            'external-data',
            60,
            fn () => 'stale',
            fn ($value) => true,
        );

        Carbon::setTestNow(Carbon::now()->addSeconds(61));

        try {
            $repository->rememberValid(
                'external-data',
                60,
                fn () => 'fresh',
                fn ($value) => throw new RuntimeException('Validation failed.'),
            );

            $this->fail('The validator exception should propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Validation failed.', $exception->getMessage());
        }

        $lock = $repository->getStore()->lock(
            'illuminate:cache:remember-valid:lock:external-data',
            0,
        );

        $this->assertTrue($lock->get());

        $lock->release();
    }

    public function test_it_resolves_a_ttl_closure_using_the_callback_result(): void
    {
        $seen = null;

        $value = Cache::rememberValid(
            'external-data',
            function ($result) use (&$seen) {
                $seen = $result;

                return 120;
            },
            fn () => ['items' => [1, 2, 3]],
            fn ($result) => isset($result['items']),
        );

        $this->assertSame(['items' => [1, 2, 3]], $value);
        $this->assertSame($value, $seen);
    }
}
