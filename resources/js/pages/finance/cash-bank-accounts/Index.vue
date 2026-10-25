<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Search } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { useClientList } from '@/composables/useClientList';
import { Input } from '@/components/ui/input';
import ClientPagination from '@/components/lists/ClientPagination.vue';
import ListControls from '@/components/lists/ListControls.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { create, edit, index } from '@/routes/cash-bank-accounts';
import type { CashBankSummary } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

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
const { filters: list, visible, pageCount, range, sortBy, clear } = useClientList(() => props.summary.rows, {
    search: (row) => `${row.display_name} ${row.account_code} ${row.account_name} ${row.bank_name ?? ''} ${row.branch_name ?? ''} ${row.account_number ?? ''}`,
    sorts: {
        name: (row) => row.display_name,
        account: (row) => row.account_code,
        kind: (row) => row.kind_label,
        bank: (row) => row.bank_name,
        status: (row) => (row.is_active ? 0 : 1),
        balance: (row) => Number(row.balance),
    },
    defaultSort: 'name',
});
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
                <p class="mt-1 font-mono text-lg">{{ money(summary.cash_total) }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Total bank') }}</p>
                <p class="mt-1 font-mono text-lg">{{ money(summary.bank_total) }}</p>
            </div>
            <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Combined') }}</p>
                <p class="mt-1 font-mono text-lg font-medium">{{ money(summary.combined_total) }}</p>
            </div>
        </div>

        <ListControls v-model:per-page="list.per_page" v-model:from="list.from" v-model:to="list.to" :period="false" @clear="clear">
            <div class="relative w-full sm:w-72">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input v-model="list.search" type="search" :placeholder="t('Search name, account or bank…')" class="pl-9" />
            </div>
        </ListControls>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <SortHeader label="Name" column="name" :sort="list.sort" :direction="list.direction" @sort="sortBy" />
                        <SortHeader label="GL account" column="account" :sort="list.sort" :direction="list.direction" @sort="sortBy" />
                        <SortHeader label="Kind" column="kind" :sort="list.sort" :direction="list.direction" @sort="sortBy" />
                        <SortHeader label="Bank" column="bank" :sort="list.sort" :direction="list.direction" @sort="sortBy" />
                        <SortHeader label="Status" column="status" :sort="list.sort" :direction="list.direction" @sort="sortBy" />
                        <SortHeader label="Balance" column="balance" align="right" :sort="list.sort" :direction="list.direction" @sort="sortBy" />
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in visible"
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
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.balance) }}</td>
                        <td class="px-4 py-3 text-right">
                            <Button v-if="can('finance.cash.accounts_manage')" variant="ghost" size="sm" as-child>
                                <Link :href="edit(row.id)">{{ t('Edit') }}</Link>
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="visible.length === 0">
                        <td colspan="7" class="text-muted-foreground px-4 py-8 text-center">
                            {{ t('No cash or bank accounts are configured yet.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ClientPagination v-model:page="list.page" :range="range" :page-count="pageCount" />

        <p class="text-muted-foreground max-w-3xl text-xs">
            {{ t('Each balance is calculated from posted journal lines every time this page loads. There is no stored balance anywhere and there never will be — the general ledger is the only record of how much money there is. Configuring an account here does not create or change a single rupee; it only says which ledger account is a real till or bank account, so payments, receipts, expense vouchers and transfers all draw on one list. An account that money has moved through is deactivated, never deleted.') }}
        </p>
    </div>
</template>
