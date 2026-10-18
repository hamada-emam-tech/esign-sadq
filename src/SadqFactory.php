<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Sadq;

use HamadaEmamTech\Esign\Contracts\ProviderFactory;
use HamadaEmamTech\Esign\Contracts\SignatureProvider;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Builds the SADQ provider from its config block:
 *
 *     'sadq' => [
 *         'environment'    => 'sandbox',            // or 'production', or set 'base_url'
 *         'account_id'     => '...', 'account_secret' => '...',
 *         'username'       => '...', 'password'       => '...',
 *         'client_secret'  => '...',                // 'client_id' defaults to Integrationclient
 *         'webhook_id'     => '...',
 *         // optional: webhook_verification ('none' default | 'token'), webhook_token, webhook_header,
 *         //           invitation_language, expires_in_days,
 *         //           http_client, request_factory, stream_factory, cache (PSR-16)
 *     ]
 */
final class SadqFactory implements ProviderFactory
{
    public const BASE_URLS = [
        'sandbox' => 'https://api-sandbox.sadq-sa.com',
        'production' => 'https://apigw.sadq.sa',
    ];

    private const REQUIRED = ['account_id', 'account_secret', 'username', 'password', 'client_secret'];

    public function create(array $config): SignatureProvider
    {
        $missing = array_values(array_filter(self::REQUIRED, fn (string $key) => ! is_string($config[$key] ?? null) || $config[$key] === ''));
        if ($missing !== []) {
            throw new EsignException('SADQ config is missing: ' . implode(', ', $missing) . '.');
        }

        $environment = (string) ($config['environment'] ?? 'sandbox');
        $baseUrl = $config['base_url'] ?? self::BASE_URLS[$environment]
            ?? throw new EsignException("SADQ environment must be \"sandbox\" or \"production\", got \"{$environment}\".");

        [$client, $requests, $streams] = self::http($config);
        $cache = $config['cache'] ?? null;

        $credentials = new SadqCredentials(
            (string) $config['account_id'],
            (string) $config['account_secret'],
            (string) $config['username'],
            (string) $config['password'],
            (string) ($config['client_id'] ?? 'Integrationclient'),
            (string) $config['client_secret'],
        );

        return new SadqProvider(
            new SadqApi($client, $requests, $streams, $credentials, rtrim((string) $baseUrl, '/'), $cache instanceof CacheInterface ? $cache : null),
            new SadqOptions(
                webhookId: self::stringOrNull($config['webhook_id'] ?? null),
                webhookToken: self::stringOrNull($config['webhook_token'] ?? null),
                webhookHeader: self::stringOrNull($config['webhook_header'] ?? null),
                invitationLanguage: (int) ($config['invitation_language'] ?? 1),
                expiresInDays: max(1, (int) ($config['expires_in_days'] ?? 30)),
                accountEmail: (string) $config['username'],
                verifyWebhooks: self::verification($config['webhook_verification'] ?? 'none'),
            ),
        );
    }

    /**
     * Use the HTTP client you pass, or find one that is installed: php-http/discovery,
     * then Guzzle 7, then Symfony HttpClient.
     *
     * @param array<string, mixed> $config
     *
     * @return array{ClientInterface, RequestFactoryInterface, StreamFactoryInterface}
     */
    private static function http(array $config): array
    {
        $client = $config['http_client'] ?? null;
        $requests = $config['request_factory'] ?? null;
        $streams = $config['stream_factory'] ?? $requests;

        if ($client instanceof ClientInterface && $requests instanceof RequestFactoryInterface && $streams instanceof StreamFactoryInterface) {
            return [$client, $requests, $streams];
        }

        if (class_exists(\Http\Discovery\Psr18ClientDiscovery::class)) {
            return [
                $client instanceof ClientInterface ? $client : \Http\Discovery\Psr18ClientDiscovery::find(),
                $requests instanceof RequestFactoryInterface ? $requests : \Http\Discovery\Psr17FactoryDiscovery::findRequestFactory(),
                $streams instanceof StreamFactoryInterface ? $streams : \Http\Discovery\Psr17FactoryDiscovery::findStreamFactory(),
            ];
        }
        if (class_exists(\GuzzleHttp\Client::class) && class_exists(\GuzzleHttp\Psr7\HttpFactory::class)) {
            $factory = new \GuzzleHttp\Psr7\HttpFactory();

            return [$client instanceof ClientInterface ? $client : new \GuzzleHttp\Client(['timeout' => 60]), $factory, $factory];
        }
        if (class_exists(\Symfony\Component\HttpClient\Psr18Client::class)) {
            $symfony = new \Symfony\Component\HttpClient\Psr18Client();

            return [$client instanceof ClientInterface ? $client : $symfony, $symfony, $symfony];
        }

        throw new EsignException('SADQ needs a PSR-18 HTTP client. Run: composer require guzzlehttp/guzzle (or pass http_client, request_factory and stream_factory).');
    }

    private static function verification(mixed $mode): bool
    {
        return match ($mode) {
            'token' => true,
            'none' => false,
            default => throw new EsignException('SADQ webhook_verification must be "token" or "none".'),
        };
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
