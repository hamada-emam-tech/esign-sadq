<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Sadq;

/** Behaviour settings for the SADQ driver. Everything except the credentials. */
final readonly class SadqOptions
{
    public function __construct(
        /**
         * The webhook SADQ notifies for envelopes this driver sends. Without it SADQ notifies the
         * account's default webhook, which on a shared account can be someone else's server.
         */
        public ?string $webhookId = null,
        /** The HeaderToken registered with the webhook. Checked only when $verifyWebhooks is true. */
        #[\SensitiveParameter] public ?string $webhookToken = null,
        /** The header that carries $webhookToken. Null checks every header. */
        public ?string $webhookHeader = null,
        /** SADQ's invitationLanguage code. */
        public int $invitationLanguage = 1,
        /** How long invitations stay open. */
        public int $expiresInDays = 30,
        /** The account's own login email. SADQ lists the account owner as an already-signed signatory. */
        public string $accountEmail = '',
        /**
         * true refuses webhooks that don't carry $webhookToken. Off by default: SADQ doesn't sign
         * webhooks, and it isn't confirmed that it forwards the HeaderToken. The driver never trusts
         * the body anyway: it takes the envelope ID, and status() asks SADQ.
         */
        public bool $verifyWebhooks = false,
    ) {
    }
}
