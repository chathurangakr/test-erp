<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import ListControls from '@/components/lists/ListControls.vue';
import ListPagination from '@/components/lists/ListPagination.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { type ListControls as ListControlValues, useListFilters } from '@/composables/useListFilters';
import { usePermissions } from '@/composables/usePermissions';
import { create, index, show } from '@/routes/employees';
import type { EmployeeRow, Paginated } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    employees: Paginated<EmployeeRow>;
    filters: { search: string; status: string } & ListControlValues;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Employees', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();

const { filters, sortBy, clear } = useListFilters(() => index().url, { ...props.filters });

</script>

<template>
    <Head :title="t('Employees')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading title="Employees" description="People paid by the day, with their daily rate and any advance they still owe." />
            <Button v-if="can('payroll.employees_manage')" as-child>
                <Link :href="create()"><Plus class="size-4" />{{ t('Add employee') }}</Link>
            </Button>
        </div>

        <ListControls v-model:per-page="filters.per_page" :period="false" @clear="clear">
            <Input v-model="filters.search" type="search" class="w-full sm:w-72" :placeholder="t('Search code, name, NIC or phone')" />
            <SearchableSelect
                v-model="filters.status"
                class="w-full sm:w-40"
                :options="[{ value: 'active', label: t('Active') }, { value: 'inactive', label: t('Inactive') }, { value: 'all', label: t('All') }]"
            />
        </ListControls>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <SortHeader label="Employee" column="name" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Phone" column="phone" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Daily rate" column="daily_rate" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <th class="px-4 py-3 text-right font-medium">{{ t('Advance owed') }}</th>
                        <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="employee in employees.data" :key="employee.id" class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t">
                        <td class="px-4 py-3">
                            <Link :href="show(employee.id)" class="font-medium hover:underline">{{ employee.name }}</Link>
                            <span class="text-muted-foreground block font-mono text-xs">{{ employee.code }}</span>
                        </td>
                        <td class="px-4 py-3">{{ employee.phone ?? '—' }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(employee.daily_rate) }}</td>
                        <td class="px-4 py-3 text-right font-mono">
                            {{ Number(employee.advances_owed) > 0 ? money(employee.advances_owed ?? '0') : '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="employee.is_active ? 'default' : 'outline'">
                                {{ employee.is_active ? t('Active') : t('Inactive') }}
                            </Badge>
                        </td>
                    </tr>
                    <tr v-if="employees.data.length === 0">
                        <td colspan="5" class="text-muted-foreground px-4 py-8 text-center">{{ t('No employees yet.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ListPagination :page="employees" />
    </div>
</template>
