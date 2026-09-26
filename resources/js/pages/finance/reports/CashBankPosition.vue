<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as cashBankBookIndex } from '@/routes/cash-bank-book';
import { cashBankPosition } from '@/routes/reports';
import type { CashBankAccountRow, CashBankSummary } from '@/types';

const props = defineProps<{
    summary: CashBankSummary;
    filters: { as_of: string | null; show_inactive: boolean };
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Cash / bank position', href: cashBankPosition() }] } });

const asOf = ref(props.filters.as_of ?? '');
const showInactive = ref(props.filters.show_inactive);

watch([asOf, showInactive], () => {
    router.get(
        cashBankPosition().url,
        { as_of: asOf.value || undefined, show_inactive: showInactive.value ? 1 : undefined },
        { preserveState: false, replace: true },
    );
});

const cashRows = computed(() => props.summary.rows.filter((row) => row.kind === 'CASH'));
const bankRows = computed(() => props.summary.rows.filter((row) => row.kind === 'BANK'));

const groups = computed(() => [
    { title: 'Cash accounts', rows: cashRows.value, total: props.summary.cash_total, totalLabel: 'Total cash' },
    { title: 'Bank accounts', rows: bankRows.value, total: props.summary.bank_total, totalLabel: 'Total bank' },
]);

function bookUrl(row: CashBankAccountRow): string {
    const params = new URLSearchParams({ cash_bank_account_id: String(row.id) });

    if (props.filters.as_of) params.set('to', props.filters.as_of);

    return `${cashBankBookIndex().url}?${params.toString()}`;
}
</script>

<template>
    <Head title="Cash / bank position" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Cash / bank position"
            :description="`Money held, as at ${filters.as_of ?? 'today'}.`"
        />

        <section class="grid gap-4 sm:grid-cols-3">
            <div class="grid gap-2">
                <Label for="as_of">As at</Label>
                <Input id="as_of" v-model="asOf" type="date" />
            </div>
            <label class="flex items-end gap-2 pb-2 text-sm">
                <input v-model="showInactive" type="checkbox" class="size-4" />
                Include inactive accounts
            </label>
        </section>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Total cash ({{ currency }})</p>
                <p class="mt-1 font-mono text-lg">{{ summary.cash_total }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Total bank ({{ currency }})</p>
                <p class="mt-1 font-mono text-lg">{{ summary.bank_total }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">Cash + bank ({{ currency }})</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ summary.combined_total }}</p>
            </div>
        </div>

        <div
            v-for="group in groups"
            :key="group.title"
            class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border"
        >
            <div class="border-sidebar-border/70 dark:border-sidebar-border border-b px-4 py-3">
                <h3 class="text-sm font-medium">{{ group.title }}</h3>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">GL code</th>
                        <th class="px-4 py-3 font-medium">Kind</th>
                        <th class="px-4 py-3 text-right font-medium">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in group.rows"
                        :key="row.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-4 py-3">
                            <Link :href="bookUrl(row)" class="font-medium hover:underline">
                                {{ row.display_name }}
                            </Link>
                            <span v-if="row.bank_name" class="text-muted-foreground block text-xs">
                                {{ row.bank_name }}<template v-if="row.account_number"> · {{ row.account_number }}</template>
                            </span>
                            <Badge v-if="!row.is_active" variant="secondary" class="mt-1">Inactive</Badge>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">{{ row.account_code }}</td>
                        <td class="px-4 py-3">{{ row.kind_label }}</td>
                        <td
                            class="px-4 py-3 text-right font-mono"
                            :class="Number(row.balance) < 0 ? 'text-destructive' : ''"
                        >
                            {{ row.balance }}
                        </td>
                    </tr>
                    <tr v-if="group.rows.length === 0">
                        <td colspan="4" class="text-muted-foreground px-4 py-6 text-center">
                            No accounts of this kind are configured.
                        </td>
                    </tr>
                    <tr class="bg-muted/50 border-sidebar-border/70 dark:border-sidebar-border border-t">
                        <td colspan="3" class="px-4 py-3 font-medium">{{ group.totalLabel }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ group.total }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            Every balance is derived from posted journal lines on each visit — nothing is stored —
            using the same calculation as the cash / bank book, so the two always agree. Click an
            account to open its book. A negative balance is shown as it stands: no overdraft
            policy has been decided yet.
        </p>
    </div>
</template>
