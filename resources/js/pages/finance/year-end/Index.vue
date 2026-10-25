<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Lock, Search, Undo2 } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { useClientList } from '@/composables/useClientList';
import { Input } from '@/components/ui/input';
import ClientPagination from '@/components/lists/ClientPagination.vue';
import ListControls from '@/components/lists/ListControls.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';
import { show as journalShow } from '@/routes/journals';
import { index, reverse, store } from '@/routes/year-end';

type Year = {
    start: string;
    end: string;
    ended: boolean;
    net_profit: string;
    last_period_open: boolean;
    can_close: boolean;
    closing: { id: number; net_profit: string; journal_id: number | null; journal_number: string | null; closed_by: string | null; closed_at: string | null; can_reverse: boolean } | null;
};

const props = defineProps<{
    years: Year[];
    endMonth: string;
    retainedEarnings: { code: string; name: string } | null;
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Year-end closing', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();
const page = usePage();
const error = computed(() => (page.props.errors as Record<string, string | undefined>).status);
const retained = computed(() => (props.retainedEarnings ? `${props.retainedEarnings.code} ${props.retainedEarnings.name}` : t('retained earnings')));

function close(year: Year): void {
    if (!window.confirm(t('Close the year :from to :to? Its profit of :amount moves to :account, and the income and expense accounts start again from zero.', { from: year.start, to: year.end, amount: money(year.net_profit), account: retained.value }))) {
        return;
    }

    router.post(store().url, { year_end_date: year.end }, { preserveScroll: true });
}

function undo(year: Year): void {
    if (year.closing && window.confirm(t('Reverse the close of :from to :to? The year opens again.', { from: year.start, to: year.end }))) {
        router.patch(reverse(year.closing.id).url, {}, { preserveScroll: true });
    }
}
/** Closed, still running, or ended but not closed — the order the status column sorts by. */
function standing(year: Year): number {
    return year.closing ? 0 : !year.ended ? 1 : 2;
}

const { filters, visible, pageCount, range, sortBy, clear } = useClientList(() => props.years, {
    search: (year) => `${year.start} ${year.end} ${year.closing ? t('Closed') : !year.ended ? t('Running') : t('Not closed')}`,
    date: (year) => year.end,
    sorts: {
        year: (year) => year.end,
        profit: (year) => Number(year.net_profit),
        status: (year) => standing(year),
    },
    defaultSort: 'year',
    defaultDirection: 'desc',
});
</script>

<template>
    <Head :title="t('Year-end closing')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="Year-end closing" :description="t('The financial year ends on the last day of :month. Closing a year moves its profit into :account.', { month: t(endMonth), account: retained })" />

        <InputError :message="error" />

        <ListControls v-model:per-page="filters.per_page" v-model:from="filters.from" v-model:to="filters.to" @clear="clear">
            <div class="relative w-full sm:w-72">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input v-model="filters.search" type="search" :placeholder="t('Search year or status…')" class="pl-9" />
            </div>
        </ListControls>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <SortHeader label="Financial year" column="year" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <SortHeader label="Net profit" column="profit" align="right" :sort="filters.sort" :direction="filters.direction" @sort="sortBy">{{ t('Net profit') }} ({{ currency }})</SortHeader>
                        <SortHeader label="Status" column="status" :sort="filters.sort" :direction="filters.direction" @sort="sortBy" />
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="year in visible" :key="year.end" class="border-sidebar-border/70 dark:border-sidebar-border border-t align-top">
                        <td class="px-4 py-3 font-medium">{{ year.start }} → {{ year.end }}</td>
                        <td class="px-4 py-3 text-right font-mono" :class="year.net_profit.startsWith('-') ? 'text-destructive' : ''">{{ money(year.net_profit) }}</td>
                        <td class="px-4 py-3">
                            <template v-if="year.closing">
                                <Badge>{{ t('Closed') }}</Badge>
                                <p class="text-muted-foreground mt-1 text-xs">
                                    {{ t(':date by :user', { date: year.closing.closed_at ?? '—', user: year.closing.closed_by ?? '—' }) }}
                                    <template v-if="year.closing.journal_id">
                                        ·
                                        <Link :href="journalShow(year.closing.journal_id)" class="underline">{{ year.closing.journal_number }}</Link>
                                    </template>
                                </p>
                            </template>
                            <template v-else-if="!year.ended">
                                <Badge variant="secondary">{{ t('Running') }}</Badge>
                                <p class="text-muted-foreground mt-1 text-xs">{{ t('Can be closed from :date.', { date: year.end }) }}</p>
                            </template>
                            <template v-else>
                                <Badge variant="outline">{{ t('Not closed') }}</Badge>
                                <p v-if="!year.last_period_open" class="text-destructive mt-1 text-xs">{{ t('Its last month is closed, so the closing entry cannot be dated :date.', { date: year.end }) }}</p>
                            </template>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Button v-if="year.can_close && can('finance.year_end_close')" size="sm" :disabled="!year.last_period_open" @click="close(year)">
                                <Lock class="size-4" />{{ t('Close the year') }}
                            </Button>
                            <Button v-if="year.closing?.can_reverse && can('finance.year_end_reverse')" size="sm" variant="outline" @click="undo(year)">
                                <Undo2 class="size-4" />{{ t('Reverse') }}
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ClientPagination v-model:page="filters.page" :range="range" :page-count="pageCount" />

        <ul class="text-muted-foreground max-w-3xl list-disc space-y-1 pl-5 text-xs">
            <li>{{ t('Close a year after its last day, BEFORE closing its last month: the closing entry is dated the year end.') }}</li>
            <li>{{ t('Years are closed in order. Profit and loss reports still show each year\'s real figures; the balance sheet shows the closed profit in retained earnings and starts current earnings again.') }}</li>
            <li>{{ t('A mistake is undone with Reverse (the latest close only), dated the same year-end day.') }}</li>
        </ul>
    </div>
</template>
