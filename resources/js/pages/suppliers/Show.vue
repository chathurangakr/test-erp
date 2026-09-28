<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import SupplierStatusController from '@/actions/App/Http/Controllers/Suppliers/SupplierStatusController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { index } from '@/routes/suppliers';
import { index as goodsReceiptsIndex } from '@/routes/goods-receipts';
import { index as purchaseOrdersIndex } from '@/routes/purchase-orders';
import { index as invoicesIndex } from '@/routes/supplier-invoices';
import { index as paymentsIndex } from '@/routes/supplier-payments';
import type { Supplier, SupplierPayablesSummary } from '@/types';
import { useT } from '@/lib/i18n';

defineProps<{
    supplier: Supplier;
    purchaseHistory: {
        purchase_orders: { id: number; po_number: string; order_date: string | null; status_label: string }[];
        goods_receipts: { id: number; grn_number: string; receipt_date: string | null; status_label: string }[];
        total_received_value: string;
    };
    payables: SupplierPayablesSummary;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Suppliers', href: index() }],
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
    <Head :title="supplier.code" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading :title="supplier.name" :description="supplier.code" />
                <div class="flex flex-wrap gap-2">
                    <Badge :variant="supplier.is_active ? 'default' : 'secondary'">
                        {{ supplier.is_active ? t('Active') : t('Inactive') }}
                    </Badge>
                </div>
            </div>

            <div v-if="can('suppliers.manage')" class="flex flex-col gap-2 sm:flex-row">
                <Button variant="outline" as-child>
                    <Link :href="`${index().url}/${supplier.id}/edit`">
                        <Pencil class="size-4" />
                        {{ t('Edit') }}
                    </Link>
                </Button>
                <Form v-bind="SupplierStatusController.update.form(supplier.id)" v-slot="{ processing }">
                    <Button type="submit" variant="outline" class="w-full" :disabled="processing">
                        {{ supplier.is_active ? t('Deactivate') : t('Activate') }}
                    </Button>
                </Form>
            </div>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl rounded-xl border p-4 sm:p-6">
            <dl class="grid gap-6 sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Code') }}</dt>
                    <dd class="mt-1 font-mono text-sm">{{ supplier.code }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Name') }}</dt>
                    <dd class="mt-1 text-sm">{{ supplier.name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Contact person') }}</dt>
                    <dd class="mt-1 text-sm">{{ supplier.contact_person ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Email') }}</dt>
                    <dd class="mt-1 text-sm">{{ supplier.email ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Phone') }}</dt>
                    <dd class="mt-1 text-sm">{{ supplier.phone ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Secondary phone') }}</dt>
                    <dd class="mt-1 text-sm">{{ supplier.secondary_phone ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Address line 1') }}</dt>
                    <dd class="mt-1 text-sm">{{ supplier.address_line_1 ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Address line 2') }}</dt>
                    <dd class="mt-1 text-sm">{{ supplier.address_line_2 ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('City') }}</dt>
                    <dd class="mt-1 text-sm">{{ supplier.city ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('District') }}</dt>
                    <dd class="mt-1 text-sm">{{ supplier.district ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Registration number') }}</dt>
                    <dd class="mt-1 text-sm">{{ supplier.registration_number ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Tax number') }}</dt>
                    <dd class="mt-1 text-sm">{{ supplier.tax_number ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Payment terms') }}</dt>
                    <dd class="mt-1 text-sm">{{ t(':days days', { days: supplier.payment_terms_days }) }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Notes') }}</dt>
                    <dd class="mt-1 text-sm whitespace-pre-line">
                        <template v-if="supplier.notes">{{ supplier.notes }}</template>
                        <span v-else class="text-muted-foreground">—</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Created') }}</dt>
                    <dd class="mt-1 text-sm">{{ formatDate(supplier.created_at) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">{{ t('Last updated') }}</dt>
                    <dd class="mt-1 text-sm">{{ formatDate(supplier.updated_at) }}</dd>
                </div>
            </dl>
        </div>

        <!-- Purchase history -->
        <div
            class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl rounded-xl border p-4 sm:p-6"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-medium">{{ t('Purchase history') }}</h3>
                <p class="text-sm">
                    {{ t('Total received value:') }}
                    <span class="ml-1 font-mono font-medium">
                        {{ purchaseHistory.total_received_value }}
                    </span>
                </p>
            </div>

            <div class="mt-4 grid gap-6 sm:grid-cols-2">
                <div>
                    <p class="text-muted-foreground text-xs font-medium">{{ t('Recent purchase orders') }}</p>
                    <ul v-if="purchaseHistory.purchase_orders.length" class="mt-2 space-y-1.5">
                        <li
                            v-for="po in purchaseHistory.purchase_orders"
                            :key="po.id"
                            class="flex flex-wrap items-center justify-between gap-2 text-xs"
                        >
                            <Link
                                v-if="can('purchasing.view')"
                                :href="`${purchaseOrdersIndex().url}/${po.id}`"
                                class="font-mono hover:underline"
                            >
                                {{ po.po_number }}
                            </Link>
                            <span v-else class="font-mono">{{ po.po_number }}</span>
                            <span class="text-muted-foreground">{{ po.order_date }}</span>
                            <Badge variant="secondary">{{ po.status_label }}</Badge>
                        </li>
                    </ul>
                    <p v-else class="text-muted-foreground mt-2 text-xs">{{ t('None yet.') }}</p>
                </div>

                <div>
                    <p class="text-muted-foreground text-xs font-medium">{{ t('Recent goods receipts') }}</p>
                    <ul v-if="purchaseHistory.goods_receipts.length" class="mt-2 space-y-1.5">
                        <li
                            v-for="grn in purchaseHistory.goods_receipts"
                            :key="grn.id"
                            class="flex flex-wrap items-center justify-between gap-2 text-xs"
                        >
                            <Link
                                v-if="can('purchasing.view')"
                                :href="`${goodsReceiptsIndex().url}/${grn.id}`"
                                class="font-mono hover:underline"
                            >
                                {{ grn.grn_number }}
                            </Link>
                            <span v-else class="font-mono">{{ grn.grn_number }}</span>
                            <span class="text-muted-foreground">{{ grn.receipt_date }}</span>
                            <Badge variant="secondary">{{ grn.status_label }}</Badge>
                        </li>
                    </ul>
                    <p v-else class="text-muted-foreground mt-2 text-xs">{{ t('None yet.') }}</p>
                </div>
            </div>

            <p class="text-muted-foreground mt-4 text-xs">
                {{ t('Total received value counts posted receipts only. Amounts owed to this supplier are not shown — payables belong to the finance module, which is not yet built.') }}
            </p>
        </div>

        <!-- Accounts payable -->
        <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="text-sm font-medium">{{ t('Accounts payable') }}</h3>
                <Link
                    v-if="can('finance.ap.view')"
                    :href="`${invoicesIndex().url}?supplier_id=${supplier.id}`"
                    class="text-xs hover:underline"
                >
                    {{ t('All invoices') }}
                </Link>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <p class="text-muted-foreground text-xs font-medium">
                        {{ t('Outstanding (:currency)', { currency: payables.currency }) }}
                    </p>
                    <p class="mt-1 font-mono text-lg font-medium">{{ payables.outstanding }}</p>
                </div>
                <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <p class="text-muted-foreground text-xs font-medium">{{ t('Overdue') }}</p>
                    <p
                        class="mt-1 font-mono text-lg font-medium"
                        :class="Number(payables.overdue) > 0 ? 'text-destructive' : ''"
                    >
                        {{ payables.overdue }}
                    </p>
                </div>
            </div>

            <div v-if="payables.invoices.length" class="mt-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Recent invoices') }}</p>
                <ul class="mt-2 space-y-1 text-sm">
                    <li v-for="invoice in payables.invoices" :key="invoice.id" class="flex justify-between gap-3">
                        <Link
                            v-if="can('finance.ap.view')"
                            :href="`${invoicesIndex().url}/${invoice.id}`"
                            class="font-mono text-xs hover:underline"
                        >
                            {{ invoice.internal_invoice_number }}
                        </Link>
                        <span v-else class="font-mono text-xs">{{ invoice.internal_invoice_number }}</span>
                        <span class="text-muted-foreground text-xs">
                            {{ invoice.invoice_date }} · {{ invoice.status_label }}
                        </span>
                        <span class="font-mono text-xs">{{ invoice.outstanding }}</span>
                    </li>
                </ul>
            </div>

            <div v-if="payables.payments.length" class="mt-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Recent payments') }}</p>
                <ul class="mt-2 space-y-1 text-sm">
                    <li v-for="payment in payables.payments" :key="payment.id" class="flex justify-between gap-3">
                        <Link
                            v-if="can('finance.ap.view')"
                            :href="`${paymentsIndex().url}/${payment.id}`"
                            class="font-mono text-xs hover:underline"
                        >
                            {{ payment.payment_number }}
                        </Link>
                        <span v-else class="font-mono text-xs">{{ payment.payment_number }}</span>
                        <span class="text-muted-foreground text-xs">
                            {{ payment.payment_date }} · {{ payment.status_label }}
                        </span>
                        <span class="font-mono text-xs">{{ payment.amount }}</span>
                    </li>
                </ul>
            </div>

            <p class="text-muted-foreground mt-4 text-xs">
                {{ t('Derived from posted invoices less the payments still allocated to them. A supplier holds no balance column — this is computed every time it is shown.') }}
            </p>
        </div>

    </div>
</template>
