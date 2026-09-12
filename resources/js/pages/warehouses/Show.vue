<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import WarehouseDefaultController from '@/actions/App/Http/Controllers/Warehouses/WarehouseDefaultController';
import WarehouseStatusController from '@/actions/App/Http/Controllers/Warehouses/WarehouseStatusController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/warehouses';
import type { Warehouse } from '@/types';

defineProps<{
    warehouse: Warehouse;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Warehouses', href: index() }],
    },
});

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleString();
}
</script>

<template>
    <Head :title="warehouse.code" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading :title="warehouse.name" :description="warehouse.code" />
                <div class="flex flex-wrap gap-2">
                    <Badge v-if="warehouse.is_default" variant="default">Default warehouse</Badge>
                    <Badge :variant="warehouse.is_active ? 'default' : 'secondary'">
                        {{ warehouse.is_active ? 'Active' : 'Inactive' }}
                    </Badge>
                </div>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <Button variant="outline" as-child>
                    <Link :href="`${index().url}/${warehouse.id}/edit`">
                        <Pencil class="size-4" />
                        Edit
                    </Link>
                </Button>
                <Form
                    v-if="!warehouse.is_default && warehouse.is_active"
                    v-bind="WarehouseDefaultController.update.form(warehouse.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">
                        Make default
                    </Button>
                </Form>
                <Form v-bind="WarehouseStatusController.update.form(warehouse.id)" v-slot="{ processing }">
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">
                        {{ warehouse.is_active ? 'Deactivate' : 'Activate' }}
                    </Button>
                </Form>
            </div>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl rounded-xl border p-4 sm:p-6">
            <dl class="grid gap-6 sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Code</dt>
                    <dd class="mt-1 font-mono text-sm">{{ warehouse.code }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Name</dt>
                    <dd class="mt-1 text-sm">{{ warehouse.name ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-muted-foreground text-sm font-medium">Description</dt>
                    <dd class="mt-1 text-sm">{{ warehouse.description ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-muted-foreground text-sm font-medium">Address</dt>
                    <dd class="mt-1 text-sm">{{ warehouse.address ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Default warehouse</dt>
                    <dd class="mt-1 text-sm">{{ warehouse.is_default ? 'Yes' : 'No' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Created</dt>
                    <dd class="mt-1 text-sm">{{ formatDate(warehouse.created_at) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Last updated</dt>
                    <dd class="mt-1 text-sm">{{ formatDate(warehouse.updated_at) }}</dd>
                </div>
            </dl>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            Stock held in this warehouse is not shown here. Inventory is derived from stock
            transactions and will appear once the inventory ledger is built.
        </p>
    </div>
</template>
