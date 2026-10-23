<?php

namespace Rohama\Koin\Zibal;

use GuzzleHttp\Client;
use Rohama\Koin\Support\Currency;
use Rohama\Koin\Support\PaymentStatus;
use Rohama\Koin\Exception\InvalidRequestException;
use Rohama\Koin\Exception\PaymentFailedException;
use Rohama\Koin\Exception\VerificationFailedException;
use Rohama\Koin\InquiryGateway;
use Rohama\Koin\PaymentGateway;
use Rohama\Koin\Requests\Callback;
use Rohama\Koin\Responses\PaymentResponse;
use Rohama\Koin\Responses\VerificationResponse;
use Rohama\Koin\Traits\IsPayment;
use Rohama\Translator\Translator;

/**
 * Zibal IPG gateway — REST API v1, authenticated with the merchant ID.
 */
class ZibalGateway implements PaymentGateway, InquiryGateway
{
    use IsPayment { __construct as protected paymentConstruct; }

    public const name = 'zibal';

    private const MIN_AMOUNT = 1000;

    private const RESULT_REQUEST_SUCCESS = 100;
    private const RESULT_VERIFY_SUCCESS = 100;
    private const RESULT_ALREADY_VERIFIED = 201;
    private const RESULT_UNPAID = 202;
    private const RESULT_NOT_FOUND = 203;

    public function __construct(string $apiKey, ?string $callbackUrl = null, bool $sandbox = false, array $extra = [], bool $refundable = false)
    {
        $this->paymentConstruct($apiKey, $callbackUrl, $sandbox, $extra, $refundable);

        Translator::register('Koin-' . self::name, dirname(__DIR__) . '/lang');
        $this->client = new Client([
            'base_uri' => 'https://gateway.zibal.ir',
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
        ]);
    }

    public static function configFields(): array
    {
        $prefix = 'Koin-' . self::name;

        return [
            [
                'key' => 'apiKey',
                'name' => Translator::trans($prefix . ':fields.apiKey'),
                'desc' => Translator::trans($prefix . ':fields.apiKey_help'),
                'type' => 'text',
                'required' => true,
                'validation' => ['required', 'string'],
                'regex' => '/^.+$/',
            ],
            [
                'key' => 'lazy',
                'name' => Translator::trans($prefix . ':fields.lazy'),
                'desc' => Translator::trans($prefix . ':fields.lazy_help'),
                'type' => 'checkbox',
                'required' => false,
                'validation' => ['boolean'],
                'regex' => null,
            ],
        ];
    }

    public function request(
        int $amount,
        string $orderId = '',
        ?string $callbackUrl = null,
        string $description = '',
        ?string $mobile = null,
        ?string $email = null,
        ?string $currency = null,
        array $metadata = [],
        array $extra = [],
    ): PaymentResponse {
        if ($amount <= 0) {
            throw new InvalidRequestException('Amount must be greater than zero.', gateway: self::name);
        }

        if ($callbackUrl === null && $this->callbackUrl === null) {
            throw new InvalidRequestException('Zibal requires a callback URL.', gateway: self::name);
        }

        $rial = Currency::toRial($currency ?? Currency::TOMAN, $amount);

        if ($rial < self::MIN_AMOUNT) {
            throw new InvalidRequestException('Zibal requires an amount of at least 1,000 Rial.', gateway: self::name);
        }

        $endpoint = (bool) ($this->extra['lazy'] ?? false) ? '/request/lazy' : '/v1/request';

        $response = $this->client()->post($endpoint, [
            'json' => array_filter(array_merge([
                'merchant' => $this->apiKey,
                'amount' => $rial,
                'callbackUrl' => $callbackUrl ?? $this->callbackUrl,
                'description' => $description,
                'orderId' => $orderId,
                'mobile' => $mobile,
            ], $extra), fn ($value) => $value !== null && $value !== ''),
        ]);
        $data = json_decode($response->getBody()->getContents(), true);

        $result = (int) ($data['result'] ?? -1);
        $trackId = isset($data['trackId']) ? (string) $data['trackId'] : '';

        if ($result !== self::RESULT_REQUEST_SUCCESS || $trackId === '') {
            throw new PaymentFailedException($this->requestError($data), $result, raw: $data, gateway: self::name);
        }

        return new PaymentResponse(
            $data,
            $response,
            PaymentStatus::PENDING,
            $trackId,
            'https://gateway.zibal.ir/start/' . $trackId,
            $orderId,
            isset($data['message']) ? (string) $data['message'] : null,
        );
    }

