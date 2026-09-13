<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Search, X } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index as lotsIndex } from '@/routes/inventory/lots';
import type { LotRow, OptionRef, Paginated } from '@/types';

const props = defineProps<{
    lots: Paginated<LotRow>;
    filters: {
        search: string;
        product_id: number | null;
        status: 'all' | 'active' | 'inactive';
    };
    products: OptionRef[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Lots', href: lotsIndex() }],
    },
});

const search = ref(props.filters.search ?? '');
const productId = ref(props.filters.product_id ? String(props.filters.product_id) : '');
const status = ref(props.filters.status ?? 'all');

let debounce: ReturnType<typeof setTimeout> | undefined;

function applyFilters(immediate = false): void {
    clearTimeout(debounce);

    const run = () =>
        router.get(
            lotsIndex().url,
            {
                search: search.value || undefined,
                product_id: productId.value || undefined,
                status: status.value === 'all' ? undefined : status.value,
            },
            { preserveState: true, replace: true, preserveScroll: true },
        );

    if (immediate) {
        run();
        return;
    }

    debounce = setTimeout(run, 300);
}

watch(search, () => applyFilters());
watch([productId, status], () => applyFilters(true));

const controlClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';

const statusOptions = [
    { value: 'all', label: 'All' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
] as const;
</script>

<template>
    <Head title="Lots" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Lots"
            description="Batch traceability. Lots are created by posting documents, not by hand."
        />

        <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
            <div class="relative flex-1">
                <Search
                    class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                />
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Search lot number or supplier lot…"
                    class="pl-9"
                    aria-label="Search lots"
                />
                <button
                    v-if="search"
                    type="button"
                    class="text-muted-foreground hover:text-foreground absolute top-1/2 right-3 -translate-y-1/2"
                    aria-label="Clear search"
                    @click="
                        search = '';
                        applyFilters(true);
                    "
                >
                    <X class="size-4" />
                </button>
            </div>

            <div class="grid gap-1.5 lg:w-64">
                <label for="lot-product" class="text-sm font-medium">Product</label>
                <select id="lot-product" v-model="productId" :class="controlClass">
                    <option value="">All products</option>
                    <option v-for="p in products" :key="p.id" :value="String(p.id)">
                        {{ p.code }}
                    </option>
                </select>
            </div>

            <div
                class="bg-muted inline-flex shrink-0 rounded-md p-1"
                role="group"
                aria-label="Filter by status"
            >
                <button
                    v-for="option in statusOptions"
                    :key="option.value"
                    type="button"
                    class="rounded px-3 py-1.5 text-sm font-medium transition-colors"
                    :class="
                        status === option.value
                            ? 'bg-background text-foreground shadow-xs'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :aria-pressed="status === option.value"
                    @click="status = option.value"
                >
                    {{ option.label }}
                </button>
            </div>
        </div>

        <div
            v-if="lots.data.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">No lots found</p>
            <p class="text-muted-foreground text-sm">
                Lots are created automatically when purchase or production documents post
                stock.
            </p>
        </div>

        <template v-else>
            <div
                class="border-sidebar-border/70 dark:border-sidebar-border hidden overflow-x-auto rounded-xl border md:block"
            >
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Lot number</th>
                            <th class="px-4 py-3 font-medium">Product</th>
                            <th class="px-4 py-3 font-medium">Source</th>
                            <th class="px-4 py-3 font-medium">Received</th>
                            <th class="px-4 py-3 font-medium">Manufactured</th>
                            <th class="px-4 py-3 text-right font-medium">Quantity</th>
                            <th class="px-4 py-3 font-medium">Warehouses</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="lot in lots.data"
                            :key="lot.id"
                            class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t"
                        >
                            <td class="px-4 py-3 font-mono text-xs font-medium">
                                {{ lot.lot_number }}
                                <span
                                    v-if="lot.supplier_lot_number"
                                    class="text-muted-foreground block font-sans"
                                >
                                    supplier: {{ lot.supplier_lot_number }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-mono text-xs">{{ lot.product_code }}</span>
                                <span class="text-muted-foreground block text-xs">
                                    {{ lot.product_name }}
                                </span>
                            </td>
                            <td class="text-muted-foreground px-4 py-3 text-xs">
                                {{ lot.source_type ?? '—' }}
                            </td>
                            <td class="px-4 py-3">{{ lot.received_at ?? '—' }}</td>
                            <td class="px-4 py-3">{{ lot.manufactured_at ?? '—' }}</td>
                            <td class="px-4 py-3 text-right font-mono">
                                {{ lot.quantity }} {{ lot.primary_uom }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    v-if="lot.warehouses.length === 0"
                                    class="text-muted-foreground"
                                >
                                    —
                                </span>
                                <span
                                    v-for="wh in lot.warehouses"
                                    :key="`${lot.id}-${wh.code}`"
                                    class="mr-1 inline-block"
                                >
                                    <Badge variant="outline">
                                        {{ wh.code }}: {{ wh.quantity }}
                                    </Badge>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <Badge :variant="lot.is_active ? 'default' : 'secondary'">
                                    {{ lot.is_active ? 'Active' : 'Inactive' }}
                                </Badge>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="grid gap-3 md:hidden">
                <div
                    v-for="lot in lots.data"
                    :key="`m-${lot.id}`"
                    class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-mono text-xs font-medium">
                                {{ lot.lot_number }}
                            </p>
                            <p class="mt-1 truncate text-sm">
                                {{ lot.product_code }} — {{ lot.product_name }}
                            </p>
                        </div>
                        <Badge :variant="lot.is_active ? 'default' : 'secondary'">
                            {{ lot.is_active ? 'Active' : 'Inactive' }}
                        </Badge>
                    </div>

                    <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <dt class="text-muted-foreground font-medium">Quantity</dt>
                            <dd class="font-mono">
                                {{ lot.quantity }} {{ lot.primary_uom }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground font-medium">Received</dt>
                            <dd>{{ lot.received_at ?? '—' }}</dd>
                        </div>
                    </dl>

                    <div v-if="lot.warehouses.length" class="mt-3 flex flex-wrap gap-1">
                        <Badge
                            v-for="wh in lot.warehouses"
                            :key="`mw-${lot.id}-${wh.code}`"
                            variant="outline"
                        >
                            {{ wh.code }}: {{ wh.quantity }}
                        </Badge>
                    </div>
                </div>
            </div>

            <div
                v-if="lots.last_page > 1"
                class="flex flex-col items-center justify-between gap-3 sm:flex-row"
            >
                <p class="text-muted-foreground text-sm">
                    Showing {{ lots.from }}–{{ lots.to }} of {{ lots.total }}
                </p>
                <div class="flex flex-wrap items-center gap-1">
                    <Button
                        v-for="link in lots.links"
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
