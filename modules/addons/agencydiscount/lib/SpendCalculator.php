<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

final class ClientNotFound extends \RuntimeException
{
}

/** Works out a client's qualifying monthly spend from their services and add-ons. */
final class SpendCalculator
{
    /** The WHMCS status of a service or add-on that is ordered but not yet provisioned. */
    public const PENDING = 'Pending';

    public function __construct(private readonly Repository $repo, private readonly Config $config)
    {
    }

    /** @throws ClientNotFound */
    public function forClient(int $clientId): SpendReport
    {
        $client = $this->repo->client($clientId);
        if ($client === null) {
            throw new ClientNotFound('Client ' . $clientId . ' not found');
        }

        // A qualifying item whose status counts is counted; one still Pending (a new order) is listed
        // separately. The two are exclusive, so adding Pending to the counted statuses moves the item.
        $services = [];
        $pendingServices = [];
        foreach ($this->repo->services($clientId) as $row) {
            if (!$this->config->countsProduct($row['productId'], $row['groupId'])) {
                continue;
            }
            $counted = $this->config->countsStatus($row['status']);
            if (!$counted && $row['status'] !== self::PENDING) {
                continue;
            }
            $item = [
                'id' => $row['id'],
                'productId' => $row['productId'],
                'groupId' => $row['groupId'],
                'name' => $row['productName'],
                'domain' => $row['domain'],
                'amount' => $row['amount'],
                'billingCycle' => $row['billingCycle'],
                'monthly' => Money::monthlyEquivalent($row['amount'], $row['billingCycle']),
                'nextInvoiceDate' => $row['nextInvoiceDate'],
                'promoId' => $row['promoId'],
            ];
            if ($counted) {
                $services[] = $item;
            } else {
                $pendingServices[] = $item;
            }
        }

        $addons = [];
        $pendingAddons = [];
        foreach ($this->repo->addons($clientId) as $row) {
            if (!$this->config->countsAddon($row['addonId'])) {
                continue;
            }
            $counted = $this->config->countsStatus($row['status']);
            if (!$counted && $row['status'] !== self::PENDING) {
                continue;
            }
            $item = [
                'id' => $row['id'],
                'hostingId' => $row['hostingId'],
                'addonId' => $row['addonId'],
                'name' => $row['addonName'],
                'domain' => $row['domain'],
                'amount' => $row['recurring'],
                'billingCycle' => $row['billingCycle'],
                'monthly' => Money::monthlyEquivalent($row['recurring'], $row['billingCycle']),
                'nextInvoiceDate' => $row['nextInvoiceDate'],
            ];
            if ($counted) {
                $addons[] = $item;
            } else {
                $pendingAddons[] = $item;
            }
        }

        $spend = Money::sum(...array_column($services, 'monthly'), ...array_column($addons, 'monthly'));
        $currency = $client['currency'];
        $tiers = $this->config->tiers;

        return new SpendReport(
            $clientId,
            $client['name'],
            $currency,
            $spend,
            $services,
            $addons,
            $tiers->tierFor($currency, $spend),
            $tiers->nextTier($currency, $spend),
            $tiers->hasCurrency($currency),
            $pendingServices,
            $pendingAddons,
        );
    }
}
