<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { PackageMinus, PackagePlus, PackageX, Search } from '@lucide/vue';
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
import { create, index, show } from '@/routes/customer-bags';
import type { CustomerBagHolding, CustomerBagRow, Paginated } from '@/types';
import { useT } from '@/lib/i18n';

const props = defineProps<{
    holdings: CustomerBagHolding[];
    movements: Paginated<CustomerBagRow>;
    filters: { search: string; customer_id: number | null; kind: string } & ListControlValues;
    customers: { id: number; code: string; name: string }[];
    kinds: { value: string; label: string }[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Customer bags', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();

const { filters, sortBy, clear } = useListFilters(() => index().url, {
    ...props.filters,
    customer_id: props.filters.customer_id ? String(props.filters.customer_id) : '',
});

const kindVariant = (row: CustomerBagRow) => (row.kind === 'GIVEN' ? 'default' : row.kind === 'RETURNED' ? 'secondary' : 'destructive');
</script>

<template>
    <Head :title="t('Customer bags')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <Heading title="Customer bags" description="Bags given free with the goods stay yours until they come back. This shows who holds how many." />
            <div v-if="can('sales.bags_record')" class="flex flex-wrap gap-2">
                <Button as-child><Link :href="create({ query: { kind: 'GIVEN' } })"><PackagePlus class="size-4" />{{ t('Give bags') }}</Link></Button>
                <Button variant="outline" as-child><Link :href="create({ query: { kind: 'RETURNED' } })"><PackageMinus class="size-4" />{{ t('Bags returned') }}</Link></Button>
                <Button variant="outline" as-child><Link :href="create({ query: { kind: 'LOST' } })"><PackageX class="size-4" />{{ t('Mark bags lost') }}</Link></Button>
            </div>
        </div>

        <ListControls v-model:per-page="filters.per_page" v-model:from="filters.from" v-model:to="filters.to" @clear="clear">
            <div class="relative w-full sm:w-64">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input v-model="filters.search" type="search" :placeholder="t('Search record or customer…')" class="pl-9" :aria-label="t('Search bag records')" />
            </div>
            <SearchableSelect
                v-model="filters.customer_id"
                class="w-full sm:w-64"
                :aria-label="t('Filter by customer')"
                :options="[{ value: '', label: t('All customers') }, ...customers.map((c) => ({ value: String(c.id), label: `${c.code} — ${c.name}` }))]"
            />
            <SearchableSelect
                v-model="filters.kind"
                class="w-full sm:w-48"
                :aria-label="t('Filter by what happened')"
                :options="[{ value: '', label: t('Everything') }, ...kinds.map((k) => ({ value: k.value, label: k.label }))]"
            />
        </ListControls>

        <section class="space-y-2">
            <h2 class="text-sm font-medium">{{ t('Bags with customers now') }}</h2>
            <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ t('Customer') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Item') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Holding') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in holdings" :key="`${row.customer_id}-${row.product_code}`" class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                            <td class="px-4 py-3">
                                {{ row.customer_name }}
                                <span class="text-muted-foreground block font-mono text-xs">{{ row.customer_code }}</span>
                            </td>
                            <td class="px-4 py-3">{{ row.product_name }}</td>
                            <td class="px-4 py-3 text-right font-mono text-base font-medium">{{ Number(row.quantity) }}</td>
                        </tr>
                        <tr v-if="holdings.length === 0">
                            <td colspan="3" class="text-muted-foreground px-4 py-8 text-center">{{ t('No customer is holding bags.') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-2">
            <h2 class="text-sm font-medium">{{ t('Bag records') }}</h2>
            <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <SortHeader label="Record" column="number" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Date" column="date" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Customer" column="customer" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="What happened" column="kind" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Bags" column="quantity" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in movements.data" :key="row.id" class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t">
                            <td class="px-4 py-3"><Link :href="show(row.id)" class="font-mono hover:underline">{{ row.movement_number }}</Link></td>
                            <td class="px-4 py-3">{{ row.movement_date }}</td>
                            <td class="px-4 py-3">{{ row.customer_name }}</td>
                            <td class="px-4 py-3"><Badge :variant="kindVariant(row)">{{ row.kind_label }}</Badge></td>
                            <td class="px-4 py-3 text-right font-mono">{{ Number(row.quantity) }}</td>
                            <td class="px-4 py-3">
                                <Badge :variant="row.is_reversed ? 'destructive' : 'outline'">{{ row.status_label }}</Badge>
                            </td>
                        </tr>
                        <tr v-if="movements.data.length === 0">
                            <td colspan="6" class="text-muted-foreground px-4 py-8 text-center">{{ t('No bags recorded yet.') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <ListPagination :page="movements" />
    </div>
</template>
