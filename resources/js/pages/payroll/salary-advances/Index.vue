<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { create, index, show } from '@/routes/salary-advances';
import type { Paginated, SalaryAdvanceRow } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    advances: Paginated<SalaryAdvanceRow>;
    filters: { employee_id: number | null };
    employees: { id: number; code: string; name: string }[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Salary advances', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();

const employeeId = ref(props.filters.employee_id ? String(props.filters.employee_id) : '');
watch(employeeId, () => {
    router.get(index().url, { employee_id: employeeId.value || undefined }, { preserveState: true, replace: true });
});

const selectClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
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

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <select v-model="employeeId" :class="selectClass">
                <option value="">{{ t('All employees') }}</option>
                <option v-for="e in employees" :key="e.id" :value="String(e.id)">{{ e.code }} — {{ e.name }}</option>
            </select>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Advance') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Date') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Employee') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Paid from') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Amount (:currency)', { currency }) }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Status') }}</th>
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

        <div v-if="advances.last_page > 1" class="flex flex-wrap gap-1">
            <template v-for="link in advances.links" :key="link.label">
                <Link v-if="link.url" :href="link.url" class="rounded-md border px-3 py-1 text-sm" :class="link.active ? 'bg-muted font-medium' : ''" preserve-state>
                    <span v-html="link.label" />
                </Link>
            </template>
        </div>
    </div>
</template>
