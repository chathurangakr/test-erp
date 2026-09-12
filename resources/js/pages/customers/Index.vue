<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Search, X } from '@lucide/vue';
import { ref, watch } from 'vue';
import CustomerStatusController from '@/actions/App/Http/Controllers/Customers/CustomerStatusController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index } from '@/routes/customers';
import type { Paginated, Customer, ProductFilters } from '@/types';

const props = defineProps<{
    customers: Paginated<Customer>;
    filters: ProductFilters;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Customers', href: index() }],
    },
});

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
</script>

<template>
    <Head title="Customers" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading title="Customers" description="Parties the factory sells products to." />
            <Button as-child class="w-full sm:w-auto">
                <Link :href="`${index().url}/create`">
                    <Plus class="size-4" />
                    New customer
                </Link>
            </Button>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Search by code, name, contact, phone or city…"
                    class="pl-9"
                    aria-label="Search customers"
                />
                <button
                    v-if="search"
                    type="button"
                    class="text-muted-foreground hover:text-foreground absolute top-1/2 right-3 -translate-y-1/2"
                    aria-label="Clear search"
                    @click="clearSearch"
                >
                    <X class="size-4" />
                </button>
            </div>

            <div class="bg-muted inline-flex shrink-0 rounded-md p-1" role="group" aria-label="Filter by status">
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
            v-if="customers.data.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">No customers found</p>
            <p class="text-muted-foreground text-sm">
                {{ filters.search ? 'Try a different search term.' : 'Create your first customer to get started.' }}
            </p>
        </div>

        <template v-else>
            <div class="border-sidebar-border/70 dark:border-sidebar-border hidden overflow-x-auto rounded-xl border md:block">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Code</th>
                            <th class="px-4 py-3 font-medium">Name</th>
                            <th class="px-4 py-3 font-medium">Contact</th>
                            <th class="px-4 py-3 font-medium">Phone</th>
                            <th class="px-4 py-3 font-medium">City</th>
                            <th class="px-4 py-3 font-medium">Credit limit</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 text-right font-medium">Actions</th>
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
                            <td class="px-4 py-3">{{ customer.credit_limit }}</td>
                            <td class="px-4 py-3">
                                <Badge :variant="customer.is_active ? 'default' : 'secondary'">
                                    {{ customer.is_active ? 'Active' : 'Inactive' }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <Button variant="ghost" size="sm" as-child>
                                        <Link :href="`${index().url}/${customer.id}/edit`">
                                            <Pencil class="size-3.5" />
                                            Edit
                                        </Link>
                                    </Button>
                                    <Form
                                        v-bind="CustomerStatusController.update.form(customer.id)"
                                        v-slot="{ processing }"
                                    >
                                        <Button type="submit" variant="outline" size="sm" :disabled="processing">
                                            {{ customer.is_active ? 'Deactivate' : 'Activate' }}
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
                                {{ customer.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                        </div>
                    </div>

                    <dl class="text-muted-foreground mt-3 grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <dt class="font-medium">Contact</dt>
                            <dd>{{ customer.contact_person ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium">Phone</dt>
                            <dd>{{ customer.phone ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium">City</dt>
                            <dd>{{ customer.city ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium">Credit limit</dt>
                            <dd>{{ customer.credit_limit }}</dd>
                        </div>
                    </dl>

                    <div class="mt-4 flex gap-2">
                        <Button variant="outline" size="sm" class="flex-1" as-child>
                            <Link :href="`${index().url}/${customer.id}/edit`">Edit</Link>
                        </Button>
                        <Form
                            v-bind="CustomerStatusController.update.form(customer.id)"
                            class="flex-1"
                            v-slot="{ processing }"
                        >
                            <Button type="submit" variant="outline" size="sm" class="w-full" :disabled="processing">
                                {{ customer.is_active ? 'Deactivate' : 'Activate' }}
                            </Button>
                        </Form>
                    </div>
                </div>
            </div>

            <div v-if="customers.last_page > 1" class="flex flex-col items-center justify-between gap-3 sm:flex-row">
                <p class="text-muted-foreground text-sm">
                    Showing {{ customers.from }}–{{ customers.to }} of {{ customers.total }}
                </p>
                <div class="flex flex-wrap items-center gap-1">
                    <Button
                        v-for="link in customers.links"
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
