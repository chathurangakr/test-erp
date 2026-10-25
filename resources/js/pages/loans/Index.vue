<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Search } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { useClientList } from '@/composables/useClientList';
import { Input } from '@/components/ui/input';
import ClientPagination from '@/components/lists/ClientPagination.vue';
import ListControls from '@/components/lists/ListControls.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { create, index, show } from '@/routes/loans';
import type { LoanRow } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{ loans: LoanRow[]; showAll: boolean; totals: { outstanding: string }; currency: string }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Loans', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();

function dueTone(loan: LoanRow): string {
    if (loan.next_due_paid || loan.days_to_due === null) return 'text-muted-foreground';
    if (loan.days_to_due === 0) return 'text-red-600 font-semibold';
    if (loan.days_to_due <= 7) return 'text-amber-600 font-medium';
    return '';
}
const { filters, visible, pageCount, range, sortBy, clear } = useClientList(() => props.loans, {
    search: (loan) => `${loan.lender} ${loan.loan_number}`,
    date: (loan) => loan.start_date,
    sorts: {
        lender: (loan) => loan.lender,
        borrowed: (loan) => Number(loan.amount),
        owed: (loan) => Number(loan.outstanding),
        installment: (loan) => (loan.installment_amount === null ? null : Number(loan.installment_amount)),
        due: (loan) => loan.next_due_date,
        status: (loan) => (loan.status === 'REVERSED' ? 2 : loan.paid_off ? 1 : 0),
    },
    defaultSort: 'due',
});
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

        <ListControls v-model:per-page="filters.per_page" v-model:from="filters.from" v-model:to="filters.to" @clear="clear">
            <div class="relative w-full sm:w-72">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input v-model="filters.search" type="search" :placeholder="t('Search lender or loan number…')" class="pl-9" />
            </div>
        </ListControls>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <SortHeader label="Lender" column="lender" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Borrowed" column="borrowed" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Still owed" column="owed" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Installment" column="installment" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Next due" column="due" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="loan in visible" :key="loan.id" class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t">
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
                    <tr v-if="visible.length === 0">
                        <td colspan="6" class="text-muted-foreground px-4 py-8 text-center">{{ t('No loans yet.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ClientPagination v-model:page="filters.page" :range="range" :page-count="pageCount" />
    </div>
</template>
