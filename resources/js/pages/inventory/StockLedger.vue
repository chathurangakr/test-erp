<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import ListControls from '@/components/lists/ListControls.vue';
import ListPagination from '@/components/lists/ListPagination.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import PrintButton from '@/components/print/PrintButton.vue';
import { Badge } from '@/components/ui/badge';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { index as ledgerIndex } from '@/routes/inventory/ledger';
import type {
    LedgerRow,
    OptionRef,
    Paginated,
    TransactionTypeOption,
} from '@/types';
import { type ListControls as ListControlValues, useListFilters } from '@/composables/useListFilters';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    lines: Paginated<LedgerRow>;
    filters: {
        product_id: number | null;
        warehouse_id: number | null;
        lot_id: number | null;
        transaction_type: string | null;
    } & ListControlValues;
    products: OptionRef[];
    warehouses: OptionRef[];
    lots: { id: number; lot_number: string }[];
    transactionTypes: TransactionTypeOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Stock ledger', href: ledgerIndex() }],
    },
});

const { t } = useT();

const { filters, sortBy, clear } = useListFilters(() => ledgerIndex().url, {
    ...props.filters,
    product_id: props.filters.product_id ? String(props.filters.product_id) : '',
    warehouse_id: props.filters.warehouse_id ? String(props.filters.warehouse_id) : '',
    lot_id: props.filters.lot_id ? String(props.filters.lot_id) : '',
    transaction_type: props.filters.transaction_type ?? '',
});
</script>

