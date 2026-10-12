#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Exports the tables Agency Programme reads from a WHMCS database to JSON files for bin/agency-discount rehearse.
# Read-only: it runs SELECT statements only. The output contains client data; keep it out of version control.
# Usage: tools/snapshot.sh OUTDIR DATABASE [mariadb client options...]
#   e.g. tools/snapshot.sh /tmp/snap whmcs_copy --defaults-file="$HOME/.my.cnf" -u root
# Amounts are exported as strings (CAST AS CHAR) so no decimal passes through a float.
set -euo pipefail
out=${1:?OUTDIR}; db=${2:?DATABASE}; shift 2
mkdir -p "$out"
dump() { # name, JSON_OBJECT column list, from-clause, then the mariadb client options
  local name=$1 columns=$2 from=$3
  shift 3
  mariadb "$@" --max-allowed-packet=1G --batch --raw --skip-column-names "$db" \
    -e "SET SESSION group_concat_max_len = 1073741824; SELECT COALESCE(JSON_ARRAYAGG(JSON_OBJECT($columns)), '[]') FROM $from;" > "$out/$name.json"
  local n
  if ! n=$(php -n -r '$d = json_decode(file_get_contents($argv[1]), true); if (!is_array($d) || !array_is_list($d)) { exit(1); } echo count($d);' "$out/$name.json"); then
    echo "snapshot.sh: $name.json is not a JSON array" >&2
    exit 1
  fi
  printf '%s: %s rows\n' "$name" "$n"
}
dump tblcurrencies "'id',id,'code',code" "tblcurrencies" "$@"
dump tblproductgroups "'id',id,'name',name" "tblproductgroups" "$@"
dump tblproducts "'id',id,'gid',gid,'name',name" "tblproducts" "$@"
dump tbladdons "'id',id,'name',name" "tbladdons" "$@"
dump tblclients "'id',id,'firstname',firstname,'lastname',lastname,'companyname',companyname,'currency',currency" "tblclients" "$@"
dump tblhosting "'id',id,'userid',userid,'packageid',packageid,'domain',domain,'amount',CAST(amount AS CHAR),'billingcycle',billingcycle,'domainstatus',domainstatus,'nextinvoicedate',nextinvoicedate,'promoid',promoid" "tblhosting" "$@"
dump tblhostingaddons "'id',id,'userid',userid,'hostingid',hostingid,'addonid',addonid,'recurring',CAST(recurring AS CHAR),'billingcycle',billingcycle,'status',status,'nextinvoicedate',nextinvoicedate" "tblhostingaddons" "$@"
dump tblinvoices "'id',id,'userid',userid,'status',status,'duedate',duedate,'paymentmethod',paymentmethod" "tblinvoices WHERE date >= DATE_SUB(CURDATE(), INTERVAL 400 DAY)" "$@"
dump tblinvoiceitems "'id',id,'invoiceid',invoiceid,'type',type,'relid',relid,'description',description,'amount',CAST(amount AS CHAR),'taxed',taxed" "tblinvoiceitems WHERE invoiceid IN (SELECT id FROM tblinvoices WHERE date >= DATE_SUB(CURDATE(), INTERVAL 400 DAY))" "$@"
