<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import JournalTable from '@/components/payroll/JournalTable.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import { reverse as reverseMaintenanceRoute } from '@/routes/asset-maintenance';
import { edit, index, reverse, sell } from '@/routes/assets';
import { store as storeMaintenance } from '@/routes/assets/maintenance';
import { reverse as reverseSaleRoute } from '@/routes/assets/sale';
import { show as loanShow } from '@/routes/loans';
import type { CashBankAccountOption, FixedAssetDetail, PaymentMethodOption } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    asset: FixedAssetDetail;
    accounts: CashBankAccountOption[];
    paymentMethods: PaymentMethodOption[];
    today: string;
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Assets', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();

const inUse = computed(() => props.asset.status === 'IN_USE');
const accountOptions = computed(() => props.accounts.map((a) => ({ value: a.id, label: `${a.code} — ${a.name} (${a.kind})` })));
const methodOptions = computed(() => props.paymentMethods.map((m) => ({ value: m.value, label: m.label ?? '' })));

const maintenance = useForm({ maintenance_date: props.today, work_done: '', done_by: '', cost: '', cash_bank_account_id: null as number | null, payment_method: 'CASH', reference: '' });
const selling = ref(false);
const sale = useForm({ sold_date: props.today, sale_price: '', buyer: '', cash_bank_account_id: null as number | null, payment_method: 'BANK_TRANSFER' });
const gain = computed(() => (Number(sale.sale_price) || 0) - Number(props.asset.purchase_price));

function addMaintenance(): void {
    maintenance.submit(storeMaintenance(props.asset.id), { preserveScroll: true, onSuccess: () => maintenance.reset('work_done', 'done_by', 'cost', 'reference') });
}

function recordSale(): void {
    if (confirm(t('Record this asset as sold for :amount?', { amount: money(sale.sale_price || '0') }))) {
        sale.submit(sell(props.asset.id), { preserveScroll: true, onSuccess: () => (selling.value = false) });
    }
}

