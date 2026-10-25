<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
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
</script>

<template>
    <Head :title="t('Cheques')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="Cheques" description="Every cheque received or issued. Mark one cleared when the bank has it, or bounced — a bounced cheque reverses its receipt or payment." />

        <div class="flex flex-wrap gap-3">
            <select v-model="direction" class="border-input bg-background h-9 rounded-md border px-3 text-sm" @change="filter">
                <option value="">{{ t('Received and issued') }}</option>
                <option value="RECEIVED">{{ t('Received') }}</option>
                <option value="ISSUED">{{ t('Issued') }}</option>
            </select>
            <select v-model="status" class="border-input bg-background h-9 rounded-md border px-3 text-sm" @change="filter">
                <option value="">{{ t('Every status') }}</option>
                <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
        </div>

        <InputError :message="errors.status ?? errors.event_date ?? errors.reason ?? errors.bank_charge" />

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Cheque') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Party') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Document') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Amount (:currency)', { currency }) }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Status') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="cheque in cheques" :key="cheque.key">
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
                    <tr v-if="cheques.length === 0">
                        <td colspan="6" class="text-muted-foreground px-4 py-8 text-center">{{ t('No cheques here.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
