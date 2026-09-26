<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import DeliveryActionController from '@/actions/App/Http/Controllers/Sales/DeliveryActionController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { index } from '@/routes/deliveries';
import { index as ledgerIndex } from '@/routes/inventory/ledger';
import type { DeliveryDetail } from '@/types';

defineProps<{ delivery: DeliveryDetail }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Deliveries', href: index() }] } });

const { can } = usePermissions();

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'POSTED') return 'default';
    return 'outline';
}
</script>

<template>
    <Head :title="delivery.delivery_number" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
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
                <Button v-if="delivery.can_edit && can('sales.delivery_create')" variant="outline" as-child>
                    <Link :href="`${index().url}/${delivery.id}/edit`">
                        <Pencil class="size-4" />
                        Edit
                    </Link>
                </Button>

                <Form
                    v-if="delivery.can_post && can('sales.delivery_post')"
                    v-bind="DeliveryActionController.post.form(delivery.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" class="w-full" :disabled="processing">
                        Post to inventory
                    </Button>
                </Form>

                <Form
                    v-if="delivery.can_reverse && can('sales.delivery_reverse')"
                    v-bind="DeliveryActionController.reverse.form(delivery.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">
                        Reverse
                    </Button>
                </Form>

                <Form
                    v-if="delivery.can_cancel && can('sales.delivery_cancel')"
                    v-bind="DeliveryActionController.cancel.form(delivery.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">
                        Cancel draft
                    </Button>
                </Form>
            </div>
        </div>

        <div
            v-if="delivery.status === 'DRAFT'"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-4 text-sm"
        >
            This delivery is a <strong>draft</strong> — no stock has left the warehouse.
            Cost and profit appear once it is posted, because the inventory cost is
            determined by the ledger at that moment.
        </div>

        <!-- Totals -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Sales value</p>
                <p class="mt-1 font-mono text-lg">{{ delivery.sales_total }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Cost of goods sold</p>
                <p class="mt-1 font-mono text-lg">{{ delivery.cogs_total ?? '—' }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Gross profit</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ delivery.gross_profit ?? '—' }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Gross margin</p>
                <p class="mt-1 font-mono text-lg">
                    {{ delivery.gross_margin !== null ? `${delivery.gross_margin}%` : '—' }}
                </p>
            </div>
        </div>

        <!-- Header -->
        <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6">
            <dl class="grid gap-6 sm:grid-cols-3">
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Warehouse</dt>
                    <dd class="mt-1 text-sm">{{ delivery.warehouse_code }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Delivery date</dt>
                    <dd class="mt-1 text-sm">{{ delivery.delivery_date }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Sales order</dt>
                    <dd class="mt-1 font-mono text-sm">{{ delivery.so_number ?? 'Direct delivery' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Vehicle</dt>
                    <dd class="mt-1 text-sm">{{ delivery.vehicle_number ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Driver</dt>
                    <dd class="mt-1 text-sm">{{ delivery.driver_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Customer reference</dt>
                    <dd class="mt-1 text-sm">{{ delivery.customer_reference ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Posted</dt>
                    <dd class="mt-1 text-sm">
                        {{ delivery.posted_at ? `${delivery.posted_at} by ${delivery.posted_by ?? '—'}` : '—' }}
                    </dd>
                </div>
                <div v-if="delivery.inventory_reference">
                    <dt class="text-muted-foreground text-sm font-medium">Inventory transaction</dt>
                    <dd class="mt-1 font-mono text-sm">
                        <Link v-if="can('inventory.view')" :href="ledgerIndex().url" class="hover:underline">
                            {{ delivery.inventory_reference }}
                        </Link>
                        <template v-else>{{ delivery.inventory_reference }}</template>
                    </dd>
                </div>
                <div v-if="delivery.reversal_reference">
                    <dt class="text-muted-foreground text-sm font-medium">Reversal transaction</dt>
                    <dd class="mt-1 font-mono text-sm">{{ delivery.reversal_reference }}</dd>
                </div>
                <div v-if="delivery.notes" class="sm:col-span-3">
                    <dt class="text-muted-foreground text-sm font-medium">Notes</dt>
                    <dd class="mt-1 text-sm whitespace-pre-line">{{ delivery.notes }}</dd>
                </div>
            </dl>
        </div>

        <!-- Lines -->
        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-3 py-3 font-medium">Product</th>
                        <th class="px-3 py-3 font-medium">Lot</th>
                        <th class="px-3 py-3 text-right font-medium">Quantity</th>
                        <th class="px-3 py-3 font-medium">UOM</th>
                        <th class="px-3 py-3 text-right font-medium">Secondary</th>
                        <th class="px-3 py-3 font-medium">Priced per</th>
                        <th class="px-3 py-3 text-right font-medium">Price</th>
                        <th class="px-3 py-3 text-right font-medium">Sales value</th>
                        <th class="px-3 py-3 text-right font-medium">Unit cost</th>
                        <th class="px-3 py-3 text-right font-medium">COGS</th>
                        <th class="px-3 py-3 text-right font-medium">Profit</th>
                        <th class="px-3 py-3 text-right font-medium">Margin</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="line in delivery.lines"
                        :key="line.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-3 py-3">
                            <span class="font-mono text-xs">{{ line.product_code }}</span>
                            <span class="text-muted-foreground block text-xs">{{ line.product_name }}</span>
                        </td>
                        <td class="text-muted-foreground px-3 py-3 font-mono text-xs">
                            {{ line.lot_number ?? '—' }}
                        </td>
                        <td class="px-3 py-3 text-right font-mono">{{ line.quantity }}</td>
                        <td class="text-muted-foreground px-3 py-3">{{ line.uom }}</td>
                        <td class="px-3 py-3 text-right font-mono">
                            {{ line.secondary_quantity ? `${line.secondary_quantity} ${line.secondary_uom ?? ''}` : '—' }}
                        </td>
                        <td class="px-3 py-3">
                            {{ line.pricing_basis === 'SECONDARY' ? line.secondary_uom : line.uom }}
                        </td>
                        <td class="px-3 py-3 text-right font-mono">{{ line.unit_price }}</td>
                        <td class="px-3 py-3 text-right font-mono">{{ line.sales_value }}</td>
                        <td class="px-3 py-3 text-right font-mono">{{ line.inventory_unit_cost ?? '—' }}</td>
                        <td class="px-3 py-3 text-right font-mono">{{ line.cost_of_goods_sold ?? '—' }}</td>
                        <td class="px-3 py-3 text-right font-mono font-medium">{{ line.gross_profit ?? '—' }}</td>
                        <td class="px-3 py-3 text-right font-mono">
                            {{ line.gross_margin !== null ? `${line.gross_margin}%` : '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            Inventory unit cost is the weighted average the ledger used when this delivery
            was posted — it is never entered by hand, and the selling price never affects
            it. Cost of goods sold follows the primary quantity, because that is what left
            stock, even on a line priced by weight. Figures here are operational
            profitability only; no accounting entries are created.
        </p>
    </div>
</template>
