<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { HandCoins, Pencil } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { edit, index } from '@/routes/employees';
import { create as advanceCreate, show as advanceShow } from '@/routes/salary-advances';
import { show as payrollShow } from '@/routes/payrolls';
import type { EmployeeDay, EmployeeDetail } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

defineProps<{
    employee: EmployeeDetail;
    attendance: EmployeeDay[];
    advances: { id: number; advance_number: string; advance_date: string; amount: string; status: string; status_label: string }[];
    salaries: {
        payroll_id: number;
        payroll_number: string;
        month: string;
        full_days: number;
        half_days: number;
        leave_days: number;
        gross_pay: string;
        advances_deducted: string;
        net_pay: string;
        reversed: boolean;
    }[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Employees', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();
</script>

<template>
    <Head :title="employee.name" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading :title="employee.name" :description="`${employee.code}${employee.phone ? ' · ' + employee.phone : ''}`" />
                <Badge :variant="employee.is_active ? 'default' : 'outline'" class="w-fit">
                    {{ employee.is_active ? t('Active') : t('Inactive') }}
                </Badge>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row">
                <Button v-if="employee.is_active && can('payroll.advance_pay')" variant="outline" as-child>
                    <Link :href="advanceCreate({ query: { employee_id: employee.id } })"><HandCoins class="size-4" />{{ t('Pay advance') }}</Link>
                </Button>
                <Button v-if="can('payroll.employees_manage')" variant="outline" as-child>
                    <Link :href="edit(employee.id)"><Pencil class="size-4" />{{ t('Edit') }}</Link>
                </Button>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Daily rate') }}</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ money(employee.daily_rate) }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Advance owed') }}</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ money(employee.advances_owed ?? '0') }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('NIC number') }}</p>
                <p class="mt-1 text-lg">{{ employee.nic ?? '—' }}</p>
            </div>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <div class="border-sidebar-border/70 dark:border-sidebar-border border-b px-4 py-3">
                <h3 class="text-sm font-medium">{{ t('Salaries paid') }}</h3>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Month') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Full / half / leave') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Earned') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Advances taken back') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Paid') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="line in salaries" :key="line.payroll_id" class="border-sidebar-border/70 dark:border-sidebar-border border-t" :class="line.reversed ? 'text-muted-foreground line-through' : ''">
                        <td class="px-4 py-3">
                            <Link :href="payrollShow(line.payroll_id)" class="font-medium hover:underline">{{ line.month }}</Link>
                            <span class="text-muted-foreground block font-mono text-xs">{{ line.payroll_number }}</span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ line.full_days }} / {{ line.half_days }} / {{ line.leave_days }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(line.gross_pay) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(line.advances_deducted) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(line.net_pay) }}</td>
                    </tr>
                    <tr v-if="salaries.length === 0">
                        <td colspan="5" class="text-muted-foreground px-4 py-6 text-center">{{ t('No salaries paid yet.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                <div class="border-sidebar-border/70 dark:border-sidebar-border border-b px-4 py-3">
                    <h3 class="text-sm font-medium">{{ t('Attendance (this month and last)') }}</h3>
                </div>
                <table class="w-full text-sm">
                    <tbody>
                        <tr v-for="day in attendance" :key="day.work_date" class="border-sidebar-border/70 dark:border-sidebar-border border-t first:border-t-0">
                            <td class="px-4 py-2 font-mono text-xs">{{ day.work_date }}</td>
                            <td class="px-4 py-2">
                                {{ day.status_label }}
                                <span v-if="day.note" class="text-muted-foreground text-xs"> — {{ day.note }}</span>
                            </td>
                            <td class="px-4 py-2 text-right font-mono">{{ money(day.pay) }}</td>
                            <td class="px-4 py-2 text-right">
                                <Badge v-if="day.paid" variant="secondary">{{ t('Paid') }}</Badge>
                            </td>
                        </tr>
                        <tr v-if="attendance.length === 0">
                            <td class="text-muted-foreground px-4 py-6 text-center">{{ t('No attendance recorded.') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                <div class="border-sidebar-border/70 dark:border-sidebar-border border-b px-4 py-3">
                    <h3 class="text-sm font-medium">{{ t('Salary advances') }}</h3>
                </div>
                <table class="w-full text-sm">
                    <tbody>
                        <tr v-for="advance in advances" :key="advance.id" class="border-sidebar-border/70 dark:border-sidebar-border border-t first:border-t-0">
                            <td class="px-4 py-2">
                                <Link :href="advanceShow(advance.id)" class="font-mono text-xs hover:underline">{{ advance.advance_number }}</Link>
                                <span class="text-muted-foreground block text-xs">{{ advance.advance_date }}</span>
                            </td>
                            <td class="px-4 py-2 text-right font-mono" :class="advance.status === 'REVERSED' ? 'line-through' : ''">{{ money(advance.amount) }}</td>
                            <td class="px-4 py-2 text-right">
                                <Badge :variant="advance.status === 'REVERSED' ? 'destructive' : 'default'">{{ advance.status_label }}</Badge>
                            </td>
                        </tr>
                        <tr v-if="advances.length === 0">
                            <td class="text-muted-foreground px-4 py-6 text-center">{{ t('No advances.') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <p v-if="employee.notes" class="text-muted-foreground text-sm">{{ employee.notes }}</p>
    </div>
</template>
