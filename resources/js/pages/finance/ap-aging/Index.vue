<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { index } from '@/routes/ap-aging';
import type { ApAgingReport, ControlAccountCheck } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    report: ApAgingReport;
    control: ControlAccountCheck;
    filters: { as_of: string | null };
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'AP aging', href: index() }] } });

const asOf = ref(props.filters.as_of ?? '');

watch(asOf, (value) => {
    router.get(index().url, { as_of: value || undefined }, { preserveState: true, replace: true, preserveScroll: true });
});

const controlClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';

const { t } = useT();
</script>

<template>
    <Head :title="t('AP aging')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Accounts payable aging"
            :description="t('What each supplier is owed, in :currency, bucketed by how overdue it is.', { currency })"
        />

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="grid gap-1.5">
                <label for="f-asof" class="text-sm font-medium">{{ t('As of') }}</label>
                <input id="f-asof" v-model="asOf" type="date" :class="controlClass" />
            </div>
        </div>

        <div
            v-if="control && !control.reconciles"
            class="border-destructive text-destructive rounded-xl border p-4 text-sm"
        >
            <strong>{{ t('Accounts payable does not reconcile to the ledger.') }}</strong>
            {{ t('The aging totals :total but :code :name carries :balance. Since Trade Payables is a control account that refuses manual posting, a difference means something needs investigating.', { total: report.totals.total, code: control.code ?? '', name: control.name ?? '', balance: control.balance ?? '' }) }}
        </div>

        <div
            v-if="report.rows.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">{{ t('Nothing outstanding') }}</p>
            <p class="text-muted-foreground text-sm">{{ t('Every supplier invoice has been paid or reversed.') }}</p>
        </div>

        <div v-else class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Supplier') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Current') }}</th>
                        <th class="px-4 py-3 text-right font-medium">1–30</th>
                        <th class="px-4 py-3 text-right font-medium">31–60</th>
                        <th class="px-4 py-3 text-right font-medium">61–90</th>
                        <th class="px-4 py-3 text-right font-medium">90+</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in report.rows"
                        :key="row.supplier_id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-4 py-3">
                            <span class="font-mono text-xs">{{ row.supplier_code }}</span>
                            <span class="text-muted-foreground block text-xs">{{ row.supplier_name }}</span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.current) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.days_1_30) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.days_31_60) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.days_61_90) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.days_90_plus) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(row.total) }}</td>
                    </tr>
                </tbody>
                <tfoot class="bg-muted/30">
                    <tr class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                        <td class="px-4 py-3 font-medium">{{ t('Totals') }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(report.totals.current) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(report.totals.days_1_30) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(report.totals.days_31_60) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(report.totals.days_61_90) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(report.totals.days_90_plus) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(report.totals.total) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div v-if="control" class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 text-sm">
            <span class="font-medium">{{ control.code }} {{ control.name }}</span>
            carries <span class="font-mono">{{ money(control.balance) }}</span> in the general ledger, against
            an aging total of <span class="font-mono">{{ money(report.totals.total) }}</span> —
            <span :class="control.reconciles ? '' : 'text-destructive font-medium'">
                {{ control.reconciles ? 'reconciled' : 'not reconciled' }}
            </span>.
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            {{ t('Every figure is derived from posted invoices less the payments still allocated to them — nothing is stored, and no supplier carries a balance column. A reversed invoice disappears from this report entirely, and a reversed payment stops reducing what is owed. No revenue or tax is modelled anywhere in this phase.') }}
        </p>
    </div>
</template>
