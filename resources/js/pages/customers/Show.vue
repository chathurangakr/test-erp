<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import CustomerStatusController from '@/actions/App/Http/Controllers/Customers/CustomerStatusController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { index } from '@/routes/customers';
import { index as deliveriesIndex } from '@/routes/deliveries';
import { index as salesOrdersIndex } from '@/routes/sales-orders';
import { index as invoicesIndex } from '@/routes/sales-invoices';
import { index as receiptsIndex } from '@/routes/customer-receipts';
import type { Customer, CustomerReceivablesSummary } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

defineProps<{
    customer: Customer;
    salesHistory: {
        sales_orders: { id: number; so_number: string; order_date: string | null; status_label: string }[];
        deliveries: { id: number; delivery_number: string; delivery_date: string | null; status_label: string }[];
        delivered_value: string;
    };
    receivables: CustomerReceivablesSummary;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Customers', href: index() }],
    },
});

const { can } = usePermissions();

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleString();
}

const { t } = useT();
</script>

<template>
    <Head :title="customer.code" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading :title="customer.name" :description="customer.code" />
                <div class="flex flex-wrap gap-2">
                    <Badge :variant="customer.is_active ? 'default' : 'secondary'">
                        {{ customer.is_active ? t('Active') : t('Inactive') }}
                    </Badge>
                </div>
            </div>

            <div v-if="can('customers.manage')" class="flex flex-col gap-2 sm:flex-row">
                <Button variant="outline" as-child>
                    <Link :href="`${index().url}/${customer.id}/edit`">
                        <Pencil class="size-4" />
                        {{ t('Edit') }}
                    </Link>
                </Button>
                <Form v-bind="CustomerStatusController.update.form(customer.id)" v-slot="{ processing }">
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">
                        {{ customer.is_active ? t('Deactivate') : t('Activate') }}
                    </Button>
                </Form>
            </div>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl rounded-xl border p-4 sm:p-6">
            <dl class="grid gap-6 sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Code') }}</dt>
                    <dd class="mt-1 font-mono text-sm">{{ customer.code }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Name') }}</dt>
                    <dd class="mt-1 text-sm">{{ customer.name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Contact person') }}</dt>
                    <dd class="mt-1 text-sm">{{ customer.contact_person ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Email') }}</dt>
                    <dd class="mt-1 text-sm">{{ customer.email ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Phone') }}</dt>
                    <dd class="mt-1 text-sm">{{ customer.phone ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Secondary phone') }}</dt>
                    <dd class="mt-1 text-sm">{{ customer.secondary_phone ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Address line 1') }}</dt>
                    <dd class="mt-1 text-sm">{{ customer.address_line_1 ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Address line 2') }}</dt>
                    <dd class="mt-1 text-sm">{{ customer.address_line_2 ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('City') }}</dt>
                    <dd class="mt-1 text-sm">{{ customer.city ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('District') }}</dt>
                    <dd class="mt-1 text-sm">{{ customer.district ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Registration number') }}</dt>
                    <dd class="mt-1 text-sm">{{ customer.registration_number ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Tax number') }}</dt>
                    <dd class="mt-1 text-sm">{{ customer.tax_number ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Credit limit') }}</dt>
                    <dd class="mt-1 text-sm">{{ money(customer.credit_limit) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Credit days') }}</dt>
                    <dd class="mt-1 text-sm">{{ t(':days days', { days: customer.credit_days }) }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Notes') }}</dt>
                    <dd class="mt-1 text-sm whitespace-pre-line">
                        <template v-if="customer.notes">{{ customer.notes }}</template>
                        <span v-else class="text-muted-foreground">—</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Created') }}</dt>
                    <dd class="mt-1 text-sm">{{ formatDate(customer.created_at) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Last updated') }}</dt>
                    <dd class="mt-1 text-sm">{{ formatDate(customer.updated_at) }}</dd>
                </div>
            </dl>
        </div>

        <!-- Sales history -->
        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl rounded-xl border p-4 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-medium">{{ t('Sales history') }}</h3>
                <p class="text-sm">
                    {{ t('Delivered value:') }}
                    <span class="ml-1 font-mono font-medium">{{ money(salesHistory.delivered_value) }}</span>
                </p>
            </div>

            <div class="mt-4 grid gap-6 sm:grid-cols-2">
                <div>
                    <p class="text-muted-foreground text-xs font-medium">{{ t('Recent sales orders') }}</p>
                    <ul v-if="salesHistory.sales_orders.length" class="mt-2 space-y-1.5">
                        <li
                            v-for="so in salesHistory.sales_orders"
                            :key="so.id"
                            class="flex flex-wrap items-center justify-between gap-2 text-xs"
                        >
                            <Link
                                v-if="can('sales.view')"
                                :href="`${salesOrdersIndex().url}/${so.id}`"
                                class="font-mono hover:underline"
                            >
                                {{ so.so_number }}
                            </Link>
                            <span v-else class="font-mono">{{ so.so_number }}</span>
                            <span class="text-muted-foreground">{{ so.order_date }}</span>
                            <Badge variant="secondary">{{ so.status_label }}</Badge>
                        </li>
                    </ul>
                    <p v-else class="text-muted-foreground mt-2 text-xs">{{ t('None yet.') }}</p>
                </div>

                <div>
                    <p class="text-muted-foreground text-xs font-medium">{{ t('Recent deliveries') }}</p>
                    <ul v-if="salesHistory.deliveries.length" class="mt-2 space-y-1.5">
                        <li
                            v-for="dn in salesHistory.deliveries"
                            :key="dn.id"
                            class="flex flex-wrap items-center justify-between gap-2 text-xs"
                        >
                            <Link
                                v-if="can('sales.view')"
                                :href="`${deliveriesIndex().url}/${dn.id}`"
                                class="font-mono hover:underline"
                            >
                                {{ dn.delivery_number }}
                            </Link>
                            <span v-else class="font-mono">{{ dn.delivery_number }}</span>
                            <span class="text-muted-foreground">{{ dn.delivery_date }}</span>
                            <Badge variant="secondary">{{ dn.status_label }}</Badge>
                        </li>
                    </ul>
                    <p v-else class="text-muted-foreground mt-2 text-xs">{{ t('None yet.') }}</p>
                </div>
            </div>

            <p class="text-muted-foreground mt-4 text-xs">
                {{ t('Delivered value counts posted deliveries only. A delivery is a physical issue — what the customer owes appears below, once the delivery is invoiced.') }}
            </p>
        </div>

        <!-- Accounts receivable -->
        <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="text-sm font-medium">{{ t('Accounts receivable') }}</h3>
                <Link
                    v-if="can('finance.ar.view')"
                    :href="`${invoicesIndex().url}?customer_id=${customer.id}`"
                    class="text-xs hover:underline"
                >
                    {{ t('All invoices') }}
                </Link>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <p class="text-muted-foreground text-xs font-medium">
                        {{ t('Outstanding (:currency)', { currency: receivables.currency }) }}
                    </p>
                    <p class="mt-1 font-mono text-lg font-medium">{{ money(receivables.outstanding) }}</p>
                </div>
                <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <p class="text-muted-foreground text-xs font-medium">{{ t('Overdue') }}</p>
                    <p
                        class="mt-1 font-mono text-lg font-medium"
                        :class="Number(receivables.overdue) > 0 ? 'text-destructive' : ''"
                    >
                        {{ receivables.overdue }}
                    </p>
                </div>
            </div>

            <div v-if="receivables.invoices.length" class="mt-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Recent invoices') }}</p>
                <ul class="mt-2 space-y-1 text-sm">
                    <li v-for="invoice in receivables.invoices" :key="invoice.id" class="flex justify-between gap-3">
                        <Link
                            v-if="can('finance.ar.view')"
                            :href="`${invoicesIndex().url}/${invoice.id}`"
                            class="font-mono text-xs hover:underline"
                        >
                            {{ invoice.invoice_number }}
                        </Link>
                        <span v-else class="font-mono text-xs">{{ invoice.invoice_number }}</span>
                        <span class="text-muted-foreground text-xs">
                            {{ invoice.invoice_date }} · {{ invoice.status_label }}
                        </span>
                        <span class="font-mono text-xs">{{ money(invoice.outstanding) }}</span>
                    </li>
                </ul>
            </div>

            <div v-if="receivables.receipts.length" class="mt-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Recent receipts') }}</p>
                <ul class="mt-2 space-y-1 text-sm">
                    <li v-for="receipt in receivables.receipts" :key="receipt.id" class="flex justify-between gap-3">
                        <Link
                            v-if="can('finance.ar.view')"
                            :href="`${receiptsIndex().url}/${receipt.id}`"
                            class="font-mono text-xs hover:underline"
                        >
                            {{ receipt.receipt_number }}
                        </Link>
                        <span v-else class="font-mono text-xs">{{ receipt.receipt_number }}</span>
                        <span class="text-muted-foreground text-xs">
                            {{ receipt.receipt_date }} · {{ receipt.status_label }}
                        </span>
                        <span class="font-mono text-xs">{{ money(receipt.amount) }}</span>
                    </li>
                </ul>
            </div>

            <p class="text-muted-foreground mt-4 text-xs">
                {{ t('Derived from posted invoices less the receipts still allocated to them. A customer holds no balance column — this is computed every time it is shown. Credit limit and credit days are agreed terms, not balances.') }}
            </p>
        </div>
    </div>
</template>
