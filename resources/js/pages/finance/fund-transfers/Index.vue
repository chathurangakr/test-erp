<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import ListControls from '@/components/lists/ListControls.vue';
import ListPagination from '@/components/lists/ListPagination.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import { type ListControls as ListControlValues, useListFilters } from '@/composables/useListFilters';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { usePermissions } from '@/composables/usePermissions';
import { create, index, show } from '@/routes/fund-transfers';
import type { FundTransferRow, Paginated } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    transfers: Paginated<FundTransferRow>;
    filters: { search: string; status: string } & ListControlValues;
    statuses: { value: string; label: string }[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Fund transfers', href: index() }] } });

const { t } = useT();

const { can } = usePermissions();

const { filters, sortBy, clear } = useListFilters(() => index().url, { ...props.filters });

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'POSTED') return 'default';
    return 'outline';
}

</script>

<template>
    <Head :title="t('Fund transfers')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading
                title="Fund transfers"
                description="Money moved between the factory's own cash and bank accounts."
            />
            <Button v-if="can('finance.cash.transfer_create')" as-child>
                <Link :href="create()">
                    <Plus class="size-4" />
                    {{ t('New transfer') }}
                </Link>
            </Button>
        </div>

        <ListControls v-model:per-page="filters.per_page" v-model:from="filters.from" v-model:to="filters.to" @clear="clear">
            <div class="grid gap-1 text-xs w-full sm:w-56">
                <span class="text-muted-foreground">{{ t('Search') }}</span>
                <Input v-model="filters.search" type="search" :placeholder="t('Search number or reference')" />
            </div>
            <div class="grid gap-1 text-xs w-full sm:w-48">
                <span class="text-muted-foreground">{{ t('Status') }}</span>
                <SearchableSelect v-model="filters.status" :options="[{ value: '', label: t('All statuses') }, ...statuses.map((s) => ({ value: s.value, label: s.label }))]" />
            </div>
        </ListControls>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <SortHeader label="Transfer" column="number" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Date" column="date" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="From" column="from" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="To" column="to" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Amount" column="amount" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy">{{ t('Amount (:currency)', { currency }) }}</SortHeader>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="transfer in transfers.data"
                        :key="transfer.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-4 py-3">
                            <Link :href="show(transfer.id)" class="font-mono text-xs font-medium hover:underline">
                                {{ transfer.transfer_number }}
                            </Link>
                            <span v-if="transfer.reference" class="text-muted-foreground block text-xs">
                                {{ transfer.reference }}
                            </span>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ transfer.transfer_date }}</td>
                        <td class="px-4 py-3">{{ transfer.from_name ?? '—' }}</td>
                        <td class="px-4 py-3">{{ transfer.to_name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <Badge :variant="badgeVariant(transfer.status)">{{ transfer.status_label }}</Badge>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(transfer.amount) }}</td>
                    </tr>
                    <tr v-if="transfers.data.length === 0">
                        <td colspan="6" class="text-muted-foreground px-4 py-8 text-center">{{ t('No fund transfers yet.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ListPagination :page="transfers" />

        <p class="text-muted-foreground max-w-3xl text-xs">
            {{ t('A transfer moves money between accounts the factory already owns. Nothing is earned, spent or owed, and the combined cash-and-bank total is identical before and after.') }}
        </p>
    </div>
</template>
