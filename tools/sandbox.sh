#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copies the WHMCS tables Agency Programme touches into a throwaway sandbox database,
# for tests/integration/SandboxTest.php. The source database is only read.
# Usage: tools/sandbox.sh SOURCE_DB SANDBOX_DB [mariadb client options...]
#   e.g. tools/sandbox.sh whmcs_copy agencydiscount_sandbox --defaults-file="$HOME/.my.cnf" -u root
# SANDBOX_DB is dropped and recreated: it must differ from SOURCE_DB (ignoring case) and contain the word "sandbox".
set -euo pipefail
source_db=${1:?SOURCE_DB}; sandbox_db=${2:?SANDBOX_DB}; shift 2
if [[ "${sandbox_db,,}" == "${source_db,,}" || "$sandbox_db" != *sandbox* ]]; then
  echo "sandbox.sh: refusing: SANDBOX_DB must differ from SOURCE_DB and contain the word \"sandbox\" (got \"$sandbox_db\")" >&2
  exit 2
fi
if [[ ! "$source_db$sandbox_db" =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "sandbox.sh: refusing: database names may only contain letters, digits and underscores" >&2
  exit 2
fi
tables=(tblclients tblcurrencies tblproductgroups tblproducts tbladdons tblhosting tblhostingaddons tblinvoices tblinvoiceitems tbladdonmodules tblpromotions tbladmins)
sql="DROP DATABASE IF EXISTS \`$sandbox_db\`; CREATE DATABASE \`$sandbox_db\`;"
for t in "${tables[@]}"; do
  sql+=" CREATE TABLE \`$sandbox_db\`.\`$t\` LIKE \`$source_db\`.\`$t\`; INSERT INTO \`$sandbox_db\`.\`$t\` SELECT * FROM \`$source_db\`.\`$t\`;"
done
mariadb "$@" --batch -e "$sql"
for t in "${tables[@]}"; do
  printf '%s: %s rows\n' "$t" "$(mariadb "$@" --batch --skip-column-names -e "SELECT COUNT(*) FROM \`$sandbox_db\`.\`$t\`")"
done
