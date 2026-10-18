<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import JournalTable from '@/components/payroll/JournalTable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { show as employeeShow } from '@/routes/employees';
import { index, reverse } from '@/routes/payrolls';
import type { PayrollDetail } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{ payroll: PayrollDetail; currency: string }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Monthly salaries', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();

function reversePayroll(): void {
    if (confirm(t("Reverse this month's salaries? The money is put back, the attendance days can be changed again, and the advances taken back are owed again."))) {
        router.patch(reverse(props.payroll.id).url, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="payroll.payroll_number" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading
                    :title="t('Salaries for :month', { month: payroll.month })"
                    :description="`${payroll.payroll_number} · ${payroll.pay_date} · ${payroll.payment_account ?? ''}`"
                />
                <Badge :variant="payroll.status === 'REVERSED' ? 'destructive' : 'default'" class="w-fit">{{ payroll.status_label }}</Badge>
            </div>
            <Button v-if="payroll.can_reverse && can('payroll.salary_reverse')" variant="outline" @click="reversePayroll">{{ t('Reverse') }}</Button>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Earned') }}</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ money(payroll.gross_pay) }}</p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Advances taken back') }}</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ money(payroll.advances_deducted) }}</p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Paid (:currency)', { currency }) }}</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ money(payroll.net_pay) }}</p>
                <span class="text-muted-foreground text-xs">{{ payroll.payment_method_label }}</span>
            </div>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Employee') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Full day') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Half day') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Leave') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Earned') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Taken back') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Paid') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="line in payroll.lines" :key="line.employee_id" class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                        <td class="px-4 py-3">
                            <Link :href="employeeShow(line.employee_id)" class="hover:underline">{{ line.name }}</Link>
                            <span class="text-muted-foreground block font-mono text-xs">{{ line.code }}</span>
                        </td>
                        <td class="px-4 py-3 text-right">{{ line.full_days }}</td>
                        <td class="px-4 py-3 text-right">{{ line.half_days }}</td>
                        <td class="px-4 py-3 text-right">{{ line.leave_days }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(line.gross_pay) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(line.advances_deducted) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(line.net_pay) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <JournalTable v-if="payroll.journal" :journal="payroll.journal" />

        <dl class="grid gap-6 rounded-xl border p-4 sm:grid-cols-3 sm:p-6">
            <div>
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Paid') }}</dt>
                <dd class="mt-1 text-sm">{{ payroll.posted_at ?? '—' }} · {{ payroll.created_by ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Reversed') }}</dt>
                <dd class="mt-1 text-sm">
                    {{ payroll.reversed_at ? `${payroll.reversed_at} · ${payroll.reversed_by ?? '—'}` : '—' }}
                    <span v-if="payroll.reversal_journal_number" class="font-mono text-xs">({{ payroll.reversal_journal_number }})</span>
                </dd>
            </div>
            <div v-if="payroll.reference">
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Reference') }}</dt>
                <dd class="mt-1 text-sm">{{ payroll.reference }}</dd>
            </div>
        </dl>
    </div>
</template>
