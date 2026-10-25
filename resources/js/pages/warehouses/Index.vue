<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Pencil, Plus, Search } from '@lucide/vue';
import WarehouseStatusController from '@/actions/App/Http/Controllers/Warehouses/WarehouseStatusController';
import Heading from '@/components/Heading.vue';
import ListControls from '@/components/lists/ListControls.vue';
import ListPagination from '@/components/lists/ListPagination.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { type ListControls as ListControlValues, useListFilters } from '@/composables/useListFilters';
import { usePermissions } from '@/composables/usePermissions';
import { index } from '@/routes/warehouses';
import type { Paginated, Warehouse, ProductFilters } from '@/types';
import { useT } from '@/lib/i18n';

const props = defineProps<{
    warehouses: Paginated<Warehouse>;
    filters: ProductFilters & ListControlValues;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Warehouses', href: index() }],
    },
});

const { can } = usePermissions();

const { filters, sortBy, clear } = useListFilters(() => index().url, { ...props.filters });

const statusOptions: { value: ProductFilters['status']; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];

const { t } = useT();
</script>

<template>
    <Head :title="t('Warehouses')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading :title="t('Warehouses')" :description="t('Stock locations. Inventory movements will require a warehouse.')" />
            <Button v-if="can('warehouses.manage')" as-child class="w-full sm:w-auto">
                <Link :href="`${index().url}/create`">
                    <Plus class="size-4" />
                    {{ t('New warehouse') }}
                </Link>
            </Button>
        </div>

        <ListControls v-model:per-page="filters.per_page" :period="false" @clear="clear">
            <div class="relative w-full sm:w-80">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input v-model="filters.search" type="search" :placeholder="t('Search by code or name…')" class="pl-9" :aria-label="t('Search warehouses')" />
            </div>
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
            v-if="warehouses.data.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">{{ t('No warehouses found') }}</p>
            <p class="text-muted-foreground text-sm">
                {{ filters.search ? t('Try a different search term.') : t('Create your first warehouse to get started.') }}
            </p>
        </div>

        <template v-else>
            <div class="border-sidebar-border/70 dark:border-sidebar-border hidden overflow-x-auto rounded-xl border md:block">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <SortHeader label="Code" column="code" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Name" column="name" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Default" column="default" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <th class="px-4 py-3 text-right font-medium">{{ t('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="warehouse in warehouses.data"
                            :key="warehouse.id"
                            class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t"
                        >
                            <td class="px-4 py-3 font-mono text-xs font-medium">
                                <Link :href="`${index().url}/${warehouse.id}`" class="hover:underline">
                                    {{ warehouse.code }}
                                </Link>
                            </td>
                            <td class="px-4 py-3">{{ warehouse.name ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <Badge v-if="warehouse.is_default" variant="default">{{ t('Default') }}</Badge>
                                <span v-else class="text-muted-foreground">—</span>
                            </td>
                            <td class="px-4 py-3">
                                <Badge :variant="warehouse.is_active ? 'default' : 'secondary'">
                                    {{ warehouse.is_active ? t('Active') : t('Inactive') }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3">
                                <div v-if="can('warehouses.manage')" class="flex items-center justify-end gap-2">
                                    <Button variant="ghost" size="sm" as-child>
                                        <Link :href="`${index().url}/${warehouse.id}/edit`">
                                            <Pencil class="size-3.5" />
                                            {{ t('Edit') }}
                                        </Link>
                                    </Button>
                                    <Form
                                        v-bind="WarehouseStatusController.update.form(warehouse.id)"
                                        v-slot="{ processing }"
                                    >
                                        <Button type="submit" variant="outline" size="sm" :disabled="processing">
                                            {{ warehouse.is_active ? t('Deactivate') : t('Activate') }}
                                        </Button>
                                    </Form>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="grid gap-3 md:hidden">
                <div
                    v-for="warehouse in warehouses.data"
                    :key="warehouse.id"
                    class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <Link :href="`${index().url}/${warehouse.id}`" class="font-mono text-xs font-medium hover:underline">
                                {{ warehouse.code }}
                            </Link>
                            <p class="mt-1 truncate font-medium">{{ warehouse.name }}</p>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1">
                            <Badge v-if="warehouse.is_default" variant="default">{{ t('Default') }}</Badge>
                            <Badge :variant="warehouse.is_active ? 'default' : 'secondary'">
                                {{ warehouse.is_active ? t('Active') : t('Inactive') }}
                            </Badge>
                        </div>
                    </div>

                    <dl class="text-muted-foreground mt-3 grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <dt class="font-medium">{{ t('Description') }}</dt>
                            <dd>{{ warehouse.description ?? '—' }}</dd>
                        </div>
                    </dl>

                    <div v-if="can('warehouses.manage')" class="mt-4 flex gap-2">
                        <Button variant="outline" size="sm" class="flex-1" as-child>
                            <Link :href="`${index().url}/${warehouse.id}/edit`">{{ t('Edit') }}</Link>
                        </Button>
                        <Form
                            v-bind="WarehouseStatusController.update.form(warehouse.id)"
                            class="flex-1"
                            v-slot="{ processing }"
                        >
                            <Button type="submit" variant="outline" size="sm" class="w-full" :disabled="processing">
                                {{ warehouse.is_active ? t('Deactivate') : t('Activate') }}
                            </Button>
                        </Form>
                    </div>
                </div>
            </div>

        </template>

        <ListPagination :page="warehouses" />
    </div>
</template>
