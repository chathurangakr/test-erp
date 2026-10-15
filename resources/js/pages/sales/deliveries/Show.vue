<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import DeliveryActionController from '@/actions/App/Http/Controllers/Sales/DeliveryActionController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { useT } from '@/lib/i18n';
import { index } from '@/routes/deliveries';
import { index as ledgerIndex } from '@/routes/inventory/ledger';
import type { DeliveryDetail } from '@/types';
import { money } from '@/lib/money';

const { t } = useT();

defineProps<{ delivery: DeliveryDetail }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Deliveries', href: index() }] },
});

const { can } = usePermissions();

function badgeVariant(
    value: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'POSTED') return 'default';
    return 'outline';
}
</script>

<template>
    <Head :title="delivery.delivery_number" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <div class="flex flex-col gap-2">
                <Heading
                    :title="delivery.delivery_number"
                    :description="`${delivery.customer_code} — ${delivery.customer_name}`"
                />
                <Badge :variant="badgeVariant(delivery.status)" class="w-fit">
                    {{ delivery.status_label }}
                </Badge>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <Button
                    v-if="delivery.can_edit && can('sales.delivery_create')"
                    variant="outline"
                    as-child
                >
                    <Link :href="`${index().url}/${delivery.id}/edit`">
                        <Pencil class="size-4" />
                        {{ t('Edit') }}
                    </Link>
                </Button>

                <Form
                    v-if="delivery.can_post && can('sales.delivery_post')"
                    v-bind="DeliveryActionController.post.form(delivery.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" class="w-full" :disabled="processing">
                        {{ t('Post to inventory') }}
                    </Button>
                </Form>

                <Form
                    v-if="delivery.can_reverse && can('sales.delivery_reverse')"
                    v-bind="DeliveryActionController.reverse.form(delivery.id)"
                    v-slot="{ processing }"
                >
                    <Button
                        type="submit"
                        variant="outline"
                        class="w-full"
                        :disabled="processing"
                    >
                        {{ t('Reverse') }}
                    </Button>
                </Form>

                <Form
                    v-if="delivery.can_cancel && can('sales.delivery_cancel')"
                    v-bind="DeliveryActionController.cancel.form(delivery.id)"
                    v-slot="{ processing }"
                >
                    <Button
                        type="submit"
                        variant="outline"
                        class="w-full"
                        :disabled="processing"
                    >
                        {{ t('Cancel draft') }}
                    </Button>
                </Form>
            </div>
        </div>

        <div
            v-if="delivery.status === 'DRAFT'"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-4 text-sm"
        >
            {{ t('This delivery is a') }} <strong>{{ t('draft') }}</strong>
            {{
                t(
                    '— no stock has left the warehouse. Cost and profit appear once it is posted, because the inventory cost is determined by the ledger at that moment.',
                )
            }}
        </div>

        <!-- Totals -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div
                class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4"
            >
                <p class="text-muted-foreground text-xs font-medium">
                    {{ t('Sales value') }}
                </p>
                <p class="mt-1 font-mono text-lg">
                    {{ money(delivery.sales_total) }}
                </p>
            </div>
            <div
                class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4"
            >
                <p class="text-muted-foreground text-xs font-medium">
                    {{ t('Cost of goods sold') }}
                </p>
                <p class="mt-1 font-mono text-lg">
                    {{ money(delivery.cogs_total) }}
                </p>
            </div>
            <div
                class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4"
            >
                <p class="text-muted-foreground text-xs font-medium">
                    {{ t('Gross profit') }}
                </p>
                <p class="mt-1 font-mono text-lg font-medium">
                    {{ money(delivery.gross_profit) }}
                </p>
            </div>
            <div
                class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4"
            >
                <p class="text-muted-foreground text-xs font-medium">
                    {{ t('Gross margin') }}
                </p>
                <p class="mt-1 font-mono text-lg">
                    {{
                        delivery.gross_margin !== null
                            ? `${delivery.gross_margin}%`
                            : '—'
                    }}
                </p>
            </div>
        </div>

        <!-- Header -->
        <div
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6"
        >
            <dl class="grid gap-6 sm:grid-cols-3">
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">
                        {{ t('Warehouse') }}
                    </dt>
                    <dd class="mt-1 text-sm">{{ delivery.warehouse_code }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">
                        {{ t('Delivery date') }}
                    </dt>
                    <dd class="mt-1 text-sm">{{ delivery.delivery_date }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">
                        {{ t('Sales order') }}
                    </dt>
                    <dd class="mt-1 font-mono text-sm">
                        {{ delivery.so_number ?? 'Direct delivery' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">
                        {{ t('Vehicle') }}
                    </dt>
                    <dd class="mt-1 text-sm">
                        {{ delivery.vehicle_number ?? '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">
                        {{ t('Driver') }}
                    </dt>
                    <dd class="mt-1 text-sm">
                        {{ delivery.driver_name ?? '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">
                        {{ t('Customer reference') }}
                    </dt>
                    <dd class="mt-1 text-sm">
                        {{ delivery.customer_reference ?? '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">
                        {{ t('Posted') }}
                    </dt>
                    <dd class="mt-1 text-sm">
                        {{
                            delivery.posted_at
                                ? `${delivery.posted_at} by ${delivery.posted_by ?? '—'}`
                                : '—'
                        }}
                    </dd>
                </div>
                <div v-if="delivery.inventory_reference">
                    <dt class="text-muted-foreground text-sm font-medium">
                        {{ t('Inventory transaction') }}
                    </dt>
                    <dd class="mt-1 font-mono text-sm">
                        <Link
                            v-if="can('inventory.view')"
                            :href="ledgerIndex().url"
                            class="hover:underline"
                        >
                            {{ delivery.inventory_reference }}
                        </Link>
                        <template v-else>{{
                            delivery.inventory_reference
                        }}</template>
                    </dd>
                </div>
                <div v-if="delivery.reversal_reference">
                    <dt class="text-muted-foreground text-sm font-medium">
                        {{ t('Reversal transaction') }}
                    </dt>
                    <dd class="mt-1 font-mono text-sm">
                        {{ delivery.reversal_reference }}
                    </dd>
                </div>
                <div v-if="delivery.backflush_reference">
                    <dt class="text-muted-foreground text-sm font-medium">
                        {{ t('Raw material transaction') }}
                    </dt>
                    <dd class="mt-1 font-mono text-sm">
                        {{ delivery.backflush_reference }}
                        <span
                            v-if="delivery.backflush_reversal_reference"
                            class="text-muted-foreground"
                        >
                            ·
                            {{
                                t('reversed by :reference', {
                                    reference:
                                        delivery.backflush_reversal_reference,
                                })
                            }}
                        </span>
                    </dd>
                </div>
                <div v-if="delivery.notes" class="sm:col-span-3">
                    <dt class="text-muted-foreground text-sm font-medium">
                        {{ t('Notes') }}
                    </dt>
                    <dd class="mt-1 text-sm whitespace-pre-line">
                        {{ delivery.notes }}
                    </dd>
                </div>
            </dl>
        </div>

        <!-- Lines -->
        <div
            class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border"
        >
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-3 py-3 font-medium">
                            {{ t('Product') }}
                        </th>
                        <th class="px-3 py-3 font-medium">{{ t('Lot') }}</th>
                        <th class="px-3 py-3 text-right font-medium">
                            {{ t('Quantity') }}
                        </th>
                        <th class="px-3 py-3 font-medium">{{ t('UOM') }}</th>
                        <th class="px-3 py-3 text-right font-medium">
                            {{ t('Secondary') }}
                        </th>
                        <th class="px-3 py-3 font-medium">
                            {{ t('Priced per') }}
                        </th>
                        <th class="px-3 py-3 text-right font-medium">
                            {{ t('Price') }}
                        </th>
                        <th class="px-3 py-3 text-right font-medium">
                            {{ t('Sales value') }}
                        </th>
                        <th class="px-3 py-3 text-right font-medium">
                            {{ t('Unit cost') }}
                        </th>
                        <th class="px-3 py-3 text-right font-medium">
                            {{ t('COGS') }}
                        </th>
                        <th class="px-3 py-3 text-right font-medium">
                            {{ t('Profit') }}
                        </th>
                        <th class="px-3 py-3 text-right font-medium">
                            {{ t('Margin') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="line in delivery.lines"
                        :key="line.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-3 py-3">
                            <span class="font-mono text-xs">{{
                                line.product_code
                            }}</span>
                            <span class="text-muted-foreground block text-xs">{{
                                line.product_name
                            }}</span>
                            <span
                                v-if="line.backflush_quantity"
                                class="mt-1 block text-xs text-amber-700 dark:text-amber-300"
                            >
                                {{
                                    t('Raw husks used: :quantity :uom', {
                                        quantity: line.backflush_quantity,
                                        uom: line.backflush_uom ?? '',
                                    })
                                }}
                                <template v-if="line.backflush_cost !== null">
                                    ·
                                    {{
                                        t('husk cost :amount', {
                                            amount: money(line.backflush_cost),
                                        })
                                    }}
                                </template>
                            </span>
                        </td>
                        <td
                            class="text-muted-foreground px-3 py-3 font-mono text-xs"
                        >
                            {{ line.lot_number ?? '—' }}
                        </td>
                        <td class="px-3 py-3 text-right font-mono">
                            {{ line.quantity }}
                        </td>
                        <td class="text-muted-foreground px-3 py-3">
                            {{ line.uom }}
                        </td>
                        <td class="px-3 py-3 text-right font-mono">
                            {{
                                line.secondary_quantity
                                    ? `${line.secondary_quantity} ${line.secondary_uom ?? ''}`
                                    : '—'
                            }}
                        </td>
                        <td class="px-3 py-3">
                            {{
                                line.pricing_basis === 'SECONDARY'
                                    ? line.secondary_uom
                                    : line.uom
                            }}
                        </td>
                        <td class="px-3 py-3 text-right font-mono">
                            {{ money(line.unit_price) }}
                        </td>
                        <td class="px-3 py-3 text-right font-mono">
                            {{ money(line.sales_value) }}
                        </td>
                        <td class="px-3 py-3 text-right font-mono">
                            {{ money(line.inventory_unit_cost) }}
                        </td>
                        <td class="px-3 py-3 text-right font-mono">
                            {{ money(line.cost_of_goods_sold) }}
                        </td>
                        <td class="px-3 py-3 text-right font-mono font-medium">
                            {{ money(line.gross_profit) }}
                        </td>
                        <td class="px-3 py-3 text-right font-mono">
                            {{
                                line.gross_margin !== null
                                    ? `${line.gross_margin}%`
                                    : '—'
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            {{ t('Inventory unit cost is the weighted average the ledger used when this delivery was posted — it is never entered by hand, and the selling price never affects it. Cost of goods sold follows the primary quantity, because that is what left stock, even on a line priced by weight. For chips and cocopeat it also includes the raw husks used, taken from stock at their average cost when the delivery was posted. Figures here are operational profitability; the delivery journal records the cost of sales, and revenue is recognised when it is invoiced.') }}
        </p>
    </div>
</template>
