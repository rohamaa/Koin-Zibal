# Koin — Zibal

<p align="center">
  <a href="https://rohamaa.github.io/Koin-Core/">Docs site</a> ·
  <a href="https://github.com/rohamaa/Koin-Core/blob/docs/index.md">Docs on GitHub</a>
</p>

The **Zibal** gateway driver for the Koin package — built on Zibal's IPG REST v1 (`gateway.zibal.ir`).

### Install

```bash
composer require rohama/koin-zibal
```

### Features

- Payment request — returns trackId + StartPay redirect URL, lazy mode supported
- Verify
- Inquiry
- Callback parsing

> Official test account: merchant key `zibal` (no sandbox flag needed). No refund endpoint exists in the official API.

### Configuration

| Field | Description |
| --- | --- |
| `apiKey` | Merchant key (`merchant`) |
| `lazy` | LazyPay mode (JSON POST callback) |

### Usage

```php
use Rohama\Koin\Zibal\ZibalGateway;
use Rohama\Koin\Support\PaymentStatus;

$gateway = new ZibalGateway('merchant-key', 'https://example.com/callback');

$payment = $gateway->request(150000, 'ORDER-1', 'Subscription purchase');

if ($payment->isSuccessful()) {
    header('Location: ' . $payment->redirectUrl);
    exit;
}

// In the callback page — parseCallback runs verify() before reporting success
$callback = $gateway->parseCallback($_GET, $_POST);
if ($callback->status === PaymentStatus::SUCCESS) {
    // Verified
}
```

> `refund()` (in gateways that offer Refund) is available only when the developer-only 5th constructor parameter (`refundable`) is enabled.

Full documentation: <https://rohamaa.github.io/Koin-Core/>