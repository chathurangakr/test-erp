<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import ExportButtons from '@/components/print/ExportButtons.vue';
import PrintButton from '@/components/print/PrintButton.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';
import { cashFlow } from '@/routes/reports';
import { exportMethod as exportReport } from '@/routes/reports/cash-flow';

type Statement = {
    from: string;
    to: string;
    opening: string;
    closing: string;
    net_change: string;
    balanced: boolean;
    sections: { key: string; label: string; rows: { label: string; amount: string }[]; total: string }[];
};

const props = defineProps<{ statement: Statement; filters: { from: string; to: string }; currency: string }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Cash flow', href: cashFlow() }] } });

const { t } = useT();
const from = ref(props.filters.from);
const to = ref(props.filters.to);

function reload(): void {
    router.get(cashFlow().url, { from: from.value, to: to.value }, { preserveState: false, replace: true });
}

const sign = (value: string) => (value.startsWith('-') ? 'text-destructive' : '');
</script>

<template>
    <Head :title="t('Cash flow')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading title="Cash flow" description="Where money came from and where it went, for cash and bank together. Moves between your own cash and bank accounts are left out." />
            <div class="flex gap-2">
                <ExportButtons :url="exportReport.url" :query="filters" />
                <PrintButton />
            </div>
        </div>

        <div class="flex flex-wrap items-end gap-3 print:hidden">
            <div class="grid gap-1"><Label for="from">{{ t('From') }}</Label><Input id="from" v-model="from" type="date" class="w-44" @change="reload" /></div>
            <div class="grid gap-1"><Label for="to">{{ t('To') }}</Label><Input id="to" v-model="to" type="date" class="w-44" @change="reload" /></div>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <tbody>
                    <tr class="bg-muted/50 font-semibold">
                        <td class="px-4 py-3">{{ t('Cash and bank at the start (:date)', { date: statement.from }) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(statement.opening) }}</td>
                    </tr>
                    <template v-for="section in statement.sections" :key="section.key">
                        <tr class="border-t font-semibold"><td class="px-4 pt-4 pb-1" colspan="2">{{ section.label }}</td></tr>
                        <tr v-for="row in section.rows" :key="row.label" class="border-sidebar-border/70 dark:border-sidebar-border">
                            <td class="px-4 py-1.5 pl-8">{{ row.label }}</td>
                            <td class="px-4 py-1.5 text-right font-mono" :class="sign(row.amount)">{{ money(row.amount) }}</td>
                        </tr>
                        <tr v-if="section.rows.length === 0"><td class="text-muted-foreground px-4 py-1.5 pl-8" colspan="2">{{ t('Nothing in this period.') }}</td></tr>
                        <tr class="font-medium">
                            <td class="px-4 py-2 pl-8">{{ t('Net :section', { section: section.label.toLowerCase() }) }}</td>
                            <td class="border-t px-4 py-2 text-right font-mono" :class="sign(section.total)">{{ money(section.total) }}</td>
                        </tr>
                    </template>
                    <tr class="border-t-2 font-semibold">
                        <td class="px-4 py-3">{{ t('Net change in cash and bank') }}</td>
                        <td class="px-4 py-3 text-right font-mono" :class="sign(statement.net_change)">{{ money(statement.net_change) }}</td>
                    </tr>
                    <tr class="bg-muted/50 font-semibold">
                        <td class="px-4 py-3">{{ t('Cash and bank at the end (:date)', { date: statement.to }) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(statement.closing) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            <Badge :variant="statement.balanced ? 'default' : 'destructive'" class="mr-2">{{ statement.balanced ? t('Agrees with the books') : t('Does not agree — check') }}</Badge>
            {{ t('Start + net change = end, read from the cash and bank accounts themselves. A loan payment is split: its interest is an operating cost, the rest repays the loan.') }}
        </p>
    </div>
</template>
