<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace Tests\Feature\EDocument;

use DOMDocument;
use DOMXPath;
use Tests\TestCase;
use Tests\MockAccountData;
use App\Models\ClientContact;
use App\Models\QuoteInvitation;
use App\Services\EDocument\Standards\OrderXDocument;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class OrderXSellerBuyerContactTest extends TestCase
{
    use MockAccountData;
    use DatabaseTransactions;

    private const SELLER_EMAIL = "//*[local-name()='SellerTradeParty']/*[local-name()='DefinedTradeContact']/*[local-name()='EmailURIUniversalCommunication']/*[local-name()='URIID']";

    private const BUYER_NAME = "//*[local-name()='BuyerTradeParty']/*[local-name()='DefinedTradeContact']/*[local-name()='PersonName']";

    private const BUYER_EMAIL = "//*[local-name()='BuyerTradeParty']/*[local-name()='DefinedTradeContact']/*[local-name()='EmailURIUniversalCommunication']/*[local-name()='URIID']";

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTestData();
    }

    public function testSellerEmailDefaultsToUserEmail(): void
    {
        $this->setCompanyEmailSettings('company@example.com', null);

        $this->assertSame($this->quote->user->email, $this->singleValue($this->buildXml(), self::SELLER_EMAIL));
    }

    public function testSellerEmailUsesCompanyEmailWhenConfigured(): void
    {
        $this->setCompanyEmailSettings('company@example.com', 'company');

        $this->assertSame('company@example.com', $this->singleValue($this->buildXml(), self::SELLER_EMAIL));
    }

    public function testBuyerContactUsesSelectedContact(): void
    {
        $selected = ClientContact::factory()->create([
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'company_id' => $this->company->id,
            'first_name' => 'Selected',
            'last_name' => 'Person',
            'email' => 'selected@example.com',
        ]);

        $invitation = QuoteInvitation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'client_contact_id' => $selected->id,
            'quote_id' => $this->quote->id,
        ]);

        $this->quote->setRelation('invitations', collect([$invitation]));

        $xml = $this->buildXml();

        $this->assertSame('Selected Person', $this->singleValue($xml, self::BUYER_NAME));
        $this->assertSame('selected@example.com', $this->singleValue($xml, self::BUYER_EMAIL));
    }

    private function setCompanyEmailSettings(string $email, ?string $seller_email): void
    {
        $settings = $this->company->settings;
        $settings->email = $email;

        if ($seller_email) {
            $settings->e_invoice_seller_email = $seller_email;
        }

        $this->company->settings = $settings;
        $this->company->save();

        $this->client->refresh();
        $this->quote->setRelation('client', $this->client);
        $this->quote->setRelation('company', $this->company);
    }

    private function buildXml(): DOMXPath
    {
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML((new OrderXDocument($this->quote))->run()->getXml()));

        return new DOMXPath($dom);
    }

    private function singleValue(DOMXPath $xpath, string $expression): string
    {
        $nodes = $xpath->query($expression);
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, "Expected exactly one node for XPath: {$expression}");

        return trim($nodes->item(0)?->textContent ?? '');
    }
}
