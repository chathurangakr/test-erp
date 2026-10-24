<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';
import { create, index, show } from '@/routes/credit-notes';
import type { Paginated } from '@/types';

type CreditNoteRow = {
    id: number;
    credit_note_number: string;
    credit_date: string;
    customer: string | null;
    invoice_number: string | null;
    kind_label: string;
    amount: string;
    status: string;
    status_label: string;
};

const props = defineProps<{
    notes: Paginated<CreditNoteRow>;
    filters: { customer_id: number | null };
    customers: { id: number; code: string; name: string }[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Credit notes', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();

const customerId = ref(props.filters.customer_id ? String(props.filters.customer_id) : '');
watch(customerId, () => {
    router.get(index().url, { customer_id: customerId.value || undefined }, { preserveState: true, replace: true });
});
</script>

<template>
    <Head :title="t('Credit notes')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading title="Credit notes" description="Discounts and quality allowances that reduce what a customer owes on an invoice. No goods come back — for that, record a return." />
            <Button v-if="can('finance.ar.credit_note_record')" as-child>
                <Link :href="create()"><Plus class="size-4" />{{ t('Give a discount or allowance') }}</Link>
            </Button>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <SearchableSelect
                v-model="customerId"
                :options="[{ value: '', label: t('All customers') }, ...customers.map((c) => ({ value: String(c.id), label: `${c.code} — ${c.name}` }))]"
            />
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Credit note') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Date') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Customer') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Invoice') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Kind') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Amount (:currency)', { currency }) }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in notes.data" :key="row.id" class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t">
                        <td class="px-4 py-3"><Link :href="show(row.id)" class="font-mono hover:underline">{{ row.credit_note_number }}</Link></td>
                        <td class="px-4 py-3">{{ row.credit_date }}</td>
                        <td class="px-4 py-3">{{ row.customer }}</td>
                        <td class="px-4 py-3 font-mono">{{ row.invoice_number }}</td>
                        <td class="px-4 py-3">{{ row.kind_label }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.amount) }}</td>
                        <td class="px-4 py-3"><Badge :variant="row.status === 'REVERSED' ? 'destructive' : 'default'">{{ row.status_label }}</Badge></td>
                    </tr>
                    <tr v-if="notes.data.length === 0">
                        <td colspan="7" class="text-muted-foreground px-4 py-8 text-center">{{ t('No credit notes yet.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="notes.last_page > 1" class="flex flex-wrap gap-1">
            <template v-for="link in notes.links" :key="link.label">
                <Link v-if="link.url" :href="link.url" class="rounded-md border px-3 py-1 text-sm" :class="link.active ? 'bg-muted font-medium' : ''" preserve-state>
                    <span v-html="link.label" />
                </Link>
            </template>
        </div>
    </div>
</template>
