<?php

namespace Enadstack\ServiceClient\Testing;

/**
 * Gives a client `Client::fake()`: binds a fake in the container so the app
 * resolves it instead of the real client. Each <name>-client supplies its own
 * fake class (and may override fakeInstance()).
 */
trait Fakeable
{
    public static function fake(?object $fake = null): object
    {
        $fake ??= static::fakeInstance();

        app()->instance(static::class, $fake);

        return $fake;
    }

    public static function fakeInstance(): object
    {
        throw new \LogicException(static::class.' does not provide a fake. Override fakeInstance().');
    }
}
