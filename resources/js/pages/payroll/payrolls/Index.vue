<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus, Search } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import ListControls from '@/components/lists/ListControls.vue';
import ListPagination from '@/components/lists/ListPagination.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { type ListControls as ListControlValues, useListFilters } from '@/composables/useListFilters';
import { usePermissions } from '@/composables/usePermissions';
import { create, index, show } from '@/routes/payrolls';
import type { Paginated, PayrollRow } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    payrolls: Paginated<PayrollRow>;
    filters: { search: string; status: string } & ListControlValues;
    statuses: { value: string; label: string }[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Salaries', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();

const { filters, sortBy, clear } = useListFilters(() => index().url, { ...props.filters });
</script>

<template>
    <Head :title="t('Salaries')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading title="Salaries" description="Every salary payment — a day, a few days or a month — worked out from attendance and paid in one step." />
            <Button v-if="can('payroll.salary_pay')" as-child>
                <Link :href="create()"><Plus class="size-4" />{{ t('Pay salaries') }}</Link>
            </Button>
        </div>

        <ListControls v-model:per-page="filters.per_page" v-model:from="filters.from" v-model:to="filters.to" period-label="Paid on" @clear="clear">
            <div class="relative w-full sm:w-64">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input v-model="filters.search" type="search" :placeholder="t('Search payment number…')" class="pl-9" :aria-label="t('Search salary payments')" />
            </div>
            <select v-model="filters.status" class="border-input bg-background h-9 rounded-md border px-3 text-sm" :aria-label="t('Filter by status')">
                <option value="">{{ t('All statuses') }}</option>
                <option v-for="option in statuses" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
        </ListControls>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <SortHeader label="Period" column="period" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Paid on" column="pay_date" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Employees" column="employees" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Earned" column="gross_pay" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Advances taken back" column="advances" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Paid" column="net_pay" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy">{{ t('Paid (:currency)', { currency }) }}</SortHeader>
                        <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="run in payrolls.data" :key="run.id" class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t">
                        <td class="px-4 py-3">
                            <Link :href="show(run.id)" class="font-medium hover:underline">{{ run.period_start === run.period_end ? run.period_start : `${run.period_start} — ${run.period_end}` }}</Link>
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

        <ListPagination :page="payrolls" />
    </div>
</template>
