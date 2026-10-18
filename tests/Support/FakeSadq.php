<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Sadq\Tests\Support;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use HamadaEmamTech\Esign\Sadq\SadqApi;
use HamadaEmamTech\Esign\Sadq\SadqCredentials;
use HamadaEmamTech\Esign\Sadq\SadqOptions;
use HamadaEmamTech\Esign\Sadq\SadqProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * An in-memory SADQ that behaves like the real sandbox did on 2026-10-18, traps included:
 * - failures come back as HTTP 200 with a non-zero errorCode;
 * - an unknown envelope is HTTP 200, errorCode 0, data null;
 * - the account owner is listed as an already-signed signatory with signOrder 0;
 * - after a cancel, status by reference says "Voided" while status by ID still says "In-progress";
 * - /envelopes/{id}/files serves the original, unsigned PDF until everyone signed, then the signed one;
 * - /documents/{id}/completed/base64 fails with errorCode 14 until everyone signed;
 * - cancelling twice fails with errorCode 9.
 */
final class FakeSadq implements ClientInterface
{
    public const OWNER = 'owner@integration.test';
    public const PASSWORD = 'p&ss#w+rd=1';
    public const ORIGINAL = "%PDF-1.4 original\n";
    public const SIGNED = "%PDF-1.7 signed by everyone /ByteRange [0 100 200 300] /SubFilter /ETSI.CAdES.detached\n";

    /** @var list<array{method: string, path: string, body: mixed}> */
    public array $calls = [];

    public int $tokenCalls = 0;

    public int $expiresIn = 99200;

    /** Answer the next N API calls with 401, as an expired token would. */
    public int $unauthorizedNext = 0;

    /** Fail the next invitation call with this errorCode. */
    public ?int $failInvitations = null;

    public bool $rejectLogin = false;

    public bool $loginOutage = false;

    /** /files still serves the unsigned original right after completion. */
    public bool $filesLag = false;

    /** Status string to report instead of the real one, to test unknown statuses. */
    public ?string $forcedStatus = null;

    /** @var array<string, array{reference: string, documentId: string, webhookId: ?string, cancelled: bool, signatories: list<array<string, mixed>>}> */
    private array $envelopes = [];

    private int $sequence = 0;

    public function provider(?SadqOptions $options = null, ?\Closure $clock = null): SadqProvider
    {
        $factory = new HttpFactory();
        $credentials = new SadqCredentials('acc-1', 'acc-secret', self::OWNER, self::PASSWORD, 'Integrationclient', 'client-secret');

        return new SadqProvider(
            new SadqApi($this, $factory, $factory, $credentials, 'https://sadq.test', null, $clock),
            $options ?? new SadqOptions(webhookId: 'wh-1', webhookToken: 'hook-token', accountEmail: self::OWNER, verifyWebhooks: true),
        );
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $body = (string) $request->getBody();

        if ($path === '/Authentication/Authority/Token') {
            return $this->token($request, $body);
        }

        $json = $body === '' ? null : json_decode($body, true);
        $this->calls[] = ['method' => $request->getMethod(), 'path' => $path, 'body' => $json];

        if ($this->unauthorizedNext > 0) {
            --$this->unauthorizedNext;

            return new Response(401);
        }
        if ($request->getHeaderLine('Authorization') !== 'Bearer token-' . $this->tokenCalls) {
            return new Response(401);
        }

        return match (true) {
            $path === '/api/v1/envelopes/initiate-base64' => $this->initiate((array) $json),
            $path === '/api/v1/invitations/envelope' => $this->invite((array) $json),
            (bool) preg_match('#^/api/v1/envelopes/reference/([^/]+)/status$#', $path, $m) => $this->statusByReference(rawurldecode($m[1])),
            (bool) preg_match('#^/api/v1/envelopes/([^/]+)/status$#', $path, $m) => $this->statusById($m[1]),
            (bool) preg_match('#^/api/v1/envelopes/([^/]+)/cancel$#', $path, $m) => $this->cancel($m[1]),
            (bool) preg_match('#^/api/v1/envelopes/([^/]+)/files$#', $path, $m) => self::ok([['fileName' => 'contract.pdf', 'file' => base64_encode(isset($this->envelopes[$m[1]]) && $this->allSigned($m[1]) && ! $this->filesLag ? self::SIGNED : self::ORIGINAL)]]),
            (bool) preg_match('#^/api/v1/documents/([^/]+)/completed/base64$#', $path, $m) => $this->completed($m[1]),
            $path === '/api/v1/invitations/reminders/send' => self::ok(true),
            $path === '/api/v1/webhooks' && $request->getMethod() === 'POST' => self::ok(['id' => 'wh-new', 'webhookUrl' => $json['webhookUrl'] ?? '', 'isDefault' => $json['isDefault'] ?? false]),
            default => new Response(404, [], '{"errorCode":404,"message":"NotFound","data":null}'),
        };
    }

    // ---- test controls ----------------------------------------------------------

    public function sign(string $envelopeId, string $email): void
    {
        foreach ($this->envelopes[$envelopeId]['signatories'] as &$signatory) {
            if ($signatory['email'] === $email) {
                $signatory['status'] = 'SIGNED';
            }
        }
    }

    /** SADQ lists the owner at varying positions, not always first. */
    public function moveOwnerToEnd(string $envelopeId): void
    {
        $signatories = $this->envelopes[$envelopeId]['signatories'];
        $owner = array_shift($signatories);
        $this->envelopes[$envelopeId]['signatories'] = $owner === null ? $signatories : [...$signatories, $owner];
    }

