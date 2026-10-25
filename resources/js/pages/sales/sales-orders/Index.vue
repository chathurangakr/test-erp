<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus, Search } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import ListControls from '@/components/lists/ListControls.vue';
import ListPagination from '@/components/lists/ListPagination.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { type ListControls as ListControlValues, useListFilters } from '@/composables/useListFilters';
import { usePermissions } from '@/composables/usePermissions';
import { useT } from '@/lib/i18n';
import { index } from '@/routes/sales-orders';
import type { OptionRef, Paginated, SalesOrderRow, StatusOption } from '@/types';
import { money } from '@/lib/money';

const { t } = useT();

const props = defineProps<{
    orders: Paginated<SalesOrderRow>;
    filters: { search: string; status: string; customer_id: number | null } & ListControlValues;
    customers: OptionRef[];
    statuses: StatusOption[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Sales orders', href: index() }] } });

const { can } = usePermissions();

const { filters, sortBy, clear } = useListFilters(() => index().url, {
    ...props.filters,
    customer_id: props.filters.customer_id ? String(props.filters.customer_id) : '',
});

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'DELIVERED') return 'default';
    if (value === 'DRAFT') return 'outline';
    return 'secondary';
}

</script>

<template>
    <Head :title="t('Sales orders')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading title="Sales orders" description="Intentions to sell. Stock leaves only when a delivery is posted." />
            <Button v-if="can('sales.order_create')" as-child class="w-full sm:w-auto">
                <Link :href="`${index().url}/create`"><Plus class="size-4" />{{ t('New sales order') }}</Link>
            </Button>
        </div>

        <ListControls v-model:per-page="filters.per_page" v-model:from="filters.from" v-model:to="filters.to" @clear="clear">
            <div class="relative w-full sm:w-72">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input v-model="filters.search" type="search" :placeholder="t('Search SO number or customer…')" class="pl-9" :aria-label="t('Search orders')" />
            </div>
            <SearchableSelect
                v-model="filters.status"
                class="w-full sm:w-48"
                :aria-label="t('Filter by status')"
                :options="[{ value: '', label: t('All statuses') }, ...statuses.map((s) => ({ value: s.value, label: s.label }))]"
            />
            <SearchableSelect
                v-model="filters.customer_id"
                class="w-full sm:w-64"
                :aria-label="t('Filter by customer')"
                :options="[{ value: '', label: t('All customers') }, ...customers.map((p) => ({ value: String(p.id), label: `${p.code} — ${p.name}` }))]"
            />
        </ListControls>

        <div v-if="orders.data.length === 0" class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center">
            <p class="font-medium">{{ t('No orders found') }}</p>
            <p class="text-muted-foreground text-sm">{{ t('Create one to start selling to a customer.') }}</p>
        </div>

        <template v-else>
            <div class="border-sidebar-border/70 dark:border-sidebar-border hidden overflow-x-auto rounded-xl border md:block">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <SortHeader label="SO No" column="number" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Customer" column="customer" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Whs" column="warehouse" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Date" column="date" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Total" column="total" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <th class="px-4 py-3 text-right font-medium">{{ t('Delivered') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in orders.data" :key="row.id" class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t">
                            <td class="px-4 py-3 font-mono text-xs font-medium">
                                <Link :href="`${index().url}/${row.id}`" class="hover:underline">{{ row.so_number }}</Link>
                            </td>
                            <td class="px-4 py-3">{{ row.customer_code }}<span class="text-muted-foreground block text-xs">{{ row.customer_name }}</span></td>
                            <td class="px-4 py-3">{{ row.warehouse_code }}</td>
                            <td class="px-4 py-3">{{ row.order_date }}</td>
                            <td class="px-4 py-3"><Badge :variant="badgeVariant(row.status)">{{ row.status_label }}</Badge></td>
                            <td class="px-4 py-3 text-right font-mono">{{ money(row.sales_total) }}</td>
                            <td class="px-4 py-3 text-right font-mono text-xs">{{ row.delivered_quantity }} / {{ row.ordered_quantity }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="grid gap-3 md:hidden">
                <Link v-for="row in orders.data" :key="`m-${row.id}`" :href="`${index().url}/${row.id}`" class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-mono text-xs font-medium">{{ row.so_number }}</p>
                            <p class="mt-1 truncate font-medium">{{ row.customer_name }}</p>
                        </div>
                        <Badge :variant="badgeVariant(row.status)">{{ row.status_label }}</Badge>
                    </div>
                    <dl class="text-muted-foreground mt-3 grid grid-cols-2 gap-2 text-xs">
                        <div><dt class="font-medium">{{ t('Date') }}</dt><dd>{{ row.order_date }}</dd></div>
                        <div><dt class="font-medium">{{ t('Total') }}</dt><dd class="font-mono">{{ money(row.sales_total) }}</dd></div>
                        <div><dt class="font-medium">{{ t('Delivered') }}</dt><dd class="font-mono">{{ row.delivered_quantity }} / {{ row.ordered_quantity }}</dd></div>
                        <div><dt class="font-medium">{{ t('Remaining') }}</dt><dd class="font-mono">{{ row.remaining_quantity }}</dd></div>
                    </dl>
                </Link>
            </div>
        </template>

        <ListPagination :page="orders" />
    </div>
</template>
