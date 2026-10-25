<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import ListControls from '@/components/lists/ListControls.vue';
import ListPagination from '@/components/lists/ListPagination.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { index as lotsIndex } from '@/routes/inventory/lots';
import type { LotRow, OptionRef, Paginated } from '@/types';
import { type ListControls as ListControlValues, useListFilters } from '@/composables/useListFilters';
import { useT } from '@/lib/i18n';

const props = defineProps<{
    lots: Paginated<LotRow>;
    filters: {
        search: string;
        product_id: number | null;
        status: 'all' | 'active' | 'inactive';
    } & ListControlValues;
    products: OptionRef[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Lots', href: lotsIndex() }],
    },
});

const { t } = useT();

const { filters, sortBy, clear } = useListFilters(() => lotsIndex().url, {
    ...props.filters,
    product_id: props.filters.product_id ? String(props.filters.product_id) : '',
});

const statusOptions = [
    { value: 'all', label: 'All' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
] as const;
</script>

<template>
    <Head :title="t('Lots')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Lots"
            description="Batch traceability. Lots are created by posting documents, not by hand."
        />

        <ListControls v-model:per-page="filters.per_page" v-model:from="filters.from" v-model:to="filters.to" period-label="Received" @clear="clear">
            <div class="relative w-full sm:w-72">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input v-model="filters.search" type="search" :placeholder="t('Search lot number or supplier lot…')" class="pl-9" :aria-label="t('Search lots')" />
            </div>
            <SearchableSelect
                v-model="filters.product_id"
                class="w-full sm:w-56"
                :aria-label="t('Product')"
                :options="[{ value: '', label: t('All products') }, ...products.map((p) => ({ value: String(p.id), label: `${p.code ?? ''}` }))]"
            />
            <div class="bg-muted inline-flex h-9 shrink-0 items-center rounded-md p-1" role="group" :aria-label="t('Filter by status')">
                <button
                    v-for="option in statusOptions"
                    :key="option.value"
                    type="button"
                    class="rounded px-3 py-1 text-sm font-medium transition-colors"
                    :class="filters.status === option.value ? 'bg-background text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                    :aria-pressed="filters.status === option.value"
                    @click="filters.status = option.value"
                >
                    {{ t(option.label) }}
                </button>
            </div>
        </ListControls>

        <div
            v-if="lots.data.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">{{ t('No lots found') }}</p>
            <p class="text-muted-foreground text-sm">
                {{
                    t(
                        'Lots are created automatically when purchase or production documents post stock.',
                    )
                }}
            </p>
        </div>

        <template v-else>
            <div
                class="border-sidebar-border/70 dark:border-sidebar-border hidden overflow-x-auto rounded-xl border md:block"
            >
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <SortHeader label="Lot number" column="lot" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Product" column="product" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <th class="px-4 py-3 font-medium">
                                {{ t('Source') }}
                            </th>
                            <SortHeader label="Received" column="received" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Manufactured" column="manufactured" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <th class="px-4 py-3 text-right font-medium">
                                {{ t('Quantity') }}
                            </th>
                            <th class="px-4 py-3 font-medium">
                                {{ t('Warehouses') }}
                            </th>
                            <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
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
                                    {{
                                        t('supplier: :lot', {
                                            lot: lot.supplier_lot_number,
                                        })
                                    }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-mono text-xs">{{
                                    lot.product_code
                                }}</span>
                                <span
                                    class="text-muted-foreground block text-xs"
                                >
                                    {{ lot.product_name }}
                                </span>
                            </td>
                            <td class="text-muted-foreground px-4 py-3 text-xs">
                                {{ lot.source ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                {{ lot.received_at ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                {{ lot.manufactured_at ?? '—' }}
                            </td>
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
                                <Badge
                                    :variant="
                                        lot.is_active ? 'default' : 'secondary'
                                    "
                                >
                                    {{
                                        lot.is_active
                                            ? t('Active')
                                            : t('Inactive')
                                    }}
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
                        <Badge
                            :variant="lot.is_active ? 'default' : 'secondary'"
                        >
                            {{ lot.is_active ? t('Active') : t('Inactive') }}
                        </Badge>
                    </div>

                    <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <dt class="text-muted-foreground font-medium">
                                {{ t('Quantity') }}
                            </dt>
                            <dd class="font-mono">
                                {{ lot.quantity }} {{ lot.primary_uom }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground font-medium">
                                {{ t('Received') }}
                            </dt>
                            <dd>{{ lot.received_at ?? '—' }}</dd>
                        </div>
                    </dl>

                    <div
                        v-if="lot.warehouses.length"
                        class="mt-3 flex flex-wrap gap-1"
                    >
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

        </template>

        <ListPagination :page="lots" />
    </div>
</template>
