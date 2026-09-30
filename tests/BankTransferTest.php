<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Tests;

use chillerlan\QRCode\QRCode;
use Nordwerk\PaymentBundle\BankTransfer\GiroCode;
use Nordwerk\PaymentBundle\BankTransfer\Iban;
use PHPUnit\Framework\TestCase;

final class BankTransferTest extends TestCase
{
    public function testIbanChecksumAndNormalization(): void
    {
        $this->assertTrue(Iban::valid('de89 3704 0044 0532 0130 00'));
        $this->assertTrue(Iban::valid('GB82 WEST 1234 5698 7654 32'));

        foreach (['DE88370400440532013000', '', 'DE00', 'DE89!370400440532013000', '89370400440532013000'] as $invalid) {
            $this->assertFalse(Iban::valid($invalid));
        }
    }

    public function testExactEpcPayload(): void
    {
        $this->assertSame("BCD\n002\n1\nSCT\nCOBADEFFXXX\nMüller\nDE89370400440532013000\nEUR12.34\n\n\nMS-000001", GiroCode::payload('Müller', 'de89 3704 0044 0532 0130 00', 1234, 'MS-000001', 'COBADEFFXXX'));
        $this->assertStringContainsString("SCT\n\n", GiroCode::payload('Name', 'DE89370400440532013000', 1, 'Ref'));
        $this->assertStringContainsString('EUR0.01', GiroCode::payload('Name', 'DE89370400440532013000', 1, 'Ref'));
        $this->assertNotEmpty(GiroCode::payload(str_repeat('a', 70), 'DE89370400440532013000', 99999999999, str_repeat('r', 140)));
    }

    public function testInvalidPayloadIsRejected(): void
    {
        foreach ([[str_repeat('a', 71), 'Ref', 100], ['Name', str_repeat('r', 141), 100], ["Name\nInjected", 'Ref', 100], ['Name', 'Ref', 0], [str_repeat('ü', 70), str_repeat('ä', 140), 100]] as [$name, $reference, $amount]) {
            try {
                GiroCode::payload($name, 'DE89370400440532013000', $amount, $reference);
                $this->fail('Invalid EPC data was accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }

    public function testQrIsALocalPngAndOnlyForEuroBankTransfers(): void
    {
        $bank = ['accountHolder' => 'Müller', 'iban' => 'DE89370400440532013000'];
        $png = GiroCode::image($bank, 1234, 'MS-000001');
        $this->assertNotNull($png);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        $this->assertSame(GiroCode::payload('Müller', $bank['iban'], 1234, 'MS-000001'), (new QRCode())->readFromBlob($png)->data);
        $this->assertNull(GiroCode::image($bank, 1234, 'MS-000001', 'USD'));
        $this->assertNull(GiroCode::image($bank, 1234, 'MS-000001', 'EUR', 'stripe'));
        $this->assertNull(GiroCode::image([], 1234, 'MS-000001'));
    }
}
