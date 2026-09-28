<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Search, X } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usePermissions } from '@/composables/usePermissions';
import { useT } from '@/lib/i18n';
import { index } from '@/routes/sales-orders';
import type { OptionRef, Paginated, SalesOrderRow, StatusOption } from '@/types';

const { t } = useT();

const props = defineProps<{
    orders: Paginated<SalesOrderRow>;
    filters: { search: string; status: string; customer_id: number | null };
    customers: OptionRef[];
    statuses: StatusOption[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Sales orders', href: index() }] } });

const { can } = usePermissions();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const customerId = ref(props.filters.customer_id ? String(props.filters.customer_id) : '');

let debounce: ReturnType<typeof setTimeout> | undefined;

function applyFilters(immediate = false): void {
    clearTimeout(debounce);
    const run = () =>
        router.get(
            index().url,
            {
                search: search.value || undefined,
                status: status.value || undefined,
                customer_id: customerId.value || undefined,
            },
            { preserveState: true, replace: true, preserveScroll: true },
        );
    if (immediate) { run(); return; }
    debounce = setTimeout(run, 300);
}

watch(search, () => applyFilters());
watch([status, customerId], () => applyFilters(true));

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'DELIVERED') return 'default';
    if (value === 'DRAFT') return 'outline';
    return 'secondary';
}

const controlClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
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

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div class="relative">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input v-model="search" type="search" :placeholder="t('Search SO number or customer…')" class="pl-9" :aria-label="t('Search orders')" />
                <button v-if="search" type="button" class="text-muted-foreground hover:text-foreground absolute top-1/2 right-3 -translate-y-1/2" :aria-label="t('Clear search')" @click="search = ''; applyFilters(true)">
                    <X class="size-4" />
                </button>
            </div>
            <select v-model="status" :class="controlClass" :aria-label="t('Filter by status')">
                <option value="">{{ t('All statuses') }}</option>
                <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
            <select v-model="customerId" :class="controlClass" :aria-label="t('Filter by customer')">
                <option value="">{{ t('All customers') }}</option>
                <option v-for="c in customers" :key="c.id" :value="String(c.id)">{{ c.code }} — {{ c.name }}</option>
            </select>
        </div>

        <div v-if="orders.data.length === 0" class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center">
            <p class="font-medium">{{ t('No orders found') }}</p>
            <p class="text-muted-foreground text-sm">{{ t('Create one to start selling to a customer.') }}</p>
        </div>

        <template v-else>
            <div class="border-sidebar-border/70 dark:border-sidebar-border hidden overflow-x-auto rounded-xl border md:block">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ t('SO No') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Customer') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Whs') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Date') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Status') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Total') }}</th>
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
                            <td class="px-4 py-3 text-right font-mono">{{ row.sales_total }}</td>
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
                        <div><dt class="font-medium">{{ t('Total') }}</dt><dd class="font-mono">{{ row.sales_total }}</dd></div>
                        <div><dt class="font-medium">{{ t('Delivered') }}</dt><dd class="font-mono">{{ row.delivered_quantity }} / {{ row.ordered_quantity }}</dd></div>
                        <div><dt class="font-medium">{{ t('Remaining') }}</dt><dd class="font-mono">{{ row.remaining_quantity }}</dd></div>
                    </dl>
                </Link>
            </div>

            <div v-if="orders.last_page > 1" class="flex flex-col items-center justify-between gap-3 sm:flex-row">
                <p class="text-muted-foreground text-sm">{{ t('Showing :from–:to of :total', { from: orders.from ?? 0, to: orders.to ?? 0, total: orders.total }) }}</p>
                <div class="flex flex-wrap items-center gap-1">
                    <Button v-for="link in orders.links" :key="link.label" :variant="link.active ? 'default' : 'outline'" size="sm" :disabled="!link.url" @click="link.url && router.visit(link.url, { preserveState: true, preserveScroll: true })" v-html="link.label" />
                </div>
            </div>
        </template>
    </div>
</template>
