<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import JournalTable from '@/components/payroll/JournalTable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { show as deliveryShow } from '@/routes/deliveries';
import { show as invoiceShow } from '@/routes/sales-invoices';
import { index, reverse } from '@/routes/sales-returns';
import type { SalesReturnRow } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

type InvoiceRef = { id: number; invoice_number: string } | null;

const props = defineProps<{
    salesReturn: SalesReturnRow & {
        delivery_id: number | null;
        uom: string | null;
        secondary_uom: string | null;
        good_quantity: string;
        damaged_quantity: string;
        secondary_quantity: string | null;
        unit_price: string;
        good_cost: string;
        damaged_cost: string;
        warehouse: string | null;
        lot_number: string | null;
        original_invoice: InvoiceRef;
        credit_invoice: InvoiceRef;
        payment_account: string | null;
        payment_method_label: string | null;
        reference: string | null;
        inventory_reference: string | null;
        created_by: string | null;
        posted_at: string | null;
        reversed_by: string | null;
        reversed_at: string | null;
        reversal_journal_number: string | null;
        can_reverse: boolean;
        journal: { id: number; journal_number: string; lines: { account_code: string | null; account_name: string | null; debit: string; credit: string }[] } | null;
    };
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Returns', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();

function reverseReturn(): void {
    if (confirm(t('Reverse this return? The goods leave stock again and the credit is undone.'))) {
        router.patch(reverse(props.salesReturn.id).url, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="salesReturn.return_number" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading :title="salesReturn.return_number" :description="`${salesReturn.return_date} · ${salesReturn.customer_name ?? ''}`" />
                <Badge :variant="salesReturn.is_reversed ? 'destructive' : 'default'" class="w-fit">{{ salesReturn.status_label }}</Badge>
            </div>
            <Button v-if="salesReturn.can_reverse && can('sales.return_reverse')" variant="outline" @click="reverseReturn">{{ t('Reverse') }}</Button>
        </div>

        <div class="grid gap-3 sm:grid-cols-4">
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ salesReturn.product_name }}</p>
                <p class="mt-1 text-sm">{{ t('Good: :quantity', { quantity: Number(salesReturn.good_quantity) }) }} {{ salesReturn.uom }}</p>
                <p class="text-sm">{{ t('Damaged: :quantity', { quantity: Number(salesReturn.damaged_quantity) }) }} {{ salesReturn.uom }}</p>
                <p v-if="salesReturn.secondary_quantity" class="text-sm">{{ Number(salesReturn.secondary_quantity) }} {{ salesReturn.secondary_uom }}</p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Credit (:currency)', { currency }) }}</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ money(salesReturn.credit_amount) }}</p>
                <span class="text-muted-foreground text-xs">{{ t('at :price each', { price: money(salesReturn.unit_price) }) }}</span>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Settled by') }}</p>
                <p class="mt-1 text-sm font-medium">{{ salesReturn.settlement_label }}</p>
                <Link v-if="salesReturn.credit_invoice" :href="invoiceShow(salesReturn.credit_invoice.id)" class="font-mono text-xs hover:underline">{{ salesReturn.credit_invoice.invoice_number }}</Link>
                <span v-else class="text-muted-foreground text-xs">{{ salesReturn.payment_method_label }} · {{ salesReturn.payment_account }}</span>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Cost back in stock / lost') }}</p>
                <p class="mt-1 font-mono text-sm">{{ money(salesReturn.good_cost) }} / {{ money(salesReturn.damaged_cost) }}</p>
                <span class="text-muted-foreground text-xs">{{ salesReturn.warehouse }}</span>
            </div>
        </div>

        <JournalTable v-if="salesReturn.journal" :journal="salesReturn.journal" />

        <dl class="grid gap-6 rounded-xl border p-4 sm:grid-cols-3 sm:p-6">
            <div>
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Reason') }}</dt>
                <dd class="mt-1 text-sm">{{ salesReturn.reason }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Delivery') }}</dt>
                <dd class="mt-1 text-sm">
                    <Link v-if="salesReturn.delivery_id" :href="deliveryShow(salesReturn.delivery_id)" class="font-mono hover:underline">{{ salesReturn.delivery_number }}</Link>
                    <span v-if="salesReturn.original_invoice" class="text-muted-foreground font-mono"> · {{ salesReturn.original_invoice.invoice_number }}</span>
                    <span v-if="salesReturn.lot_number" class="text-muted-foreground font-mono"> · {{ salesReturn.lot_number }}</span>
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Recorded') }}</dt>
                <dd class="mt-1 text-sm">{{ salesReturn.posted_at ?? '—' }} · {{ salesReturn.created_by ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Stock movement') }}</dt>
                <dd class="mt-1 font-mono text-sm">{{ salesReturn.inventory_reference ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Reversed') }}</dt>
                <dd class="mt-1 text-sm">
                    {{ salesReturn.reversed_at ? `${salesReturn.reversed_at} · ${salesReturn.reversed_by ?? '—'}` : '—' }}
                    <span v-if="salesReturn.reversal_journal_number" class="font-mono text-xs">({{ salesReturn.reversal_journal_number }})</span>
                </dd>
            </div>
        </dl>
    </div>
</template>
