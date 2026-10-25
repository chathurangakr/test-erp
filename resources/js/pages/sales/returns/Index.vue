<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus, Search } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import ListControls from '@/components/lists/ListControls.vue';
import ListPagination from '@/components/lists/ListPagination.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { type ListControls as ListControlValues, useListFilters } from '@/composables/useListFilters';
import { usePermissions } from '@/composables/usePermissions';
import { create, index, show } from '@/routes/sales-returns';
import type { Paginated, SalesReturnRow } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    returns: Paginated<SalesReturnRow>;
    filters: { search: string; customer_id: number | null; settlement: string } & ListControlValues;
    customers: { id: number; code: string; name: string }[];
    settlements: { value: string; label: string }[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Returns', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();

const { filters, sortBy, clear } = useListFilters(() => index().url, {
    ...props.filters,
    customer_id: props.filters.customer_id ? String(props.filters.customer_id) : '',
});
</script>

<template>
    <Head :title="t('Returns')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading title="Returns" description="Goods customers brought back. They are credited at the price charged; good goods go back into stock and damaged ones are written off." />
            <Button v-if="can('sales.return_record')" as-child>
                <Link :href="create()"><Plus class="size-4" />{{ t('Record a return') }}</Link>
            </Button>
        </div>

        <ListControls v-model:per-page="filters.per_page" v-model:from="filters.from" v-model:to="filters.to" @clear="clear">
            <div class="relative w-full sm:w-72">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input v-model="filters.search" type="search" :placeholder="t('Search return, delivery, customer or product…')" class="pl-9" :aria-label="t('Search returns')" />
            </div>
            <SearchableSelect
                v-model="filters.customer_id"
                class="w-full sm:w-64"
                :aria-label="t('Filter by customer')"
                :options="[{ value: '', label: t('All customers') }, ...customers.map((c) => ({ value: String(c.id), label: `${c.code} — ${c.name}` }))]"
            />
            <SearchableSelect
                v-model="filters.settlement"
                class="w-full sm:w-48"
                :aria-label="t('Filter by settlement')"
                :options="[{ value: '', label: t('Any settlement') }, ...settlements.map((o) => ({ value: o.value, label: o.label }))]"
            />
        </ListControls>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <SortHeader label="Return" column="number" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Date" column="date" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Customer" column="customer" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Product" column="product" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Quantity" column="quantity" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Credit" column="credit" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy">{{ t('Credit (:currency)', { currency }) }}</SortHeader>
                        <SortHeader label="Settled by" column="settlement" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in returns.data" :key="row.id" class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t">
                        <td class="px-4 py-3">
                            <Link :href="show(row.id)" class="font-mono hover:underline">{{ row.return_number }}</Link>
                            <span class="text-muted-foreground block font-mono text-xs">{{ row.delivery_number }}</span>
                        </td>
                        <td class="px-4 py-3">{{ row.return_date }}</td>
                        <td class="px-4 py-3">{{ row.customer_name }}</td>
                        <td class="px-4 py-3">{{ row.product_name }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ Number(row.quantity) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.credit_amount) }}</td>
                        <td class="px-4 py-3">{{ row.settlement_label }}</td>
                        <td class="px-4 py-3"><Badge :variant="row.is_reversed ? 'destructive' : 'default'">{{ row.status_label }}</Badge></td>
                    </tr>
                    <tr v-if="returns.data.length === 0">
                        <td colspan="8" class="text-muted-foreground px-4 py-8 text-center">{{ t('No returns recorded yet.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ListPagination :page="returns" />
    </div>
</template>
