<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Search, X } from '@lucide/vue';
import { ref, watch } from 'vue';
import SupplierStatusController from '@/actions/App/Http/Controllers/Suppliers/SupplierStatusController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usePermissions } from '@/composables/usePermissions';
import { index } from '@/routes/suppliers';
import type { Paginated, Supplier, ProductFilters } from '@/types';
import { useT } from '@/lib/i18n';

const props = defineProps<{
    suppliers: Paginated<Supplier>;
    filters: ProductFilters;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Suppliers', href: index() }],
    },
});

const { can } = usePermissions();

const search = ref(props.filters.search ?? '');
const status = ref<ProductFilters['status']>(props.filters.status ?? 'all');

let debounce: ReturnType<typeof setTimeout> | undefined;

function applyFilters(immediate = false): void {
    clearTimeout(debounce);

    const run = () =>
        router.get(
            index().url,
            {
                search: search.value || undefined,
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
watch(status, () => applyFilters(true));

function clearSearch(): void {
    search.value = '';
    applyFilters(true);
}

const statusOptions: { value: ProductFilters['status']; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];

const { t } = useT();
</script>

<template>
    <Head :title="t('Suppliers')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading :title="t('Suppliers')" :description="t('Parties the factory buys materials from.')" />
            <Button v-if="can('suppliers.manage')" as-child class="w-full sm:w-auto">
                <Link :href="`${index().url}/create`">
                    <Plus class="size-4" />
                    {{ t('New supplier') }}
                </Link>
            </Button>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input
                    v-model="search"
                    type="search"
                    :placeholder="t('Search by code, name, contact, phone or city…')"
                    class="pl-9"
                    :aria-label="t('Search suppliers')"
                />
                <button
                    v-if="search"
                    type="button"
                    class="text-muted-foreground hover:text-foreground absolute top-1/2 right-3 -translate-y-1/2"
                    :aria-label="t('Clear search')"
                    @click="clearSearch"
                >
                    <X class="size-4" />
                </button>
            </div>

            <div class="bg-muted inline-flex shrink-0 rounded-md p-1" role="group" :aria-label="t('Filter by status')">
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
                    {{ t(option.label) }}
                </button>
            </div>
        </div>

        <div
            v-if="suppliers.data.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">{{ t('No suppliers found') }}</p>
            <p class="text-muted-foreground text-sm">
                {{ filters.search ? t('Try a different search term.') : t('Create your first supplier to get started.') }}
            </p>
        </div>

        <template v-else>
            <div class="border-sidebar-border/70 dark:border-sidebar-border hidden overflow-x-auto rounded-xl border md:block">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ t('Code') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Name') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Contact') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Phone') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('City') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Terms') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Status') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="supplier in suppliers.data"
                            :key="supplier.id"
                            class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t"
                        >
                            <td class="px-4 py-3 font-mono text-xs font-medium">
                                <Link :href="`${index().url}/${supplier.id}`" class="hover:underline">
                                    {{ supplier.code }}
                                </Link>
                            </td>
                            <td class="px-4 py-3">{{ supplier.name ?? '—' }}</td>
                            <td class="text-muted-foreground px-4 py-3">{{ supplier.contact_person ?? '—' }}</td>
                            <td class="text-muted-foreground px-4 py-3">{{ supplier.phone ?? '—' }}</td>
                            <td class="text-muted-foreground px-4 py-3">{{ supplier.city ?? '—' }}</td>
                            <td class="px-4 py-3">{{ t(':days d', { days: supplier.payment_terms_days }) }}</td>
                            <td class="px-4 py-3">
                                <Badge :variant="supplier.is_active ? 'default' : 'secondary'">
                                    {{ supplier.is_active ? t('Active') : t('Inactive') }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3">
                                <div v-if="can('suppliers.manage')" class="flex items-center justify-end gap-2">
                                    <Button variant="ghost" size="sm" as-child>
                                        <Link :href="`${index().url}/${supplier.id}/edit`">
                                            <Pencil class="size-3.5" />
                                            {{ t('Edit') }}
                                        </Link>
                                    </Button>
                                    <Form
                                        v-bind="SupplierStatusController.update.form(supplier.id)"
                                        v-slot="{ processing }"
                                    >
                                        <Button type="submit" variant="outline" size="sm" :disabled="processing">
                                            {{ supplier.is_active ? t('Deactivate') : t('Activate') }}
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
                    v-for="supplier in suppliers.data"
                    :key="supplier.id"
                    class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <Link :href="`${index().url}/${supplier.id}`" class="font-mono text-xs font-medium hover:underline">
                                {{ supplier.code }}
                            </Link>
                            <p class="mt-1 truncate font-medium">{{ supplier.name }}</p>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1">
                            <Badge :variant="supplier.is_active ? 'default' : 'secondary'">
                                {{ supplier.is_active ? t('Active') : t('Inactive') }}
                            </Badge>
                        </div>
                    </div>

                    <dl class="text-muted-foreground mt-3 grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <dt class="font-medium">{{ t('Contact') }}</dt>
                            <dd>{{ supplier.contact_person ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium">{{ t('Phone') }}</dt>
                            <dd>{{ supplier.phone ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium">{{ t('City') }}</dt>
                            <dd>{{ supplier.city ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium">{{ t('Terms') }}</dt>
                            <dd>{{ t(':days days', { days: supplier.payment_terms_days }) }}</dd>
                        </div>
                    </dl>

                    <div v-if="can('suppliers.manage')" class="mt-4 flex gap-2">
                        <Button variant="outline" size="sm" class="flex-1" as-child>
                            <Link :href="`${index().url}/${supplier.id}/edit`">{{ t('Edit') }}</Link>
                        </Button>
                        <Form
                            v-bind="SupplierStatusController.update.form(supplier.id)"
                            class="flex-1"
                            v-slot="{ processing }"
                        >
                            <Button type="submit" variant="outline" size="sm" class="w-full" :disabled="processing">
                                {{ supplier.is_active ? t('Deactivate') : t('Activate') }}
                            </Button>
                        </Form>
                    </div>
                </div>
            </div>

            <div v-if="suppliers.last_page > 1" class="flex flex-col items-center justify-between gap-3 sm:flex-row">
                <p class="text-muted-foreground text-sm">
                    {{ t('Showing :from–:to of :total', { from: suppliers.from ?? 0, to: suppliers.to ?? 0, total: suppliers.total }) }}
                </p>
                <div class="flex flex-wrap items-center gap-1">
                    <Button
                        v-for="link in suppliers.links"
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