    public function verify(
        string $transactionId,
        string $orderId = '',
        ?int $amount = null,
        ?string $currency = null,
        array $extra = [],
    ): VerificationResponse {
        $endpoint = (bool) ($this->extra['lazy'] ?? false) ? '/verify' : '/v1/verify';

        $response = $this->client()->post($endpoint, [
            'json' => array_filter(array_merge([
                'merchant' => $this->apiKey,
                'trackId' => (int) $transactionId,
            ], $extra), fn ($value) => $value !== null && $value !== ''),
        ]);
        $data = json_decode($response->getBody()->getContents(), true);

        $result = (int) ($data['result'] ?? -1);

        if ($result === self::RESULT_UNPAID || $result === self::RESULT_NOT_FOUND) {
            return new VerificationResponse(
                $data,
                $response,
                PaymentStatus::FAILED,
                $transactionId,
                null,
                null,
                null,
                $result === self::RESULT_UNPAID ? 'Unpaid transaction.' : 'Transaction not found.',
            );
        }

        if ($result !== self::RESULT_VERIFY_SUCCESS && $result !== self::RESULT_ALREADY_VERIFIED) {
            throw new VerificationFailedException($this->requestError($data), $result, raw: $data, gateway: self::name);
        }

        if ($amount !== null) {
            $expected = Currency::toRial($currency ?? Currency::TOMAN, $amount);
            $actual = isset($data['amount']) ? (int) $data['amount'] : null;

            if ($actual === null || $actual !== $expected) {
                throw new VerificationFailedException(
                    sprintf('Zibal verified amount mismatch: expected %d, got %s.', $expected, $actual === null ? 'null' : (string) $actual),
                    0,
                    raw: ['expected' => $expected, 'actual' => $actual],
                    gateway: self::name,
                );
            }
        }

        return new VerificationResponse(
            $data,
            $response,
            PaymentStatus::SUCCESS,
            $transactionId,
            isset($data['refNumber']) ? (string) $data['refNumber'] : null,
            isset($data['amount']) ? Currency::fromRial($currency ?? Currency::TOMAN, (int) $data['amount']) : null,
            isset($data['cardNumber']) ? (string) $data['cardNumber'] : null,
            isset($data['message']) ? (string) $data['message'] : null,
        );
    }

    public function parseCallback(array $query, array $body): Callback
    {
        $raw = array_merge($query, $body);
        $trackId = isset($raw['trackId']) ? (string) $raw['trackId'] : '';
        $orderId = isset($raw['orderId']) ? (string) $raw['orderId'] : '';

        if ($trackId === '') {
            throw new InvalidRequestException('Zibal invalid Callback payload.', gateway: self::name);
        }

        if ((int) ($raw['success'] ?? -1) !== 1) {
            return new Callback(PaymentStatus::FAILED, $trackId, $orderId, $raw);
        }

        $verified = $this->verify($trackId, $orderId);

        return new Callback($verified->status, $trackId, $orderId, $raw);
    }

    public function inquiry(
        string $transactionId,
        string $orderId = '',
        ?int $amount = null,
        ?string $currency = null,
        array $extra = [],
    ): VerificationResponse {
        $response = $this->client()->post('/v1/inquiry', [
            'json' => array_filter(array_merge([
                'merchant' => $this->apiKey,
                'trackId' => (int) $transactionId,
            ], $extra), fn ($value) => $value !== null && $value !== ''),
        ]);
        $data = json_decode($response->getBody()->getContents(), true);

        $result = (int) ($data['result'] ?? -1);

        if ($result !== self::RESULT_VERIFY_SUCCESS) {
            throw new VerificationFailedException($this->requestError($data), $result, raw: $data, gateway: self::name);
        }

        $status = (int) ($data['status'] ?? -1);

        if ($status === 1) {
            return new VerificationResponse(
                $data,
                $response,
                PaymentStatus::SUCCESS,
                $transactionId,
                isset($data['refNumber']) ? (string) $data['refNumber'] : null,
                isset($data['amount']) ? Currency::fromRial($currency ?? Currency::TOMAN, (int) $data['amount']) : null,
                isset($data['cardNumber']) ? (string) $data['cardNumber'] : null,
                isset($data['message']) ? (string) $data['message'] : null,
            );
        }

        return new VerificationResponse(
            $data,
            $response,
            PaymentStatus::FAILED,
            $transactionId,
            null,
            null,
            null,
            'Unpaid or unverified transaction.',
        );
    }

    /**
     * Builds an error message from a Zibal failure payload.
     */
    private function requestError(array $data): string
    {
        $result = (int) ($data['result'] ?? -1);
        $message = isset($data['message']) ? (string) $data['message'] : 'Unknown error';
        return sprintf('Zibal error (result %d): %s', $result, $message);
    }
}
