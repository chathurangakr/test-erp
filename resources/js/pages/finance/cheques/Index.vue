<script setup lang="ts">
import { Search } from '@lucide/vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { useClientList } from '@/composables/useClientList';
import ClientPagination from '@/components/lists/ClientPagination.vue';
import ListControls from '@/components/lists/ListControls.vue';
import SortHeader from '@/components/lists/SortHeader.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usePermissions } from '@/composables/usePermissions';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';
import { bounce, clear, index, undoClear } from '@/routes/cheques';

type Cheque = {
    key: string;
    direction: 'RECEIVED' | 'ISSUED';
    date: string;
    document_number: string;
    document_url: string;
    party: string | null;
    cheque_number: string | null;
    cheque_date: string | null;
    cheque_bank: string | null;
    amount: string;
    not_yet_due: boolean;
    status: string;
    status_label: string;
    status_date: string | null;
    can_clear: boolean;
    can_bounce: boolean;
    bank_charge: string | null;
    can_undo_clear: boolean;
};

const props = defineProps<{
    cheques: Cheque[];
    filters: { direction: string | null; status: string | null };
    statuses: { value: string; label: string }[];
    today: string;
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Cheques', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();
const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string | undefined>);

const direction = ref(props.filters.direction ?? '');
const status = ref(props.filters.status ?? '');
function filter(): void {
    router.get(index().url, { direction: direction.value || undefined, status: status.value || undefined }, { preserveState: true, replace: true });
}

const acting = ref<string | null>(null);
const actionDate = ref(props.today);
const reason = ref('');
const bankCharge = ref('');
const mode = ref<'clear' | 'bounce'>('clear');

function open(cheque: Cheque, what: 'clear' | 'bounce'): void {
    acting.value = cheque.key;
    mode.value = what;
    actionDate.value = props.today;
    reason.value = '';
    bankCharge.value = '';
}

function confirm(cheque: Cheque): void {
    if (mode.value === 'bounce' && !window.confirm(t('Mark cheque :number bounced? Its :document is reversed, so the money it paid is owed again.', { number: cheque.cheque_number ?? '', document: cheque.document_number }))) {
        return;
    }

    const url = mode.value === 'clear' ? clear().url : bounce().url;
    router.post(url, { cheque: cheque.key, event_date: actionDate.value, reason: reason.value, bank_charge: bankCharge.value || undefined }, { preserveScroll: true, onSuccess: () => (acting.value = null) });
}

function undo(cheque: Cheque): void {
    router.post(undoClear().url, { cheque: cheque.key }, { preserveScroll: true });
}

const variant = (s: string) => (s === 'CLEARED' ? 'default' : s === 'BOUNCED' ? 'destructive' : s === 'VOID' ? 'outline' : 'secondary');
const { filters: list, visible, pageCount, range, sortBy, clear: clearList } = useClientList(() => props.cheques, {
    search: (cheque) => `${cheque.cheque_number ?? ''} ${cheque.cheque_bank ?? ''} ${cheque.party ?? ''} ${cheque.document_number}`,
    date: (cheque) => cheque.cheque_date ?? cheque.date,
    sorts: {
        cheque: (cheque) => cheque.cheque_date ?? cheque.date,
        party: (cheque) => cheque.party,
        document: (cheque) => cheque.date,
        amount: (cheque) => Number(cheque.amount),
        status: (cheque) => cheque.status_label,
    },
    defaultSort: 'cheque',
    defaultDirection: 'desc',
});

/** Clears the in-page controls and, when set, the direction and status filters too. */
function clearFilters(): void {
    clearList();

    if (direction.value || status.value) {
        direction.value = '';
        status.value = '';
        filter();
    }
}
</script>

