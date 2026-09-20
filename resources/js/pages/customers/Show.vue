<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import CustomerStatusController from '@/actions/App/Http/Controllers/Customers/CustomerStatusController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/customers';
import { index as deliveriesIndex } from '@/routes/deliveries';
import { index as salesOrdersIndex } from '@/routes/sales-orders';
import type { Customer } from '@/types';

defineProps<{
    customer: Customer;
    salesHistory: {
        sales_orders: { id: number; so_number: string; order_date: string | null; status_label: string }[];
        deliveries: { id: number; delivery_number: string; delivery_date: string | null; status_label: string }[];
        delivered_value: string;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Customers', href: index() }],
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
    <Head :title="customer.code" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading :title="customer.name" :description="customer.code" />
                <div class="flex flex-wrap gap-2">
                    <Badge :variant="customer.is_active ? 'default' : 'secondary'">
                        {{ customer.is_active ? 'Active' : 'Inactive' }}
                    </Badge>
                </div>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <Button variant="outline" as-child>
                    <Link :href="`${index().url}/${customer.id}/edit`">
                        <Pencil class="size-4" />
                        Edit
                    </Link>
                </Button>
                <Form v-bind="CustomerStatusController.update.form(customer.id)" v-slot="{ processing }">
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">
                        {{ customer.is_active ? 'Deactivate' : 'Activate' }}
                    </Button>
                </Form>
            </div>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl rounded-xl border p-4 sm:p-6">
            <dl class="grid gap-6 sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Code</dt>
                    <dd class="mt-1 font-mono text-sm">{{ customer.code }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Name</dt>
                    <dd class="mt-1 text-sm">{{ customer.name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Contact person</dt>
                    <dd class="mt-1 text-sm">{{ customer.contact_person ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Email</dt>
                    <dd class="mt-1 text-sm">{{ customer.email ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Phone</dt>
                    <dd class="mt-1 text-sm">{{ customer.phone ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Secondary phone</dt>
                    <dd class="mt-1 text-sm">{{ customer.secondary_phone ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-muted-foreground text-sm font-medium">Address line 1</dt>
                    <dd class="mt-1 text-sm">{{ customer.address_line_1 ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-muted-foreground text-sm font-medium">Address line 2</dt>
                    <dd class="mt-1 text-sm">{{ customer.address_line_2 ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">City</dt>
                    <dd class="mt-1 text-sm">{{ customer.city ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">District</dt>
                    <dd class="mt-1 text-sm">{{ customer.district ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Registration number</dt>
                    <dd class="mt-1 text-sm">{{ customer.registration_number ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Tax number</dt>
                    <dd class="mt-1 text-sm">{{ customer.tax_number ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Credit limit</dt>
                    <dd class="mt-1 text-sm">{{ customer.credit_limit }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Credit days</dt>
                    <dd class="mt-1 text-sm">{{ customer.credit_days + ' days' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-muted-foreground text-sm font-medium">Notes</dt>
                    <dd class="mt-1 text-sm whitespace-pre-line">
                        <template v-if="customer.notes">{{ customer.notes }}</template>
                        <span v-else class="text-muted-foreground">—</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Created</dt>
                    <dd class="mt-1 text-sm">{{ formatDate(customer.created_at) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Last updated</dt>
                    <dd class="mt-1 text-sm">{{ formatDate(customer.updated_at) }}</dd>
                </div>
            </dl>
        </div>

        <!-- Sales history -->
        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl rounded-xl border p-4 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-medium">Sales history</h3>
                <p class="text-sm">
                    Delivered value:
                    <span class="ml-1 font-mono font-medium">{{ salesHistory.delivered_value }}</span>
                </p>
            </div>

            <div class="mt-4 grid gap-6 sm:grid-cols-2">
                <div>
                    <p class="text-muted-foreground text-xs font-medium">Recent sales orders</p>
                    <ul v-if="salesHistory.sales_orders.length" class="mt-2 space-y-1.5">
                        <li
                            v-for="so in salesHistory.sales_orders"
                            :key="so.id"
                            class="flex flex-wrap items-center justify-between gap-2 text-xs"
                        >
                            <Link :href="`${salesOrdersIndex().url}/${so.id}`" class="font-mono hover:underline">
                                {{ so.so_number }}
                            </Link>
                            <span class="text-muted-foreground">{{ so.order_date }}</span>
                            <Badge variant="secondary">{{ so.status_label }}</Badge>
                        </li>
                    </ul>
                    <p v-else class="text-muted-foreground mt-2 text-xs">None yet.</p>
                </div>

                <div>
                    <p class="text-muted-foreground text-xs font-medium">Recent deliveries</p>
                    <ul v-if="salesHistory.deliveries.length" class="mt-2 space-y-1.5">
                        <li
                            v-for="dn in salesHistory.deliveries"
                            :key="dn.id"
                            class="flex flex-wrap items-center justify-between gap-2 text-xs"
                        >
                            <Link :href="`${deliveriesIndex().url}/${dn.id}`" class="font-mono hover:underline">
                                {{ dn.delivery_number }}
                            </Link>
                            <span class="text-muted-foreground">{{ dn.delivery_date }}</span>
                            <Badge variant="secondary">{{ dn.status_label }}</Badge>
                        </li>
                    </ul>
                    <p v-else class="text-muted-foreground mt-2 text-xs">None yet.</p>
                </div>
            </div>

            <p class="text-muted-foreground mt-4 text-xs">
                Delivered value counts posted deliveries only. Amounts owed by this customer
                are not shown — receivables belong to the finance module, which is not yet
                built.
            </p>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            Credit limit and credit days are agreed terms. Outstanding balances are not
            shown here — receivables are derived from posted transactions in the finance
            module, which is not yet built.
        </p>
    </div>
</template>
