# hamada-emam-tech/esign-sadq

[SADQ](https://sadq.sa) driver for [hamada-emam-tech/esign](https://github.com/hamada-emam-tech/esign-php): Saudi e-signature envelopes, with Nafath identity.

```bash
vendor/bin/esign add sadq          # or: composer require hamada-emam-tech/esign-sadq
```

The core discovers the driver on install. You also need a PSR-18 HTTP client: Guzzle and Symfony HttpClient are picked up automatically.

## Configure

```php
$esign = new Esign([
    'default'   => 'sadq',
    'providers' => [
        'sadq' => [
            'environment'    => 'production',      // or 'sandbox' (the default)
            'account_id'     => getenv('SADQ_ACCOUNT_ID'),
            'account_secret' => getenv('SADQ_ACCOUNT_SECRET'),
            'username'       => getenv('SADQ_USERNAME'),
            'password'       => getenv('SADQ_PASSWORD'),
            'client_secret'  => getenv('SADQ_CLIENT_SECRET'),
            'webhook_id'     => getenv('SADQ_WEBHOOK_ID'),
        ],
    ],
]);
```

| Option | Default | Notes |
|---|---|---|
| `environment` | `sandbox` | `sandbox` → `https://api-sandbox.sadq-sa.com`, `production` → `https://apigw.sadq.sa` |
| `base_url` | from `environment` | Overrides `environment`. |
| `account_id`, `account_secret`, `username`, `password`, `client_secret` | required | Issued by SADQ for the integration account. |
| `client_id` | `Integrationclient` | |
| `webhook_id` | none | **Set it.** Without it, SADQ notifies the account's *default* webhook, which on a shared account may be someone else's server. |
| `webhook_token` | none | The `HeaderToken` you registered. Checked only when `webhook_verification` is `token`. |
| `webhook_header` | any header | Name of the header that carries the token, to check only that one. |
| `webhook_verification` | `none` | `token` refuses webhooks that don't carry `webhook_token` (see [Webhook verification](#webhook-verification)). |
| `invitation_language` | `1` | SADQ's language code for invitation emails. |
| `expires_in_days` | `30` | How long invitations stay open. |
| `http_client`, `request_factory`, `stream_factory` | auto-detected | PSR-18 / PSR-17 objects. |
| `cache` | none | A PSR-16 cache, to share the access token across requests and workers. |

## Send

```php
use HamadaEmamTech\Esign\Envelope\{Envelope, Document, Signer, SignatureField};

$sent = $esign->send(new Envelope(
    documents: [Document::fromPath('contract.pdf')],
    signers: [
        Signer::nafath('1012345678', 'Ahmed Ali', 'ahmed@example.sa', order: 1, fields: [new SignatureField(page: 1, x: 60, y: 700)]),
        Signer::email('Sara', 'sara@example.sa', order: 2),     // no fields: signs by agreeing
    ],
    subject: 'Please sign the agreement',
    metadata: ['reference' => 'contract-42'],
));

$sent->envelopeId;      // store this: it is the only ID you need
$sent->signingUrls;     // ['ahmed@example.sa' => 'https://…/sign/…', …]. Open in a new tab, not an iframe.
```

## Webhook, then status, then download

```php
// POST /webhooks/sadq
try {
    $event = $esign->parseWebhook($request->headers->all(), $request->getContent(), 'sadq');
    dispatch(new SyncSadqEnvelope($event->envelopeId));    // do the rest in a queue
} catch (InvalidWebhook $e) {
    logger()->warning('Bad SADQ webhook', ['error' => $e->getMessage()]);
}
return response('', 200);   // always 200, or SADQ retries

// In the job:
$status = $esign->provider('sadq')->status($envelopeId);
if ($status === EnvelopeStatus::Signed) {
    $pdf = $esign->provider('sadq')->download($envelopeId);
}
```

## Status mapping

| SADQ | `EnvelopeStatus` |
|---|---|
| `In-progress`, nobody you invited has signed | `Sent` |
| `In-progress`, some of your signers have signed | `PartiallySigned` |
| `Completed`, and every signer you invited shows `SIGNED` | `Signed` |
| `Completed`, but not every invited signer signed (seen live on an envelope with no signers) | `Sent` / `PartiallySigned` |
| `Voided`, `Cancelled` | `Cancelled` |
| `Rejected`, `Declined` | `Declined` |
| `Expired` | `Expired` |
| anything else | throws `ProviderError`. Please report it. |

`In-progress` (statusValue 1), `Voided` (2) and `Completed` (5) were seen on the sandbox across 192 envelopes. `Rejected`, `Declined` and `Expired` follow SADQ's naming and haven't been seen live yet. Signatory statuses seen: `PENDING` and `SIGNED`.

## Webhook verification

SADQ's webhook body is `{"RequestId": "<envelope id>", "Status": 1|2|3}`. SADQ doesn't sign it, so by default the driver accepts any webhook (`webhook_verification: none`). That's safe because the driver never trusts the body: `parseWebhook()` only extracts the envelope ID, and `status()` asks SADQ's API. A fake call can at most cause one status lookup and can never mark an envelope signed. Rate-limit the route.

You can register a webhook with a `HeaderToken` (`registerWebhook($url, $token)`). SADQ's delivery log shows those deliveries succeeding, but it doesn't show whether the token is forwarded, or in which header. Once you've confirmed it on a real delivery, set `'webhook_verification' => 'token'` and `webhook_token` (plus `webhook_header` to check one header only). In that mode the driver fails closed.

## SADQ-only operations

```php
/** @var \HamadaEmamTech\Esign\Sadq\SadqProvider $sadq */
$sadq = $esign->provider('sadq');

$sadq->remind($envelopeId, 'sara@example.sa');               // email the link again
$sadq->extend($envelopeId, new DateTimeImmutable('+14 days'));
$id = $sadq->registerWebhook('https://app.example.sa/webhooks/sadq', $token);   // never the default unless you ask
$sadq->webhooks();
$sadq->deleteWebhook($id);
$sadq->resendWebhook($envelopeId);                            // ask SADQ to call your webhook again
```

## What this driver handles for you

Checked against the SADQ sandbox, October 2026:

1. **Errors come back as HTTP 200.** A non-zero `errorCode` always throws `ProviderError`, carrying the HTTP status and SADQ's code, and never a credential.
2. **The account owner is listed as a signer who already signed**, at a varying position in the list. That entry is ignored, so a new envelope is `Sent`, not `PartiallySigned`. `Completed` only counts as `Signed` when every signer you invited has signed.
3. **Cancelling is asynchronous.** `status()` keeps saying `Sent` for a few minutes after `cancel()` (under 5 minutes on the sandbox), then `Cancelled`. If your app needs to show "cancelled" right away, record it on your side.
4. **Status by reference shows changes before status by ID.** Every envelope gets a unique reference number, and status is read by reference.
5. **`/envelopes/{id}/files` returns the unsigned original while an envelope is open**, and the signed PDF once it is complete (a PAdES signature: `adbe.pkcs7.detached` or `ETSI.CAdES.detached`, both seen). Verified on completed sandbox envelopes. `download()` runs only when the status is `Signed`, and returns a file only if it **contains a digital signature**. If `/files` still has the original, it falls back to `/documents/{id}/completed/base64`, and otherwise throws.
6. **An unknown envelope answers "Success" with no data.** That becomes a `ProviderError` with HTTP 404. A single null for an existing envelope (also seen) is retried once first.
7. **Sending invitations again duplicates the signer.** Use `remind()`.
8. **One document per envelope.** Adding files to an envelope fails on the sandbox. Merge the PDFs first.
9. **Passwords with `&`, `#` or `+`** are form-encoded correctly. The access token (about 27 hours on the sandbox) is cached and refreshed 5 minutes early, with one retry on a 401.
10. **Identity:** `email` and `nafath` (with a 10-digit national ID or Iqama). SMS OTP throws until SADQ's code for it is confirmed.

See [getting started with SADQ](https://github.com/hamada-emam-tech/esign-php/blob/main/docs/getting-started-with-sadq.md) for credentials, webhook setup and going to production.

## Testing without real signers

Use the core's `fake` provider in your app's tests:

```php
$esign = new Esign(['default' => 'fake']);
$fake = $esign->provider();
$sent = $esign->send($envelope);
$fake->sign($sent->envelopeId);
```

The package's own tests run against `tests/Support/FakeSadq.php`, a PSR-18 fake that reproduces every behaviour listed above. An opt-in suite runs against the real sandbox: `vendor/bin/phpunit --group live`.