<template>
    <Head :title="t('Stock ledger')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Stock ledger"
                description="Every posted inventory movement. Read only — postings come from business documents."
            />
            <PrintButton />
        </div>

        <ListControls v-model:per-page="filters.per_page" v-model:from="filters.from" v-model:to="filters.to" @clear="clear">
            <label class="grid w-40 gap-1 text-xs">
                <span class="text-muted-foreground">{{ t('Product') }}</span>
                <SearchableSelect
                    v-model="filters.product_id"
                    :options="[{ value: '', label: t('All') }, ...products.map((p) => ({ value: String(p.id), label: `${p.code ?? ''}` }))]"
                />
            </label>
            <label class="grid w-36 gap-1 text-xs">
                <span class="text-muted-foreground">{{ t('Warehouse') }}</span>
                <SearchableSelect
                    v-model="filters.warehouse_id"
                    :options="[{ value: '', label: t('All') }, ...warehouses.map((w) => ({ value: String(w.id), label: `${w.code ?? ''}` }))]"
                />
            </label>
            <label class="grid w-48 gap-1 text-xs">
                <span class="text-muted-foreground">{{ t('Type') }}</span>
                <SearchableSelect
                    v-model="filters.transaction_type"
                    :options="[{ value: '', label: t('All') }, ...transactionTypes.map((option) => ({ value: option.value, label: `${option.label ?? ''}` }))]"
                />
            </label>
            <label class="grid w-40 gap-1 text-xs">
                <span class="text-muted-foreground">{{ t('Lot') }}</span>
                <SearchableSelect
                    v-model="filters.lot_id"
                    :options="[{ value: '', label: t('All') }, ...lots.map((lot) => ({ value: String(lot.id), label: `${lot.lot_number ?? ''}` }))]"
                />
            </label>
        </ListControls>

        <div
            v-if="lines.data.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">{{ t('No movements found') }}</p>
            <p class="text-muted-foreground text-sm">
                {{ t('The ledger is empty, or no movement matches these filters.') }}
            </p>
        </div>

        <template v-else>
            <div
                class="border-sidebar-border/70 dark:border-sidebar-border hidden overflow-x-auto rounded-xl border md:block"
            >
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <SortHeader label="Date" column="date" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Reference" column="reference" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Type" column="type" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Product" column="product" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Whs" column="warehouse" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Lot" column="lot" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <th class="px-3 py-3 text-right font-medium">{{ t('In') }}</th>
                            <th class="px-3 py-3 text-right font-medium">{{ t('Out') }}</th>
                            <th class="px-3 py-3 font-medium">{{ t('UOM') }}</th>
                            <th class="px-3 py-3 text-right font-medium">{{ t('2nd') }}</th>
                            <SortHeader label="Unit cost" column="unit_cost" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Total cost" column="total_cost" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <th class="px-3 py-3 font-medium">{{ t('User') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="line in lines.data"
                            :key="line.id"
                            class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t"
                        >
                            <td class="px-3 py-3 whitespace-nowrap">{{ line.date }}</td>
                            <td class="px-3 py-3 font-mono text-xs">
                                {{ line.reference }}
                            </td>
                            <td class="px-3 py-3">
                                <Badge
                                    :variant="line.is_reversal ? 'destructive' : 'secondary'"
                                >
                                    {{ line.type_label }}
                                </Badge>
                            </td>
                            <td class="px-3 py-3 font-mono text-xs">
                                {{ line.product_code }}
                            </td>
                            <td class="px-3 py-3">{{ line.warehouse_code }}</td>
                            <td class="text-muted-foreground px-3 py-3 font-mono text-xs">
                                {{ line.lot_number ?? '—' }}
                            </td>
                            <td class="px-3 py-3 text-right font-mono">
                                {{ line.in_quantity ?? '' }}
                            </td>
                            <td class="px-3 py-3 text-right font-mono">
                                {{ line.out_quantity ?? '' }}
                            </td>
                            <td class="text-muted-foreground px-3 py-3">
                                {{ line.primary_uom }}
                            </td>
                            <td class="px-3 py-3 text-right font-mono">
                                {{
                                    line.secondary_quantity
                                        ? `${line.secondary_quantity} ${line.secondary_uom ?? ''}`
                                        : '—'
                                }}
                            </td>
                            <td class="px-3 py-3 text-right font-mono">
                                {{ money(line.unit_cost) }}
                            </td>
                            <td class="px-3 py-3 text-right font-mono">
                                {{ money(line.total_cost) }}
                            </td>
                            <td class="text-muted-foreground px-3 py-3">
                                {{ line.user ?? '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="grid gap-3 md:hidden">
                <div
                    v-for="line in lines.data"
                    :key="`m-${line.id}`"
                    class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-mono text-xs font-medium">
                                {{ line.reference }}
                            </p>
                            <p class="text-muted-foreground mt-0.5 text-xs">
                                {{ line.date }}
                            </p>
                        </div>
                        <Badge :variant="line.is_reversal ? 'destructive' : 'secondary'">
                            {{ line.type_label }}
                        </Badge>
                    </div>

                    <p class="mt-2 font-medium">
                        {{ line.product_code }}
                        <span class="text-muted-foreground font-normal">
                            @ {{ line.warehouse_code }}
                        </span>
                    </p>

                    <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <dt class="text-muted-foreground font-medium">{{ t('Movement') }}</dt>
                            <dd class="font-mono">
                                {{ line.in_quantity ? `+${line.in_quantity}` : `−${line.out_quantity}` }}
                                {{ line.primary_uom }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground font-medium">{{ t('Unit cost') }}</dt>
                            <dd class="font-mono">{{ money(line.unit_cost) }}</dd>
                        </div>
                        <div v-if="line.lot_number">
                            <dt class="text-muted-foreground font-medium">{{ t('Lot') }}</dt>
                            <dd class="font-mono">{{ line.lot_number }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground font-medium">{{ t('Total cost') }}</dt>
                            <dd class="font-mono">{{ money(line.total_cost) }}</dd>
                        </div>
                    </dl>
                </div>
            </div>

        </template>

        <ListPagination :page="lines" />

        <p class="text-muted-foreground max-w-3xl text-xs print:hidden">
            {{ t('Posted movements are permanent. Corrections are made by posting a reversal, which appears here as a separate entry referencing the original.') }}
        </p>
    </div>
</template>
