<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { create, edit, index } from '@/routes/cash-bank-accounts';
import type { CashBankSummary } from '@/types';
import { useT } from '@/lib/i18n';

const props = defineProps<{
    summary: CashBankSummary;
    filters: { show_inactive: boolean };
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Cash / bank accounts', href: index() }] } });

const { can } = usePermissions();

const { t } = useT();

function toggleInactive(): void {
    router.get(index().url, { show_inactive: props.filters.show_inactive ? undefined : 1 }, {
        preserveState: false,
        replace: true,
    });
}
</script>

<template>
    <Head :title="t('Cash / bank accounts')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading
                title="Cash / bank accounts"
                description="Which ledger accounts hold the factory's money. Balances are calculated from posted journals."
            />
            <div class="flex flex-col gap-2 sm:flex-row">
                <Button variant="outline" @click="toggleInactive">
                    {{ filters.show_inactive ? t('Hide inactive') : t('Show inactive') }}
                </Button>
                <Button v-if="can('finance.cash.accounts_manage')" as-child>
                    <Link :href="create()">
                        <Plus class="size-4" />
                        {{ t('Configure account') }}
                    </Link>
                </Button>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Total cash (:currency)', { currency }) }}</p>
                <p class="mt-1 font-mono text-lg">{{ summary.cash_total }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Total bank') }}</p>
                <p class="mt-1 font-mono text-lg">{{ summary.bank_total }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Combined') }}</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ summary.combined_total }}</p>
            </div>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Name') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('GL account') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Kind') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Bank') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Status') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Balance') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in summary.rows"
                        :key="row.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-4 py-3 font-medium">{{ row.display_name }}</td>
                        <td class="px-4 py-3">
                            <span class="font-mono text-xs">{{ row.account_code }}</span>
                            <span class="text-muted-foreground block text-xs">{{ row.account_name }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <Badge variant="secondary">{{ row.kind_label }}</Badge>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            <template v-if="row.bank_name">
                                {{ row.bank_name }}
                                <span v-if="row.branch_name" class="text-muted-foreground block">
                                    {{ row.branch_name }}
                                </span>
                                <span v-if="row.account_number" class="text-muted-foreground block font-mono">
                                    {{ row.account_number }}
                                </span>
                            </template>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="row.is_active ? 'default' : 'secondary'">
                                {{ row.is_active ? t('Active') : t('Inactive') }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ row.balance }}</td>
                        <td class="px-4 py-3 text-right">
                            <Button v-if="can('finance.cash.accounts_manage')" variant="ghost" size="sm" as-child>
                                <Link :href="edit(row.id)">{{ t('Edit') }}</Link>
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="summary.rows.length === 0">
                        <td colspan="7" class="text-muted-foreground px-4 py-8 text-center">
                            {{ t('No cash or bank accounts are configured yet.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            {{ t('Each balance is calculated from posted journal lines every time this page loads. There is no stored balance anywhere and there never will be — the general ledger is the only record of how much money there is. Configuring an account here does not create or change a single rupee; it only says which ledger account is a real till or bank account, so payments, receipts, expense vouchers and transfers all draw on one list. An account that money has moved through is deactivated, never deleted.') }}
        </p>
    </div>
</template>
