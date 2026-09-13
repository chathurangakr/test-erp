<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Search, X } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index } from '@/routes/goods-receipts';
import type { GoodsReceiptRow, OptionRef, Paginated, StatusOption } from '@/types';

const props = defineProps<{
    receipts: Paginated<GoodsReceiptRow>;
    filters: { search: string; status: string; supplier_id: number | null };
    suppliers: OptionRef[];
    statuses: StatusOption[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Goods receipts', href: index() }] } });

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const supplierId = ref(props.filters.supplier_id ? String(props.filters.supplier_id) : '');

let debounce: ReturnType<typeof setTimeout> | undefined;

function applyFilters(immediate = false): void {
    clearTimeout(debounce);
    const run = () =>
        router.get(
            index().url,
            { search: search.value || undefined, status: status.value || undefined, supplier_id: supplierId.value || undefined },
            { preserveState: true, replace: true, preserveScroll: true },
        );
    if (immediate) { run(); return; }
    debounce = setTimeout(run, 300);
}

watch(search, () => applyFilters());
watch([status, supplierId], () => applyFilters(true));

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'POSTED') return 'default';
    return 'outline';
}

const controlClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
</script>

<template>
    <Head title="Goods receipts" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading title="Goods receipts" description="Stock enters inventory only when a receipt is posted." />
            <Button as-child class="w-full sm:w-auto">
                <Link :href="`${index().url}/create`"><Plus class="size-4" />New goods receipt</Link>
            </Button>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div class="relative">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input v-model="search" type="search" placeholder="Search GRN number or supplier…" class="pl-9" aria-label="Search goods receipts" />
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

        <div v-if="receipts.data.length === 0" class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center">
            <p class="font-medium">No goods receipts found</p>
            <p class="text-muted-foreground text-sm">Receipts can be raised against a purchase order, or directly.</p>
        </div>

        <template v-else>
            <div class="border-sidebar-border/70 dark:border-sidebar-border hidden overflow-x-auto rounded-xl border md:block">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">GRN No</th>
                            <th class="px-4 py-3 font-medium">Supplier</th>
                            <th class="px-4 py-3 font-medium">PO</th>
                            <th class="px-4 py-3 font-medium">Whs</th>
                            <th class="px-4 py-3 font-medium">Date</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 text-right font-medium">Material</th>
                            <th class="px-4 py-3 text-right font-medium">Landed</th>
                            <th class="px-4 py-3 text-right font-medium">Total cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="grn in receipts.data" :key="grn.id" class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t">
                            <td class="px-4 py-3 font-mono text-xs font-medium">
                                <Link :href="`${index().url}/${grn.id}`" class="hover:underline">{{ grn.grn_number }}</Link>
                            </td>
                            <td class="px-4 py-3">{{ grn.supplier_code }}<span class="text-muted-foreground block text-xs">{{ grn.supplier_name }}</span></td>
                            <td class="text-muted-foreground px-4 py-3 font-mono text-xs">{{ grn.po_number ?? '—' }}</td>
                            <td class="px-4 py-3">{{ grn.warehouse_code }}</td>
                            <td class="px-4 py-3">{{ grn.receipt_date }}</td>
                            <td class="px-4 py-3"><Badge :variant="badgeVariant(grn.status)">{{ grn.status_label }}</Badge></td>
                            <td class="px-4 py-3 text-right font-mono">{{ grn.material_total }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ grn.landed_total }}</td>
                            <td class="px-4 py-3 text-right font-mono font-medium">{{ grn.grand_total }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="grid gap-3 md:hidden">
                <Link v-for="grn in receipts.data" :key="`m-${grn.id}`" :href="`${index().url}/${grn.id}`" class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-mono text-xs font-medium">{{ grn.grn_number }}</p>
                            <p class="mt-1 truncate font-medium">{{ grn.supplier_name }}</p>
                        </div>
                        <Badge :variant="badgeVariant(grn.status)">{{ grn.status_label }}</Badge>
                    </div>
                    <dl class="text-muted-foreground mt-3 grid grid-cols-2 gap-2 text-xs">
                        <div><dt class="font-medium">Date</dt><dd>{{ grn.receipt_date }}</dd></div>
                        <div><dt class="font-medium">Material</dt><dd class="font-mono">{{ grn.material_total }}</dd></div>
                        <div><dt class="font-medium">Landed</dt><dd class="font-mono">{{ grn.landed_total }}</dd></div>
                        <div><dt class="font-medium">Total cost</dt><dd class="font-mono">{{ grn.grand_total }}</dd></div>
                    </dl>
                </Link>
            </div>

            <div v-if="receipts.last_page > 1" class="flex flex-col items-center justify-between gap-3 sm:flex-row">
                <p class="text-muted-foreground text-sm">Showing {{ receipts.from }}–{{ receipts.to }} of {{ receipts.total }}</p>
                <div class="flex flex-wrap items-center gap-1">
                    <Button v-for="link in receipts.links" :key="link.label" :variant="link.active ? 'default' : 'outline'" size="sm" :disabled="!link.url" @click="link.url && router.visit(link.url, { preserveState: true, preserveScroll: true })" v-html="link.label" />
                </div>
            </div>
        </template>
    </div>
</template>
