<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Sadq\Tests\Unit;

use HamadaEmamTech\Esign\Envelope\Document;
use HamadaEmamTech\Esign\Envelope\Envelope;
use HamadaEmamTech\Esign\Envelope\EnvelopeStatus;
use HamadaEmamTech\Esign\Envelope\IdentityMethod;
use HamadaEmamTech\Esign\Envelope\SignatureField;
use HamadaEmamTech\Esign\Envelope\Signer;
use HamadaEmamTech\Esign\Envelope\SignerStatus;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use HamadaEmamTech\Esign\Exceptions\InvalidWebhook;
use HamadaEmamTech\Esign\Exceptions\ProviderError;
use HamadaEmamTech\Esign\Sadq\SadqOptions;
use HamadaEmamTech\Esign\Sadq\Tests\Support\FakeSadq;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SadqProviderTest extends TestCase
{
    private FakeSadq $sadq;

    protected function setUp(): void
    {
        $this->sadq = new FakeSadq();
    }

    private function envelope(Signer ...$signers): Envelope
    {
        return new Envelope(
            [new Document('contract.pdf', FakeSadq::ORIGINAL)],
            $signers === [] ? [
                Signer::nafath('1012345678', 'Ahmed Ali', 'ahmed@example.sa', 1, [new SignatureField(1, 60.4, 700, 150, 50)]),
                Signer::email('Sara', 'sara@example.sa', 2),
            ] : array_values($signers),
            subject: 'Please sign',
            metadata: ['reference' => 'contract-42'],
        );
    }

    // ---- send ----------------------------------------------------------------------

    #[Test]
    public function send_creates_the_envelope_invites_everyone_and_returns_their_links(): void
    {
        $sent = $this->sadq->provider()->send($this->envelope());

        self::assertSame('env-1', $sent->envelopeId);
        self::assertSame(EnvelopeStatus::Sent, $sent->status);
        // The account owner is in SADQ's list too, but it is not one of our signers.
        self::assertSame(['ahmed@example.sa', 'sara@example.sa'], array_keys($sent->signingUrls));

        $initiate = $this->sadq->lastBody('/api/v1/envelopes/initiate-base64');
        self::assertSame('wh-1', $initiate['webhookId']);
        self::assertSame(base64_encode(FakeSadq::ORIGINAL), $initiate['File']['File']);
        self::assertMatchesRegularExpression('/^contract-42-[0-9a-f]{12}$/', $initiate['referenceNumber']);
    }

    #[Test]
    public function signers_are_mapped_to_sadq_destinations(): void
    {
        $this->sadq->provider()->send($this->envelope());
        [$ahmed, $sara] = $this->sadq->lastBody('/api/v1/invitations/envelope')['destinations'];

        self::assertSame(7, $ahmed['authenticationType']);          // Nafath
        self::assertSame('1012345678', $ahmed['nationalId']);
        self::assertSame(1, $ahmed['signeOrder']);
        self::assertFalse($ahmed['consentOnly']);
        self::assertSame(
            ['type' => 'Signature', 'documentId' => 'doc-1', 'pageNumber' => 1, 'positionX' => 60, 'positionY' => 700, 'signatureWidth' => 150, 'signatureHigh' => 50, 'text' => '', 'fontFamily' => '', 'fontSize' => 0],
            $ahmed['signatories'][0],
        );

        self::assertSame(0, $sara['authenticationType']);           // email
        self::assertNull($sara['nationalId']);
        self::assertSame(2, $sara['signeOrder']);
        self::assertTrue($sara['consentOnly']);                     // no fields: agree-to-sign
        self::assertSame([], $sara['signatories']);
    }

    #[Test]
    public function every_envelope_gets_a_unique_reference_even_for_the_same_contract(): void
    {
        $provider = $this->sadq->provider();
        $provider->send($this->envelope());
        $first = $this->sadq->lastBody('/api/v1/envelopes/initiate-base64')['referenceNumber'];
        $provider->send($this->envelope());

        self::assertNotSame($first, $this->sadq->lastBody('/api/v1/envelopes/initiate-base64')['referenceNumber']);
    }

    #[Test]
    public function more_than_one_document_is_refused_before_calling_sadq(): void
    {
        $envelope = new Envelope([new Document('a.pdf', '%PDF a'), new Document('b.pdf', '%PDF b')], [Signer::email('A', 'a@example.sa')]);

        try {
            $this->sadq->provider()->send($envelope);
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('one document per envelope', $e->getMessage());
        }
        self::assertSame([], $this->sadq->calls);
    }

    #[Test]
    public function an_unsupported_identity_method_is_refused_before_calling_sadq(): void
    {
        $sms = new Signer('Omar', 'omar@example.sa', IdentityMethod::SmsOtp, mobile: '+966500000000');

        try {
            $this->sadq->provider()->send($this->envelope($sms));
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('sms_otp', $e->getMessage());
        }
        self::assertSame([], $this->sadq->calls);
    }

    #[Test]
    public function a_failed_invitation_cancels_the_half_made_envelope(): void
    {
        $this->sadq->failInvitations = 400;

        try {
            $this->sadq->provider()->send($this->envelope());
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame('400', $e->providerCode);
        }
        self::assertContains('/api/v1/envelopes/env-1/cancel', $this->sadq->paths());
    }

    // ---- status --------------------------------------------------------------------

    #[Test]
    public function status_ignores_the_owner_and_follows_the_signers(): void
    {
        $provider = $this->sadq->provider();
        $id = $provider->send($this->envelope())->envelopeId;

        self::assertSame(EnvelopeStatus::Sent, $provider->status($id));        // owner shows SIGNED already

        $this->sadq->sign($id, 'ahmed@example.sa');
        self::assertSame(EnvelopeStatus::PartiallySigned, $provider->status($id));

        $this->sadq->signAll($id);
        self::assertSame(EnvelopeStatus::Signed, $provider->status($id));
    }

    #[Test]
    public function details_lists_only_the_invited_signers_with_their_status_and_links(): void
    {
        $provider = $this->sadq->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->sadq->sign($id, 'ahmed@example.sa');

        $details = $provider->details($id);

        self::assertSame('sadq:' . $id, (string) $details->ref);
        self::assertSame(EnvelopeStatus::PartiallySigned, $details->status);
        self::assertSame(['ahmed@example.sa', 'sara@example.sa'], array_map(fn ($s) => $s->email, $details->signers));   // no owner
        self::assertSame([SignerStatus::Signed, SignerStatus::Pending], array_map(fn ($s) => $s->status, $details->signers));
        self::assertSame([1, 2], array_map(fn ($s) => $s->order, $details->signers));
        self::assertSame('sara@example.sa', $details->pending()[0]->email);
    }

    #[Test]
    public function a_cancelled_envelope_is_read_by_reference_which_shows_the_cancel_first(): void
    {
        $provider = $this->sadq->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $provider->cancel($id);

        self::assertSame(EnvelopeStatus::Cancelled, $provider->status($id));
        self::assertContains('/api/v1/envelopes/reference/' . rawurlencode($this->sadq->lastBody('/api/v1/envelopes/initiate-base64')['referenceNumber']) . '/status', $this->sadq->paths());
    }

    #[Test]
    public function completed_without_every_invited_signer_signing_is_not_signed(): void
    {
        $provider = $this->sadq->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->sadq->forcedStatus = 'Completed';     // seen live on an envelope with no invited signers

        self::assertSame(EnvelopeStatus::Sent, $provider->status($id));
        $this->sadq->sign($id, 'ahmed@example.sa');
        self::assertSame(EnvelopeStatus::PartiallySigned, $provider->status($id));
        $this->expectException(EsignException::class);
        $provider->download($id);
    }

    #[Test]
    public function the_owner_is_recognised_wherever_sadq_lists_it(): void
    {
        $provider = $this->sadq->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->sadq->moveOwnerToEnd($id);

        self::assertSame(EnvelopeStatus::Sent, $provider->status($id));
        $this->sadq->signAll($id);
        self::assertSame(EnvelopeStatus::Signed, $provider->status($id));
    }

    #[Test]
    public function an_unknown_envelope_is_not_found_even_though_sadq_says_success(): void
    {
        $this->expectException(ProviderError::class);
        $this->expectExceptionMessage('was not found');
        $this->sadq->provider()->status('does-not-exist');
    }

    #[Test]
    public function an_unknown_sadq_status_fails_loudly_instead_of_guessing(): void
    {
        $provider = $this->sadq->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->sadq->forcedStatus = 'Archived';

        $this->expectException(ProviderError::class);
        $this->expectExceptionMessage('"Archived"');
        $provider->status($id);
    }

    #[Test]
    public function cancelling_twice_surfaces_sadqs_error_code(): void
    {
        $provider = $this->sadq->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $provider->cancel($id);

        try {
            $provider->cancel($id);
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame('9', $e->providerCode);
            self::assertSame(200, $e->httpStatus);
        }
    }

    // ---- download ------------------------------------------------------------------

    #[Test]
    public function download_returns_the_signed_pdf_from_the_files_endpoint(): void
    {
        $provider = $this->sadq->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->sadq->signAll($id);

        self::assertSame(FakeSadq::SIGNED, $provider->download($id));
        self::assertNotContains('/api/v1/documents/doc-1/completed/base64', $this->sadq->paths());
    }

    #[Test]
    public function an_unsigned_file_is_never_returned_as_the_signed_one(): void
    {
        $provider = $this->sadq->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->sadq->signAll($id);
        $this->sadq->filesLag = true;       // /files still has the original: fall back to the completed endpoint

        self::assertSame(FakeSadq::SIGNED, $provider->download($id));
        self::assertContains('/api/v1/documents/doc-1/completed/base64', $this->sadq->paths());
    }

    #[Test]
    public function download_refuses_before_everyone_signed(): void
    {
        $provider = $this->sadq->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->sadq->sign($id, 'ahmed@example.sa');

        try {
            $provider->download($id);
            self::fail('Expected an exception');
        } catch (EsignException $e) {
            self::assertStringContainsString('partially_signed', $e->getMessage());
        }
        self::assertNotContains('/api/v1/documents/doc-1/completed/base64', $this->sadq->paths());
        self::assertNotContains("/api/v1/envelopes/{$id}/files", $this->sadq->paths());
    }

    // ---- authentication --------------------------------------------------------------

    #[Test]
    public function the_token_is_fetched_once_and_reused(): void
    {
        $provider = $this->sadq->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $provider->status($id);
        $provider->status($id);

        self::assertSame(1, $this->sadq->tokenCalls);
    }

    #[Test]
    public function the_token_is_refreshed_before_it_expires(): void
    {
        $now = 1_000_000;
        $provider = $this->sadq->provider(clock: function () use (&$now) {
            return $now;
        });
        $id = $provider->send($this->envelope())->envelopeId;

        $now += 99_200 - 299;   // inside the 5-minute safety margin
        $provider->status($id);

        self::assertSame(2, $this->sadq->tokenCalls);
    }

    #[Test]
    public function a_401_gets_a_fresh_token_and_one_retry(): void
    {
        $provider = $this->sadq->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $this->sadq->unauthorizedNext = 1;

        self::assertSame(EnvelopeStatus::Sent, $provider->status($id));
        self::assertSame(2, $this->sadq->tokenCalls);
    }

    #[Test]
    public function special_characters_in_the_password_survive_the_form_body(): void
    {
        // FakeSadq only accepts the exact password, which contains & # + and =.
        $this->sadq->provider()->send($this->envelope());

        self::assertSame(1, $this->sadq->tokenCalls);
    }

    #[Test]
    public function a_failed_login_never_leaks_credentials_into_the_error(): void
    {
        $this->sadq->rejectLogin = true;

        try {
            $this->sadq->provider()->send($this->envelope());
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame('invalid_grant', $e->providerCode);
            self::assertStringNotContainsString(FakeSadq::PASSWORD, $e->getMessage());
            self::assertStringNotContainsString(FakeSadq::OWNER, $e->getMessage());
        }
    }

    #[Test]
    public function a_login_outage_is_reported_as_an_outage_not_as_bad_credentials(): void
    {
        $this->sadq->loginOutage = true;

        try {
            $this->sadq->provider()->send($this->envelope());
            self::fail('Expected an exception');
        } catch (ProviderError $e) {
            self::assertSame(500, $e->httpStatus);
            self::assertStringContainsString('unavailable', $e->getMessage());
        }
    }

    // ---- webhooks ------------------------------------------------------------------

    #[Test]
    public function a_webhook_with_the_right_token_says_which_envelope_changed(): void
    {
        $provider = $this->sadq->provider();

        foreach (['RequestId', 'requestId', 'EnvelopeId', 'envelopeId'] as $key) {
            $event = $provider->parseWebhook(['X-Custom-Token' => ['hook-token']], (string) json_encode([$key => 'env-9', 'Status' => 1]));
            self::assertSame('env-9', $event->envelopeId, $key);
        }
        self::assertSame('env-9', $provider->parseWebhook(['Authorization' => 'Bearer hook-token'], '{"RequestId":"env-9"}')->envelopeId);
    }

    #[Test]
    public function a_webhook_with_a_wrong_token_is_refused(): void
    {
        $this->expectException(InvalidWebhook::class);
        $this->sadq->provider()->parseWebhook(['X-Custom-Token' => 'wrong'], '{"RequestId":"env-9"}');
    }

    #[Test]
    public function in_token_mode_a_missing_token_setting_refuses_every_webhook(): void
    {
        $provider = $this->sadq->provider(new SadqOptions(webhookToken: null, verifyWebhooks: true));

        $this->expectException(InvalidWebhook::class);
        $this->expectExceptionMessage('no webhook_token is configured');
        $provider->parseWebhook(['X-Custom-Token' => ''], '{"RequestId":"env-9"}');
    }

    #[Test]
    public function by_default_webhooks_are_accepted_without_a_token(): void
    {
        $provider = $this->sadq->provider(new SadqOptions());

        // Exactly the headers seen on SADQ webhook deliveries: no token anywhere.
        $headers = ['host' => 'app.example.sa', 'accept' => '*/*', 'content-type' => 'application/json', 'content-length' => '58'];
        self::assertSame('env-9', $provider->parseWebhook($headers, '{"RequestId":"env-9","Status":2}')->envelopeId);

        $this->expectException(InvalidWebhook::class);       // still refuses a body with no envelope ID
        $provider->parseWebhook($headers, '[]');
    }

    #[Test]
    public function a_named_webhook_header_is_the_only_one_checked(): void
    {
        $provider = $this->sadq->provider(new SadqOptions(webhookToken: 'hook-token', webhookHeader: 'X-Sadq-Token', verifyWebhooks: true));

        self::assertSame('env-9', $provider->parseWebhook(['x-sadq-token' => 'hook-token'], '{"RequestId":"env-9"}')->envelopeId);

        $this->expectException(InvalidWebhook::class);
        $provider->parseWebhook(['X-Other' => 'hook-token'], '{"RequestId":"env-9"}');
    }

    #[Test]
    public function a_webhook_without_an_envelope_id_is_refused(): void
    {
        $this->expectException(InvalidWebhook::class);
        $this->sadq->provider()->parseWebhook(['X-Custom-Token' => 'hook-token'], '{"Status":3}');
    }

    // ---- SADQ-only ---------------------------------------------------------------------

    #[Test]
    public function remind_uses_the_reminder_endpoint_not_a_second_invitation(): void
    {
        $provider = $this->sadq->provider();
        $id = $provider->send($this->envelope())->envelopeId;
        $invitations = count(array_keys($this->sadq->paths(), '/api/v1/invitations/envelope', true));

        $provider->remind($id, 'sara@example.sa');

        self::assertSame($invitations, count(array_keys($this->sadq->paths(), '/api/v1/invitations/envelope', true)));
        self::assertSame('sara@example.sa', $this->sadq->lastBody('/api/v1/invitations/reminders/send')['destinationEmail']);
    }

    #[Test]
    public function register_webhook_is_never_default_unless_asked(): void
    {
        $id = $this->sadq->provider()->registerWebhook('https://app.example.sa/webhooks/sadq', 'secret-token');

        self::assertSame('wh-new', $id);
        self::assertFalse($this->sadq->lastBody('/api/v1/webhooks')['isDefault']);
        self::assertSame('secret-token', $this->sadq->lastBody('/api/v1/webhooks')['HeaderToken']);
    }
}
