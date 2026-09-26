<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Search, X } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usePermissions } from '@/composables/usePermissions';
import { index } from '@/routes/purchase-orders';
import type { OptionRef, Paginated, PurchaseOrderRow, StatusOption } from '@/types';

const props = defineProps<{
    orders: Paginated<PurchaseOrderRow>;
    filters: { search: string; status: string; supplier_id: number | null };
    suppliers: OptionRef[];
    statuses: StatusOption[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Purchase orders', href: index() }] } });

const { can } = usePermissions();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const supplierId = ref(props.filters.supplier_id ? String(props.filters.supplier_id) : '');

let debounce: ReturnType<typeof setTimeout> | undefined;

function applyFilters(immediate = false): void {
    clearTimeout(debounce);
    const run = () =>
        router.get(
            index().url,
            {
                search: search.value || undefined,
                status: status.value || undefined,
                supplier_id: supplierId.value || undefined,
            },
            { preserveState: true, replace: true, preserveScroll: true },
        );
    if (immediate) { run(); return; }
    debounce = setTimeout(run, 300);
}

watch(search, () => applyFilters());
watch([status, supplierId], () => applyFilters(true));

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED') return 'destructive';
    if (value === 'RECEIVED') return 'default';
    if (value === 'DRAFT') return 'outline';
    return 'secondary';
}

const controlClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
</script>

<template>
    <Head title="Purchase orders" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading title="Purchase orders" description="Intentions to buy. Stock appears only when a goods receipt is posted." />
            <Button v-if="can('purchasing.order_create')" as-child class="w-full sm:w-auto">
                <Link :href="`${index().url}/create`"><Plus class="size-4" />New purchase order</Link>
            </Button>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div class="relative">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input v-model="search" type="search" placeholder="Search PO number or supplier…" class="pl-9" aria-label="Search purchase orders" />
                <button v-if="search" type="button" class="text-muted-foreground hover:text-foreground absolute top-1/2 right-3 -translate-y-1/2" aria-label="Clear search" @click="search = ''; applyFilters(true)">
                    <X class="size-4" />
                </button>
            </div>
            <select v-model="status" :class="controlClass" aria-label="Filter by status">
                <option value="">All statuses</option>
                <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
            <select v-model="supplierId" :class="controlClass" aria-label="Filter by supplier">
                <option value="">All suppliers</option>
                <option v-for="s in suppliers" :key="s.id" :value="String(s.id)">{{ s.code }} — {{ s.name }}</option>
            </select>
        </div>

        <div v-if="orders.data.length === 0" class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center">
            <p class="font-medium">No purchase orders found</p>
            <p class="text-muted-foreground text-sm">Create one to start ordering from a supplier.</p>
        </div>

        <template v-else>
            <div class="border-sidebar-border/70 dark:border-sidebar-border hidden overflow-x-auto rounded-xl border md:block">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">PO No</th>
                            <th class="px-4 py-3 font-medium">Supplier</th>
                            <th class="px-4 py-3 font-medium">Warehouse</th>
                            <th class="px-4 py-3 font-medium">Date</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 text-right font-medium">Total</th>
                            <th class="px-4 py-3 text-right font-medium">Received</th>
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
                            <td class="px-4 py-3 text-right font-mono">{{ order.material_total }}</td>
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
                        <div><dt class="font-medium">Date</dt><dd>{{ order.order_date }}</dd></div>
                        <div><dt class="font-medium">Total</dt><dd class="font-mono">{{ order.material_total }}</dd></div>
                        <div><dt class="font-medium">Received</dt><dd class="font-mono">{{ order.received_quantity }} / {{ order.ordered_quantity }}</dd></div>
                        <div><dt class="font-medium">Remaining</dt><dd class="font-mono">{{ order.remaining_quantity }}</dd></div>
                    </dl>
                </Link>
            </div>

            <div v-if="orders.last_page > 1" class="flex flex-col items-center justify-between gap-3 sm:flex-row">
                <p class="text-muted-foreground text-sm">Showing {{ orders.from }}–{{ orders.to }} of {{ orders.total }}</p>
                <div class="flex flex-wrap items-center gap-1">
                    <Button v-for="link in orders.links" :key="link.label" :variant="link.active ? 'default' : 'outline'" size="sm" :disabled="!link.url" @click="link.url && router.visit(link.url, { preserveState: true, preserveScroll: true })" v-html="link.label" />
                </div>
            </div>
        </template>
    </div>
</template>
