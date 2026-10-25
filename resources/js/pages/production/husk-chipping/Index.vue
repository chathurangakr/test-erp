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
import { index } from '@/routes/husk-chipping';
import type { HuskChippingRow, OptionRef, Paginated, StatusOption } from '@/types';
import { money } from '@/lib/money';

const { t } = useT();

const props = defineProps<{
    orders: Paginated<HuskChippingRow>;
    filters: { search: string; status: string; warehouse_id: number | null } & ListControlValues;
    warehouses: OptionRef[];
    statuses: StatusOption[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Husk chipping', href: index() }] } });

const { can } = usePermissions();

const { filters, sortBy, clear } = useListFilters(() => index().url, {
    ...props.filters,
    warehouse_id: props.filters.warehouse_id ? String(props.filters.warehouse_id) : '',
});

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'POSTED') return 'default';
    return 'outline';
}

</script>

<template>
    <Head :title="t('Husk chipping')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading
                title="Husk chipping"
                description="Raw husk is chipped into 7C, 9C and 10C. The operator enters the weights actually measured."
            />
            <Button v-if="can('production.create')" as-child class="w-full sm:w-auto">
                <Link :href="`${index().url}/create`"><Plus class="size-4" />{{ t('New husk chipping') }}</Link>
            </Button>
        </div>

        <ListControls v-model:per-page="filters.per_page" v-model:from="filters.from" v-model:to="filters.to" @clear="clear">
            <div class="relative w-full sm:w-56">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input v-model="filters.search" type="search" :placeholder="t('Search production number…')" class="pl-9" :aria-label="t('Search production runs')" />
            </div>
            <SearchableSelect
                v-model="filters.status"
                class="w-full sm:w-48"
                :aria-label="t('Filter by status')"
                :options="[{ value: '', label: t('All statuses') }, ...statuses.map((s) => ({ value: s.value, label: s.label }))]"
            />
            <SearchableSelect
                v-model="filters.warehouse_id"
                class="w-full sm:w-48"
                :aria-label="t('Filter by warehouse')"
                :options="[{ value: '', label: t('All warehouses') }, ...warehouses.map((w) => ({ value: String(w.id), label: w.code }))]"
            />
        </ListControls>

        <div
            v-if="orders.data.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">{{ t('No husk chipping runs found') }}</p>
            <p class="text-muted-foreground text-sm">{{ t('Create one to chip raw husk into 7C, 9C and 10C.') }}</p>
        </div>

        <template v-else>
            <div class="border-sidebar-border/70 dark:border-sidebar-border hidden overflow-x-auto rounded-xl border md:block">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <SortHeader label="Production No" column="number" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Date" column="date" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Warehouse" column="warehouse" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Husk in (NOS)" column="input_quantity" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <th class="px-4 py-3 text-right font-medium">{{ t('Output') }}</th>
                            <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Input value" column="input_value" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Output value" column="output_value" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in orders.data"
                            :key="row.id"
                            class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t"
                        >
                            <td class="px-4 py-3 font-mono text-xs font-medium">
                                <Link :href="`${index().url}/${row.id}`" class="hover:underline">
                                    {{ row.production_number }}
                                </Link>
                            </td>
                            <td class="px-4 py-3">{{ row.production_date }}</td>
                            <td class="px-4 py-3">{{ row.warehouse_code }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ row.input_quantity }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ row.output_quantity }}</td>
                            <td class="px-4 py-3">
                                <Badge :variant="badgeVariant(row.status)">{{ row.status_label }}</Badge>
                            </td>
                            <td class="px-4 py-3 text-right font-mono">{{ money(row.input_value) }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ money(row.output_value) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="grid gap-3 md:hidden">
                <Link
                    v-for="row in orders.data"
                    :key="`m-${row.id}`"
                    :href="`${index().url}/${row.id}`"
                    class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-mono text-xs font-medium">{{ row.production_number }}</p>
                            <p class="text-muted-foreground mt-0.5 text-xs">{{ row.production_date }}</p>
                        </div>
                        <Badge :variant="badgeVariant(row.status)">{{ row.status_label }}</Badge>
                    </div>
                    <dl class="text-muted-foreground mt-3 grid grid-cols-2 gap-2 text-xs">
                        <div><dt class="font-medium">{{ t('Husk in') }}</dt><dd class="font-mono">{{ row.input_quantity }}</dd></div>
                        <div><dt class="font-medium">{{ t('Chips out') }}</dt><dd class="font-mono">{{ row.output_quantity }}</dd></div>
                        <div><dt class="font-medium">{{ t('Input value') }}</dt><dd class="font-mono">{{ money(row.input_value) }}</dd></div>
                        <div><dt class="font-medium">{{ t('Output value') }}</dt><dd class="font-mono">{{ money(row.output_value) }}</dd></div>
                    </dl>
                </Link>
            </div>
        </template>

        <ListPagination :page="orders" />
    </div>
</template>
