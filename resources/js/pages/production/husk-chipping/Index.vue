<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/husk-chipping';
import type { HuskChippingRow, OptionRef, Paginated, StatusOption } from '@/types';

const props = defineProps<{
    orders: Paginated<HuskChippingRow>;
    filters: { search: string; status: string; warehouse_id: number | null; from: string | null; to: string | null };
    warehouses: OptionRef[];
    statuses: StatusOption[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Husk chipping', href: index() }] } });

const status = ref(props.filters.status ?? '');
const warehouseId = ref(props.filters.warehouse_id ? String(props.filters.warehouse_id) : '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');

function applyFilters(): void {
    router.get(
        index().url,
        {
            status: status.value || undefined,
            warehouse_id: warehouseId.value || undefined,
            from: from.value || undefined,
            to: to.value || undefined,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

watch([status, warehouseId, from, to], applyFilters);

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'POSTED') return 'default';
    return 'outline';
}

const controlClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
</script>

<template>
    <Head title="Husk chipping" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading
                title="Husk chipping"
                description="Raw husk is chipped into 7C, 9C and 10C. The operator enters the weights actually measured."
            />
            <Button as-child class="w-full sm:w-auto">
                <Link :href="`${index().url}/create`"><Plus class="size-4" />New husk chipping</Link>
            </Button>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="grid gap-1.5">
                <label for="f-from" class="text-sm font-medium">From</label>
                <input id="f-from" v-model="from" type="date" :class="controlClass" />
            </div>
            <div class="grid gap-1.5">
                <label for="f-to" class="text-sm font-medium">To</label>
                <input id="f-to" v-model="to" type="date" :class="controlClass" />
            </div>
            <div class="grid gap-1.5">
                <label for="f-status" class="text-sm font-medium">Status</label>
                <select id="f-status" v-model="status" :class="controlClass">
                    <option value="">All statuses</option>
                    <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
            </div>
            <div class="grid gap-1.5">
                <label for="f-whs" class="text-sm font-medium">Warehouse</label>
                <select id="f-whs" v-model="warehouseId" :class="controlClass">
                    <option value="">All warehouses</option>
                    <option v-for="w in warehouses" :key="w.id" :value="String(w.id)">{{ w.code }}</option>
                </select>
            </div>
        </div>

        <div
            v-if="orders.data.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">No husk chipping runs found</p>
            <p class="text-muted-foreground text-sm">Create one to chip raw husk into 7C, 9C and 10C.</p>
        </div>

        <template v-else>
            <div class="border-sidebar-border/70 dark:border-sidebar-border hidden overflow-x-auto rounded-xl border md:block">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Production No</th>
                            <th class="px-4 py-3 font-medium">Date</th>
                            <th class="px-4 py-3 font-medium">Warehouse</th>
                            <th class="px-4 py-3 text-right font-medium">Husk in (NOS)</th>
                            <th class="px-4 py-3 text-right font-medium">Chips out (KG)</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 text-right font-medium">Input value</th>
                            <th class="px-4 py-3 text-right font-medium">Output value</th>
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
                            <td class="px-4 py-3 text-right font-mono">{{ row.input_value ?? '—' }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ row.output_value ?? '—' }}</td>
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
                        <div><dt class="font-medium">Husk in</dt><dd class="font-mono">{{ row.input_quantity }}</dd></div>
                        <div><dt class="font-medium">Chips out</dt><dd class="font-mono">{{ row.output_quantity }}</dd></div>
                        <div><dt class="font-medium">Input value</dt><dd class="font-mono">{{ row.input_value ?? '—' }}</dd></div>
                        <div><dt class="font-medium">Output value</dt><dd class="font-mono">{{ row.output_value ?? '—' }}</dd></div>
                    </dl>
                </Link>
            </div>

            <div v-if="orders.last_page > 1" class="flex flex-col items-center justify-between gap-3 sm:flex-row">
                <p class="text-muted-foreground text-sm">Showing {{ orders.from }}–{{ orders.to }} of {{ orders.total }}</p>
                <div class="flex flex-wrap items-center gap-1">
                    <Button
                        v-for="link in orders.links"
                        :key="link.label"
                        :variant="link.active ? 'default' : 'outline'"
                        size="sm"
                        :disabled="!link.url"
                        @click="link.url && router.visit(link.url, { preserveState: true, preserveScroll: true })"
                        v-html="link.label"
                    />
                </div>
            </div>
        </template>
    </div>
</template>
