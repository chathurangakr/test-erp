<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { usePermissions } from '@/composables/usePermissions';
import { create, index, show } from '@/routes/employees';
import type { EmployeeRow, Paginated } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    employees: Paginated<EmployeeRow>;
    filters: { search: string; status: string };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Employees', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();

const search = ref(props.filters.search);
const status = ref(props.filters.status);

watch([search, status], () => {
    router.get(index().url, { search: search.value || undefined, status: status.value }, { preserveState: true, replace: true });
});

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

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Input v-model="search" :placeholder="t('Search code, name, NIC or phone')" />
            <SearchableSelect
                v-model="status"
                :options="[{ value: 'active', label: t('Active') }, { value: 'inactive', label: t('Inactive') }, { value: 'all', label: t('All') }]"
            />
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Employee') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Phone') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Daily rate') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Advance owed') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Status') }}</th>
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

        <div v-if="employees.last_page > 1" class="flex flex-wrap gap-1">
            <template v-for="link in employees.links" :key="link.label">
                <Link v-if="link.url" :href="link.url" class="rounded-md border px-3 py-1 text-sm" :class="link.active ? 'bg-muted font-medium' : ''" preserve-state>
                    <span v-html="link.label" />
                </Link>
            </template>
        </div>
    </div>
</template>
