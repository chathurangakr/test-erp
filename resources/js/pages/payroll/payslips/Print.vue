<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, FileDown } from '@lucide/vue';
import { computed } from 'vue';
import PrintButton from '@/components/print/PrintButton.vue';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';
import { payslips, show } from '@/routes/payrolls';
import type { CompanyLetterhead } from '@/types';

type Slip = {
    employee_id: number;
    code: string | null;
    name: string | null;
    nic: string | null;
    payroll_number: string;
    period_start: string;
    period_end: string;
    pay_date: string;
    paid_by: string;
    reference: string | null;
    reversed: boolean;
    days: { date: string; status: string; rate: string; pay: string }[];
    full_days: number;
    half_days: number;
    leave_days: number;
    gross_pay: string;
    advances_deducted: string;
    net_pay: string;
    advance_still_owed: string;
};

const props = defineProps<{
    payroll: { id: number; payroll_number: string };
    slips: Slip[];
    employeeId: number | null;
    currency: string;
}>();

const { t } = useT();
const page = usePage();
const company = computed(() => page.props.company as CompanyLetterhead | null);
const pdfUrl = computed(() => payslips(props.payroll.id, { query: { employee_id: props.employeeId ?? undefined, format: 'pdf' } }).url);
</script>

<template>
    <Head :title="t('Payslips — :number', { number: payroll.payroll_number })" />

    <div class="mx-auto mb-4 flex w-[148mm] max-w-full justify-between gap-2 px-4 print:hidden">
        <Button variant="outline" as-child>
            <Link :href="show(payroll.id)"><ArrowLeft class="size-4" />{{ t('Back to salaries') }}</Link>
        </Button>
        <div class="flex gap-2">
            <Button variant="outline" as="a" :href="pdfUrl" download><FileDown class="size-4" />{{ t('PDF') }}</Button>
            <PrintButton />
        </div>
    </div>

    <article
        v-for="slip in slips"
        :key="slip.employee_id"
        class="relative mx-auto mb-6 flex w-[148mm] max-w-full break-after-page flex-col bg-white p-[8mm] text-[12px] leading-snug shadow-lg print:mb-0 print:w-auto print:shadow-none"
    >
        <p v-if="slip.reversed" class="mb-3 rounded border-2 border-red-700 px-3 py-1 text-center font-bold text-red-700">{{ t('REVERSED — this payment was undone') }}</p>

        <header class="flex items-center justify-between gap-4 border-b-2 border-neutral-800 pb-2">
            <div class="flex items-center gap-3">
                <img v-if="company?.logo_url" :src="company.logo_url" alt="" class="h-12 w-auto object-contain" />
                <div>
                    <p class="font-semibold">{{ company?.name }}</p>
                    <p v-if="company?.address" class="text-[10px] whitespace-pre-line text-neutral-600">{{ company.address }}</p>
                </div>
            </div>
            <h1 class="text-xl font-bold tracking-widest">{{ t('PAYSLIP') }}</h1>
        </header>

        <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-0.5">
            <dt class="text-neutral-600">{{ t('Employee') }}</dt>
            <dd><strong>{{ slip.name }}</strong> ({{ slip.code }})<span v-if="slip.nic"> · {{ t('NIC') }} {{ slip.nic }}</span></dd>
            <dt class="text-neutral-600">{{ t('Period') }}</dt>
            <dd>{{ slip.period_start }} → {{ slip.period_end }}</dd>
            <dt class="text-neutral-600">{{ t('Paid on') }}</dt>
            <dd>{{ slip.pay_date }} · {{ slip.paid_by }}<span v-if="slip.reference"> · {{ slip.reference }}</span></dd>
            <dt class="text-neutral-600">{{ t('Payroll') }}</dt>
            <dd class="font-mono">{{ slip.payroll_number }}</dd>
        </dl>

        <table class="mt-3 w-full border-collapse">
            <thead>
                <tr class="border-y border-neutral-800 text-left">
                    <th class="py-1 font-semibold">{{ t('Date') }}</th>
                    <th class="py-1 font-semibold">{{ t('Day') }}</th>
                    <th class="py-1 text-right font-semibold">{{ t('Day rate') }}</th>
                    <th class="py-1 text-right font-semibold">{{ t('Pay') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="day in slip.days" :key="day.date" class="border-b border-neutral-200">
                    <td class="py-0.5">{{ day.date }}</td>
                    <td class="py-0.5">{{ day.status }}</td>
                    <td class="py-0.5 text-right font-mono">{{ money(day.rate) }}</td>
                    <td class="py-0.5 text-right font-mono">{{ money(day.pay) }}</td>
                </tr>
            </tbody>
        </table>

        <div class="mt-3 ml-auto w-64">
            <div class="flex justify-between py-0.5"><span>{{ t('Full / half days / leave') }}</span><span>{{ slip.full_days }} / {{ slip.half_days }} / {{ slip.leave_days }}</span></div>
            <div class="flex justify-between py-0.5"><span>{{ t('Earned') }}</span><span class="font-mono">{{ money(slip.gross_pay) }}</span></div>
            <div class="flex justify-between py-0.5"><span>{{ t('Advance taken back') }}</span><span class="font-mono">− {{ money(slip.advances_deducted) }}</span></div>
            <div class="flex justify-between border-t-2 border-neutral-800 py-1 text-base font-bold"><span>{{ t('Paid (:currency)', { currency }) }}</span><span class="font-mono">{{ money(slip.net_pay) }}</span></div>
            <div class="flex justify-between py-0.5 text-neutral-600"><span>{{ t('Advance still owed') }}</span><span class="font-mono">{{ money(slip.advance_still_owed) }}</span></div>
        </div>

        <footer class="mt-10 grid grid-cols-2 gap-10 text-center text-[10px]">
            <p class="border-t border-neutral-500 pt-1">{{ t('Paid by') }}</p>
            <p class="border-t border-neutral-500 pt-1">{{ t('Received by (:name)', { name: slip.name ?? '' }) }}</p>
        </footer>
    </article>
</template>
