<?php

namespace Enadstack\ServiceClient\Testing;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;

/**
 * Recorder returned by `Client::fake()`. The real client keeps running against
 * Http::fake(), so typed methods work unchanged; this scopes the recorded
 * requests to one service (by its configured base URL) and adds assertions.
 */
class ServiceFake
{
    /** @param class-string $client */
    public function __construct(protected string $client, protected string $baseUrl) {}

    /** @return Collection<int, array{0: Request, 1: Response|null}> */
    public function recorded(?callable $callback = null): Collection
    {
        $base = rtrim($this->baseUrl, '/');

        $recorded = Http::recorded()
            ->filter(fn (array $pair) => str_starts_with($pair[0]->url(), $base.'/') || $pair[0]->url() === $base);

        if ($callback !== null) {
            $recorded = $recorded->filter(fn (array $pair) => $callback($pair[0], $pair[1]));
        }

        return $recorded->values();
    }

    /** @return Collection<int, Request> */
    public function requests(): Collection
    {
        return $this->recorded()->map(fn (array $pair) => $pair[0]);
    }

    public function assertSent(?Closure $callback = null): static
    {
        Assert::assertTrue($this->recorded($callback)->isNotEmpty(), "Expected a matching request to {$this->baseUrl}, none was sent.");

        return $this;
    }

    public function assertNotSent(?Closure $callback = null): static
    {
        Assert::assertTrue($this->recorded($callback)->isEmpty(), "Unexpected matching request sent to {$this->baseUrl}.");

        return $this;
    }

    public function assertNothingSent(): static
    {
        return $this->assertNotSent();
    }

    public function assertSentCount(int $count): static
    {
        Assert::assertSame($count, $this->recorded()->count(), "Expected {$count} requests to {$this->baseUrl}, got {$this->recorded()->count()}.");

        return $this;
    }

    /** Drop the container binding and the Http fake state. */
    public function reset(): void
    {
        ($this->client)::resetFake();
    }
}
