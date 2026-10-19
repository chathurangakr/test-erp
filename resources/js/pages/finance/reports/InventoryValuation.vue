<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BarList from '@/components/charts/BarList.vue';
import Heading from '@/components/Heading.vue';
import ChartsButton from '@/components/charts/ChartsButton.vue';
import PrintButton from '@/components/print/PrintButton.vue';
import { Label } from '@/components/ui/label';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { useChartsVisible } from '@/composables/useChartsVisible';
import { useT } from '@/lib/i18n';
import { index as generalLedgerIndex } from '@/routes/general-ledger';
import { inventoryGlReconciliation, inventoryValuation } from '@/routes/reports';
import type { InventoryValuationReport, OptionRef } from '@/types';
import { addDecimals } from '@/lib/charts';
import { money } from '@/lib/money';

const props = defineProps<{
    report: InventoryValuationReport;
    products: OptionRef[];
    warehouses: OptionRef[];
    filters: { product_id: number | null; warehouse_id: number | null };
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Inventory valuation', href: inventoryValuation() }] } });

const { t } = useT();
const { chartsVisible } = useChartsVisible();

const productId = ref(props.filters.product_id ? String(props.filters.product_id) : '');
const warehouseId = ref(props.filters.warehouse_id ? String(props.filters.warehouse_id) : '');

watch([productId, warehouseId], () => {
    router.get(
        inventoryValuation().url,
        { product_id: productId.value || undefined, warehouse_id: warehouseId.value || undefined },
        { preserveState: false, replace: true },
    );
});


/** Stock value by product (all warehouses together), largest first; the rest folded into "Other". */
const valueByProduct = computed(() => {
    const byProduct = new Map<number, { label: string; sublabel: string; values: string[] }>();

    for (const row of props.report.rows) {
        const entry = byProduct.get(row.product_id) ?? { label: row.product_code, sublabel: row.product_name, values: [] };
        entry.values.push(row.value);
        byProduct.set(row.product_id, entry);
    }

    const rows = [...byProduct.entries()]
        .map(([id, entry]) => {
            const total = addDecimals(entry.values);

            return { key: id, label: entry.label, sublabel: entry.sublabel, value: Number(total), display: money(total), total };
        })
        .sort((a, b) => b.value - a.value);

    if (rows.length <= 10) {
        return rows;
    }

    const other = addDecimals(rows.slice(9).map((r) => r.total));

    return [...rows.slice(0, 9), { key: 'other', label: t('Other'), sublabel: t(':count products', { count: rows.length - 9 }), value: Number(other), display: money(other), total: other }];
});
</script>

<template>
    <Head :title="t('Inventory valuation')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Inventory valuation"
                description="What the stock is worth, from the inventory ledger."
            />
            <div class="flex gap-2">
                <ChartsButton />
                <PrintButton />
            </div>
        </div>

        <section class="grid gap-4 sm:grid-cols-3">
            <div class="grid gap-2">
                <Label for="product">{{ t('Product') }}</Label>
                <SearchableSelect
                    id="product"
                    v-model="productId"
                    :options="[{ value: '', label: t('All products') }, ...(products).map((p) => ({ value: String(p.id), label: `${p.code} — ${p.name}` }))]"
                />
            </div>
            <div class="grid gap-2">
                <Label for="warehouse">{{ t('Warehouse') }}</Label>
                <SearchableSelect
                    id="warehouse"
                    v-model="warehouseId"
                    :options="[{ value: '', label: t('All warehouses') }, ...(warehouses).map((w) => ({ value: String(w.id), label: `${w.code} — ${w.name}` }))]"
                />
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Total value (:currency)', { currency }) }}</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ money(report.value_total) }}</p>
            </div>
        </section>

        <div
            v-if="report.unmapped_count > 0"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-4 text-sm"
        >
            {{ t(':count row(s) belong to products with no accounting mapping. Their stock is real but has never reached the general ledger — see', { count: report.unmapped_count }) }}
            <Link :href="inventoryGlReconciliation()" class="underline">{{ t('Inventory vs GL') }}</Link>.
        </div>

        <div v-if="chartsVisible && valueByProduct.length > 0" class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
            <h3 class="mb-3 text-sm font-medium">{{ t('Stock value by product (:currency)', { currency }) }}</h3>
            <BarList :rows="valueByProduct" :chart-label="t('Stock value by product (:currency)', { currency })" />
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Product') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Warehouse') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('UOM') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Quantity') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Secondary') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('WAC') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Value') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('GL account') }}</th>
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
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.weighted_average_cost) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(row.value) }}</td>
                        <td class="px-4 py-3">
                            <Link
                                v-if="row.inventory_account_id"
                                :href="`${generalLedgerIndex().url}?account_id=${row.inventory_account_id}`"
                                class="font-mono text-xs hover:underline"
                            >
                                {{ row.inventory_account_code }}
                            </Link>
                            <span v-else class="text-muted-foreground text-xs">{{ t('Unmapped') }}</span>
                        </td>
                    </tr>
                    <tr v-if="report.rows.length === 0">
                        <td colspan="8" class="text-muted-foreground px-4 py-8 text-center">{{ t('No stock on hand.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs print:hidden">
            {{ t('Quantity, weighted average and value all come from the inventory ledger, not the general ledger — this is the authoritative record of what is physically held. Where a product carries a second unit, both are shown as measured; neither is ever derived from the other, and value always follows the primary quantity.') }}
            {{ t('This is the position now: historical valuation as at a past date is not supported, because the stock service has no date filter and approximating one would produce a figure that disagrees with every other stock screen.') }}
        </p>
    </div>
</template>
