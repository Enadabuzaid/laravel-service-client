<?php

namespace Enadstack\ServiceClient\Testing;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/**
 * Gives a client `Client::fake()`.
 *
 *  - `Client::fake()` / `Client::fake(['demo.test/*' => Http::response(...)])`
 *    fakes the HTTP layer (the real client keeps working, so typed methods
 *    run end to end) and returns a ServiceFake recorder with assertSent /
 *    assertNotSent / assertSentCount. Plain `Http::fake()` keeps working too.
 *  - `Client::fake($object)` binds your own fake object in the container
 *    (override fakeInstance() to supply a default one).
 *  - `Client::resetFake()` forgets the binding and the Http fake state.
 */
trait Fakeable
{
    /** @param  object|array<string, mixed>|null  $fake  fake object, or Http::fake() response map */
    public static function fake(object|array|null $fake = null): object
    {
        if (is_object($fake)) {
            app()->instance(static::class, $fake);

            return $fake;
        }

        $custom = static::fakeInstance();

        if ($custom !== null) {
            app()->instance(static::class, $custom);

            return $custom;
        }

        Http::fake($fake ?: null);

        return new ServiceFake(static::class, (string) config('services.'.static::fakeServiceName().'.url'));
    }

    /** Override to return a hand-written fake that gets bound in the container. */
    public static function fakeInstance(): ?object
    {
        return null;
    }

    public static function resetFake(): void
    {
        app()->forgetInstance(static::class);
        app()->forgetInstance(Factory::class);
        Http::clearResolvedInstance(Factory::class);
    }

    protected static function fakeServiceName(): string
    {
        $client = app()->make(static::class);

        return (fn () => $this->serviceName())->call($client);
    }
}
