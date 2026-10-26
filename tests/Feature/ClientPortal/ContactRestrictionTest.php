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

namespace Tests\Feature\ClientPortal;

use App\Livewire\InvoicesTable;
use App\Livewire\PaymentsTable;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceInvitation;
use App\Models\Payment;
use App\Models\User;
use App\Services\Client\Statement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Client setting `client_portal_restrict_to_contact`: contacts only see the
 * entities they are an invitee of.
 */
class ContactRestrictionTest extends TestCase
{
    use DatabaseTransactions;

    private Account $account;

    private Company $company;

    private Client $client;

    private ClientContact $contact_a;

    private ClientContact $contact_b;

    private Invoice $invoice_a;

    private Invoice $invoice_b;

    private Payment $payment_a;

    private Payment $payment_b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->account = Account::factory()->create();

        $user = User::factory()->create([
            'account_id' => $this->account->id,
            'email' => uniqid('testuser') . '@gmail.com',
        ]);

        $this->company = Company::factory()->create(['account_id' => $this->account->id]);
        $this->company->settings->language_id = '1';
        $this->company->save();

        $this->client = Client::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
        ]);

        $this->contact_a = ClientContact::factory()->create([
            'user_id' => $user->id,
            'client_id' => $this->client->id,
            'company_id' => $this->company->id,
            'is_primary' => true,
        ]);

        $this->contact_b = ClientContact::factory()->create([
            'user_id' => $user->id,
            'client_id' => $this->client->id,
            'company_id' => $this->company->id,
        ]);

        $this->invoice_a = $this->invoiceFor($user, $this->contact_a, 'restrict-inv-a');
        $this->invoice_b = $this->invoiceFor($user, $this->contact_b, 'restrict-inv-b');

        $this->payment_a = $this->paymentFor($user, $this->invoice_a, 'restrict-pay-a');
        $this->payment_b = $this->paymentFor($user, $this->invoice_b, 'restrict-pay-b');
    }

    protected function tearDown(): void
    {
        $this->account->delete();

        parent::tearDown();
    }

    private function invoiceFor(User $user, ClientContact $contact, string $number): Invoice
    {
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'number' => $number,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addMonth()->format('Y-m-d'),
            'status_id' => Invoice::STATUS_SENT,
            'is_deleted' => false,
        ]);

        InvoiceInvitation::factory()->create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'client_contact_id' => $contact->id,
            'invoice_id' => $invoice->id,
        ]);

        return $invoice;
    }

    private function paymentFor(User $user, Invoice $invoice, string $number): Payment
    {
        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'number' => $number,
            'amount' => 10,
            'applied' => 10,
            'status_id' => Payment::STATUS_COMPLETED,
            'is_deleted' => false,
            'date' => now()->format('Y-m-d'),
        ]);

        $payment->invoices()->attach($invoice->id, ['amount' => 10]);

        return $payment;
    }

    private function restrict(bool $restrict): void
    {
        $settings = $this->client->settings;
        $settings->client_portal_restrict_to_contact = $restrict;
        $this->client->settings = $settings;
        $this->client->save();

        $this->contact_a->unsetRelation('client');
        $this->contact_b->unsetRelation('client');
    }

    public function testSettingIsOffByDefault(): void
    {
        $this->assertFalse($this->contact_a->portalRestricted());
        $this->assertTrue($this->invoice_b->isVisibleToContact($this->contact_a));
    }

    public function testRestrictedContactOnlySeesOwnInvoices(): void
    {
        $this->restrict(true);

        $this->actingAs($this->contact_a, 'contact');

        Livewire::test(InvoicesTable::class, ['company_id' => $this->company->id, 'db' => $this->company->db])
            ->assertSee($this->invoice_a->number)
            ->assertDontSee($this->invoice_b->number);
    }

    public function testUnrestrictedContactSeesAllInvoices(): void
    {
        $this->restrict(false);

        $this->actingAs($this->contact_a, 'contact');

        Livewire::test(InvoicesTable::class, ['company_id' => $this->company->id, 'db' => $this->company->db])
            ->assertSee($this->invoice_a->number)
            ->assertSee($this->invoice_b->number);
    }

    public function testRestrictedContactOnlySeesPaymentsOfOwnInvoices(): void
    {
        $this->restrict(true);

        $this->actingAs($this->contact_a, 'contact');

        Livewire::test(PaymentsTable::class, ['company_id' => $this->company->id, 'db' => $this->company->db])
            ->assertSee($this->payment_a->number)
            ->assertDontSee($this->payment_b->number);
    }

    public function testRestrictedContactSeesPaymentItMade(): void
    {
        $this->restrict(true);

        $this->payment_b->client_contact_id = $this->contact_a->id;
        $this->payment_b->save();

        $this->assertTrue($this->payment_b->isVisibleToContact($this->contact_a));
        $this->assertFalse($this->payment_a->isVisibleToContact($this->contact_b));
    }

    public function testRestrictedContactCannotOpenForeignInvoice(): void
    {
        $this->restrict(true);

        $this->actingAs($this->contact_a, 'contact');

        $this->get(route('client.invoice.show', ['invoice' => $this->invoice_b->hashed_id]))
            ->assertStatus(403);

        $this->get(route('client.payments.show', ['payment' => $this->payment_b->hashed_id]))
            ->assertStatus(403);
    }

    public function testStatementOnlyContainsOwnEntities(): void
    {
        $this->restrict(true);

        $options = [
            'start_date' => now()->subMonth()->format('Y-m-d'),
            'end_date' => now()->addMonth()->format('Y-m-d'),
        ];

        $restricted = (new Statement($this->client->fresh(), $options))->setContact($this->contact_a);

        $this->assertEquals([$this->invoice_a->id], $restricted->getInvoices()->pluck('id')->all());

        // Without a portal contact (admin / scheduled statements) nothing is filtered.
        $unrestricted = new Statement($this->client->fresh(), $options);

        $this->assertEqualsCanonicalizing(
            [$this->invoice_a->id, $this->invoice_b->id],
            $unrestricted->getInvoices()->pluck('id')->all()
        );
    }
}
