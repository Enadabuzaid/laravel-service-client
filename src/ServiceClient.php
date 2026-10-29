<?php

namespace Enadstack\ServiceClient;

use Enadstack\ServiceClient\Testing\Fakeable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Base for every enadstack/<name>-client. Subclasses implement serviceName()
 * and expose typed methods that call get()/post()/patch()/delete().
 *
 * Config read from services.<name>: url, api_key (or token), timeout,
 * connect_timeout, webhook_secret.
 */
abstract class ServiceClient
{
    use Fakeable;

    protected ?string $tenantReference = null;

    protected ?string $idempotencyKey = null;

    protected ?string $requestId = null;

    /** Config key under services.* (e.g. "loyalty"). */
    abstract protected function serviceName(): string;

    public function forTenant(?string $tenantReference): static
    {
        $clone = clone $this;
        $clone->tenantReference = $tenantReference;

        return $clone;
    }

    public function withIdempotencyKey(?string $key): static
    {
        $clone = clone $this;
        $clone->idempotencyKey = $key;

        return $clone;
    }

    public function withRequestId(?string $id): static
    {
        $clone = clone $this;
        $clone->requestId = $id;

        return $clone;
    }

    public function isConfigured(): bool
    {
        return filled($this->config('url'));
    }

    /** @param array<string, mixed> $query */
    protected function get(string $path, array $query = []): Response
    {
        return $this->send('GET', $path, $query);
    }

    /** @param array<string, mixed> $data */
    protected function post(string $path, array $data = []): Response
    {
        return $this->send('POST', $path, $data);
    }

    /** @param array<string, mixed> $data */
    protected function put(string $path, array $data = []): Response
    {
        return $this->send('PUT', $path, $data);
    }

    /** @param array<string, mixed> $data */
    protected function patch(string $path, array $data = []): Response
    {
        return $this->send('PATCH', $path, $data);
    }

    /** @param array<string, mixed> $data */
    protected function delete(string $path, array $data = []): Response
    {
        return $this->send('DELETE', $path, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ServiceException
     */
    protected function send(string $method, string $path, array $data = []): Response
    {
        if (! $this->isConfigured()) {
            throw new ServiceException('SERVICE_NOT_CONFIGURED', "Service [{$this->serviceName()}] has no URL configured.");
        }

        $request = $this->pending($method);
        $url = '/'.ltrim($path, '/');

        try {
            $response = $request->send($method, $url, $method === 'GET' ? ['query' => $data] : ['json' => $data]);
        } catch (ConnectionException $e) {
            throw ServiceException::unreachable($this->serviceName(), $e);
        }

        if ($response->failed()) {
            throw $this->mapError($response);
        }

        return $response;
    }

    protected function pending(string $method): PendingRequest
    {
        $headers = array_filter([
            'X-Application' => config('service-client.application'),
            'X-Tenant-Reference' => $this->tenantReference,
            'X-Request-Id' => $this->requestId ?? (string) Str::uuid(),
            'Accept' => 'application/json',
        ]);

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $headers['Idempotency-Key'] = $this->idempotencyKey ?? (string) Str::uuid();
        }

        $request = Http::baseUrl(rtrim((string) $this->config('url'), '/'))
            ->withHeaders($headers)
            ->timeout((int) $this->config('timeout', config('service-client.timeout')))
            ->connectTimeout((int) $this->config('connect_timeout', config('service-client.connect_timeout')))
            ->retry(
                (int) config('service-client.retries') + 1,
                (int) config('service-client.retry_delay_ms'),
                fn ($e) => $e instanceof ConnectionException,
                throw: false,
            );

        $token = $this->config('api_key', $this->config('token'));

        return filled($token) ? $request->withToken($token) : $request;
    }

    protected function mapError(Response $response): ServiceException
    {
        $error = $response->json('error');

        if (is_array($error) && isset($error['code'])) {
            return new ServiceException(
                (string) $error['code'],
                (string) ($error['message'] ?? 'Service error.'),
                $response->status(),
                (array) ($error['details'] ?? []),
            );
        }

        return new ServiceException('SERVICE_ERROR', "Service [{$this->serviceName()}] returned HTTP {$response->status()}.", $response->status());
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return config("services.{$this->serviceName()}.{$key}", $default);
    }
}