function confirmPatch(message: string, url: string): void {
    if (confirm(message)) {
        router.patch(url, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="asset.name" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <Heading :title="asset.name" :description="`${asset.asset_number} · ${asset.category_label}${asset.description ? ' · ' + asset.description : ''}`" />
                <Badge :variant="asset.status === 'REVERSED' ? 'destructive' : asset.status === 'SOLD' ? 'secondary' : 'default'" class="w-fit">{{ asset.status_label }}</Badge>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button v-if="inUse && can('assets.manage')" variant="outline" as-child>
                    <Link :href="edit(asset.id)"><Pencil class="size-4" />{{ t('Edit') }}</Link>
                </Button>
                <Button v-if="inUse && can('assets.sell')" variant="outline" @click="selling = !selling">{{ t('Sell or scrap') }}</Button>
                <Button
                    v-if="asset.status === 'SOLD' && can('assets.sell')"
                    variant="outline"
                    @click="confirmPatch(t('Reverse this sale? The asset is back in use and the sale is undone in the books.'), reverseSaleRoute(asset.id).url)"
                >
                    {{ t('Reverse the sale') }}
                </Button>
                <Button
                    v-if="inUse && can('assets.reverse')"
                    variant="outline"
                    @click="confirmPatch(t('Reverse this asset? It is taken off the books as if never recorded, with its loan if it had one.'), reverse(asset.id).url)"
                >
                    {{ t('Reverse') }}
                </Button>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-4">
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Cost (:currency)', { currency }) }}</p>
                <p class="mt-1 font-mono text-2xl font-semibold">{{ money(asset.purchase_price) }}</p>
                <span class="text-muted-foreground text-xs">{{ t('Bought on :date', { date: asset.purchase_date }) }}</span>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Paid for') }}</p>
                <p class="mt-1 text-sm font-medium">{{ asset.funding_label }}</p>
                <span v-if="asset.paid_from" class="text-muted-foreground block text-xs">{{ asset.paid_from }}<template v-if="asset.funding === 'LOAN'"> · {{ t('down :amount', { amount: money(asset.down_payment) }) }}</template></span>
                <Link v-if="asset.loan" :href="loanShow(asset.loan.id)" class="block text-xs hover:underline">
                    {{ asset.loan.lender }} — {{ t('still owed :amount', { amount: money(asset.loan.outstanding) }) }}
                </Link>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Repairs so far') }}</p>
                <p class="mt-1 font-mono text-lg">{{ money(asset.maintenance_cost) }}</p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-muted-foreground text-xs font-medium">{{ t('Sale') }}</p>
                <template v-if="asset.sale">
                    <p class="mt-1 font-mono text-lg">{{ money(asset.sale.sale_price) }}</p>
                    <span class="text-xs" :class="Number(asset.sale.gain) < 0 ? 'text-red-600' : 'text-green-700'">
                        {{ Number(asset.sale.gain) < 0 ? t('Loss :amount', { amount: money(-Number(asset.sale.gain)) }) : t('Profit :amount', { amount: money(asset.sale.gain) }) }}
                    </span>
                </template>
                <p v-else class="text-muted-foreground mt-1 text-sm">—</p>
            </div>
        </div>

        <form v-if="selling && inUse" class="space-y-4 rounded-xl border border-amber-300 p-4 sm:p-6" @submit.prevent="recordSale">
            <h2 class="text-sm font-medium">{{ t('Sell or scrap') }}</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="sold_date">{{ t('Date') }}</Label>
                    <Input id="sold_date" v-model="sale.sold_date" type="date" required />
                    <InputError :message="sale.errors.sold_date" />
                </div>
                <div class="grid gap-2">
                    <Label for="sale_price">{{ t('Sale price (:currency)', { currency }) }}</Label>
                    <Input id="sale_price" v-model="sale.sale_price" required inputmode="decimal" :placeholder="t('0 if scrapped')" />
                    <InputError :message="sale.errors.sale_price" />
                </div>
                <div class="grid gap-2">
                    <Label for="buyer">{{ t('Buyer') }}</Label>
                    <Input id="buyer" v-model="sale.buyer" maxlength="150" />
                </div>
                <template v-if="Number(sale.sale_price) > 0">
                    <div class="grid gap-2">
                        <Label for="sale_method">{{ t('Paid by') }}</Label>
                        <SearchableSelect id="sale_method" v-model="sale.payment_method" :options="methodOptions" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="sale_account">{{ t('Paid into') }}</Label>
                        <SearchableSelect id="sale_account" v-model="sale.cash_bank_account_id" required :options="accountOptions" :placeholder="t('Select a cash or bank account…')" />
                        <InputError :message="sale.errors.cash_bank_account_id" />
                    </div>
                </template>
            </div>
            <p class="text-sm" :class="gain < 0 ? 'text-red-600' : 'text-green-700'">
                {{ gain < 0 ? t('A loss of :amount against its cost.', { amount: money(-gain) }) : t('A profit of :amount over its cost.', { amount: money(gain) }) }}
            </p>
            <Button type="submit" :disabled="sale.processing">{{ t('Record sale') }}</Button>
        </form>

        <section class="space-y-3">
            <h2 class="text-sm font-medium">{{ t('Maintenance and repairs') }}</h2>
            <form v-if="inUse && can('assets.maintenance_record')" class="grid gap-3 rounded-xl border p-4 sm:grid-cols-6 sm:items-end" @submit.prevent="addMaintenance">
                <div class="grid gap-1">
                    <Label for="maintenance_date" class="text-xs">{{ t('Date') }}</Label>
                    <Input id="maintenance_date" v-model="maintenance.maintenance_date" type="date" required />
                </div>
                <div class="grid gap-1 sm:col-span-2">
                    <Label for="work_done" class="text-xs">{{ t('Work done') }}</Label>
                    <Input id="work_done" v-model="maintenance.work_done" required maxlength="255" :placeholder="t('e.g. Engine service, belt replaced')" />
                    <InputError :message="maintenance.errors.work_done" />
                </div>
                <div class="grid gap-1">
                    <Label for="done_by" class="text-xs">{{ t('Done by') }}</Label>
                    <Input id="done_by" v-model="maintenance.done_by" maxlength="150" />
                </div>
                <div class="grid gap-1">
                    <Label for="cost" class="text-xs">{{ t('Cost (:currency)', { currency }) }}</Label>
                    <Input id="cost" v-model="maintenance.cost" required inputmode="decimal" />
                    <InputError :message="maintenance.errors.cost" />
                </div>
                <div class="grid gap-1">
                    <Button type="submit" :disabled="maintenance.processing">{{ t('Add') }}</Button>
                </div>
                <template v-if="Number(maintenance.cost) > 0">
                    <div class="grid gap-1 sm:col-span-2">
                        <Label for="maintenance_account" class="text-xs">{{ t('Paid from') }}</Label>
                        <SearchableSelect id="maintenance_account" v-model="maintenance.cash_bank_account_id" required :options="accountOptions" :placeholder="t('Select a cash or bank account…')" />
                        <InputError :message="maintenance.errors.cash_bank_account_id" />
                    </div>
                    <div class="grid gap-1 sm:col-span-2">
                        <Label for="maintenance_method" class="text-xs">{{ t('Paid by') }}</Label>
                        <SearchableSelect id="maintenance_method" v-model="maintenance.payment_method" :options="methodOptions" />
                    </div>
                    <div class="grid gap-1 sm:col-span-2">
                        <Label for="maintenance_reference" class="text-xs">{{ t('Reference') }}</Label>
                        <Input id="maintenance_reference" v-model="maintenance.reference" maxlength="100" />
                    </div>
                </template>
            </form>

            <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ t('Date') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Work done') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Done by') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Cost (:currency)', { currency }) }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in asset.maintenances" :key="m.id" class="border-sidebar-border/70 dark:border-sidebar-border border-t" :class="m.is_reversed ? 'text-muted-foreground line-through' : ''">
                            <td class="px-4 py-3">{{ m.maintenance_date }}</td>
                            <td class="px-4 py-3">{{ m.work_done }}</td>
                            <td class="px-4 py-3">{{ m.done_by ?? '—' }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ money(m.cost) }}</td>
                            <td class="px-4 py-3 text-right">
                                <Button
                                    v-if="!m.is_reversed && can('assets.maintenance_reverse')"
                                    size="sm"
                                    variant="ghost"
                                    @click="confirmPatch(t('Reverse this maintenance record? Its cost is taken back out of the books.'), reverseMaintenanceRoute(m.id).url)"
                                >
                                    {{ t('Reverse') }}
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="asset.maintenances.length === 0">
                            <td colspan="5" class="text-muted-foreground px-4 py-8 text-center">{{ t('No maintenance recorded yet.') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <JournalTable v-if="asset.journal" :journal="asset.journal" />
        <JournalTable v-if="asset.sale?.journal" :journal="asset.sale.journal" />

        <dl class="grid gap-6 rounded-xl border p-4 sm:grid-cols-3 sm:p-6">
            <div>
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Recorded') }}</dt>
                <dd class="mt-1 text-sm">{{ asset.posted_at ?? '—' }} · {{ asset.created_by ?? '—' }}</dd>
            </div>
            <div v-if="asset.sale">
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Sold') }}</dt>
                <dd class="mt-1 text-sm">{{ asset.sale.sold_date }}<template v-if="asset.sale.buyer"> · {{ asset.sale.buyer }}</template><template v-if="asset.sale.paid_into"> · {{ asset.sale.paid_into }}</template></dd>
            </div>
            <div v-if="asset.reversed_at">
                <dt class="text-muted-foreground text-sm font-medium">{{ t('Reversed') }}</dt>
                <dd class="mt-1 text-sm">{{ asset.reversed_at }} · {{ asset.reversed_by ?? '—' }}</dd>
            </div>
        </dl>
    </div>
</template>
