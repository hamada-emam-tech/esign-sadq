<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Sadq;

/** The six values SADQ issues for an integration account. Kept out of var_dump() and error trackers. */
final readonly class SadqCredentials
{
    public function __construct(
        public string $accountId,
        #[\SensitiveParameter] public string $accountSecret,
        public string $username,
        #[\SensitiveParameter] public string $password,
        public string $clientId = 'Integrationclient',
        #[\SensitiveParameter] public string $clientSecret = '',
    ) {
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['accountId' => $this->accountId, 'username' => $this->username, 'clientId' => $this->clientId, 'secrets' => '***'];
    }
}
