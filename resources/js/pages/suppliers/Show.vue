<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import SupplierStatusController from '@/actions/App/Http/Controllers/Suppliers/SupplierStatusController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/suppliers';
import type { Supplier } from '@/types';

defineProps<{
    supplier: Supplier;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Suppliers', href: index() }],
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
    <Head :title="supplier.code" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading :title="supplier.name" :description="supplier.code" />
                <div class="flex flex-wrap gap-2">
                    <Badge :variant="supplier.is_active ? 'default' : 'secondary'">
                        {{ supplier.is_active ? 'Active' : 'Inactive' }}
                    </Badge>
                </div>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <Button variant="outline" as-child>
                    <Link :href="`${index().url}/${supplier.id}/edit`">
                        <Pencil class="size-4" />
                        Edit
                    </Link>
                </Button>
                <Form v-bind="SupplierStatusController.update.form(supplier.id)" v-slot="{ processing }">
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">
                        {{ supplier.is_active ? 'Deactivate' : 'Activate' }}
                    </Button>
                </Form>
            </div>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl rounded-xl border p-4 sm:p-6">
            <dl class="grid gap-6 sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Code</dt>
                    <dd class="mt-1 font-mono text-sm">{{ supplier.code }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Name</dt>
                    <dd class="mt-1 text-sm">{{ supplier.name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Contact person</dt>
                    <dd class="mt-1 text-sm">{{ supplier.contact_person ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Email</dt>
                    <dd class="mt-1 text-sm">{{ supplier.email ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Phone</dt>
                    <dd class="mt-1 text-sm">{{ supplier.phone ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Secondary phone</dt>
                    <dd class="mt-1 text-sm">{{ supplier.secondary_phone ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-muted-foreground text-sm font-medium">Address line 1</dt>
                    <dd class="mt-1 text-sm">{{ supplier.address_line_1 ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-muted-foreground text-sm font-medium">Address line 2</dt>
                    <dd class="mt-1 text-sm">{{ supplier.address_line_2 ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">City</dt>
                    <dd class="mt-1 text-sm">{{ supplier.city ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">District</dt>
                    <dd class="mt-1 text-sm">{{ supplier.district ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Registration number</dt>
                    <dd class="mt-1 text-sm">{{ supplier.registration_number ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Tax number</dt>
                    <dd class="mt-1 text-sm">{{ supplier.tax_number ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Payment terms</dt>
                    <dd class="mt-1 text-sm">{{ supplier.payment_terms_days + ' days' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-muted-foreground text-sm font-medium">Notes</dt>
                    <dd class="mt-1 text-sm whitespace-pre-line">
                        <template v-if="supplier.notes">{{ supplier.notes }}</template>
                        <span v-else class="text-muted-foreground">—</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Created</dt>
                    <dd class="mt-1 text-sm">{{ formatDate(supplier.created_at) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Last updated</dt>
                    <dd class="mt-1 text-sm">{{ formatDate(supplier.updated_at) }}</dd>
                </div>
            </dl>
        </div>
    </div>
</template>
