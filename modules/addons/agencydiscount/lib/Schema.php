<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

/** DDL for the module's three tables (MySQL/MariaDB dialect). */
final class Schema
{
    public const TABLES = ['mod_agencydiscount_clients', 'mod_agencydiscount_log', 'mod_agencydiscount_invoices'];

    private const CLIENTS = <<<'SQL'
CREATE TABLE IF NOT EXISTS mod_agencydiscount_clients (
  client_id INT NOT NULL PRIMARY KEY,
  approved TINYINT(1) NOT NULL DEFAULT 0,
  note VARCHAR(255) NOT NULL DEFAULT '',
  approved_by VARCHAR(64) NOT NULL DEFAULT '',
  approved_at DATETIME NULL,
  effective_from DATE NULL,
  currency VARCHAR(3) NOT NULL DEFAULT '',
  spend DECIMAL(16,2) NOT NULL DEFAULT 0.00,
  tier_index INT NULL,
  tier_percent DECIMAL(5,2) NULL,
  counted_services INT NOT NULL DEFAULT 0,
  counted_addons INT NOT NULL DEFAULT 0,
  calculated_at DATETIME NULL,
  updated_at DATETIME NOT NULL
) DEFAULT CHARSET=utf8mb4
SQL;

    private const LOG = <<<'SQL'
CREATE TABLE IF NOT EXISTS mod_agencydiscount_log (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  client_id INT NULL,
  invoice_id INT NULL,
  event VARCHAR(32) NOT NULL,
  detail TEXT NOT NULL,
  actor VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_client (client_id),
  KEY idx_created (created_at)
) DEFAULT CHARSET=utf8mb4
SQL;

    private const INVOICES = <<<'SQL'
CREATE TABLE IF NOT EXISTS mod_agencydiscount_invoices (
  invoice_id INT NOT NULL PRIMARY KEY,
  client_id INT NOT NULL,
  item_id INT NULL,
  tier_index INT NOT NULL,
  percent DECIMAL(5,2) NOT NULL,
  base DECIMAL(16,2) NOT NULL,
  amount DECIMAL(16,2) NOT NULL,
  dry_run TINYINT(1) NOT NULL,
  created_at DATETIME NOT NULL
) DEFAULT CHARSET=utf8mb4
SQL;

    public static function install(Repository $repo): void
    {
        foreach (self::createStatements() as $sql) {
            $repo->execute($sql);
        }
    }

    public static function uninstall(Repository $repo): void
    {
        foreach (self::dropStatements() as $sql) {
            $repo->execute($sql);
        }
    }

    /** @return list<string> */
    public static function createStatements(): array
    {
        return [self::CLIENTS, self::LOG, self::INVOICES];
    }

    /** @return list<string> */
    public static function dropStatements(): array
    {
        return array_map(static fn (string $table): string => 'DROP TABLE IF EXISTS ' . $table, self::TABLES);
    }
}
