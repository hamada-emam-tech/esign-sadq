<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Sadq\Tests\Unit;

use HamadaEmamTech\Esign\Discovery\ProviderRegistry;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use HamadaEmamTech\Esign\Sadq\SadqCredentials;
use HamadaEmamTech\Esign\Sadq\SadqFactory;
use HamadaEmamTech\Esign\Sadq\SadqProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SadqFactoryTest extends TestCase
{
    /** @return array<string, string> */
    private function config(): array
    {
        return ['account_id' => 'a', 'account_secret' => 'b', 'username' => 'u@example.sa', 'password' => 'p', 'client_secret' => 'c'];
    }

    #[Test]
    public function it_builds_the_provider_and_finds_guzzle_on_its_own(): void
    {
        self::assertInstanceOf(SadqProvider::class, (new SadqFactory())->create($this->config()));
    }

    #[Test]
    public function missing_settings_are_all_named_at_once(): void
    {
        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('SADQ config is missing: account_secret, password.');
        (new SadqFactory())->create(['account_secret' => '', 'password' => ''] + $this->config());
    }

    #[Test]
    public function an_unknown_environment_is_refused(): void
    {
        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('"sandbox" or "production"');
        (new SadqFactory())->create(['environment' => 'staging'] + $this->config());
    }

    #[Test]
    public function webhook_verification_only_accepts_token_or_none(): void
    {
        (new SadqFactory())->create(['webhook_verification' => 'token', 'webhook_token' => 't'] + $this->config());

        $this->expectException(EsignException::class);
        $this->expectExceptionMessage('"token" or "none"');
        (new SadqFactory())->create(['webhook_verification' => 'off'] + $this->config());
    }

    #[Test]
    public function the_package_declares_itself_to_the_core_discovery(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);
        $installedJson = sys_get_temp_dir() . '/esign-sadq-installed-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($installedJson, (string) json_encode(['packages' => [$composer]]));

        $registry = new ProviderRegistry($installedJson);
        $found = $registry->has('sadq');   // discovery reads the file lazily, on first use
        unlink($installedJson);

        self::assertTrue($found);
        self::assertInstanceOf(SadqProvider::class, $registry->create('sadq', $this->config()));
    }

    #[Test]
    public function credentials_stay_out_of_debug_output(): void
    {
        $dump = print_r(new SadqCredentials('acc', 'acc-secret-value', 'user', 'password-value', 'Integrationclient', 'client-secret-value'), true);

        foreach (['acc-secret-value', 'password-value', 'client-secret-value'] as $secret) {
            self::assertStringNotContainsString($secret, $dump);
        }
    }
}
