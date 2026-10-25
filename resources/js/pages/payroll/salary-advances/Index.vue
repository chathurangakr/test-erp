<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import ListControls from '@/components/lists/ListControls.vue';
import ListPagination from '@/components/lists/ListPagination.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { type ListControls as ListControlValues, useListFilters } from '@/composables/useListFilters';
import { usePermissions } from '@/composables/usePermissions';
import { create, index, show } from '@/routes/salary-advances';
import type { Paginated, SalaryAdvanceRow } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    advances: Paginated<SalaryAdvanceRow>;
    filters: { employee_id: number | null; status: string } & ListControlValues;
    statuses: { value: string; label: string }[];
    employees: { id: number; code: string; name: string }[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Salary advances', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();

const { filters, sortBy, clear } = useListFilters(() => index().url, {
    ...props.filters,
    employee_id: props.filters.employee_id ? String(props.filters.employee_id) : '',
});

</script>

<template>
    <Head :title="t('Salary advances')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading title="Salary advances" description="Money given before payday. Each is taken back in full from the next salary paid." />
            <Button v-if="can('payroll.advance_pay')" as-child>
                <Link :href="create()"><Plus class="size-4" />{{ t('Pay advance') }}</Link>
            </Button>
        </div>

        <ListControls v-model:per-page="filters.per_page" v-model:from="filters.from" v-model:to="filters.to" @clear="clear">
            <SearchableSelect
                v-model="filters.employee_id"
                class="w-full sm:w-64"
                :options="[{ value: '', label: t('All employees') }, ...employees.map((e) => ({ value: String(e.id), label: `${e.code} — ${e.name}` }))]"
            />
            <select v-model="filters.status" class="border-input bg-background h-9 rounded-md border px-3 text-sm" :aria-label="t('Filter by status')">
                <option value="">{{ t('All statuses') }}</option>
                <option v-for="option in statuses" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
        </ListControls>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <SortHeader label="Advance" column="number" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Date" column="date" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Employee" column="employee" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <th class="px-4 py-3 font-medium">{{ t('Paid from') }}</th>
                        <SortHeader label="Amount" column="amount" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy">{{ t('Amount (:currency)', { currency }) }}</SortHeader>
                        <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="advance in advances.data" :key="advance.id" class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t">
                        <td class="px-4 py-3"><Link :href="show(advance.id)" class="font-mono hover:underline">{{ advance.advance_number }}</Link></td>
                        <td class="px-4 py-3">{{ advance.advance_date }}</td>
                        <td class="px-4 py-3">
                            {{ advance.employee_name }}
                            <span class="text-muted-foreground block font-mono text-xs">{{ advance.employee_code }}</span>
                        </td>
                        <td class="px-4 py-3">{{ advance.payment_account ?? '—' }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(advance.amount) }}</td>
                        <td class="px-4 py-3">
                            <Badge :variant="advance.is_reversed ? 'destructive' : 'default'">{{ advance.status_label }}</Badge>
                        </td>
                    </tr>
                    <tr v-if="advances.data.length === 0">
                        <td colspan="6" class="text-muted-foreground px-4 py-8 text-center">{{ t('No salary advances yet.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ListPagination :page="advances" />
    </div>
</template>