    public function signAll(string $envelopeId): void
    {
        foreach ($this->envelopes[$envelopeId]['signatories'] as &$signatory) {
            $signatory['status'] = 'SIGNED';
        }
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_map(fn (array $c) => $c['path'], $this->calls);
    }

    /** @return mixed the JSON body of the last call to $path */
    public function lastBody(string $path): mixed
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['path'] === $path) {
                return $call['body'];
            }
        }

        return null;
    }

    // ---- endpoints ----------------------------------------------------------------

    private function token(RequestInterface $request, string $body): ResponseInterface
    {
        if ($this->loginOutage) {
            return new Response(500);    // seen live: empty body
        }
        parse_str($body, $form);
        $basic = 'Basic ' . base64_encode('Integrationclient:client-secret');
        $valid = ! $this->rejectLogin
            && $request->getHeaderLine('Authorization') === $basic
            && ($form['grant_type'] ?? null) === 'integration'
            && ($form['password'] ?? null) === self::PASSWORD;

        if (! $valid) {
            // Like the real thing: HTTP 200, an error, and the username echoed back.
            return new Response(200, [], (string) json_encode(['access_token' => null, 'error' => 'invalid_grant', 'errorMessage' => 'Invalid login for ' . self::OWNER]));
        }
        ++$this->tokenCalls;

        return new Response(200, [], (string) json_encode(['access_token' => 'token-' . $this->tokenCalls, 'expires_in' => $this->expiresIn, 'token_type' => 'Bearer', 'error' => null]));
    }

    /** @param array<string, mixed> $body */
    private function initiate(array $body): ResponseInterface
    {
        $id = 'env-' . ++$this->sequence;
        $this->envelopes[$id] = [
            'reference' => (string) ($body['referenceNumber'] ?? "auto-{$this->sequence}"),
            'documentId' => "doc-{$this->sequence}",
            'webhookId' => $body['webhookId'] ?? null,
            'cancelled' => false,
            'signatories' => [['id' => 'owner', 'status' => 'SIGNED', 'fullName' => 'Account Owner', 'signOrder' => 0, 'email' => self::OWNER, 'signingUrl' => null]],
        ];

        return self::ok(['documentId' => "doc-{$this->sequence}", 'envelopeId' => $id, 'destinations' => null, 'referenceNumber' => $this->envelopes[$id]['reference']]);
    }

    /** @param array<string, mixed> $body */
    private function invite(array $body): ResponseInterface
    {
        if ($this->failInvitations !== null) {
            return self::error($this->failInvitations, 'BadRequest');
        }
        $id = (string) ($body['envelopeId'] ?? '');
        foreach ((array) ($body['destinations'] ?? []) as $i => $destination) {
            $this->envelopes[$id]['signatories'][] = [
                'id' => "sig-{$i}",
                'status' => 'PENDING',
                'fullName' => $destination['destinationName'],
                'signOrder' => $destination['signeOrder'],
                'email' => $destination['destinationEmail'],
                'signingUrl' => "https://sign.sadq.test/{$id}/{$i}",
            ];
        }

        return self::ok(true);
    }

    private function statusById(string $id): ResponseInterface
    {
        if (! isset($this->envelopes[$id])) {
            return self::ok(null);
        }

        // By ID lags behind: it still shows the envelope as open after a cancel.
        return self::ok($this->envelopeData($id, $this->forcedStatus ?? ($this->allSigned($id) ? 'Completed' : 'In-progress')));
    }

    private function statusByReference(string $reference): ResponseInterface
    {
        foreach ($this->envelopes as $id => $envelope) {
            if ($envelope['reference'] === $reference) {
                $status = $envelope['cancelled'] ? 'Voided' : ($this->allSigned($id) ? 'Completed' : 'In-progress');

                return self::ok($this->envelopeData($id, $this->forcedStatus ?? $status));
            }
        }

        return self::ok(null);
    }

    private function cancel(string $id): ResponseInterface
    {
        if (! isset($this->envelopes[$id]) || $this->envelopes[$id]['cancelled']) {
            return self::error(9, 'BadRequest');
        }
        $this->envelopes[$id]['cancelled'] = true;

        return self::ok(null);
    }

    private function completed(string $documentId): ResponseInterface
    {
        foreach ($this->envelopes as $id => $envelope) {
            if ($envelope['documentId'] === $documentId) {
                return $this->allSigned($id)
                    ? self::ok(['file' => base64_encode(self::SIGNED), 'fileName' => 'contract.pdf', 'contentType' => 'application/pdf'])   // an object here, a list on /files
                    : self::error(14, "The envelope is not complete, you can't download the file,  File Id = {$documentId} ");
            }
        }

        return self::error(14, 'Not found');
    }

    private function allSigned(string $id): bool
    {
        $signatories = $this->envelopes[$id]['signatories'];

        return count($signatories) > 1 && ! $this->envelopes[$id]['cancelled']
            && array_filter($signatories, fn (array $s) => $s['status'] !== 'SIGNED') === [];
    }

    /** @return array<string, mixed> */
    private function envelopeData(string $id, string $status): array
    {
        $envelope = $this->envelopes[$id];

        return [
            'id' => $id,
            'status' => $status,
            'signatories' => $envelope['signatories'],
            'documents' => [['id' => $envelope['documentId'], 'fileName' => 'contract.pdf', 'isSigned' => $this->allSigned($id)]],
            'referenceNumber' => $envelope['reference'],
        ];
    }

    private static function ok(mixed $data): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['data' => $data, 'errorCode' => 0, 'message' => 'Success', 'stateValidationErrors' => null]));
    }

    private static function error(int $code, string $message): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['data' => null, 'errorCode' => $code, 'message' => $message, 'stateValidationErrors' => null]));
    }
}
