<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import ListControls from '@/components/lists/ListControls.vue';
import ListPagination from '@/components/lists/ListPagination.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { type ListControls as ListControlValues, useListFilters } from '@/composables/useListFilters';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';
import type { Paginated } from '@/types';
import type { AdvanceRow } from './types';

const props = defineProps<{
    title: string;
    description: string;
    partyLabel: string;
    newLabel: string;
    canRecord: boolean;
    advances: Paginated<AdvanceRow>;
    filters: { search: string; status: string; party_id: number | null } & ListControlValues;
    statuses: { value: string; label: string }[];
    parties: { id: number; code: string; name: string }[];
    allPartiesLabel: string;
    indexUrl: string;
    currency: string;
    createHref: string;
    showHref: (id: number) => string;
}>();

const { t } = useT();

const { filters, sortBy, clear } = useListFilters(() => props.indexUrl, {
    ...props.filters,
    party_id: props.filters.party_id ? String(props.filters.party_id) : '',
});
</script>

<template>
    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading :title="title" :description="description" />
            <Button v-if="canRecord" as-child>
                <Link :href="createHref"><Plus class="size-4" />{{ t(newLabel) }}</Link>
            </Button>
        </div>

        <ListControls v-model:per-page="filters.per_page" v-model:from="filters.from" v-model:to="filters.to" @clear="clear">
            <div class="grid gap-1 text-xs w-full sm:w-56">
                <span class="text-muted-foreground">{{ t('Search') }}</span>
                <Input v-model="filters.search" type="search" :placeholder="t('Search number or name')" />
            </div>
            <div class="grid w-full gap-1 text-xs sm:w-64">
                <span class="text-muted-foreground">{{ t(partyLabel) }}</span>
                <SearchableSelect v-model="filters.party_id" :options="[{ value: '', label: t(allPartiesLabel) }, ...parties.map((p) => ({ value: String(p.id), label: `${p.code} — ${p.name}` }))]" />
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
                        <SortHeader label="Advance" column="number" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Date" column="date" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader :label="partyLabel" column="party" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Amount" column="amount" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy">{{ t('Amount (:currency)', { currency }) }}</SortHeader>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Not yet applied') }}</th>
                        <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in advances.data" :key="row.id" class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t">
                        <td class="px-4 py-3"><Link :href="showHref(row.id)" class="font-mono hover:underline">{{ row.advance_number }}</Link></td>
                        <td class="px-4 py-3">{{ row.advance_date }}</td>
                        <td class="px-4 py-3">{{ row.party }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.amount) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.remaining) }}</td>
                        <td class="px-4 py-3"><Badge :variant="row.status === 'REVERSED' ? 'destructive' : 'default'">{{ row.status_label }}</Badge></td>
                    </tr>
                    <tr v-if="advances.data.length === 0">
                        <td colspan="6" class="text-muted-foreground px-4 py-8 text-center">{{ t('No advances yet.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ListPagination :page="advances" />
    </div>
</template>
