<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Sadq;

use DateTimeImmutable;
use DateTimeInterface;
use HamadaEmamTech\Esign\Capabilities\Capabilities;
use HamadaEmamTech\Esign\Capabilities\Feature;
use HamadaEmamTech\Esign\Contracts\Features\ExtendsExpiry;
use HamadaEmamTech\Esign\Contracts\Features\ManagesWebhooks;
use HamadaEmamTech\Esign\Contracts\Features\ReportsSigners;
use HamadaEmamTech\Esign\Contracts\Features\SendsReminders;
use HamadaEmamTech\Esign\Contracts\HasCapabilities;
use HamadaEmamTech\Esign\Contracts\Features\RecognizesWebhooks;
use HamadaEmamTech\Esign\Contracts\SignatureProvider;
use HamadaEmamTech\Esign\Support\Headers;
use HamadaEmamTech\Esign\Envelope\EnvelopeDetails;
use HamadaEmamTech\Esign\Envelope\EnvelopeRef;
use HamadaEmamTech\Esign\Envelope\SignerState;
use HamadaEmamTech\Esign\Envelope\SignerStatus;
use HamadaEmamTech\Esign\Envelope\Envelope;
use HamadaEmamTech\Esign\Envelope\EnvelopeStatus;
use HamadaEmamTech\Esign\Envelope\IdentityMethod;
use HamadaEmamTech\Esign\Envelope\SentEnvelope;
use HamadaEmamTech\Esign\Envelope\SignatureField;
use HamadaEmamTech\Esign\Envelope\Signer;
use HamadaEmamTech\Esign\Envelope\WebhookEvent;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use HamadaEmamTech\Esign\Exceptions\InvalidWebhook;
use HamadaEmamTech\Esign\Exceptions\ProviderError;
use Throwable;

/**
 * SADQ (sadq.sa), the Saudi e-signature platform with Nafath identity.
 *
 * Behaviour checked against the SADQ sandbox, 2026-10-18:
 * - Cancelling is asynchronous: status keeps saying "In-progress" for a few minutes, then "Voided".
 *   Status by reference number showed "Voided" before status by envelope ID did, so this driver gives
 *   every envelope a unique reference and reads status by reference.
 * - An unknown envelope ID answers HTTP 200, errorCode 0, data null. An existing one sometimes
 *   does too, for a moment, so a null is retried once.
 * - The account owner appears as a signatory that has already signed (signOrder 0, at any position
 *   in the list). It is not one of your signers, so it never counts toward partially signed.
 * - "Completed" is also reported for an envelope with no invited signers, so Signed additionally
 *   requires every invited signer to show SIGNED.
 * - /envelopes/{id}/files returns the original, unsigned PDF while the envelope is open, and the
 *   signed PDF (PAdES) once it is complete. download() only accepts a PDF that carries a signature.
 * - Adding a second file to an envelope fails on the sandbox, so one document per envelope.
 * - Sending invitations again duplicates the signer. Use remind() to nudge someone.
 */
final class SadqProvider implements SignatureProvider, HasCapabilities, SendsReminders, ExtendsExpiry, ManagesWebhooks, ReportsSigners, RecognizesWebhooks
{
    private const AUTH_EMAIL = 0;
    private const AUTH_NAFATH = 7;

    public function __construct(
        private readonly SadqApi $api,
        private readonly SadqOptions $options = new SadqOptions(),
    ) {
    }

