<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { index as stockIndex } from '@/routes/inventory/stock';
import type { OptionRef, StockRow } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    rows: StockRow[];
    filters: {
        product_id: number | null;
        warehouse_id: number | null;
        include_inactive: boolean;
    };
    products: OptionRef[];
    warehouses: OptionRef[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Stock on hand', href: stockIndex() }],
    },
});

const { t } = useT();

const productId = ref<string>(
    props.filters.product_id ? String(props.filters.product_id) : '',
);
const warehouseId = ref<string>(
    props.filters.warehouse_id ? String(props.filters.warehouse_id) : '',
);
const includeInactive = ref<boolean>(props.filters.include_inactive);

function applyFilters(): void {
    router.get(
        stockIndex().url,
        {
            product_id: productId.value || undefined,
            warehouse_id: warehouseId.value || undefined,
            include_inactive: includeInactive.value ? 1 : undefined,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

watch([productId, warehouseId, includeInactive], applyFilters);


/** Totals are a convenience only — the ledger remains the source of truth. */
const totalValue = computed(() =>
    props.rows
        .reduce((carry, row) => carry + Number(row.value), 0),
);
</script>

<template>
    <Head :title="t('Stock on hand')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Stock on hand"
            description="Derived from posted inventory movements. There is no stored stock figure."
        />

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="grid gap-1.5">
                <label for="product" class="text-sm font-medium">{{ t('Product') }}</label>
                <SearchableSelect
                    id="product"
                    v-model="productId"
                    :options="[{ value: '', label: t('All products') }, ...(products).map((product) => ({ value: String(product.id), label: `${product.code} — ${product.name}` }))]"
                />
            </div>

            <div class="grid gap-1.5">
                <label for="warehouse" class="text-sm font-medium">{{ t('Warehouse') }}</label>
                <SearchableSelect
                    id="warehouse"
                    v-model="warehouseId"
                    :options="[{ value: '', label: t('All warehouses') }, ...(warehouses).map((warehouse) => ({ value: String(warehouse.id), label: `${warehouse.code} — ${warehouse.name}` }))]"
                />
            </div>

            <div class="flex items-end">
                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="includeInactive"
                        type="checkbox"
                        class="border-input size-4 rounded border"
                    />
                    {{ t('Include inactive products') }}
                </label>
            </div>
        </div>

        <div
            v-if="rows.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">{{ t('No stock on hand') }}</p>
            <p class="text-muted-foreground text-sm">
                {{ t('Inventory appears here once purchase, production or adjustment documents post movements to the ledger.') }}
            </p>
        </div>

        <template v-else>
            <!-- Desktop -->
            <div
                class="border-sidebar-border/70 dark:border-sidebar-border hidden overflow-x-auto rounded-xl border md:block"
            >
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ t('Code') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Product') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Warehouse') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Quantity') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('UOM') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Secondary') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('2nd UOM') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Avg cost') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Value') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in rows"
                            :key="`${row.product_id}-${row.warehouse_id}`"
                            class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t"
                        >
                            <td class="px-4 py-3 font-mono text-xs font-medium">
                                {{ row.product_code }}
                            </td>
                            <td class="px-4 py-3">
                                {{ row.product_name }}
                                <Badge
                                    v-if="!row.product_is_active"
                                    variant="secondary"
                                    class="ml-2"
                                >
                                    {{ t('Inactive') }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3">{{ row.warehouse_code }}</td>
                            <td class="px-4 py-3 text-right font-mono">
                                {{ row.quantity }}
                            </td>
                            <td class="text-muted-foreground px-4 py-3">
                                {{ row.primary_uom }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono">
                                {{ row.secondary_quantity ?? '—' }}
                            </td>
                            <td class="text-muted-foreground px-4 py-3">
                                {{ row.secondary_uom ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono">
                                {{ money(row.unit_cost) }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono">
                                {{ money(row.value) }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-muted/30">
                        <tr class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                            <td colspan="8" class="px-4 py-3 text-right font-medium">
                                {{ t('Total value') }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono font-medium">
                                {{ money(totalValue) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Mobile -->
            <div class="grid gap-3 md:hidden">
                <div
                    v-for="row in rows"
                    :key="`m-${row.product_id}-${row.warehouse_id}`"
                    class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-mono text-xs font-medium">
                                {{ row.product_code }}
                            </p>
                            <p class="mt-1 truncate font-medium">
                                {{ row.product_name }}
                            </p>
                        </div>
                        <Badge variant="secondary">{{ row.warehouse_code }}</Badge>
                    </div>

                    <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <dt class="text-muted-foreground font-medium">{{ t('Quantity') }}</dt>
                            <dd class="font-mono">
                                {{ row.quantity }} {{ row.primary_uom }}
                            </dd>
                        </div>
                        <div v-if="row.secondary_quantity !== null">
                            <dt class="text-muted-foreground font-medium">{{ t('Secondary') }}</dt>
                            <dd class="font-mono">
                                {{ row.secondary_quantity }} {{ row.secondary_uom }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground font-medium">{{ t('Avg cost') }}</dt>
                            <dd class="font-mono">{{ money(row.unit_cost) }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground font-medium">{{ t('Value') }}</dt>
                            <dd class="font-mono">{{ money(row.value) }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </template>

        <p class="text-muted-foreground max-w-3xl text-xs">
            {{ t('Weighted average cost is maintained at company level, so it is the same for a product in every warehouse. Moving stock between warehouses does not change total quantity, total value or the average cost.') }}
        </p>
    </div>
</template>
