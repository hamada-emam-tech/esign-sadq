<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Sadq\Tests\Live;

use HamadaEmamTech\Esign\Envelope\Document;
use HamadaEmamTech\Esign\Envelope\Envelope;
use HamadaEmamTech\Esign\Envelope\EnvelopeStatus;
use HamadaEmamTech\Esign\Envelope\SignatureField;
use HamadaEmamTech\Esign\Envelope\Signer;
use HamadaEmamTech\Esign\Esign;
use HamadaEmamTech\Esign\Exceptions\EsignException;
use HamadaEmamTech\Esign\Sadq\SadqFactory;
use HamadaEmamTech\Esign\Sadq\SadqProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the real SADQ sandbox. Opt-in: vendor/bin/phpunit --group live
 *
 * Needs .env.sandbox in the repo root (gitignored) with SADQ_ACCOUNT_ID, SADQ_ACCOUNT_SECRET,
 * SADQ_USERNAME, SADQ_PASSWORD, SADQ_CLIENT_SECRET, SADQ_TEST_EMAIL and SADQ_WEBHOOK_ID.
 * Invitations go to SADQ_TEST_EMAIL. To check the signed download, set SADQ_SIGNED_ENVELOPE to any
 * completed envelope on the account (POST /api/v1/reports/requests lists them).
 */
#[Group('live')]
final class SadqSandboxTest extends TestCase
{
    /** @var array<string, string> */
    private static array $env = [];

    public static function setUpBeforeClass(): void
    {
        $file = dirname(__DIR__, 4) . '/.env.sandbox';
        if (is_file($file)) {
            foreach ((array) file($file) as $line) {
                if (preg_match('/^([A-Z_]+)=(.*)$/', trim((string) $line), $m)) {
                    self::$env[$m[1]] = trim($m[2], "'\"");
                }
            }
        }
    }

    private function provider(): SadqProvider
    {
        foreach (['SADQ_ACCOUNT_ID', 'SADQ_ACCOUNT_SECRET', 'SADQ_USERNAME', 'SADQ_PASSWORD', 'SADQ_CLIENT_SECRET', 'SADQ_TEST_EMAIL', 'SADQ_WEBHOOK_ID'] as $key) {
            if ((self::$env[$key] ?? '') === '') {
                self::markTestSkipped("{$key} is not set in .env.sandbox");
            }
        }

        $esign = new Esign(['default' => 'sadq', 'providers' => ['sadq' => [
            'environment' => 'sandbox',
            'account_id' => self::$env['SADQ_ACCOUNT_ID'],
            'account_secret' => self::$env['SADQ_ACCOUNT_SECRET'],
            'username' => self::$env['SADQ_USERNAME'],
            'password' => self::$env['SADQ_PASSWORD'],
            'client_secret' => self::$env['SADQ_CLIENT_SECRET'],
            'webhook_id' => self::$env['SADQ_WEBHOOK_ID'],
            'expires_in_days' => 2,
        ]]]);
        $esign->extend('sadq', SadqFactory::class);
        $provider = $esign->provider();
        self::assertInstanceOf(SadqProvider::class, $provider);

        return $provider;
    }

    #[Test]
    public function a_full_send_status_remind_cancel_cycle(): void
    {
        $sadq = $this->provider();
        $email = self::$env['SADQ_TEST_EMAIL'];

        $sent = $sadq->send(new Envelope(
            [new Document('esign-live-test.pdf', self::pdf())],
            [
                Signer::email('Live Test One', $email, 1, [new SignatureField(1, 60, 700)]),
                Signer::email('Live Test Two', $email, 2),
            ],
            subject: 'esign package live test',
            message: 'Automated sandbox test from hamada-emam-tech/esign-sadq. Safe to ignore.',
            metadata: ['reference' => 'esign-live'],
        ));

        self::assertNotSame('', $sent->envelopeId);
        self::assertArrayHasKey($email, $sent->signingUrls);
        self::assertStringStartsWith('https://', $sent->signingUrls[$email]);
        fwrite(STDERR, "\n  live envelope {$sent->envelopeId}: {$sent->signingUrls[$email]}\n");

        self::assertSame(EnvelopeStatus::Sent, $sadq->status($sent->envelopeId), 'the account owner must not count as a signer');

        $sadq->remind($sent->envelopeId, $email);

        try {
            $sadq->download($sent->envelopeId);
            self::fail('Download must refuse an unsigned envelope');
        } catch (EsignException $e) {
            self::assertStringContainsString('not signed', $e->getMessage());
        }

        $sadq->cancel($sent->envelopeId);

        // SADQ applies the cancel asynchronously (a few minutes on the sandbox).
        $deadline = time() + 420;
        do {
            $status = $sadq->status($sent->envelopeId);
            self::assertContains($status, [EnvelopeStatus::Sent, EnvelopeStatus::Cancelled]);
            if ($status === EnvelopeStatus::Cancelled) {
                break;
            }
            sleep(20);
        } while (time() < $deadline);
        self::assertSame(EnvelopeStatus::Cancelled, $status, 'SADQ did not show the cancel within 7 minutes');
    }

    #[Test]
    public function an_envelope_signed_by_hand_downloads_as_a_pdf(): void
    {
        $id = getenv('SADQ_SIGNED_ENVELOPE') ?: (self::$env['SADQ_SIGNED_ENVELOPE'] ?? '');
        if ($id === '') {
            self::markTestSkipped('Set SADQ_SIGNED_ENVELOPE to a completed envelope ID.');
        }
        $sadq = $this->provider();

        self::assertSame(EnvelopeStatus::Signed, $sadq->status($id));
        $pdf = $sadq->download($id);
        self::assertStringStartsWith('%PDF', $pdf);
        self::assertStringContainsString('/ByteRange', $pdf, 'the download must carry a digital signature');
    }

    private static function pdf(): string
    {
        $stream = 'BT /F1 18 Tf 72 760 Td (hamada-emam-tech/esign-sadq live test) Tj ET';
        $objects = [
            '<</Type/Catalog/Pages 2 0 R>>',
            '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            '<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>',
            '<</Length ' . strlen($stream) . ">>stream\n{$stream}\nendstream",
            '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . 'trailer<</Size ' . (count($objects) + 1) . "/Root 1 0 R>>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
