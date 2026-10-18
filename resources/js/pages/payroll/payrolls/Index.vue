<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { create, index, show } from '@/routes/payrolls';
import type { Paginated, PayrollRow } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

defineProps<{ payrolls: Paginated<PayrollRow>; currency: string }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Monthly salaries', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();
</script>

<template>
    <Head :title="t('Monthly salaries')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading title="Monthly salaries" description="Each month's salaries, worked out from attendance and paid in one step." />
            <Button v-if="can('payroll.salary_pay')" as-child>
                <Link :href="create()"><Plus class="size-4" />{{ t('Pay a month') }}</Link>
            </Button>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Month') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Paid on') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Employees') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Earned') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Advances taken back') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Paid (:currency)', { currency }) }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="run in payrolls.data" :key="run.id" class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t">
                        <td class="px-4 py-3">
                            <Link :href="show(run.id)" class="font-medium hover:underline">{{ run.month }}</Link>
                            <span class="text-muted-foreground block font-mono text-xs">{{ run.payroll_number }}</span>
                        </td>
                        <td class="px-4 py-3">{{ run.pay_date }}</td>
                        <td class="px-4 py-3 text-right">{{ run.employees }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(run.gross_pay) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(run.advances_deducted) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(run.net_pay) }}</td>
                        <td class="px-4 py-3">
                            <Badge :variant="run.status === 'REVERSED' ? 'destructive' : 'default'">{{ run.status_label }}</Badge>
                        </td>
                    </tr>
                    <tr v-if="payrolls.data.length === 0">
                        <td colspan="7" class="text-muted-foreground px-4 py-8 text-center">{{ t('No salaries paid yet.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="payrolls.last_page > 1" class="flex flex-wrap gap-1">
            <template v-for="link in payrolls.links" :key="link.label">
                <Link v-if="link.url" :href="link.url" class="rounded-md border px-3 py-1 text-sm" :class="link.active ? 'bg-muted font-medium' : ''" preserve-state>
                    <span v-html="link.label" />
                </Link>
            </template>
        </div>
    </div>
</template>
