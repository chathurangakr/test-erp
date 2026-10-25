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
import { index } from '@/routes/sales-invoices';
import type { OptionRef, Paginated, SalesInvoiceRow, StatusOption } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    invoices: Paginated<SalesInvoiceRow>;
    filters: { search: string; status: string; customer_id: number | null; overdue: boolean } & ListControlValues;
    statuses: StatusOption[];
    customers: OptionRef[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Sales invoices', href: index() }] } });

const { can } = usePermissions();

const { filters, sortBy, clear } = useListFilters(() => index().url, {
    ...props.filters,
    customer_id: props.filters.customer_id ? String(props.filters.customer_id) : '',
    overdue: props.filters.overdue ? '1' : '',
});

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'PAID') return 'secondary';
    if (value === 'POSTED' || value === 'PARTIALLY_PAID') return 'default';
    return 'outline';
}


const { t } = useT();
</script>

<template>
    <Head :title="t('Sales invoices')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading
                title="Sales invoices"
                :description="t('What customers have been billed, in :currency. Revenue is recognised when an invoice is posted.', { currency })"
            />
            <Button v-if="can('finance.ar.invoice_create')" as-child class="w-full sm:w-auto">
                <Link :href="`${index().url}/create`"><Plus class="size-4" />{{ t('New sales invoice') }}</Link>
            </Button>
        </div>

        <ListControls v-model:per-page="filters.per_page" v-model:from="filters.from" v-model:to="filters.to" period-label="Invoice date" @clear="clear">
            <div class="grid gap-1 text-xs w-full sm:w-56">
                <span class="text-muted-foreground">{{ t('Search') }}</span>
                <Input v-model="filters.search" type="search" :placeholder="t('Search number or reference')" />
            </div>
            <div class="grid gap-1 text-xs w-full sm:w-64">
                <span class="text-muted-foreground">{{ t('Customer') }}</span>
                <SearchableSelect v-model="filters.customer_id" :options="[{ value: '', label: t('All customers') }, ...customers.map((c) => ({ value: String(c.id), label: `${c.code} — ${c.name}` }))]" />
            </div>
            <div class="grid gap-1 text-xs w-full sm:w-48">
                <span class="text-muted-foreground">{{ t('Status') }}</span>
                <SearchableSelect v-model="filters.status" :options="[{ value: '', label: t('All statuses') }, ...statuses.map((s) => ({ value: s.value, label: s.label }))]" />
            </div>
            <label class="flex h-9 items-center gap-2 text-sm">
                <input type="checkbox" :checked="filters.overdue === '1'" @change="filters.overdue = ($event.target as HTMLInputElement).checked ? '1' : ''" />
                {{ t('Overdue only') }}
            </label>
        </ListControls>

        <div
            v-if="invoices.data.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">{{ t('No sales invoices found') }}</p>
            <p class="text-muted-foreground text-sm">{{ t('Raise one against a posted delivery to recognise its revenue.') }}</p>
        </div>

        <div v-else class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <SortHeader label="Invoice No" column="number" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Customer" column="customer" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Invoice date" column="date" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Due" column="due" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Total" column="total" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <th class="px-4 py-3 text-right font-medium">{{ t('Received') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Outstanding') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in invoices.data"
                        :key="row.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t"
                    >
                        <td class="px-4 py-3 font-mono text-xs font-medium">
                            <Link :href="`${index().url}/${row.id}`" class="hover:underline">
                                {{ row.invoice_number }}
                            </Link>
                            <span v-if="row.customer_reference" class="text-muted-foreground block">
                                {{ row.customer_reference }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-mono text-xs">{{ row.customer_code }}</span>
                            <span class="text-muted-foreground block text-xs">{{ row.customer_name }}</span>
                        </td>
                        <td class="px-4 py-3">{{ row.invoice_date }}</td>
                        <td class="px-4 py-3">
                            {{ row.due_date }}
                            <span v-if="row.is_overdue" class="text-destructive block text-xs">{{ t('Overdue') }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="badgeVariant(row.status)">{{ row.status_label }}</Badge>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.total) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.received) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(row.outstanding) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ListPagination :page="invoices" />
    </div>
</template>
