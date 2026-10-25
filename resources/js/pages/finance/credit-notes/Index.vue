<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import ListControls from '@/components/lists/ListControls.vue';
import ListPagination from '@/components/lists/ListPagination.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import { type ListControls as ListControlValues, useListFilters } from '@/composables/useListFilters';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usePermissions } from '@/composables/usePermissions';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';
import { create, index, show } from '@/routes/credit-notes';
import type { Paginated } from '@/types';

type CreditNoteRow = {
    id: number;
    credit_note_number: string;
    credit_date: string;
    customer: string | null;
    invoice_number: string | null;
    kind_label: string;
    amount: string;
    status: string;
    status_label: string;
};

const props = defineProps<{
    notes: Paginated<CreditNoteRow>;
    filters: { customer_id: number | null; search: string; status: string } & ListControlValues;
    statuses: { value: string; label: string }[];
    customers: { id: number; code: string; name: string }[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Credit notes', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();

const { filters, sortBy, clear } = useListFilters(() => index().url, {
    ...props.filters,
    customer_id: props.filters.customer_id ? String(props.filters.customer_id) : '',
});
</script>

<template>
    <Head :title="t('Credit notes')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading title="Credit notes" description="Discounts and quality allowances that reduce what a customer owes on an invoice. No goods come back — for that, record a return." />
            <Button v-if="can('finance.ar.credit_note_record')" as-child>
                <Link :href="create()"><Plus class="size-4" />{{ t('Give a discount or allowance') }}</Link>
            </Button>
        </div>

        <ListControls v-model:per-page="filters.per_page" v-model:from="filters.from" v-model:to="filters.to" @clear="clear">
            <div class="grid gap-1 text-xs w-full sm:w-56">
                <span class="text-muted-foreground">{{ t('Search') }}</span>
                <Input v-model="filters.search" type="search" :placeholder="t('Search number or customer')" />
            </div>
            <div class="grid gap-1 text-xs w-full sm:w-64">
                <span class="text-muted-foreground">{{ t('Customer') }}</span>
                <SearchableSelect v-model="filters.customer_id" :options="[{ value: '', label: t('All customers') }, ...customers.map((c) => ({ value: String(c.id), label: `${c.code} — ${c.name}` }))]" />
            </div>
            <div class="grid gap-1 text-xs w-full sm:w-48">
                <span class="text-muted-foreground">{{ t('Status') }}</span>
                <SearchableSelect v-model="filters.status" :options="[{ value: '', label: t('All statuses') }, ...statuses.map((s) => ({ value: s.value, label: s.label }))]" />
            </div>
        </ListControls>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <SortHeader label="Credit note" column="number" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Date" column="date" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Customer" column="customer" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Invoice" column="invoice" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Kind" column="kind" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Amount" column="amount" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy">{{ t('Amount (:currency)', { currency }) }}</SortHeader>
                        <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in notes.data" :key="row.id" class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t">
                        <td class="px-4 py-3"><Link :href="show(row.id)" class="font-mono hover:underline">{{ row.credit_note_number }}</Link></td>
                        <td class="px-4 py-3">{{ row.credit_date }}</td>
                        <td class="px-4 py-3">{{ row.customer }}</td>
                        <td class="px-4 py-3 font-mono">{{ row.invoice_number }}</td>
                        <td class="px-4 py-3">{{ row.kind_label }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.amount) }}</td>
                        <td class="px-4 py-3"><Badge :variant="row.status === 'REVERSED' ? 'destructive' : 'default'">{{ row.status_label }}</Badge></td>
                    </tr>
                    <tr v-if="notes.data.length === 0">
                        <td colspan="7" class="text-muted-foreground px-4 py-8 text-center">{{ t('No credit notes yet.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ListPagination :page="notes" />
    </div>
</template>
