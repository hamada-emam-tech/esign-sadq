<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Sadq;

use HamadaEmamTech\Esign\Exceptions\ProviderError;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Talks to SADQ's REST API: the access token, and the response envelope every endpoint uses:
 *
 *     {"data": ..., "errorCode": 0, "message": "Success", "stateValidationErrors": null}
 *
 * SADQ reports most failures with HTTP 200 and a non-zero errorCode, so the errorCode is
 * what decides success here, not the HTTP status.
 *
 * @internal used by SadqProvider
 */
final class SadqApi
{
    private ?string $token = null;

    private int $tokenExpiresAt = 0;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly SadqCredentials $credentials,
        private readonly string $baseUrl,
        private readonly ?CacheInterface $cache = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @return mixed the "data" field */
    public function get(string $path): mixed
    {
        return $this->call('GET', $path);
    }

    /**
     * @param array<mixed> $body
     *
     * @return mixed the "data" field
     */
    public function post(string $path, array $body): mixed
    {
        return $this->call('POST', $path, $body);
    }

    /**
     * @param array<mixed> $body
     *
     * @return mixed the "data" field
     */
    public function put(string $path, array $body): mixed
    {
        return $this->call('PUT', $path, $body);
    }

    /** @return mixed the "data" field */
    public function delete(string $path): mixed
    {
        return $this->call('DELETE', $path);
    }

    /** @param array<mixed>|null $body */
    private function call(string $method, string $path, ?array $body = null, bool $isRetry = false): mixed
    {
        $request = $this->requests->createRequest($method, $this->baseUrl . $path)
            ->withHeader('Authorization', 'Bearer ' . $this->token())
            ->withHeader('Accept', 'application/json');
        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
        }

        $response = $this->send($request, "{$method} {$path}");

        if ($response->getStatusCode() === 401 && ! $isRetry) {
            $this->forgetToken();

            return $this->call($method, $path, $body, true);
        }

        return $this->unwrap($response, "{$method} {$path}");
    }

    private function unwrap(ResponseInterface $response, string $what): mixed
    {
        $status = $response->getStatusCode();
        $json = json_decode((string) $response->getBody(), true);

        if (! is_array($json)) {
            throw new ProviderError('sadq', "{$what} returned a non-JSON response.", $status);
        }

        $errorCode = (int) ($json['errorCode'] ?? ($status >= 400 ? $status : 0));
        if ($status >= 400 || $errorCode !== 0) {
            $message = is_string($json['message'] ?? null) && $json['message'] !== '' ? $json['message'] : 'Request failed';
            $details = $json['stateValidationErrors'] ?? null;
            if ($details !== null && $details !== '') {
                $message .= ': ' . (is_string($details) ? $details : (string) json_encode($details));
            }

            throw new ProviderError('sadq', "{$what}: {$message}", $status, (string) $errorCode);
        }

        return $json['data'] ?? null;
    }

    private function token(): string
    {
        $now = ($this->clock)();
        if ($this->token !== null && $now < $this->tokenExpiresAt) {
            return $this->token;
        }

        $cached = $this->cache?->get($this->cacheKey());
        if (is_array($cached) && is_string($cached['token'] ?? null) && (int) ($cached['expires_at'] ?? 0) > $now) {
            [$this->token, $this->tokenExpiresAt] = [$cached['token'], (int) $cached['expires_at']];

            return $this->token;
        }

        // A form body built with http_build_query, so "&", "#" and "+" in a password stay intact.
        $form = http_build_query([
            'grant_type' => 'integration',
            'accountId' => $this->credentials->accountId,
            'accountSecret' => $this->credentials->accountSecret,
            'username' => $this->credentials->username,
            'password' => $this->credentials->password,
        ], '', '&', PHP_QUERY_RFC1738);

        $request = $this->requests->createRequest('POST', $this->baseUrl . '/Authentication/Authority/Token')
            ->withHeader('Authorization', 'Basic ' . base64_encode($this->credentials->clientId . ':' . $this->credentials->clientSecret))
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streams->createStream($form));

        $response = $this->send($request, 'token');
        $json = json_decode((string) $response->getBody(), true);
        $token = is_array($json) ? ($json['access_token'] ?? null) : null;

        if ($response->getStatusCode() >= 500) {
            // Seen live: HTTP 500 with an empty body. An outage, not a credentials problem.
            throw new ProviderError('sadq', 'SADQ authentication service is unavailable. Try again later.', $response->getStatusCode());
        }
        if (! is_string($token) || $token === '') {
            // Never echo the response: it can repeat the username back.
            $code = is_array($json) && is_string($json['error'] ?? null) && $json['error'] !== '' ? $json['error'] : null;
            throw new ProviderError('sadq', 'Authentication failed. Check account_id, account_secret, username, password and client_secret.', $response->getStatusCode(), $code);
        }

        // Refresh 5 minutes early. The sandbox issues tokens for about 27 hours.
        $lifetime = max(60, (int) ($json['expires_in'] ?? 3600) - 300);
        $this->token = $token;
        $this->tokenExpiresAt = $now + $lifetime;
        $this->cache?->set($this->cacheKey(), ['token' => $token, 'expires_at' => $this->tokenExpiresAt], $lifetime);

        return $token;
    }

    private function forgetToken(): void
    {
        $this->token = null;
        $this->tokenExpiresAt = 0;
        $this->cache?->delete($this->cacheKey());
    }

    private function cacheKey(): string
    {
        return 'esign_sadq_token_' . sha1($this->baseUrl . '|' . $this->credentials->accountId . '|' . $this->credentials->username);
    }

    private function send(\Psr\Http\Message\RequestInterface $request, string $what): ResponseInterface
    {
        try {
            return $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new ProviderError('sadq', "{$what}: could not reach SADQ ({$e->getMessage()})", null, null, $e);
        }
    }
}
