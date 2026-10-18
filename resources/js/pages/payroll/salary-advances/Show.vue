<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import JournalTable from '@/components/payroll/JournalTable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { show as employeeShow } from '@/routes/employees';
import { index, reverse } from '@/routes/salary-advances';
import type { SalaryAdvanceDetail } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{ advance: SalaryAdvanceDetail; currency: string }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Salary advances', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();

function reverseAdvance(): void {
    if (confirm(t('Reverse this advance? The money is put back into the account it was paid from.'))) {
        router.patch(reverse(props.advance.id).url, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="advance.advance_number" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading :title="advance.advance_number" :description="`${advance.advance_date} · ${advance.payment_account ?? ''}`" />
                <Badge :variant="advance.is_reversed ? 'destructive' : 'default'" class="w-fit">{{ advance.status_label }}</Badge>
            </div>
            <Button v-if="advance.can_reverse && can('payroll.advance_reverse')" variant="outline" @click="reverseAdvance">{{ t('Reverse') }}</Button>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Employee') }}</p>
                <Link :href="employeeShow(advance.employee_id)" class="mt-1 block text-lg hover:underline">{{ advance.employee_name }}</Link>
                <span class="text-muted-foreground font-mono text-xs">{{ advance.employee_code }}</span>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Amount (:currency)', { currency }) }}</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ money(advance.amount) }}</p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Paid by') }}</p>
                <p class="mt-1 text-lg">{{ advance.payment_method_label }}</p>
                <span v-if="advance.reference" class="text-muted-foreground text-xs">{{ advance.reference }}</span>
            </div>
        </div>

        <JournalTable v-if="advance.journal" :journal="advance.journal" />

        <dl class="grid gap-6 rounded-xl border p-4 sm:grid-cols-3 sm:p-6">
            <div>
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Paid') }}</dt>
                <dd class="mt-1 text-sm">{{ advance.posted_at ?? '—' }} · {{ advance.created_by ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Reversed') }}</dt>
                <dd class="mt-1 text-sm">
                    {{ advance.reversed_at ? `${advance.reversed_at} · ${advance.reversed_by ?? '—'}` : '—' }}
                    <span v-if="advance.reversal_journal_number" class="font-mono text-xs">({{ advance.reversal_journal_number }})</span>
                </dd>
            </div>
            <div v-if="advance.note">
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Note') }}</dt>
                <dd class="mt-1 text-sm">{{ advance.note }}</dd>
            </div>
        </dl>
    </div>
</template>
