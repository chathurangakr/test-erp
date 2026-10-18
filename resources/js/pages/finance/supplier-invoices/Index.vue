<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { usePermissions } from '@/composables/usePermissions';
import { index } from '@/routes/supplier-invoices';
import type { OptionRef, Paginated, StatusOption, SupplierInvoiceRow } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    invoices: Paginated<SupplierInvoiceRow>;
    filters: { search: string; status: string; supplier_id: number | null; from: string | null; to: string | null; overdue: boolean };
    statuses: StatusOption[];
    suppliers: OptionRef[];
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Supplier invoices', href: index() }] } });

const { can } = usePermissions();

const status = ref(props.filters.status ?? '');
const supplierId = ref(props.filters.supplier_id ? String(props.filters.supplier_id) : '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');
const overdue = ref(props.filters.overdue ?? false);

function applyFilters(): void {
    router.get(
        index().url,
        {
            status: status.value || undefined,
            supplier_id: supplierId.value || undefined,
            from: from.value || undefined,
            to: to.value || undefined,
            overdue: overdue.value ? 1 : undefined,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

watch([status, supplierId, from, to, overdue], applyFilters);

function badgeVariant(value: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (value === 'CANCELLED' || value === 'REVERSED') return 'destructive';
    if (value === 'PAID') return 'secondary';
    if (value === 'POSTED' || value === 'PARTIALLY_PAID') return 'default';
    return 'outline';
}

const controlClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';

const { t } = useT();
</script>

<template>
    <Head :title="t('Supplier invoices')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading
                title="Supplier invoices"
                :description="t('What suppliers have billed, in :currency. A draft owes nothing until it is posted.', { currency })"
            />
            <Button v-if="can('finance.ap.invoice_create')" as-child class="w-full sm:w-auto">
                <Link :href="`${index().url}/create`"><Plus class="size-4" />{{ t('New supplier invoice') }}</Link>
            </Button>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div class="grid gap-1.5">
                <label for="f-supplier" class="text-sm font-medium">{{ t('Supplier') }}</label>
                <SearchableSelect
                    id="f-supplier"
                    v-model="supplierId"
                    :options="[{ value: '', label: t('All suppliers') }, ...(suppliers).map((s) => ({ value: String(s.id), label: `${s.code} — ${s.name}` }))]"
                />
            </div>
            <div class="grid gap-1.5">
                <label for="f-status" class="text-sm font-medium">{{ t('Status') }}</label>
                <SearchableSelect
                    id="f-status"
                    v-model="status"
                    :options="[{ value: '', label: t('All statuses') }, ...(statuses).map((s) => ({ value: s.value, label: `${(s.label) ?? ''}` }))]"
                />
            </div>
            <div class="grid gap-1.5">
                <label for="f-from" class="text-sm font-medium">{{ t('From') }}</label>
                <input id="f-from" v-model="from" type="date" :class="controlClass" />
            </div>
            <div class="grid gap-1.5">
                <label for="f-to" class="text-sm font-medium">{{ t('To') }}</label>
                <input id="f-to" v-model="to" type="date" :class="controlClass" />
            </div>
            <div class="flex items-end">
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="overdue" type="checkbox" />
                    {{ t('Overdue only') }}
                </label>
            </div>
        </div>

        <div
            v-if="invoices.data.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">{{ t('No supplier invoices found') }}</p>
            <p class="text-muted-foreground text-sm">
                {{ t("Record one to clear a goods receipt's accrual, or to charge an expense directly.") }}
            </p>
        </div>

        <div v-else class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('Internal No') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t("Supplier's No") }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Supplier') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Invoice date') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Due') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('Status') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Total') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Paid') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('Outstanding') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in invoices.data"
                        :key="row.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border hover:bg-muted/40 border-t"
                    >
                        <td class="px-4 py-3 font-mono text-xs font-medium">
                            <Link :href="`${index().url}/${row.id}`" class="hover:underline">
                                {{ row.internal_invoice_number }}
                            </Link>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">{{ row.supplier_invoice_number }}</td>
                        <td class="px-4 py-3">
                            <span class="font-mono text-xs">{{ row.supplier_code }}</span>
                            <span class="text-muted-foreground block text-xs">{{ row.supplier_name }}</span>
                        </td>
                        <td class="px-4 py-3">{{ row.invoice_date }}</td>
                        <td class="px-4 py-3">
                            {{ row.due_date }}
                            <span v-if="row.is_overdue" class="text-destructive block text-xs">{{ t('Overdue') }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="badgeVariant(row.status)">{{ row.status_label }}</Badge>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.total) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money(row.paid) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-medium">{{ money(row.outstanding) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="invoices.last_page > 1" class="flex flex-col items-center justify-between gap-3 sm:flex-row">
            <p class="text-muted-foreground text-sm">
                {{ t('Showing :from–:to of :total', { from: invoices.from ?? 0, to: invoices.to ?? 0, total: invoices.total }) }}
            </p>
            <div class="flex flex-wrap items-center gap-1">
                <Button
                    v-for="link in invoices.links"
                    :key="link.label"
                    :variant="link.active ? 'default' : 'outline'"
                    size="sm"
                    :disabled="!link.url"
                    @click="link.url && router.visit(link.url, { preserveState: true, preserveScroll: true })"
                    v-html="link.label"
                />
            </div>
        </div>
    </div>
</template>
