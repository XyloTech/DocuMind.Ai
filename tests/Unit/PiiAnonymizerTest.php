<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Privacy\PiiAnonymizer;
use Tests\TestCase;

class PiiAnonymizerTest extends TestCase
{
    private PiiAnonymizer $anonymizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anonymizer = new PiiAnonymizer;
    }

    public function test_email_addresses_are_removed_from_any_text(): void
    {
        $clean = $this->anonymizer->anonymize('Write to ada.lovelace+work@example.com or sales@example.co.uk today.');

        $this->assertStringNotContainsString('example.com', $clean);
        $this->assertStringContainsString('[EMAIL]', $clean);
    }

    public function test_the_account_holders_own_name_and_email_are_removed_precisely(): void
    {
        $user = new User(['name' => 'Grace Hopper', 'email' => 'grace@navy.mil']);

        $clean = $this->anonymizer->anonymize(
            'Grace Hopper asked about GRACE HOPPER reports; contact grace@navy.mil. Hopperhead stays.',
            $user,
        );

        $this->assertStringNotContainsString('grace@navy.mil', $clean);
        $this->assertStringNotContainsString('Grace Hopper', $clean);
        $this->assertStringNotContainsString('GRACE HOPPER', $clean);
        $this->assertStringContainsString('Hopperhead', $clean, 'word boundaries must not clip inside another word');
    }

    public function test_credentials_and_api_keys_are_redacted(): void
    {
        $clean = $this->anonymizer->anonymize(
            'password: hunter2 and api_key=sk-abc123def456ghi789 and Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abcdefgh12345678 and key sk-proj-abcdefghijklmnopqrstuv',
        );

        $this->assertStringNotContainsString('hunter2', $clean);
        $this->assertStringNotContainsString('sk-abc123def456ghi789', $clean);
        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $clean);
        $this->assertStringContainsString('[SECRET]', $clean);
    }

    public function test_credentials_embedded_in_a_url_are_redacted(): void
    {
        $clean = $this->anonymizer->anonymize('Fetch https://admin:Sup3rS3cret@intranet.local/api first.');

        $this->assertStringNotContainsString('Sup3rS3cret', $clean);
        $this->assertStringContainsString('https://[SECRET]@intranet.local/api', $clean);
    }

    public function test_card_ssn_phone_and_ip_values_are_replaced(): void
    {
        $clean = $this->anonymizer->anonymize(
            'Card 4111 1111 1111 1111, SSN 123-45-6789, call +44 20 7946 0958 from 192.168.10.4.',
        );

        $this->assertStringNotContainsString('4111', $clean);
        $this->assertStringNotContainsString('123-45-6789', $clean);
        $this->assertStringNotContainsString('44 20 7946 0958', $clean);
        $this->assertStringNotContainsString('192.168.10.4', $clean);
        $this->assertStringContainsString('[CARD]', $clean);
        $this->assertStringContainsString('[SSN]', $clean);
        $this->assertStringContainsString('[PHONE]', $clean);
        $this->assertStringContainsString('[IP]', $clean);
    }

    public function test_ordinary_document_text_is_left_intact(): void
    {
        $text = 'Invoices are due within 30 days. See section 4.2 and table 12. Renewal: 2026-09-25.';

        $this->assertSame($text, $this->anonymizer->anonymize($text));
    }

    public function test_empty_input_is_returned_unchanged(): void
    {
        $this->assertSame('', $this->anonymizer->anonymize(''));
    }
}
