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
import { index } from '@/routes/purchase-orders';
import type { OptionRef, Paginated, PurchaseOrderRow, StatusOption } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    orders: Paginated<PurchaseOrderRow>;
    filters: { search: string; status: string; supplier_id: number | null } & ListControlValues;
    suppliers: OptionRef[];
    statuses: StatusOption[];
}>();

const { t } = useT();

defineOptions({ layout: { breadcrumbs: [{ title: 'Purchase orders', href: index() }] } });

const { can } = usePermissions();

const { filters, sortBy, clear } = useListFilters(() => index().url, {
    ...props.filters,
    supplier_id: props.filters.supplier_id ? String(props.filters.supplier_id) : '',
});

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED') return 'destructive';
    if (value === 'RECEIVED') return 'default';
    if (value === 'DRAFT') return 'outline';
    return 'secondary';
}

</script>

<template>
    <Head :title="t('Purchase orders')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading title="Purchase orders" description="Intentions to buy. Stock appears only when a goods receipt is posted." />
            <Button v-if="can('purchasing.order_create')" as-child class="w-full sm:w-auto">
                <Link :href="`${index().url}/create`"><Plus class="size-4" />{{ t('New purchase order') }}</Link>
            </Button>
        </div>

        <ListControls v-model:per-page="filters.per_page" v-model:from="filters.from" v-model:to="filters.to" @clear="clear">
            <div class="relative w-full sm:w-72">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input v-model="filters.search" type="search" :placeholder="t('Search PO number or supplier…')" class="pl-9" :aria-label="t('Search purchase orders')" />
            </div>
            <SearchableSelect
                v-model="filters.status"
                class="w-full sm:w-48"
                :aria-label="t('Filter by status')"
                :options="[{ value: '', label: t('All statuses') }, ...statuses.map((s) => ({ value: s.value, label: s.label }))]"
            />
            <SearchableSelect
                v-model="filters.supplier_id"
                class="w-full sm:w-64"
                :aria-label="t('Filter by supplier')"
                :options="[{ value: '', label: t('All suppliers') }, ...suppliers.map((p) => ({ value: String(p.id), label: `${p.code} — ${p.name}` }))]"
            />
        </ListControls>

        <div v-if="orders.data.length === 0" class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center">
            <p class="font-medium">{{ t('No purchase orders found') }}</p>
            <p class="text-muted-foreground text-sm">{{ t('Create one to start ordering from a supplier.') }}</p>
        </div>

        <template v-else>
            <div class="border-sidebar-border/70 dark:border-sidebar-border hidden overflow-x-auto rounded-xl border md:block">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <SortHeader label="PO No" column="number" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Supplier" column="supplier" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Warehouse" column="warehouse" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Date" column="date" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Total" column="total" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <th class="px-4 py-3 text-right font-medium">{{ t('Received') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="order in orders.data" :key="order.id" class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t">
                            <td class="px-4 py-3 font-mono text-xs font-medium">
                                <Link :href="`${index().url}/${order.id}`" class="hover:underline">{{ order.po_number }}</Link>
                            </td>
                            <td class="px-4 py-3">{{ order.supplier_code }}<span class="text-muted-foreground block text-xs">{{ order.supplier_name }}</span></td>
                            <td class="px-4 py-3">{{ order.warehouse_code }}</td>
                            <td class="px-4 py-3">{{ order.order_date }}</td>
                            <td class="px-4 py-3"><Badge :variant="badgeVariant(order.status)">{{ order.status_label }}</Badge></td>
                            <td class="px-4 py-3 text-right font-mono">{{ money(order.material_total) }}</td>
                            <td class="px-4 py-3 text-right font-mono text-xs">{{ order.received_quantity }} / {{ order.ordered_quantity }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="grid gap-3 md:hidden">
                <Link v-for="order in orders.data" :key="`m-${order.id}`" :href="`${index().url}/${order.id}`" class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-mono text-xs font-medium">{{ order.po_number }}</p>
                            <p class="mt-1 truncate font-medium">{{ order.supplier_name }}</p>
                        </div>
                        <Badge :variant="badgeVariant(order.status)">{{ order.status_label }}</Badge>
                    </div>
                    <dl class="text-muted-foreground mt-3 grid grid-cols-2 gap-2 text-xs">
                        <div><dt class="font-medium">{{ t('Date') }}</dt><dd>{{ order.order_date }}</dd></div>
                        <div><dt class="font-medium">{{ t('Total') }}</dt><dd class="font-mono">{{ money(order.material_total) }}</dd></div>
                        <div><dt class="font-medium">{{ t('Received') }}</dt><dd class="font-mono">{{ order.received_quantity }} / {{ order.ordered_quantity }}</dd></div>
                        <div><dt class="font-medium">{{ t('Remaining') }}</dt><dd class="font-mono">{{ order.remaining_quantity }}</dd></div>
                    </dl>
                </Link>
            </div>
        </template>

        <ListPagination :page="orders" />
    </div>
</template>
