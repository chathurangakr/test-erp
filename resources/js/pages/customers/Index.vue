<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Pencil, Plus, Search } from '@lucide/vue';
import CustomerStatusController from '@/actions/App/Http/Controllers/Customers/CustomerStatusController';
import Heading from '@/components/Heading.vue';
import ListControls from '@/components/lists/ListControls.vue';
import ListPagination from '@/components/lists/ListPagination.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { type ListControls as ListControlValues, useListFilters } from '@/composables/useListFilters';
import { usePermissions } from '@/composables/usePermissions';
import { index } from '@/routes/customers';
import type { Paginated, Customer, ProductFilters } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    customers: Paginated<Customer>;
    filters: ProductFilters & ListControlValues;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Customers', href: index() }],
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
    <Head :title="t('Customers')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading :title="t('Customers')" :description="t('Parties the factory sells products to.')" />
            <Button v-if="can('customers.manage')" as-child class="w-full sm:w-auto">
                <Link :href="`${index().url}/create`">
                    <Plus class="size-4" />
                    {{ t('New customer') }}
                </Link>
            </Button>
        </div>

        <ListControls v-model:per-page="filters.per_page" :period="false" @clear="clear">
            <div class="relative w-full sm:w-80">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input v-model="filters.search" type="search" :placeholder="t('Search by code, name, contact, phone or city…')" class="pl-9" :aria-label="t('Search customers')" />
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
            v-if="customers.data.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">{{ t('No customers found') }}</p>
            <p class="text-muted-foreground text-sm">
                {{ filters.search ? t('Try a different search term.') : t('Create your first customer to get started.') }}
            </p>
        </div>

        <template v-else>
            <div class="border-sidebar-border/70 dark:border-sidebar-border hidden overflow-x-auto rounded-xl border md:block">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <SortHeader label="Code" column="code" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Name" column="name" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Contact" column="contact" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Phone" column="phone" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="City" column="city" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Credit limit" column="credit_limit" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                            <th class="px-4 py-3 text-right font-medium">{{ t('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="customer in customers.data"
                            :key="customer.id"
                            class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t"
                        >
                            <td class="px-4 py-3 font-mono text-xs font-medium">
                                <Link :href="`${index().url}/${customer.id}`" class="hover:underline">
                                    {{ customer.code }}
                                </Link>
                            </td>
                            <td class="px-4 py-3">{{ customer.name ?? '—' }}</td>
                            <td class="text-muted-foreground px-4 py-3">{{ customer.contact_person ?? '—' }}</td>
                            <td class="text-muted-foreground px-4 py-3">{{ customer.phone ?? '—' }}</td>
                            <td class="text-muted-foreground px-4 py-3">{{ customer.city ?? '—' }}</td>
                            <td class="px-4 py-3">{{ money(customer.credit_limit) }}</td>
                            <td class="px-4 py-3">
                                <Badge :variant="customer.is_active ? 'default' : 'secondary'">
                                    {{ customer.is_active ? t('Active') : t('Inactive') }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3">
                                <div v-if="can('customers.manage')" class="flex items-center justify-end gap-2">
                                    <Button variant="ghost" size="sm" as-child>
                                        <Link :href="`${index().url}/${customer.id}/edit`">
                                            <Pencil class="size-3.5" />
                                            {{ t('Edit') }}
                                        </Link>
                                    </Button>
                                    <Form
                                        v-bind="CustomerStatusController.update.form(customer.id)"
                                        v-slot="{ processing }"
                                    >
                                        <Button type="submit" variant="outline" size="sm" :disabled="processing">
                                            {{ customer.is_active ? t('Deactivate') : t('Activate') }}
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
                    v-for="customer in customers.data"
                    :key="customer.id"
                    class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <Link :href="`${index().url}/${customer.id}`" class="font-mono text-xs font-medium hover:underline">
                                {{ customer.code }}
                            </Link>
                            <p class="mt-1 truncate font-medium">{{ customer.name }}</p>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1">
                            <Badge :variant="customer.is_active ? 'default' : 'secondary'">
                                {{ customer.is_active ? t('Active') : t('Inactive') }}
                            </Badge>
                        </div>
                    </div>

                    <dl class="text-muted-foreground mt-3 grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <dt class="font-medium">{{ t('Contact') }}</dt>
                            <dd>{{ customer.contact_person ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium">{{ t('Phone') }}</dt>
                            <dd>{{ customer.phone ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium">{{ t('City') }}</dt>
                            <dd>{{ customer.city ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium">{{ t('Credit limit') }}</dt>
                            <dd>{{ money(customer.credit_limit) }}</dd>
                        </div>
                    </dl>

                    <div v-if="can('customers.manage')" class="mt-4 flex gap-2">
                        <Button variant="outline" size="sm" class="flex-1" as-child>
                            <Link :href="`${index().url}/${customer.id}/edit`">{{ t('Edit') }}</Link>
                        </Button>
                        <Form
                            v-bind="CustomerStatusController.update.form(customer.id)"
                            class="flex-1"
                            v-slot="{ processing }"
                        >
                            <Button type="submit" variant="outline" size="sm" class="w-full" :disabled="processing">
                                {{ customer.is_active ? t('Deactivate') : t('Activate') }}
                            </Button>
                        </Form>
                    </div>
                </div>
            </div>

        </template>

        <ListPagination :page="customers" />
    </div>
</template>