<template>
    <Head :title="t('Cheques')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="Cheques" description="Every cheque received or issued. Mark one cleared when the bank has it, or bounced — a bounced cheque reverses its receipt or payment." />

        <ListControls v-model:per-page="list.per_page" v-model:from="list.from" v-model:to="list.to" @clear="clearFilters">
            <div class="relative w-full sm:w-72">
                <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input v-model="list.search" type="search" :placeholder="t('Search cheque number, bank, party or document…')" class="pl-9" />
            </div>
            <select v-model="direction" class="border-input bg-background h-9 rounded-md border px-3 text-sm" :aria-label="t('Received and issued')" @change="filter">
                <option value="">{{ t('Received and issued') }}</option>
                <option value="RECEIVED">{{ t('Received') }}</option>
                <option value="ISSUED">{{ t('Issued') }}</option>
            </select>
            <select v-model="status" class="border-input bg-background h-9 rounded-md border px-3 text-sm" :aria-label="t('Every status')" @change="filter">
                <option value="">{{ t('Every status') }}</option>
                <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
        </ListControls>

        <InputError :message="errors.status ?? errors.event_date ?? errors.reason ?? errors.bank_charge" />

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <SortHeader label="Cheque" column="cheque" :sort="list.sort" :direction="list.direction" @sort="sortBy" />
                        <SortHeader label="Party" column="party" :sort="list.sort" :direction="list.direction" @sort="sortBy" />
                        <SortHeader label="Document" column="document" :sort="list.sort" :direction="list.direction" @sort="sortBy" />
                        <SortHeader label="Amount" column="amount" align="right" :sort="list.sort" :direction="list.direction" @sort="sortBy">{{ t('Amount (:currency)', { currency }) }}</SortHeader>
                        <SortHeader label="Status" column="status" :sort="list.sort" :direction="list.direction" @sort="sortBy" />
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="cheque in visible" :key="cheque.key">
                        <tr class="border-sidebar-border/70 dark:border-sidebar-border border-t align-top">
                            <td class="px-4 py-3">
                                <span class="font-mono">#{{ cheque.cheque_number }}</span>
                                <span class="text-muted-foreground block text-xs">{{ cheque.cheque_bank }} · {{ cheque.cheque_date }}</span>
                                <span class="text-xs">{{ cheque.direction === 'RECEIVED' ? t('Received') : t('Issued') }}</span>
                            </td>
                            <td class="px-4 py-3">{{ cheque.party }}</td>
                            <td class="px-4 py-3"><Link :href="cheque.document_url" class="font-mono underline">{{ cheque.document_number }}</Link><span class="text-muted-foreground block text-xs">{{ cheque.date }}</span></td>
                            <td class="px-4 py-3 text-right font-mono">{{ money(cheque.amount) }}</td>
                            <td class="px-4 py-3">
                                <Badge :variant="variant(cheque.status)">{{ cheque.status_label }}</Badge>
                                <span v-if="cheque.status_date" class="text-muted-foreground block text-xs">{{ cheque.status_date }}</span>
                                <span v-if="cheque.bank_charge" class="text-muted-foreground block text-xs">{{ t('Bank charge :amount', { amount: money(cheque.bank_charge) }) }}</span>
                                <span v-if="cheque.status === 'WAITING' && cheque.not_yet_due" class="block text-xs text-amber-600">{{ t('Not yet due') }}</span>
                            </td>
                            <td class="space-x-1 px-4 py-3 text-right whitespace-nowrap">
                                <template v-if="can('finance.cash.cheque_manage')">
                                    <Button v-if="cheque.can_clear" size="sm" variant="outline" @click="open(cheque, 'clear')">{{ t('Cleared') }}</Button>
                                    <Button v-if="cheque.can_bounce" size="sm" variant="outline" @click="open(cheque, 'bounce')">{{ t('Bounced') }}</Button>
                                    <Button v-if="cheque.can_undo_clear" size="sm" variant="ghost" @click="undo(cheque)">{{ t('Undo cleared') }}</Button>
                                </template>
                            </td>
                        </tr>
                        <tr v-if="acting === cheque.key" class="bg-muted/30">
                            <td colspan="6" class="px-4 py-3">
                                <div class="flex flex-wrap items-end gap-3">
                                    <label class="grid gap-1 text-xs">{{ mode === 'clear' ? t('Cleared on') : t('Bounced on') }}<Input v-model="actionDate" type="date" class="w-40" /></label>
                                    <label v-if="mode === 'bounce'" class="grid flex-1 gap-1 text-xs">{{ t('Reason') }}<Input v-model="reason" :placeholder="t('For example: insufficient funds')" /></label>
                                    <label v-if="mode === 'bounce'" class="grid gap-1 text-xs">{{ t('Bank charge (:currency)', { currency }) }}<Input v-model="bankCharge" inputmode="decimal" placeholder="0.00" class="w-32" /></label>
                                    <Button size="sm" @click="confirm(cheque)">{{ mode === 'clear' ? t('Mark cleared') : t('Mark bounced') }}</Button>
                                    <Button size="sm" variant="ghost" @click="acting = null">{{ t('Cancel') }}</Button>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="visible.length === 0">
                        <td colspan="6" class="text-muted-foreground px-4 py-8 text-center">{{ t('No cheques here.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ClientPagination v-model:page="list.page" :range="range" :page-count="pageCount" />
    </div>
</template>
