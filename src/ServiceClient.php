<?php

namespace Enadstack\ServiceClient;

use Enadstack\ServiceClient\Testing\Fakeable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    protected bool $idempotencyKeyInBody = false;

    /** Config key under services.* (e.g. "loyalty"). */
    abstract protected function serviceName(): string;

    public function forTenant(?string $tenantReference): static
    {
        $clone = clone $this;
        $clone->tenantReference = $tenantReference;

        return $clone;
    }

    /**
     * Explicit idempotency key for the next write. With $inBody the key is also
     * mirrored into the JSON body as `idempotency_key` (for services that read
     * the body field rather than the header).
     */
    public function withIdempotencyKey(?string $key, bool $inBody = false): static
    {
        $clone = clone $this;
        $clone->idempotencyKey = $key;
        $clone->idempotencyKeyInBody = $inBody;

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

        $idempotencyKey = $this->resolveIdempotencyKey($method);

        if ($idempotencyKey !== null && $this->idempotencyKeyInBody) {
            $data['idempotency_key'] = $idempotencyKey;
        }

        $request = $this->pending($method, $idempotencyKey);
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

    protected function isWrite(string $method): bool
    {
        return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /**
     * Writes need an explicit key. A random one defeats idempotency (a retry by
     * the caller would get a new key), so it is only generated when
     * service-client.allow_generated_idempotency_keys is true.
     *
     * @throws ServiceException
     */
    protected function resolveIdempotencyKey(string $method): ?string
    {
        if (! $this->isWrite($method)) {
            return null;
        }

        if (filled($this->idempotencyKey)) {
            return $this->idempotencyKey;
        }

        if (! config('service-client.allow_generated_idempotency_keys', false)) {
            throw new ServiceException(
                ServiceException::IDEMPOTENCY_KEY_REQUIRED,
                "Service [{$this->serviceName()}] {$method} requires an explicit idempotency key; call withIdempotencyKey().",
            );
        }

        Log::warning('service-client: generated a random idempotency key; retries by the caller will not be deduplicated.', [
            'service' => $this->serviceName(),
            'method' => $method,
        ]);

        return (string) Str::uuid();
    }

    protected function pending(string $method, ?string $idempotencyKey = null): PendingRequest
    {
        $headers = array_filter([
            'X-Application' => config('service-client.application'),
            'X-Tenant-Reference' => $this->tenantReference,
            'X-Request-Id' => $this->requestId ?? (string) Str::uuid(),
            'Accept' => 'application/json',
        ]);

        if ($this->isWrite($method) && $idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
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
        $status = $response->status();
        $retryAfter = static::parseRetryAfter($response->header('Retry-After'));
        $error = $response->json('error');

        // Standard envelope: {"error": {code, message, details}}.
        if (is_array($error) && isset($error['code'])) {
            return new ServiceException(
                (string) $error['code'],
                (string) ($error['message'] ?? 'Service error.'),
                $status,
                (array) ($error['details'] ?? []),
                null,
                $retryAfter,
            );
        }

        // Anything else (framework/proxy bodies such as {"message": "Unauthorized"}): map by status.
        $body = $response->json();
        $message = is_array($body) && is_string($body['message'] ?? null) && $body['message'] !== ''
            ? $body['message']
            : "Service [{$this->serviceName()}] returned HTTP {$status}.";
        $details = is_array($body) && is_array($body['errors'] ?? null) ? $body['errors'] : [];

        return new ServiceException(static::codeForStatus($status), $message, $status, $details, null, $retryAfter);
    }

    protected static function codeForStatus(int $status): string
    {
        return match (true) {
            $status === 401 => ServiceException::UNAUTHORIZED,
            $status === 403 => ServiceException::FORBIDDEN,
            $status === 404 => ServiceException::NOT_FOUND,
            $status === 409 => ServiceException::CONFLICT,
            $status === 422 => ServiceException::VALIDATION_FAILED,
            $status === 429 => ServiceException::RATE_LIMITED,
            default => ServiceException::SERVICE_ERROR,
        };
    }

    /** Retry-After as seconds; accepts delta-seconds or an HTTP date. */
    protected static function parseRetryAfter(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (ctype_digit(trim($value))) {
            return (int) $value;
        }

        $at = strtotime($value);

        return $at === false ? null : max(0, $at - time());
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return config("services.{$this->serviceName()}.{$key}", $default);
    }
}
