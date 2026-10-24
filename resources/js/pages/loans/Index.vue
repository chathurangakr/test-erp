<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { create, index, show } from '@/routes/loans';
import type { LoanRow } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

defineProps<{ loans: LoanRow[]; showAll: boolean; totals: { outstanding: string }; currency: string }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Loans', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();

function dueTone(loan: LoanRow): string {
    if (loan.next_due_paid || loan.days_to_due === null) return 'text-muted-foreground';
    if (loan.days_to_due === 0) return 'text-red-600 font-semibold';
    if (loan.days_to_due <= 7) return 'text-amber-600 font-medium';
    return '';
}
</script>

<template>
    <Head :title="t('Loans')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading title="Loans" description="Every loan the factory owes, what is still owed and when the next installment is due." />
            <Button v-if="can('loans.manage')" as-child>
                <Link :href="create()"><Plus class="size-4" />{{ t('Add a loan') }}</Link>
            </Button>
        </div>

        <div class="flex flex-wrap items-center gap-4">
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Still owed on all loans (:currency)', { currency }) }}</p>
                <p class="mt-1 font-mono text-2xl font-semibold">{{ money(totals.outstanding) }}</p>
            </div>
            <Button variant="ghost" size="sm" @click="router.get(index().url, showAll ? {} : { all: 1 })">
                {{ showAll ? t('Show only loans still owed') : t('Show paid-off and reversed loans too') }}
            </Button>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Lender') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Borrowed') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Still owed') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Installment') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Next due') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="loan in loans" :key="loan.id" class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t">
                        <td class="px-4 py-3">
                            <Link :href="show(loan.id)" class="font-medium hover:underline">{{ loan.lender }}</Link>
                            <span class="text-muted-foreground block font-mono text-xs">{{ loan.loan_number }}</span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(loan.amount) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(loan.outstanding) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(loan.installment_amount) }}</td>
                        <td class="px-4 py-3" :class="dueTone(loan)">
                            <template v-if="loan.next_due_date">
                                {{ loan.next_due_date }}
                                <span class="block text-xs">
                                    {{ loan.next_due_paid ? t('Paid') : loan.days_to_due === 0 ? t('Due today') : t('In :days days', { days: loan.days_to_due ?? 0 }) }}
                                </span>
                            </template>
                            <span v-else>—</span>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="loan.status === 'REVERSED' ? 'destructive' : loan.paid_off ? 'secondary' : 'default'">
                                {{ loan.status === 'REVERSED' ? loan.status_label : loan.paid_off ? t('Paid off') : t('Being paid') }}
                            </Badge>
                        </td>
                    </tr>
                    <tr v-if="loans.length === 0">
                        <td colspan="6" class="text-muted-foreground px-4 py-8 text-center">{{ t('No loans yet.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
