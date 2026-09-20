<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Search, X } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index } from '@/routes/deliveries';
import type { OptionRef, Paginated, DeliveryRow, StatusOption } from '@/types';

const props = defineProps<{
    deliveries: Paginated<DeliveryRow>;
    filters: { search: string; status: string; customer_id: number | null };
    customers: OptionRef[];
    statuses: StatusOption[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Deliveries', href: index() }] } });

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
    if (value === 'POSTED') return 'default';
    if (value === 'DRAFT') return 'outline';
    return 'secondary';
}

const controlClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
</script>

<template>
    <Head title="Deliveries" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading title="Deliveries" description="Dispatch notes. Stock leaves the warehouse only when a delivery is posted." />
            <Button as-child class="w-full sm:w-auto">
                <Link :href="`${index().url}/create`"><Plus class="size-4" />New delivery</Link>
            </Button>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div class="relative">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input v-model="search" type="search" placeholder="Search delivery number, customer or vehicle…" class="pl-9" aria-label="Search deliveries" />
                <button v-if="search" type="button" class="text-muted-foreground hover:text-foreground absolute top-1/2 right-3 -translate-y-1/2" aria-label="Clear search" @click="search = ''; applyFilters(true)">
                    <X class="size-4" />
                </button>
            </div>
            <select v-model="status" :class="controlClass" aria-label="Filter by status">
                <option value="">All statuses</option>
                <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
            <select v-model="customerId" :class="controlClass" aria-label="Filter by customer">
                <option value="">All customers</option>
                <option v-for="c in customers" :key="c.id" :value="String(c.id)">{{ c.code }} — {{ c.name }}</option>
            </select>
        </div>

        <div v-if="deliveries.data.length === 0" class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center">
            <p class="font-medium">No deliveries found</p>
            <p class="text-muted-foreground text-sm">Deliveries can be raised against a sales order, or directly.</p>
        </div>

        <template v-else>
            <div class="border-sidebar-border/70 dark:border-sidebar-border hidden overflow-x-auto rounded-xl border md:block">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Delivery No</th>
                            <th class="px-4 py-3 font-medium">Customer</th>
                            <th class="px-4 py-3 font-medium">SO</th>
                            <th class="px-4 py-3 font-medium">Whs</th>
                            <th class="px-4 py-3 font-medium">Date</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 text-right font-medium">Sales</th>
                            <th class="px-4 py-3 text-right font-medium">COGS</th>
                            <th class="px-4 py-3 text-right font-medium">Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in deliveries.data" :key="row.id" class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t">
                            <td class="px-4 py-3 font-mono text-xs font-medium">
                                <Link :href="`${index().url}/${row.id}`" class="hover:underline">{{ row.delivery_number }}</Link>
                            </td>
                            <td class="px-4 py-3">{{ row.customer_code }}<span class="text-muted-foreground block text-xs">{{ row.customer_name }}</span></td>
                            <td class="text-muted-foreground px-4 py-3 font-mono text-xs">{{ row.so_number ?? '—' }}</td>
                            <td class="px-4 py-3">{{ row.warehouse_code }}</td>
                            <td class="px-4 py-3">{{ row.delivery_date }}</td>
                            <td class="px-4 py-3"><Badge :variant="badgeVariant(row.status)">{{ row.status_label }}</Badge></td>
                            <td class="px-4 py-3 text-right font-mono">{{ row.sales_total }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ row.cogs_total ?? '—' }}</td>
                            <td class="px-4 py-3 text-right font-mono font-medium">{{ row.gross_profit ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="grid gap-3 md:hidden">
                <Link v-for="row in deliveries.data" :key="`m-${row.id}`" :href="`${index().url}/${row.id}`" class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-mono text-xs font-medium">{{ row.delivery_number }}</p>
                            <p class="mt-1 truncate font-medium">{{ row.customer_name }}</p>
                        </div>
                        <Badge :variant="badgeVariant(row.status)">{{ row.status_label }}</Badge>
                    </div>
                    <dl class="text-muted-foreground mt-3 grid grid-cols-2 gap-2 text-xs">
                        <div><dt class="font-medium">Date</dt><dd>{{ row.delivery_date }}</dd></div>
                        <div><dt class="font-medium">Sales</dt><dd class="font-mono">{{ row.sales_total }}</dd></div>
                        <div><dt class="font-medium">COGS</dt><dd class="font-mono">{{ row.cogs_total ?? '—' }}</dd></div>
                        <div><dt class="font-medium">Profit</dt><dd class="font-mono">{{ row.gross_profit ?? '—' }}</dd></div>
                    </dl>
                </Link>
            </div>

            <div v-if="deliveries.last_page > 1" class="flex flex-col items-center justify-between gap-3 sm:flex-row">
                <p class="text-muted-foreground text-sm">Showing {{ deliveries.from }}–{{ deliveries.to }} of {{ deliveries.total }}</p>
                <div class="flex flex-wrap items-center gap-1">
                    <Button v-for="link in deliveries.links" :key="link.label" :variant="link.active ? 'default' : 'outline'" size="sm" :disabled="!link.url" @click="link.url && router.visit(link.url, { preserveState: true, preserveScroll: true })" v-html="link.label" />
                </div>
            </div>
        </template>
    </div>
</template>
