<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed } from 'vue';
import PrintButton from '@/components/print/PrintButton.vue';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';
import { index } from '@/routes/sales-invoices';
import type { CompanyLetterhead, SalesInvoicePrint } from '@/types';

const props = defineProps<{ invoice: SalesInvoicePrint; currency: string }>();

const { t } = useT();
const page = usePage();
const company = computed(() => page.props.company as CompanyLetterhead | null);

/** A quantity without trailing zeros: "1,250" or "312.5". */
function quantity(value: string | null): string {
    if (value === null) {
        return '';
    }

    const [whole, fraction = ''] = value.split('.');
    const decimals = fraction.replace(/0+$/, '');

    return Number(whole).toLocaleString('en-US') + (decimals ? `.${decimals}` : '');
}

/** Only a posted invoice is one the customer can rely on; anything else is marked across the sheet. */
const notValid = computed(() => {
    switch (props.invoice.status) {
        case 'DRAFT':
            return t('DRAFT — not a valid invoice');
        case 'REVERSED':
        case 'CANCELLED':
            return t(':status — this invoice is void', { status: props.invoice.status_label.toUpperCase() });
        default:
            return null;
    }
});
</script>

<template>
    <Head :title="invoice.invoice_number" />

    <div class="mx-auto mb-4 flex w-[210mm] max-w-full justify-between gap-2 px-4 print:hidden">
        <Button variant="outline" as-child>
            <Link :href="`${index().url}/${invoice.id}`">
                <ArrowLeft class="size-4" />
                {{ t('Back to invoice') }}
            </Link>
        </Button>
        <PrintButton />
    </div>

    <article
        class="relative mx-auto flex min-h-[297mm] w-[210mm] max-w-full flex-col bg-white p-[12mm] text-[13px] leading-snug shadow-lg print:min-h-[272mm] print:w-auto print:p-0 print:shadow-none"
    >
        <p
            v-if="notValid"
            class="mb-4 rounded border-2 border-red-700 px-3 py-2 text-center text-base font-bold tracking-wide text-red-700"
        >
            {{ notValid }}
        </p>

        <header class="flex items-start justify-between gap-6 border-b-2 border-neutral-800 pb-4">
            <div class="flex items-center gap-4">
                <img v-if="company?.logo_url" :src="company.logo_url" alt="" class="h-28 w-auto object-contain" />
                <div>
                    <p class="text-xl font-semibold tracking-wide">{{ company?.name }}</p>
                    <p v-if="company?.address" class="whitespace-pre-line">{{ company.address }}</p>
                    <p v-if="company?.phone">{{ t('Tel: :phone', { phone: company.phone }) }}</p>
                    <p v-if="company?.email">{{ company.email }}</p>
                    <p v-if="company?.registration_number">{{ t('Reg. No: :number', { number: company.registration_number }) }}</p>
                </div>
            </div>
            <div class="text-right">
                <h1 class="text-3xl font-bold tracking-widest">{{ t('INVOICE') }}</h1>
                <table class="mt-2 ml-auto text-left">
                    <tbody>
                        <tr>
                            <th class="pr-3 font-medium text-neutral-600">{{ t('Invoice no.') }}</th>
                            <td class="font-mono">{{ invoice.invoice_number }}</td>
                        </tr>
                        <tr>
                            <th class="pr-3 font-medium text-neutral-600">{{ t('Date') }}</th>
                            <td>{{ invoice.invoice_date }}</td>
                        </tr>
                        <tr>
                            <th class="pr-3 font-medium text-neutral-600">{{ t('Due date') }}</th>
                            <td>{{ invoice.due_date }}</td>
                        </tr>
                        <tr v-if="invoice.customer_reference">
                            <th class="pr-3 font-medium text-neutral-600">{{ t('Your reference') }}</th>
                            <td>{{ invoice.customer_reference }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </header>

        <section class="mt-5">
            <p class="text-xs font-semibold tracking-wider text-neutral-600 uppercase">{{ t('Bill to') }}</p>
            <p class="mt-1 text-base font-semibold">{{ invoice.customer.name }}</p>
            <p class="text-neutral-600">{{ invoice.customer.code }}</p>
            <p v-if="invoice.customer.contact_person">{{ invoice.customer.contact_person }}</p>
            <p v-if="invoice.customer.address">{{ invoice.customer.address }}</p>
            <p v-if="invoice.customer.phone">{{ t('Tel: :phone', { phone: invoice.customer.phone }) }}</p>
            <p v-if="invoice.customer.tax_number">{{ t('Tax no: :number', { number: invoice.customer.tax_number }) }}</p>
        </section>

        <table class="mt-6 w-full border-collapse">
            <thead>
                <tr class="border-y-2 border-neutral-800 text-left">
                    <th class="w-8 py-2 pr-2 font-semibold">#</th>
                    <th class="py-2 pr-2 font-semibold">{{ t('Description') }}</th>
                    <th class="py-2 pr-2 text-right font-semibold">{{ t('Quantity') }}</th>
                    <th class="py-2 pr-2 text-right font-semibold">{{ t('Unit price') }}</th>
                    <th class="py-2 text-right font-semibold">{{ t('Amount (:currency)', { currency }) }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(line, i) in invoice.lines" :key="line.id" class="border-b border-neutral-300 align-top">
                    <td class="py-2 pr-2">{{ i + 1 }}</td>
                    <td class="py-2 pr-2">
                        <span class="font-medium">{{ line.product_name }}</span>
                        <span v-if="line.description" class="block">{{ line.description }}</span>
                        <span v-if="line.delivery_number" class="block text-xs text-neutral-600">
                            {{ t('Delivery :number of :date', { number: line.delivery_number, date: line.delivery_date ?? '' }) }}
                        </span>
                        <span
                            v-if="line.delivered_value !== null && money(line.delivered_value) !== money(line.amount)"
                            class="block text-xs text-neutral-600"
                        >
                            {{ t('Part of the delivered value of :amount', { amount: money(line.delivered_value) }) }}
                        </span>
                    </td>
                    <td class="py-2 pr-2 text-right whitespace-nowrap">{{ quantity(line.quantity) }} {{ line.uom }}</td>
                    <td class="py-2 pr-2 text-right font-mono whitespace-nowrap">{{ money(line.unit_price, '') }}</td>
                    <td class="py-2 text-right font-mono whitespace-nowrap">{{ money(line.amount) }}</td>
                </tr>
            </tbody>
        </table>

        <div class="mt-4 ml-auto w-72">
            <div class="flex justify-between border-b-2 border-neutral-800 py-1 text-base font-bold">
                <span>{{ t('Total (:currency)', { currency }) }}</span>
                <span class="font-mono">{{ money(invoice.total) }}</span>
            </div>
            <template v-if="Number(invoice.received) > 0">
                <div class="flex justify-between py-1">
                    <span>{{ t('Received') }}</span>
                    <span class="font-mono">{{ money(invoice.received) }}</span>
                </div>
                <div class="flex justify-between border-t border-neutral-300 py-1 font-semibold">
                    <span>{{ t('Balance due') }}</span>
                    <span class="font-mono">{{ money(invoice.outstanding) }}</span>
                </div>
            </template>
        </div>

        <section v-if="invoice.description" class="mt-6">
            <p class="text-xs font-semibold tracking-wider text-neutral-600 uppercase">{{ t('Notes') }}</p>
            <p class="mt-1 whitespace-pre-line">{{ invoice.description }}</p>
        </section>

        <footer class="mt-auto pt-16">
            <div class="grid grid-cols-2 gap-16">
                <p class="border-t border-neutral-500 pt-1 text-center text-xs">{{ t('Authorised signature') }}</p>
                <p class="border-t border-neutral-500 pt-1 text-center text-xs">{{ t('Received by (customer)') }}</p>
            </div>
            <p class="mt-6 text-center text-xs text-neutral-600">{{ t('Thank you for your business.') }}</p>
        </footer>
    </article>
</template>
