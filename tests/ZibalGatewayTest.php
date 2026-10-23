<?php

use Rohama\Koin\Exception\InvalidRequestException;
use Rohama\Koin\Exception\VerificationFailedException;
use Rohama\Koin\Zibal\ZibalGateway;
use Rohama\Translator\Translator;

final class ZibalGatewayTest extends TestCase
{
    public function testParseCallbackThrowsWithoutATrackId(): void
    {
        $this->expectException(InvalidRequestException::class);
        (new ZibalGateway('k'))->parseCallback([], []);
    }

    public function testParseCallbackMapsSuccess1ToSuccess(): void
    {
        $gateway = new ZibalGateway('k');
        $gateway->setClient($this->mockHttp([[200, ['result' => 100, 'amount' => 10000]]]));

        $result = $gateway->parseCallback(['trackId' => '12', 'orderId' => 'o1', 'success' => 1], []);

        $this->assertSame('success', $result->status);
        $this->assertSame('12', $result->transactionId);
        $this->assertSame('o1', $result->orderId);
    }

    public function testParseCallbackMapsAnUnpaidVerifyToFailed(): void
    {
        $gateway = new ZibalGateway('k');
        $gateway->setClient($this->mockHttp([[200, ['result' => 202]]]));

        $result = $gateway->parseCallback(['trackId' => '12', 'orderId' => 'o1', 'success' => 1], []);

        $this->assertSame('failed', $result->status);
    }

    public function testParseCallbackMapsSuccess0ToFailed(): void
    {
        $gateway = new ZibalGateway('k');
        $result = $gateway->parseCallback(['trackId' => 't1', 'orderId' => 'o1', 'success' => 0], []);

        $this->assertSame('failed', $result->status);
    }

    public function testVerifyMapsResult202ToFailed(): void
    {
        $gateway = new ZibalGateway('k');
        $gateway->setClient($this->mockHttp([[200, ['result' => 202]]]));

        $result = $gateway->verify('t1');

        $this->assertSame('failed', $result->status);
    }

    public function testVerifyMapsANotFoundTrackIdToFailed(): void
    {
        $gateway = new ZibalGateway('k');
        $gateway->setClient($this->mockHttp([[200, ['result' => 203]]]));

        $result = $gateway->verify('t1');

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('not found', $result->message);
    }

    public function testVerifyReturnsSuccessWithConvertedAmount(): void
    {
        $gateway = new ZibalGateway('k');
        $gateway->setClient($this->mockHttp([
            [200, ['result' => 100, 'amount' => 10000, 'refNumber' => 'ref1']],
        ]));

        $result = $gateway->verify('t1', amount: 1000);

        $this->assertSame('success', $result->status);
        $this->assertSame('ref1', $result->referenceId);
        $this->assertSame(1000, $result->amount);
    }

    public function testVerifyThrowsForAnUnknownResultCode(): void
    {
        $gateway = new ZibalGateway('k');
        $gateway->setClient($this->mockHttp([[200, ['result' => 102]]]));

        $this->expectException(VerificationFailedException::class);
        $this->expectExceptionMessage('result 102');
        $gateway->verify('t1');
    }

    public function testConfigFieldsListsTheZibalInputs(): void
    {
        $keys = array_map(fn ($f) => $f['key'], ZibalGateway::configFields());

        $this->assertSame(['apiKey', 'lazy'], $keys);
    }

    public function testLangFilesCoverTheZibalName(): void
    {
        $this->assertSame('Zibal', Translator::trans('Koin-zibal:driver.name'));
    }
}
