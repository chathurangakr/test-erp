<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import PrintButton from '@/components/print/PrintButton.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { index as ledgerIndex } from '@/routes/inventory/ledger';
import type {
    LedgerRow,
    OptionRef,
    Paginated,
    TransactionTypeOption,
} from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    lines: Paginated<LedgerRow>;
    filters: {
        product_id: number | null;
        warehouse_id: number | null;
        lot_id: number | null;
        transaction_type: string | null;
        from: string | null;
        to: string | null;
    };
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

const productId = ref(props.filters.product_id ? String(props.filters.product_id) : '');
const warehouseId = ref(props.filters.warehouse_id ? String(props.filters.warehouse_id) : '');
const lotId = ref(props.filters.lot_id ? String(props.filters.lot_id) : '');
const transactionType = ref(props.filters.transaction_type ?? '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');

function applyFilters(): void {
    router.get(
        ledgerIndex().url,
        {
            product_id: productId.value || undefined,
            warehouse_id: warehouseId.value || undefined,
            lot_id: lotId.value || undefined,
            transaction_type: transactionType.value || undefined,
            from: from.value || undefined,
            to: to.value || undefined,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

watch([productId, warehouseId, lotId, transactionType, from, to], applyFilters);

function reset(): void {
    productId.value = '';
    warehouseId.value = '';
    lotId.value = '';
    transactionType.value = '';
    from.value = '';
    to.value = '';
}

const controlClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
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

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            <div class="grid gap-1.5">
                <label for="from" class="text-sm font-medium">{{ t('From') }}</label>
                <input id="from" v-model="from" type="date" :class="controlClass" />
            </div>
            <div class="grid gap-1.5">
                <label for="to" class="text-sm font-medium">{{ t('To') }}</label>
                <input id="to" v-model="to" type="date" :class="controlClass" />
            </div>
            <div class="grid gap-1.5">
                <label for="l-product" class="text-sm font-medium">{{ t('Product') }}</label>
                <SearchableSelect
                    id="l-product"
                    v-model="productId"
                    :options="[{ value: '', label: t('All') }, ...(products).map((p) => ({ value: String(p.id), label: `${(p.code) ?? ''}` }))]"
                />
            </div>
            <div class="grid gap-1.5">
                <label for="l-warehouse" class="text-sm font-medium">{{ t('Warehouse') }}</label>
                <SearchableSelect
                    id="l-warehouse"
                    v-model="warehouseId"
                    :options="[{ value: '', label: t('All') }, ...(warehouses).map((w) => ({ value: String(w.id), label: `${(w.code) ?? ''}` }))]"
                />
            </div>
            <div class="grid gap-1.5">
                <label for="l-type" class="text-sm font-medium">{{ t('Type') }}</label>
                <SearchableSelect
                    id="l-type"
                    v-model="transactionType"
                    :options="[{ value: '', label: t('All') }, ...(transactionTypes).map((option) => ({ value: option.value, label: `${(option.label) ?? ''}` }))]"
                />
            </div>
            <div class="grid gap-1.5">
                <label for="l-lot" class="text-sm font-medium">{{ t('Lot') }}</label>
                <SearchableSelect
                    id="l-lot"
                    v-model="lotId"
                    :options="[{ value: '', label: t('All') }, ...(lots).map((lot) => ({ value: String(lot.id), label: `${(lot.lot_number) ?? ''}` }))]"
                />
            </div>
        </div>

        <div>
            <Button variant="outline" size="sm" @click="reset">{{ t('Clear filters') }}</Button>
        </div>

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
                            <th class="px-3 py-3 font-medium">{{ t('Date') }}</th>
                            <th class="px-3 py-3 font-medium">{{ t('Reference') }}</th>
                            <th class="px-3 py-3 font-medium">{{ t('Type') }}</th>
                            <th class="px-3 py-3 font-medium">{{ t('Product') }}</th>
                            <th class="px-3 py-3 font-medium">{{ t('Whs') }}</th>
                            <th class="px-3 py-3 font-medium">{{ t('Lot') }}</th>
                            <th class="px-3 py-3 text-right font-medium">{{ t('In') }}</th>
                            <th class="px-3 py-3 text-right font-medium">{{ t('Out') }}</th>
                            <th class="px-3 py-3 font-medium">{{ t('UOM') }}</th>
                            <th class="px-3 py-3 text-right font-medium">{{ t('2nd') }}</th>
                            <th class="px-3 py-3 text-right font-medium">{{ t('Unit cost') }}</th>
                            <th class="px-3 py-3 text-right font-medium">{{ t('Total cost') }}</th>
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

            <div
                v-if="lines.last_page > 1"
                class="flex flex-col items-center justify-between gap-3 sm:flex-row"
            >
                <p class="text-muted-foreground text-sm">
                    {{ t('Showing :from–:to of :total', { from: lines.from ?? 0, to: lines.to ?? 0, total: lines.total }) }}
                </p>
                <div class="flex flex-wrap items-center gap-1">
                    <Button
                        v-for="link in lines.links"
                        :key="link.label"
                        :variant="link.active ? 'default' : 'outline'"
                        size="sm"
                        :disabled="!link.url"
                        @click="link.url && router.visit(link.url, { preserveState: true, preserveScroll: true })"
                        v-html="link.label"
                    />
                </div>
            </div>
        </template>

        <p class="text-muted-foreground max-w-3xl text-xs print:hidden">
            {{ t('Posted movements are permanent. Corrections are made by posting a reversal, which appears here as a separate entry referencing the original.') }}
        </p>
    </div>
</template>
