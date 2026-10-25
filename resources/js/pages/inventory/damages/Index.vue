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
import { create, index, show } from '@/routes/stock-damages';
import type { Paginated, StockDamageRow } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    damages: Paginated<StockDamageRow>;
    filters: { product_id: number | null; search: string; status: string } & ListControlValues;
    statuses: { value: string; label: string }[];
    products: { id: number; code: string; name: string }[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Damaged stock', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();

const { filters, sortBy, clear } = useListFilters(() => index().url, {
    ...props.filters,
    product_id: props.filters.product_id ? String(props.filters.product_id) : '',
});
</script>

<template>
    <Head :title="t('Damaged stock')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading title="Damaged stock" description="Stock thrown away because it was damaged — torn bags, spoiled coconuts. Each write-off takes the stock out and records its value as a loss." />
            <Button v-if="can('inventory.damage_record')" as-child>
                <Link :href="create()"><Plus class="size-4" />{{ t('Write off damaged stock') }}</Link>
            </Button>
        </div>

        <ListControls v-model:per-page="filters.per_page" v-model:from="filters.from" v-model:to="filters.to" @clear="clear">
            <div class="relative w-full sm:w-64">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input v-model="filters.search" type="search" :placeholder="t('Search number or reason…')" class="pl-9" :aria-label="t('Search write-offs')" />
            </div>
            <SearchableSelect
                v-model="filters.product_id"
                class="w-full sm:w-64"
                :options="[{ value: '', label: t('All products') }, ...products.map((p) => ({ value: String(p.id), label: `${p.code} — ${p.name}` }))]"
            />
            <SearchableSelect
                v-model="filters.status"
                class="w-full sm:w-44"
                :aria-label="t('Filter by status')"
                :options="[{ value: '', label: t('All statuses') }, ...statuses]"
            />
        </ListControls>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <SortHeader label="Write-off" column="number" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Date" column="date" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <th class="px-4 py-3 font-medium">{{ t('Products') }}</th>
                        <SortHeader label="Reason" column="reason" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Value lost" column="value" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy">{{ t('Value lost (:currency)', { currency }) }}</SortHeader>
                        <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="damage in damages.data" :key="damage.id" class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t">
                        <td class="px-4 py-3"><Link :href="show(damage.id)" class="font-mono hover:underline">{{ damage.damage_number }}</Link></td>
                        <td class="px-4 py-3">{{ damage.damage_date }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ damage.products.join(', ') }}</td>
                        <td class="px-4 py-3">{{ damage.reason }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(damage.total_cost) }}</td>
                        <td class="px-4 py-3">
                            <Badge :variant="damage.is_reversed ? 'destructive' : 'default'">{{ damage.status_label }}</Badge>
                        </td>
                    </tr>
                    <tr v-if="damages.data.length === 0">
                        <td colspan="6" class="text-muted-foreground px-4 py-8 text-center">{{ t('No damaged stock written off yet.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ListPagination :page="damages" />
    </div>
</template>
