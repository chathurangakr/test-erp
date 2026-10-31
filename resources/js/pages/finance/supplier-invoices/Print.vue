<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed } from 'vue';
import PrintButton from '@/components/print/PrintButton.vue';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';
import { index } from '@/routes/supplier-invoices';
import type { CompanyLetterhead, SupplierInvoicePrint } from '@/types';

const props = defineProps<{ invoice: SupplierInvoicePrint; currency: string }>();

const { t } = useT();
const page = usePage();
const company = computed(() => page.props.company as CompanyLetterhead | null);

/** Only a posted invoice is one the books rely on; anything else is marked across the sheet. */
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
    <Head :title="invoice.internal_invoice_number" />

    <div class="mx-auto mb-4 flex w-[210mm] max-w-full justify-between gap-2 px-4 print:hidden">
        <Button variant="outline" as-child>
            <Link :href="`${index().url}/${invoice.id}`">
                <ArrowLeft class="size-4" />
                {{ t('Back to invoice') }}
            </Link>
        </Button>
        <div class="flex flex-col items-end gap-1">
            <PrintButton />
            <p class="text-xs text-neutral-600">{{ t('For a PDF file, choose "Save as PDF" as the printer.') }}</p>
        </div>
    </div>

    <article
        class="relative mx-auto flex min-h-[297mm] w-[210mm] max-w-full flex-col bg-white p-[12mm] text-[13px] leading-snug shadow-lg print:min-h-[296mm] print:w-auto print:p-[12mm] print:shadow-none"
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
                <h1 class="text-2xl font-bold tracking-widest">{{ t('SUPPLIER INVOICE') }}</h1>
                <table class="mt-2 ml-auto text-left">
                    <tbody>
                        <tr>
                            <th class="pr-3 font-medium text-neutral-600">{{ t('Our no.') }}</th>
                            <td class="font-mono">{{ invoice.internal_invoice_number }}</td>
                        </tr>
                        <tr>
                            <th class="pr-3 font-medium text-neutral-600">{{ t("Supplier's invoice no.") }}</th>
                            <td class="font-mono">{{ invoice.supplier_invoice_number }}</td>
                        </tr>
                        <tr>
                            <th class="pr-3 font-medium text-neutral-600">{{ t('Date') }}</th>
                            <td>{{ invoice.invoice_date }}</td>
                        </tr>
                        <tr v-if="invoice.due_date">
                            <th class="pr-3 font-medium text-neutral-600">{{ t('Due date') }}</th>
                            <td>{{ invoice.due_date }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </header>

        <section class="mt-5">
            <p class="text-xs font-semibold tracking-wider text-neutral-600 uppercase">{{ t('Supplier') }}</p>
            <p class="mt-1 text-base font-semibold">{{ invoice.supplier.name }}</p>
            <p class="text-neutral-600">{{ invoice.supplier.code }}</p>
            <p v-if="invoice.supplier.contact_person">{{ invoice.supplier.contact_person }}</p>
            <p v-if="invoice.supplier.address">{{ invoice.supplier.address }}</p>
            <p v-if="invoice.supplier.phone">{{ t('Tel: :phone', { phone: invoice.supplier.phone }) }}</p>
            <p v-if="invoice.supplier.tax_number">{{ t('Tax no: :number', { number: invoice.supplier.tax_number }) }}</p>
        </section>

        <table class="mt-6 w-full border-collapse">
            <thead>
                <tr class="border-y-2 border-neutral-800 text-left">
                    <th class="w-8 py-2 pr-2 font-semibold">#</th>
                    <th class="py-2 pr-2 font-semibold">{{ t('Description') }}</th>
                    <th class="py-2 text-right font-semibold">{{ t('Amount (:currency)', { currency }) }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(line, i) in invoice.lines" :key="line.id" class="border-b border-neutral-300 align-top">
                    <td class="py-2 pr-2">{{ i + 1 }}</td>
                    <td class="py-2 pr-2">
                        <span class="font-medium">{{ line.line_type_label }}</span>
                        <span v-if="line.description" class="block">{{ line.description }}</span>
                        <span v-if="line.grn_number" class="block text-xs text-neutral-600">
                            {{ t('Goods receipt :number of :date', { number: line.grn_number, date: line.receipt_date ?? '' }) }}
                        </span>
                        <span v-if="line.account_code" class="block text-xs text-neutral-600">
                            {{ line.account_code }} — {{ line.account_name }}
                        </span>
                    </td>
                    <td class="py-2 text-right font-mono whitespace-nowrap">{{ money(line.amount) }}</td>
                </tr>
            </tbody>
        </table>

        <div class="mt-4 ml-auto w-72">
            <div class="flex justify-between border-b-2 border-neutral-800 py-1 text-base font-bold">
                <span>{{ t('Total (:currency)', { currency }) }}</span>
                <span class="font-mono">{{ money(invoice.total) }}</span>
            </div>
            <template v-if="Number(invoice.paid) > 0">
                <div class="flex justify-between py-1">
                    <span>{{ t('Paid') }}</span>
                    <span class="font-mono">{{ money(invoice.paid) }}</span>
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
                <p class="border-t border-neutral-500 pt-1 text-center text-xs">{{ t('Prepared by') }}</p>
                <p class="border-t border-neutral-500 pt-1 text-center text-xs">{{ t('Authorised signature') }}</p>
            </div>
        </footer>
    </article>
</template>
