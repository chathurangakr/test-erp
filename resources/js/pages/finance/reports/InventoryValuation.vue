<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import { index as generalLedgerIndex } from '@/routes/general-ledger';
import { inventoryGlReconciliation, inventoryValuation } from '@/routes/reports';
import type { InventoryValuationReport, OptionRef } from '@/types';

const props = defineProps<{
    report: InventoryValuationReport;
    products: OptionRef[];
    warehouses: OptionRef[];
    filters: { product_id: number | null; warehouse_id: number | null };
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Inventory valuation', href: inventoryValuation() }] } });

const productId = ref(props.filters.product_id ? String(props.filters.product_id) : '');
const warehouseId = ref(props.filters.warehouse_id ? String(props.filters.warehouse_id) : '');

watch([productId, warehouseId], () => {
    router.get(
        inventoryValuation().url,
        { product_id: productId.value || undefined, warehouse_id: warehouseId.value || undefined },
        { preserveState: false, replace: true },
    );
});

const selectClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
</script>

<template>
    <Head title="Inventory valuation" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Inventory valuation"
            description="What the stock is worth, from the inventory ledger."
        />

        <section class="grid gap-4 sm:grid-cols-3">
            <div class="grid gap-2">
                <Label for="product">Product</Label>
                <select id="product" v-model="productId" :class="selectClass">
                    <option value="">All products</option>
                    <option v-for="p in products" :key="p.id" :value="String(p.id)">
                        {{ p.code }} — {{ p.name }}
                    </option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="warehouse">Warehouse</Label>
                <select id="warehouse" v-model="warehouseId" :class="selectClass">
                    <option value="">All warehouses</option>
                    <option v-for="w in warehouses" :key="w.id" :value="String(w.id)">
                        {{ w.code }} — {{ w.name }}
                    </option>
                </select>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Total value ({{ currency }})</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ report.value_total }}</p>
            </div>
        </section>

        <div
            v-if="report.unmapped_count > 0"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-4 text-sm"
        >
            {{ report.unmapped_count }} row(s) belong to products with no accounting mapping.
            Their stock is real but has never reached the general ledger — see
            <Link :href="inventoryGlReconciliation()" class="underline">Inventory vs GL</Link>.
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Product</th>
                        <th class="px-4 py-3 font-medium">Warehouse</th>
                        <th class="px-4 py-3 font-medium">UOM</th>
                        <th class="px-4 py-3 text-right font-medium">Quantity</th>
                        <th class="px-4 py-3 text-right font-medium">Secondary</th>
                        <th class="px-4 py-3 text-right font-medium">WAC</th>
                        <th class="px-4 py-3 text-right font-medium">Value</th>
                        <th class="px-4 py-3 font-medium">GL account</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in report.rows"
                        :key="`${row.product_id}-${row.warehouse_id}`"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-4 py-3">
                            <span class="font-mono text-xs font-medium">{{ row.product_code }}</span>
                            <span class="text-muted-foreground block text-xs">{{ row.product_name }}</span>
                        </td>
                        <td class="px-4 py-3">{{ row.warehouse_code }}</td>
                        <td class="px-4 py-3">{{ row.primary_uom }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ row.quantity }}</td>
                        <td class="px-4 py-3 text-right font-mono">
                            <template v-if="row.secondary_quantity !== null">
                                {{ row.secondary_quantity }}
                                <span class="text-muted-foreground text-xs">{{ row.secondary_uom }}</span>
                            </template>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ row.weighted_average_cost }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ row.value }}</td>
                        <td class="px-4 py-3">
                            <Link
                                v-if="row.inventory_account_id"
                                :href="`${generalLedgerIndex().url}?account_id=${row.inventory_account_id}`"
                                class="font-mono text-xs hover:underline"
                            >
                                {{ row.inventory_account_code }}
                            </Link>
                            <span v-else class="text-muted-foreground text-xs">Unmapped</span>
                        </td>
                    </tr>
                    <tr v-if="report.rows.length === 0">
                        <td colspan="8" class="text-muted-foreground px-4 py-8 text-center">No stock on hand.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            Quantity, weighted average and value all come from the inventory ledger, not the
            general ledger — this is the authoritative record of what is physically held.
            Where a product carries a second unit, both are shown as measured;
            <strong>neither is ever derived from the other</strong>, and value always follows
            the primary quantity. This is the position <em>now</em>: historical valuation as at
            a past date is not supported, because the stock service has no date filter and
            approximating one would produce a figure that disagrees with every other stock
            screen.
        </p>
    </div>
</template>
