{* SPDX-License-Identifier: AGPL-3.0-or-later *}
{* Agency Programme client-area page. Read-only. Variables come from AgencyDiscount\ClientArea\PageData::build();
   amounts arrive formatted and every value is escaped here. Bootstrap 4 classes of the client theme only. *}
<div class="agencydiscount-clientarea">
{if !$approved}
    <div class="alert alert-info">{$text.not_member|escape}</div>
{else}
    <p>{$text.intro|escape}</p>
    {if $dryRun}<div class="alert alert-warning">{$text.dry_run|escape}</div>{/if}
    {if $startsText}<div class="alert alert-info">{$startsText|escape}</div>{/if}

    <div class="row">
        <div class="col-md-6">
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="card-title">{$text.tier|escape}</h5>
                    <p class="h4">
                        {if $tier}<span class="badge badge-success">{$tier.label|escape}</span>
                        {elseif !$hasTiers}<span class="badge badge-secondary">{$noTiersText|escape}</span>
                        {else}<span class="badge badge-secondary">{$text.no_tier|escape}</span>{/if}
                    </p>
                    <dl class="row mb-0">
                        <dt class="col-sm-6">{$text.spend|escape}</dt><dd class="col-sm-6">{$spend|escape}</dd>
                        <dt class="col-sm-6">{$text.next_invoice|escape}</dt><dd class="col-sm-6">{$nextInvoiceText|escape}</dd>
                        <dt class="col-sm-6">{$savedLabel|escape}</dt><dd class="col-sm-6">{$savedThisYear|escape}</dd>
                    </dl>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="card-title">{$text.to_next|escape}</h5>
                    {if $hasProgress}
                    <div class="progress mb-2">
                        <div class="progress-bar" role="progressbar" style="width: {$progress|escape}%" aria-valuenow="{$progress|escape}" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    {/if}
                    <p class="mb-0">{$toNextText|escape}</p>
                </div>
            </div>
        </div>
    </div>

    <h4>{$text.tiers|escape}</h4>
    {if $tiers}
    <p class="text-muted">{$text.tiers_help|escape}</p>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>{$text.col_tier|escape}</th><th>{$text.col_above|escape}</th><th>{$text.col_percent|escape}</th><th></th></tr></thead>
            <tbody>
            {foreach $tiers as $row}
                <tr{if $row.current} class="table-success"{/if}>
                    <td>{$row.index|escape}</td>
                    <td>{$row.above|escape}</td>
                    <td>{$row.percent|escape}%</td>
                    <td>{if $row.current}<span class="badge badge-success">{$text.you_are_here|escape}</span>{/if}</td>
                </tr>
            {/foreach}
            </tbody>
        </table>
    </div>
    {else}
    <p class="text-muted">{$noTiersText|escape}</p>
    {/if}

    <h4>{$text.counts|escape}</h4>
    <p class="text-muted">{$text.counts_help|escape}</p>
    <div class="row">
        <div class="col-md-4">
            <h6>{$text.counts_groups|escape}</h6>
            {if $counts.groups}<ul>{foreach $counts.groups as $name}<li>{$name|escape}</li>{/foreach}</ul>{else}<p class="text-muted">{$text.counts_none|escape}</p>{/if}
        </div>
        <div class="col-md-4">
            <h6>{$text.counts_products|escape}</h6>
            {if $counts.products}<ul>{foreach $counts.products as $name}<li>{$name|escape}</li>{/foreach}</ul>{else}<p class="text-muted">{$text.counts_none|escape}</p>{/if}
        </div>
        <div class="col-md-4">
            <h6>{$text.counts_addons|escape}</h6>
            {if $counts.addons}<ul>{foreach $counts.addons as $name}<li>{$name|escape}</li>{/foreach}</ul>{else}<p class="text-muted">{$text.counts_none|escape}</p>{/if}
        </div>
    </div>

    <h4>{$text.not_counted|escape}</h4>
    <p class="text-muted">{$notCountedText|escape}</p>

    <h4>{$text.your_items|escape}</h4>
    {if $itemCount == 0}
    <p class="text-muted">{$text.items_none|escape}</p>
    {else}
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>{$text.col_domain|escape}</th><th>{$text.col_item|escape}</th><th>{$text.col_cycle|escape}</th><th class="text-right">{$text.col_amount|escape}</th><th class="text-right">{$text.col_monthly|escape}</th></tr></thead>
            <tbody>
            {foreach $services as $item}
                <tr><td>{$item.domain|escape}</td><td>{$item.name|escape}</td><td>{$item.cycle|escape}</td><td class="text-right">{$item.amount|escape}</td><td class="text-right">{$item.monthly|escape}</td></tr>
            {/foreach}
            {foreach $addons as $item}
                <tr><td>{$item.domain|escape}</td><td>{$item.name|escape}</td><td>{$item.cycle|escape}</td><td class="text-right">{$item.amount|escape}</td><td class="text-right">{$item.monthly|escape}</td></tr>
            {/foreach}
            </tbody>
            <tfoot>
                <tr><th colspan="4" class="text-right">{$text.total_services|escape}</th><th class="text-right">{$totals.services|escape}</th></tr>
                <tr><th colspan="4" class="text-right">{$text.total_addons|escape}</th><th class="text-right">{$totals.addons|escape}</th></tr>
                <tr><th colspan="4" class="text-right">{$text.total|escape}</th><th class="text-right">{$totals.all|escape}</th></tr>
            </tfoot>
        </table>
    </div>
    {/if}
{/if}
</div>
