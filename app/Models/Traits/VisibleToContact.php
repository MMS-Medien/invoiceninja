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

namespace App\Models\Traits;

use App\Models\ClientContact;
use Illuminate\Database\Eloquent\Builder;

/**
 * Limits client portal visibility of an entity to the contacts it was
 * sent to, when the client setting `client_portal_restrict_to_contact`
 * is enabled.
 *
 * Entities using this trait must expose an `invitations()` relation.
 */
trait VisibleToContact
{
    public function scopeVisibleToContact(Builder $query, ?ClientContact $contact): Builder
    {
        if (! $contact?->portalRestricted()) {
            return $query;
        }

        return $query->whereHas('invitations', function ($q) use ($contact) {
            $q->where('client_contact_id', $contact->id);
        });
    }

    public function isVisibleToContact(?ClientContact $contact): bool
    {
        if (! $contact?->portalRestricted()) {
            return true;
        }

        return static::withTrashed()
            ->whereKey($this->getKey())
            ->visibleToContact($contact)
            ->exists();
    }
}
