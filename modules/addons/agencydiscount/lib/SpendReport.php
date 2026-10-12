<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

/**
 * A client's qualifying spend, the counted items behind it, and where it sits in the tier table.
 *
 * Pending items are the client's qualifying products and add-ons that are still Pending (a new
 * order not yet provisioned) when Pending is not a counted status. They are outside the spend and
 * the tier; InvoiceDiscounter adds the ones on a new-order invoice when "Discount new orders" is on.
 *
 * @phpstan-type ServiceItem array{id:int, productId:int, groupId:int, name:string, domain:string, amount:string, billingCycle:string, monthly:string, nextInvoiceDate:?string, promoId:int}
 * @phpstan-type AddonItem array{id:int, hostingId:int, addonId:int, name:string, domain:string, amount:string, billingCycle:string, monthly:string, nextInvoiceDate:?string}
 */
final class SpendReport
{
    /**
     * @param list<ServiceItem> $services  counted services only
     * @param list<AddonItem> $addons  counted add-ons only
     * @param list<ServiceItem> $pendingServices  qualifying services still Pending, never also in $services
     * @param list<AddonItem> $pendingAddons  qualifying add-ons still Pending, never also in $addons
     */
    public function __construct(
        public readonly int $clientId,
        public readonly string $clientName,
        public readonly string $currency,
        public readonly string $monthlySpend,
        public readonly array $services,
        public readonly array $addons,
        public readonly ?Tier $tier,
        public readonly ?Tier $nextTier,
        public readonly bool $currencyHasTiers,
        public readonly array $pendingServices = [],
        public readonly array $pendingAddons = [],
    ) {
    }

    /** @return array<int,string> pending service id => monthly equivalent */
    public function pendingServiceMonthly(): array
    {
        return array_column($this->pendingServices, 'monthly', 'id');
    }

    /** @return array<int,string> pending add-on id => monthly equivalent */
    public function pendingAddonMonthly(): array
    {
        return array_column($this->pendingAddons, 'monthly', 'id');
    }

    public function countedItems(): int
    {
        return count($this->services) + count($this->addons);
    }

    /** @return list<int> */
    public function serviceIds(): array
    {
        return array_column($this->services, 'id');
    }

    /** @return list<int> */
    public function addonIds(): array
    {
        return array_column($this->addons, 'id');
    }

    /** @return array<int,int> service id => promoId */
    public function servicePromoIds(): array
    {
        $promos = [];
        foreach ($this->services as $service) {
            $promos[$service['id']] = $service['promoId'];
        }
        return $promos;
    }

    /** What the client must add to exceed the next threshold (spend has to be strictly above it), or null at the top tier. */
    public function shortfallToNextTier(): ?string
    {
        if ($this->nextTier === null) {
            return null;
        }
        return bcadd(bcsub($this->nextTier->above, $this->monthlySpend, 2), '0.01', 2);
    }
}
