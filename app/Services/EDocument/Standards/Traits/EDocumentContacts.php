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

namespace App\Services\EDocument\Standards\Traits;

use App\Models\Client;
use App\Models\Vendor;
use App\Models\ClientContact;
use App\Models\VendorContact;

trait EDocumentContacts
{
    /**
     * The seller contact email, depending on the e_invoice_seller_email setting.
     * Falls back to the user email if no valid company email is set.
     */
    private function sellerEmail(object $document, Client|Vendor $settings_entity): string
    {
        $company_email = $document->company->getSetting('email') ?? '';

        if ($settings_entity->getSetting('e_invoice_seller_email') === 'company' && str_contains($company_email, '@')) {
            return $company_email;
        }

        return $document->user->email;
    }

    /**
     * The contact selected on the document. With several
     * selected contacts the first invitation wins.
     */
    private function selectedContact(object $document): ClientContact|VendorContact|null
    {
        return $document->invitations->first()?->contact;
    }

    private function selectedContactName(object $document): ?string
    {
        $contact = $this->selectedContact($document);

        return strlen($contact->first_name ?? '') > 1 ? trim($contact->first_name . ' ' . $contact->last_name) : null;
    }

    private function selectedContactEmail(object $document): ?string
    {
        $email = $this->selectedContact($document)?->email;

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
