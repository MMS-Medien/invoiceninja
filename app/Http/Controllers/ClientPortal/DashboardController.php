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

namespace App\Http\Controllers\ClientPortal;

use App\Http\Controllers\Controller;
use App\Models\Invoice;

class DashboardController extends Controller
{
    public function index(): \Illuminate\View\View|\Illuminate\Http\RedirectResponse
    {
        /** @var \App\Models\ClientContact $contact */
        $contact = auth()->guard('contact')->user();

        if ($contact->client->getSetting('enable_client_portal_dashboard') === false) {
            return redirect()->route('client.invoices.index');
        }

        $invoices = Invoice::withTrashed()
            ->where('client_id', $contact->client_id)
            ->visibleToContact($contact)
            ->where('is_deleted', 0)
            ->whereIn('status_id', [Invoice::STATUS_SENT, Invoice::STATUS_PARTIAL, Invoice::STATUS_PAID]);

        $total_invoices = (clone $invoices)->sum('amount');

        // Restricted contacts must not see the totals of the whole client.
        if ($contact->portalRestricted()) {
            $balance = (clone $invoices)->whereIn('status_id', [Invoice::STATUS_SENT, Invoice::STATUS_PARTIAL])->sum('balance');
            $paid_to_date = $total_invoices - (clone $invoices)->sum('balance');
        } else {
            $balance = $contact->client->balance;
            $paid_to_date = $contact->client->paid_to_date;
        }

        return $this->render('dashboard.index', [
            'total_invoices' => $total_invoices,
            'balance' => $balance,
            'paid_to_date' => $paid_to_date,
        ]);
    }
}
