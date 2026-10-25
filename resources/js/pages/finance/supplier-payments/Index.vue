<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import ListControls from '@/components/lists/ListControls.vue';
import ListPagination from '@/components/lists/ListPagination.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import { type ListControls as ListControlValues, useListFilters } from '@/composables/useListFilters';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { usePermissions } from '@/composables/usePermissions';
import { index } from '@/routes/supplier-payments';
import type { OptionRef, Paginated, StatusOption, SupplierPaymentRow } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    payments: Paginated<SupplierPaymentRow>;
    filters: { search: string; status: string; supplier_id: number | null } & ListControlValues;
    statuses: StatusOption[];
    suppliers: OptionRef[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Supplier payments', href: index() }] } });

const { can } = usePermissions();

const { filters, sortBy, clear } = useListFilters(() => index().url, {
    ...props.filters,
    supplier_id: props.filters.supplier_id ? String(props.filters.supplier_id) : '',
});

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'POSTED') return 'default';
    return 'outline';
}


const { t } = useT();
</script>

<template>
    <Head :title="t('Supplier payments')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading
                title="Supplier payments"
                :description="t('Money paid to suppliers, in :currency. Every payment is allocated to invoices.', { currency })"
            />
            <Button v-if="can('finance.ap.payment_create')" as-child class="w-full sm:w-auto">
                <Link :href="`${index().url}/create`"><Plus class="size-4" />{{ t('New payment') }}</Link>
            </Button>
        </div>

        <ListControls v-model:per-page="filters.per_page" v-model:from="filters.from" v-model:to="filters.to" @clear="clear">
            <div class="grid gap-1 text-xs w-full sm:w-56">
                <span class="text-muted-foreground">{{ t('Search') }}</span>
                <Input v-model="filters.search" type="search" :placeholder="t('Search number or reference')" />
            </div>
            <div class="grid gap-1 text-xs w-full sm:w-64">
                <span class="text-muted-foreground">{{ t('Supplier') }}</span>
                <SearchableSelect v-model="filters.supplier_id" :options="[{ value: '', label: t('All suppliers') }, ...suppliers.map((c) => ({ value: String(c.id), label: `${c.code} — ${c.name}` }))]" />
            </div>
            <div class="grid gap-1 text-xs w-full sm:w-48">
                <span class="text-muted-foreground">{{ t('Status') }}</span>
                <SearchableSelect v-model="filters.status" :options="[{ value: '', label: t('All statuses') }, ...statuses.map((s) => ({ value: s.value, label: s.label }))]" />
            </div>
        </ListControls>

        <div
            v-if="payments.data.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">{{ t('No supplier payments found') }}</p>
        </div>

        <div v-else class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <SortHeader label="Payment No" column="number" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Supplier" column="supplier" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Date" column="date" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Paid from" column="account" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Reference" column="reference" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Amount" column="amount" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in payments.data"
                        :key="row.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t"
                    >
                        <td class="px-4 py-3 font-mono text-xs font-medium">
                            <Link :href="`${index().url}/${row.id}`" class="hover:underline">
                                {{ row.payment_number }}
                            </Link>
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-mono text-xs">{{ row.supplier_code }}</span>
                            <span class="text-muted-foreground block text-xs">{{ row.supplier_name }}</span>
                        </td>
                        <td class="px-4 py-3">{{ row.payment_date }}</td>
                        <td class="text-muted-foreground px-4 py-3 text-xs">{{ row.payment_account }}</td>
                        <td class="text-muted-foreground px-4 py-3 text-xs">{{ row.reference ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <Badge :variant="badgeVariant(row.status)">{{ row.status_label }}</Badge>
                        </td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(row.amount) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ListPagination :page="payments" />
    </div>
</template>
