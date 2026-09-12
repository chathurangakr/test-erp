<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Search, X } from '@lucide/vue';
import { ref, watch } from 'vue';
import ProductStatusController from '@/actions/App/Http/Controllers/Products/ProductStatusController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index } from '@/routes/products';
import type { Paginated, Product, ProductFilters } from '@/types';

const props = defineProps<{
    products: Paginated<Product>;
    filters: ProductFilters;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Products', href: index() }],
    },
});

const search = ref(props.filters.search ?? '');
const status = ref<ProductFilters['status']>(props.filters.status ?? 'all');

let debounce: ReturnType<typeof setTimeout> | undefined;

/** Push the current filters into the URL so results are shareable and back-button safe. */
function applyFilters(immediate = false): void {
    clearTimeout(debounce);

    const run = () =>
        router.get(
            index().url,
            { search: search.value || undefined, status: status.value === 'all' ? undefined : status.value },
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
    <Head title="Products" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <Heading
                title="Product master"
                description="Products used across purchasing, production, inventory and sales."
            />
            <Button as-child class="w-full sm:w-auto">
                <Link :href="`${index().url}/create`">
                    <Plus class="size-4" />
                    New product
                </Link>
            </Button>
        </div>

        <!-- Filters -->
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <Search
                    class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                />
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Search by code or name…"
                    class="pl-9"
                    aria-label="Search products"
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

        <!-- Empty state -->
        <div
            v-if="products.data.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">No products found</p>
            <p class="text-muted-foreground text-sm">
                {{
                    filters.search
                        ? 'Try a different search term.'
                        : 'Create your first product to get started.'
                }}
            </p>
        </div>

        <template v-else>
            <!-- Desktop / tablet: table -->
            <div
                class="border-sidebar-border/70 dark:border-sidebar-border hidden overflow-x-auto rounded-xl border md:block"
            >
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Code</th>
                            <th class="px-4 py-3 font-medium">Name</th>
                            <th class="px-4 py-3 font-medium">Primary UOM</th>
                            <th class="px-4 py-3 font-medium">Secondary UOM</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="product in products.data"
                            :key="product.id"
                            class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t"
                        >
                            <td class="px-4 py-3 font-mono text-xs font-medium">
                                <Link
                                    :href="`${index().url}/${product.id}`"
                                    class="hover:underline"
                                >
                                    {{ product.code }}
                                </Link>
                            </td>
                            <td class="px-4 py-3">{{ product.name }}</td>
                            <td class="px-4 py-3">
                                {{ product.primary_uom?.code ?? '—' }}
                            </td>
                            <td class="text-muted-foreground px-4 py-3">
                                {{ product.secondary_uom?.code ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <Badge
                                    :variant="
                                        product.is_active
                                            ? 'default'
                                            : 'secondary'
                                    "
                                >
                                    {{
                                        product.is_active
                                            ? 'Active'
                                            : 'Inactive'
                                    }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3">
                                <div
                                    class="flex items-center justify-end gap-2"
                                >
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        as-child
                                    >
                                        <Link
                                            :href="`${index().url}/${product.id}/edit`"
                                        >
                                            <Pencil class="size-3.5" />
                                            Edit
                                        </Link>
                                    </Button>
                                    <Form
                                        v-bind="
                                            ProductStatusController.update.form(
                                                product.id,
                                            )
                                        "
                                        v-slot="{ processing }"
                                    >
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            size="sm"
                                            :disabled="processing"
                                        >
                                            {{
                                                product.is_active
                                                    ? 'Deactivate'
                                                    : 'Activate'
                                            }}
                                        </Button>
                                    </Form>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Mobile: cards -->
            <div class="grid gap-3 md:hidden">
                <div
                    v-for="product in products.data"
                    :key="product.id"
                    class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <Link
                                :href="`${index().url}/${product.id}`"
                                class="font-mono text-xs font-medium hover:underline"
                            >
                                {{ product.code }}
                            </Link>
                            <p class="mt-1 truncate font-medium">
                                {{ product.name }}
                            </p>
                        </div>
                        <Badge
                            :variant="
                                product.is_active ? 'default' : 'secondary'
                            "
                        >
                            {{ product.is_active ? 'Active' : 'Inactive' }}
                        </Badge>
                    </div>

                    <dl
                        class="text-muted-foreground mt-3 grid grid-cols-2 gap-2 text-xs"
                    >
                        <div>
                            <dt class="font-medium">Primary UOM</dt>
                            <dd>{{ product.primary_uom?.code ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium">Secondary UOM</dt>
                            <dd>{{ product.secondary_uom?.code ?? '—' }}</dd>
                        </div>
                    </dl>

                    <div class="mt-4 flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            class="flex-1"
                            as-child
                        >
                            <Link :href="`${index().url}/${product.id}/edit`">
                                Edit
                            </Link>
                        </Button>
                        <Form
                            v-bind="
                                ProductStatusController.update.form(product.id)
                            "
                            class="flex-1"
                            v-slot="{ processing }"
                        >
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                class="w-full"
                                :disabled="processing"
                            >
                                {{
                                    product.is_active
                                        ? 'Deactivate'
                                        : 'Activate'
                                }}
                            </Button>
                        </Form>
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            <div
                v-if="products.last_page > 1"
                class="flex flex-col items-center justify-between gap-3 sm:flex-row"
            >
                <p class="text-muted-foreground text-sm">
                    Showing {{ products.from }}–{{ products.to }} of
                    {{ products.total }}
                </p>
                <div class="flex flex-wrap items-center gap-1">
                    <Button
                        v-for="link in products.links"
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
