<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useT } from '@/lib/i18n';
import { index } from '@/routes/copra';
import type { CopraOutputColumn, CopraRow, OptionRef, Paginated, StatusOption } from '@/types';
import { money } from '@/lib/money';

const { t } = useT();

const props = defineProps<{
    orders: Paginated<CopraRow>;
    filters: { search: string; status: string; warehouse_id: number | null; from: string | null; to: string | null };
    warehouses: OptionRef[];
    statuses: StatusOption[];
    outputColumns: CopraOutputColumn[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Copra production', href: index() }] } });

const { can } = usePermissions();

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

/** The output quantity for one product on one row, or an em dash. */
function quantity(row: CopraRow, column: CopraOutputColumn): string {
    return row.outputs_by_product[String(column.product_id)]?.quantity ?? '—';
}

function secondary(row: CopraRow, column: CopraOutputColumn): string {
    return row.outputs_by_product[String(column.product_id)]?.secondary_quantity ?? '—';
}

const controlClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
</script>

<template>
    <Head :title="t('Copra production')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading
                title="Copra production"
                description="Dehusked coconut is dried into copra and leaves raw shell behind. The operator enters the weights actually measured."
            />
            <Button v-if="can('production.create')" as-child class="w-full sm:w-auto">
                <Link :href="`${index().url}/create`"><Plus class="size-4" />{{ t('New copra production') }}</Link>
            </Button>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="grid gap-1.5">
                <label for="f-from" class="text-sm font-medium">{{ t('From') }}</label>
                <input id="f-from" v-model="from" type="date" :class="controlClass" />
            </div>
            <div class="grid gap-1.5">
                <label for="f-to" class="text-sm font-medium">{{ t('To') }}</label>
                <input id="f-to" v-model="to" type="date" :class="controlClass" />
            </div>
            <div class="grid gap-1.5">
                <label for="f-status" class="text-sm font-medium">{{ t('Status') }}</label>
                <SearchableSelect
                    id="f-status"
                    v-model="status"
                    :options="[{ value: '', label: t('All statuses') }, ...(statuses).map((s) => ({ value: s.value, label: `${(s.label) ?? ''}` }))]"
                />
            </div>
            <div class="grid gap-1.5">
                <label for="f-whs" class="text-sm font-medium">{{ t('Warehouse') }}</label>
                <SearchableSelect
                    id="f-whs"
                    v-model="warehouseId"
                    :options="[{ value: '', label: t('All warehouses') }, ...(warehouses).map((w) => ({ value: String(w.id), label: `${(w.code) ?? ''}` }))]"
                />
            </div>
        </div>

        <div
            v-if="orders.data.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">{{ t('No copra production runs found') }}</p>
            <p class="text-muted-foreground text-sm">
                {{ t('Create one to dry dehusked coconut into copra and raw shell.') }}
            </p>
        </div>

        <template v-else>
            <div class="border-sidebar-border/70 dark:border-sidebar-border hidden overflow-x-auto rounded-xl border md:block">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ t('Production No') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Date') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Warehouse') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Dehusked in (NOS)') }}</th>
                            <template v-for="column in outputColumns" :key="column.product_id">
                                <th class="px-4 py-3 text-right font-medium">
                                    {{ column.code }} ({{ column.uom }})
                                </th>
                                <th
                                    v-if="column.records_weight"
                                    class="px-4 py-3 text-right font-medium"
                                >
                                    {{ column.code }} ({{ column.secondary_uom }})
                                </th>
                            </template>
                            <th class="px-4 py-3 font-medium">{{ t('Status') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Input value') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Output value') }}</th>
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
                            <template v-for="column in outputColumns" :key="`${row.id}-${column.product_id}`">
                                <td class="px-4 py-3 text-right font-mono">{{ quantity(row, column) }}</td>
                                <td
                                    v-if="column.records_weight"
                                    class="px-4 py-3 text-right font-mono"
                                >
                                    {{ secondary(row, column) }}
                                </td>
                            </template>
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
                        <div>
                            <dt class="font-medium">{{ t('Dehusked in') }}</dt>
                            <dd class="font-mono">{{ row.input_quantity }}</dd>
                        </div>
                        <div v-for="column in outputColumns" :key="`mc-${row.id}-${column.product_id}`">
                            <dt class="font-medium">{{ column.code }}</dt>
                            <dd class="font-mono">
                                {{ quantity(row, column) }} {{ column.uom }}
                                <span v-if="column.records_weight" class="block">
                                    {{ secondary(row, column) }} {{ column.secondary_uom }}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">{{ t('Input value') }}</dt>
                            <dd class="font-mono">{{ money(row.input_value) }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium">{{ t('Output value') }}</dt>
                            <dd class="font-mono">{{ money(row.output_value) }}</dd>
                        </div>
                    </dl>
                </Link>
            </div>

            <div v-if="orders.last_page > 1" class="flex flex-col items-center justify-between gap-3 sm:flex-row">
                <p class="text-muted-foreground text-sm">
                    {{ t('Showing :from–:to of :total', { from: orders.from ?? 0, to: orders.to ?? 0, total: orders.total }) }}
                </p>
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