    public function name(): string
    {
        return 'sadq';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            identityMethods: [IdentityMethod::Email, IdentityMethod::Nafath],
            features: [
                Feature::SigningOrder, Feature::FieldPlacement, Feature::SigningUrls, Feature::Reminders,
                Feature::ExpiryExtension, Feature::WebhookManagement, Feature::SignerDetails,
            ],
            maxDocuments: 1,          // adding files to an envelope fails on SADQ
            asyncCancel: true,        // "Voided" shows a few minutes after cancel()
        );
    }

    public function details(string $envelopeId): EnvelopeDetails
    {
        $data = $this->current($envelopeId);
        $signers = array_map(fn (array $s) => new SignerState(
            (string) ($s['email'] ?? ''),
            trim((string) ($s['fullName'] ?? '')),
            match (strtoupper((string) ($s['status'] ?? ''))) {
                'SIGNED' => SignerStatus::Signed,
                'DECLINED', 'REJECTED' => SignerStatus::Declined,
                default => SignerStatus::Pending,
            },
            is_string($s['signingUrl'] ?? null) ? $s['signingUrl'] : null,
            isset($s['signOrder']) ? (int) $s['signOrder'] : null,
        ), $this->invitedSignatories($data));

        return new EnvelopeDetails(new EnvelopeRef('sadq', $envelopeId), $this->map($data), $signers);
    }

    public function send(Envelope $envelope): SentEnvelope
    {
        if (count($envelope->documents) !== 1) {
            throw new EsignException('SADQ takes one document per envelope. Merge the PDFs into one file first.');
        }
        $document = $envelope->documents[0];
        if ($document->mimeType !== 'application/pdf') {
            throw new EsignException("SADQ signs PDFs only; \"{$document->name}\" is {$document->mimeType}.");
        }
        $destinations = array_map(fn (Signer $s) => $this->destination($s), $envelope->signers);

        $initiate = array_filter([
            'webhookId' => $this->options->webhookId,
            'referenceNumber' => $this->reference($envelope),
            'File' => ['FileName' => $document->name, 'File' => base64_encode($document->contents)],
        ], fn ($v) => $v !== null);
        $created = $this->api->post('/api/v1/envelopes/initiate-base64', $initiate);

        $envelopeId = is_array($created) ? ($created['envelopeId'] ?? null) : null;
        $documentId = is_array($created) ? ($created['documentId'] ?? null) : null;
        if (! is_string($envelopeId) || ! is_string($documentId)) {
            throw new ProviderError('sadq', 'Envelope was created without an envelopeId or documentId.');
        }

        try {
            $this->api->post('/api/v1/invitations/envelope', [
                'envelopeId' => $envelopeId,
                'destinations' => array_map(fn (array $d) => $this->withDocument($d, $documentId), $destinations),
                'invitationSubject' => $envelope->subject,
                'invitationMessage' => $envelope->message,
            ]);
        } catch (Throwable $e) {
            // Don't leave an envelope with no signers behind: SADQ treats it as complete.
            try {
                $this->cancel($envelopeId);
            } catch (Throwable) {
            }
            throw $e;
        }

        $urls = [];
        foreach ($this->invitedSignatories($this->byId($envelopeId)) as $signatory) {
            if (is_string($signatory['signingUrl'] ?? null) && is_string($signatory['email'] ?? null)) {
                $urls[$signatory['email']] = $signatory['signingUrl'];
            }
        }

        return new SentEnvelope('sadq', $envelopeId, EnvelopeStatus::Sent, $urls);
    }

    public function status(string $envelopeId): EnvelopeStatus
    {
        return $this->map($this->current($envelopeId));
    }

    public function download(string $envelopeId): string
    {
        $data = $this->current($envelopeId);
        $status = $this->map($data);
        if ($status !== EnvelopeStatus::Signed) {
            throw new EsignException("SADQ envelope {$envelopeId} is {$status->value}, not signed. There is no signed PDF yet.");
        }
        // Once the envelope is complete, /files serves the signed PDF (a PAdES signature, seen in
        // PDFs SADQ delivered). Before that it serves the unsigned original, so the result must carry a
        // signature. /documents/{id}/completed/base64 is the fallback.
        $signed = $this->signedPdfFrom($this->api->get('/api/v1/envelopes/' . rawurlencode($envelopeId) . '/files'));
        if ($signed !== null) {
            return $signed;
        }

        $documentId = $data['documents'][0]['id'] ?? null;
        if (! is_string($documentId)) {
            throw new ProviderError('sadq', "Envelope {$envelopeId} has no document.");
        }
        $signed = $this->signedPdfFrom($this->api->get('/api/v1/documents/' . rawurlencode($documentId) . '/completed/base64'));

        return $signed ?? throw new ProviderError('sadq', "SADQ says envelope {$envelopeId} is signed, but returned no PDF with a digital signature. Try again shortly.");
    }

    /** SADQ processes the cancel asynchronously: status() can report the old state for a few minutes. */
    public function cancel(string $envelopeId): void
    {
        $this->api->post('/api/v1/envelopes/' . rawurlencode($envelopeId) . '/cancel', ['envelopId' => $envelopeId]);
    }

    /** SADQ posts {"RequestId": "...", "Status": n} with no signature header of its own. */
    public function recognizesWebhook(array $headers, string $rawBody): bool
    {
        $payload = json_decode($rawBody, true);

        return is_array($payload)
            && array_intersect(['RequestId', 'requestId', 'EnvelopeId', 'envelopeId'], array_keys($payload)) !== []
            && (array_key_exists('Status', $payload) || array_key_exists('status', $payload));
    }

    public function parseWebhook(array $headers, string $rawBody): WebhookEvent
    {
        if ($this->options->verifyWebhooks) {
            $expected = (string) $this->options->webhookToken;
            if ($expected === '') {
                throw new InvalidWebhook('SADQ webhook_verification is "token" but no webhook_token is configured. Refusing the webhook.');
            }
            if (! $this->hasToken($headers, $expected)) {
                throw new InvalidWebhook('SADQ webhook token does not match.');
            }
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            throw new InvalidWebhook('SADQ webhook body is not JSON.');
        }
        // SADQ has sent each of these spellings.
        $id = $payload['RequestId'] ?? $payload['requestId'] ?? $payload['EnvelopeId'] ?? $payload['envelopeId'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new InvalidWebhook('SADQ webhook has no envelope ID.');
        }

        return new WebhookEvent('sadq', $id, null, $payload);
    }

    // ---- SADQ-only operations ----------------------------------------------------

    /** Email the signing link to a signer again. Safe: unlike re-inviting, it does not duplicate the signer. */
    public function remind(string $envelopeId, string $signerEmail): void
    {
        $this->api->post('/api/v1/invitations/reminders/send', ['requestId' => $envelopeId, 'envelopId' => $envelopeId, 'destinationEmail' => $signerEmail]);
    }

    /** Move every open invitation's deadline. */
    public function extend(string $envelopeId, DateTimeInterface $until): void
    {
        $this->api->put('/api/v1/envelopes/extend-invitations', ['envelopeId' => $envelopeId, 'availableTo' => $until->format('Y-m-d')]);
    }

    /**
     * Register a webhook URL, with an optional HeaderToken ($secret).
     * Keep $isDefault false on a shared account, and pass the returned ID as webhook_id.
     */
    public function registerWebhook(string $url, #[\SensitiveParameter] ?string $secret = null, bool $isDefault = false): string
    {
        $data = $this->api->post('/api/v1/webhooks', ['webhookUrl' => $url, 'isDefault' => $isDefault, 'HeaderToken' => $secret ?? '']);
        $id = is_array($data) ? ($data['id'] ?? null) : null;

        return is_string($id) ? $id : throw new ProviderError('sadq', 'Webhook was registered without an ID.');
    }

    public function deleteWebhook(string $webhookId): void
    {
        $this->api->delete('/api/v1/webhooks/' . rawurlencode($webhookId));
    }

    /** @return list<array{id: string, url: string, default: bool}> */
    public function webhooks(): array
    {
        $data = $this->api->get('/api/v1/webhooks');

        return array_values(array_map(fn (array $w) => [
            'id' => (string) ($w['id'] ?? ''),
            'url' => (string) ($w['webhookUrl'] ?? ''),
            'default' => (bool) ($w['isDefault'] ?? false),
        ], is_array($data) ? array_filter($data, 'is_array') : []));
    }

    /** Ask SADQ to send the webhook for an envelope again. */
    public function resendWebhook(string $envelopeId): void
    {
        $this->api->post('/api/v1/webhooks/recall', ['requestId' => $envelopeId]);
    }

    // ---- internals ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function destination(Signer $signer): array
    {
        $authentication = match ($signer->identity) {
            IdentityMethod::Email => self::AUTH_EMAIL,
            IdentityMethod::Nafath => self::AUTH_NAFATH,
            default => throw new EsignException("SADQ does not support the \"{$signer->identity->value}\" identity method. Use email or nafath."),
        };
        foreach ($signer->fields as $field) {
            if ($field->documentIndex !== 0) {
                throw new EsignException("Signer \"{$signer->name}\" has a field on document {$field->documentIndex}; SADQ envelopes hold one document.");
            }
        }

        return [
            'destinationName' => $signer->name,
            'destinationEmail' => $signer->email,
            'destinationPhoneNumber' => $signer->mobile ?? '',
            'nationalId' => $signer->identity === IdentityMethod::Nafath ? $signer->nationalId : null,
            // Order 0 is the account owner in SADQ's lists, so signers start at 1.
            'signeOrder' => max(1, $signer->order),
            'consentOnly' => $signer->fields === [],
            'fields' => $signer->fields,
            'availableTo' => (new DateTimeImmutable("+{$this->options->expiresInDays} days"))->format('Y-m-d'),
            'authenticationType' => $authentication,
            'invitationLanguage' => $this->options->invitationLanguage,
            'redirectUrl' => null,
        ];
    }

    /**
     * @param array<string, mixed> $destination
     *
     * @return array<string, mixed>
     */
    private function withDocument(array $destination, string $documentId): array
    {
        /** @var list<SignatureField> $fields */
        $fields = $destination['fields'];
        unset($destination['fields']);
        $destination['signatories'] = array_map(fn (SignatureField $f) => [
            'type' => 'Signature',
            'documentId' => $documentId,
            'pageNumber' => $f->page,
            'positionX' => (int) round($f->x),
            'positionY' => (int) round($f->y),
            'signatureWidth' => (int) round($f->width),
            'signatureHigh' => (int) round($f->height),   // SADQ's spelling
            'text' => '',
            'fontFamily' => '',
            'fontSize' => 0,
        ], $fields);

        return $destination;
    }

    /** A reference that is unique per envelope, so status by reference can never hit another one. */
    private function reference(Envelope $envelope): string
    {
        $mine = $envelope->metadata['reference'] ?? null;
        $suffix = bin2hex(random_bytes(6));

        return is_string($mine) && $mine !== '' ? substr($mine, 0, 80) . '-' . $suffix : 'esign-' . $suffix . bin2hex(random_bytes(2));
    }

    /** @return array<string, mixed> */
    private function byId(string $envelopeId): array
    {
        $path = '/api/v1/envelopes/' . rawurlencode($envelopeId) . '/status';
        // The sandbox now and then answers data:null for an envelope that exists. Ask once more
        // before calling it missing.
        $data = $this->api->get($path) ?? $this->api->get($path);
        if (! is_array($data)) {
            throw new ProviderError('sadq', "Envelope {$envelopeId} was not found.", 404);
        }

        return $data;
    }

    /**
     * The envelope as SADQ really has it: by reference when there is one, because status by ID
     * misses cancellations.
     *
     * @return array<string, mixed>
     */
    private function current(string $envelopeId): array
    {
        $byId = $this->byId($envelopeId);
        $reference = $byId['referenceNumber'] ?? null;
        if (! is_string($reference) || $reference === '') {
            return $byId;
        }

        $byReference = $this->api->get('/api/v1/envelopes/reference/' . rawurlencode($reference) . '/status');
        $sameEnvelope = is_array($byReference) && strcasecmp((string) ($byReference['id'] ?? ''), $envelopeId) === 0;

        return $sameEnvelope ? $byReference : $byId;
    }

    /** @param array<string, mixed> $data */
    private function map(array $data): EnvelopeStatus
    {
        $raw = (string) ($data['status'] ?? '');

        return match (strtolower(str_replace([' ', '_'], '-', trim($raw)))) {
            // SADQ also says "Completed" for an envelope with no invited signers, which its own
            // download endpoint then refuses. Signed means every signer you invited signed.
            'completed' => $this->everyoneSigned($data) ? EnvelopeStatus::Signed : ($this->anyoneSigned($data) ? EnvelopeStatus::PartiallySigned : EnvelopeStatus::Sent),
            'voided', 'cancelled', 'canceled' => EnvelopeStatus::Cancelled,
            'rejected', 'declined' => EnvelopeStatus::Declined,
            'expired' => EnvelopeStatus::Expired,
            'in-progress', 'inprogress', 'pending', 'new' => $this->anyoneSigned($data) ? EnvelopeStatus::PartiallySigned : EnvelopeStatus::Sent,
            default => throw new ProviderError('sadq', "SADQ returned an envelope status this driver does not know: \"{$raw}\". Please report it."),
        };
    }

    /** @param array<string, mixed> $data */
    private function everyoneSigned(array $data): bool
    {
        $invited = $this->invitedSignatories($data);

        return $invited !== [] && array_filter($invited, fn (array $s) => strtoupper((string) ($s['status'] ?? '')) !== 'SIGNED') === [];
    }

    /** @param array<string, mixed> $data */
    private function anyoneSigned(array $data): bool
    {
        foreach ($this->invitedSignatories($data) as $signatory) {
            if (strtoupper((string) ($signatory['status'] ?? '')) === 'SIGNED') {
                return true;
            }
        }

        return false;
    }

    /**
     * The signatories you invited, without the account owner SADQ lists first.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function invitedSignatories(array $data): array
    {
        $signatories = is_array($data['signatories'] ?? null) ? $data['signatories'] : [];

        return array_values(array_filter($signatories, fn ($s) => is_array($s) && ! $this->isOwner($s)));
    }

    /** @param array<string, mixed> $signatory */
    private function isOwner(array $signatory): bool
    {
        if ((int) ($signatory['signOrder'] ?? -1) !== 0) {
            return false;
        }

        return $this->options->accountEmail === '' || strcasecmp((string) ($signatory['email'] ?? ''), $this->options->accountEmail) === 0;
    }

    /** The first file in a SADQ response that is a PDF with a digital signature in it, or null. */
    private function signedPdfFrom(mixed $data): ?string
    {
        // Seen: data = [{file, fileName}] and [{file, fileName, contentType}]. A bare string is accepted too.
        $items = match (true) {
            is_string($data) => [$data],
            is_array($data) && array_is_list($data) => $data,
            is_array($data) => [$data],
            default => [],
        };
        foreach ($items as $item) {
            $encoded = is_string($item) ? $item : (is_array($item) ? ($item['file'] ?? $item['content'] ?? null) : null);
            $pdf = is_string($encoded) ? base64_decode($encoded, true) : false;
            if ($pdf !== false && str_starts_with($pdf, '%PDF') && str_contains($pdf, '/ByteRange')) {
                return $pdf;
            }
        }

        return null;
    }

    /** @param array<string, string|string[]> $headers */
    private function hasToken(array $headers, string $expected): bool
    {
        $wanted = $this->options->webhookHeader === null ? null : strtolower($this->options->webhookHeader);
        foreach ($headers as $name => $values) {
            if ($wanted !== null && strtolower((string) $name) !== $wanted) {
                continue;
            }
            foreach ((array) $values as $value) {
                $value = (string) $value;
                if (hash_equals($expected, $value) || (str_starts_with($value, 'Bearer ') && hash_equals($expected, substr($value, 7)))) {
                    return true;
                }
            }
        }

        return false;
    }
}
