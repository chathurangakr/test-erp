<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AccountingSetupController from '@/actions/App/Http/Controllers/Accounting/AccountingSetupController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import { index } from '@/routes/accounting-setup';
import { index as accountsIndex } from '@/routes/accounts';
import type { AccountingSettingsView, AccountOption, AccountRef, ProductMappingRow } from '@/types';

defineProps<{
    settings: AccountingSettingsView;
    referenceAccounts: { trade_payables: AccountRef; trade_receivables: AccountRef };
    products: ProductMappingRow[];
    unmappedCount: number;
    accountOptions: {
        inventory: AccountOption[];
        cogs: AccountOption[];
        revenue: AccountOption[];
        liability: AccountOption[];
    };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Accounting setup', href: index() }] } });

const { can } = usePermissions();

const editing = ref<number | null>(null);

function label(account: AccountRef): string {
    return account ? `${account.code} — ${account.name}` : '—';
}

const selectClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50';
</script>

<template>
    <Head title="Accounting setup" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Accounting setup"
            description="Where stock movements meet the general ledger: when accounting starts, and which accounts each product uses."
        />

        <div
            v-if="!settings.operational_accounting_start_date"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-4 text-sm"
        >
            <strong>Operational accounting is not switched on.</strong> Goods receipts,
            deliveries and production runs move stock exactly as before and generate no journal
            entries. Set a start date below when the books are ready — nothing before that date
            will be accounted for, and no history is rewritten.
        </div>

        <div
            v-else-if="unmappedCount > 0"
            class="border-destructive text-destructive rounded-xl border p-4 text-sm"
        >
            <strong>{{ unmappedCount }} product(s) have no account mapping.</strong> Any new
            stock movement of an unmapped product will be refused, because there is no account
            to post it to. Map them below, or leave them unmapped deliberately if they should
            not be transacted.
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6">
            <h3 class="text-sm font-medium">Configuration</h3>

            <Form
                v-bind="AccountingSetupController.updateSettings.form()"
                v-slot="{ errors, processing }"
                class="mt-4 space-y-4"
            >
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="currency">Base currency</Label>
                        <Input
                            id="currency"
                            :model-value="settings.base_currency"
                            readonly
                            tabindex="-1"
                            class="bg-muted/50"
                        />
                        <p class="text-muted-foreground text-xs">
                            Configuration, not a literal in the code. Multi-currency is not in scope.
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="start_date">Operational accounting start date</Label>
                        <Input
                            id="start_date"
                            name="operational_accounting_start_date"
                            type="date"
                            :default-value="settings.operational_accounting_start_date ?? ''"
                            :disabled="settings.cutover_locked || !can('finance.setup_manage')"
                        />
                        <p v-if="settings.cutover_locked" class="text-muted-foreground text-xs">
                            Frozen: {{ settings.operational_journal_count }} operational journal(s)
                            exist. Moving this date would leave documents accounted for that should
                            not be, or unaccounted for that should be.
                        </p>
                        <p v-else class="text-muted-foreground text-xs">
                            Documents dated on or after this must create a journal. Earlier ones
                            never will.
                        </p>
                        <InputError :message="errors.operational_accounting_start_date" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="grni">Goods Received Not Invoiced</Label>
                        <select
                            id="grni"
                            name="grni_account_id"
                            :class="selectClass"
                            :disabled="!can('finance.setup_manage')"
                        >
                            <option value="">Not configured</option>
                            <option
                                v-for="account in accountOptions.liability"
                                :key="account.id"
                                :value="account.id"
                                :selected="account.id === settings.grni_account?.id"
                            >
                                {{ account.code }} — {{ account.name }}
                            </option>
                        </select>
                        <InputError :message="errors.grni_account_id" />
                    </div>
                </div>

                <Button v-if="can('finance.setup_manage')" type="submit" :disabled="processing">
                    Save configuration
                </Button>
            </Form>

            <dl class="border-sidebar-border/70 dark:border-sidebar-border mt-6 grid gap-6 border-t pt-6 sm:grid-cols-3">
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Trade Payables</dt>
                    <dd class="mt-1 font-mono text-sm">{{ label(referenceAccounts.trade_payables) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Trade Receivables</dt>
                    <dd class="mt-1 font-mono text-sm">{{ label(referenceAccounts.trade_receivables) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">Mapping completeness</dt>
                    <dd class="mt-1 text-sm">
                        {{ products.length - unmappedCount }} of {{ products.length }} products mapped
                    </dd>
                </div>
            </dl>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 text-sm sm:p-6">
            <h3 class="text-sm font-medium">What each event posts</h3>
            <dl class="text-muted-foreground mt-3 grid gap-3 text-xs sm:grid-cols-2">
                <div>
                    <dt class="font-medium">Goods receipt</dt>
                    <dd class="font-mono">Dr Inventory / Cr Goods Received Not Invoiced</dd>
                </div>
                <div>
                    <dt class="font-medium">Delivery</dt>
                    <dd class="font-mono">Dr Cost of Sales / Cr Inventory</dd>
                </div>
                <div>
                    <dt class="font-medium">Production</dt>
                    <dd class="font-mono">Dr Output Inventory / Cr Input Inventory</dd>
                </div>
                <div>
                    <dt class="font-medium">Warehouse transfer</dt>
                    <dd class="font-mono">No journal — same product, same value, same account</dd>
                </div>
            </dl>
            <p class="text-muted-foreground mt-3 max-w-3xl text-xs">
                A delivery records the cost of goods sold only. Revenue and receivables belong to
                the sales invoice, and the supplier invoice will later move the GRNI balance to
                Trade Payables — neither is built yet.
            </p>
        </div>

        <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Product</th>
                        <th class="px-4 py-3 font-medium">Inventory</th>
                        <th class="px-4 py-3 font-medium">Cost of sales</th>
                        <th class="px-4 py-3 font-medium">Revenue</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="product in products" :key="product.id">
                        <tr class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                            <td class="px-4 py-3">
                                <span class="font-mono text-xs font-medium">{{ product.code }}</span>
                                <span class="text-muted-foreground block text-xs">{{ product.name }}</span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">{{ label(product.inventory_account) }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ label(product.cogs_account) }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ label(product.revenue_account) }}</td>
                            <td class="px-4 py-3">
                                <Badge :variant="product.is_mapped ? 'default' : 'destructive'">
                                    {{ product.is_mapped ? 'Mapped' : 'Unmapped' }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Button
                                    v-if="can('finance.setup_manage')"
                                    variant="outline"
                                    size="sm"
                                    @click="editing = editing === product.id ? null : product.id"
                                >
                                    {{ editing === product.id ? 'Close' : 'Map' }}
                                </Button>
                            </td>
                        </tr>

                        <tr v-if="editing === product.id && can('finance.setup_manage')" class="bg-muted/20">
                            <td colspan="6" class="px-4 py-4">
                                <Form
                                    v-bind="AccountingSetupController.updateMapping.form(product.id)"
                                    v-slot="{ errors, processing }"
                                    class="space-y-3"
                                >
                                    <div class="grid gap-4 sm:grid-cols-3">
                                        <div class="grid gap-2">
                                            <Label :for="`inv-${product.id}`">Inventory (asset)</Label>
                                            <select
                                                :id="`inv-${product.id}`"
                                                name="inventory_account_id"
                                                required
                                                :class="selectClass"
                                            >
                                                <option value="">Select…</option>
                                                <option
                                                    v-for="a in accountOptions.inventory"
                                                    :key="a.id"
                                                    :value="a.id"
                                                    :selected="a.id === product.inventory_account?.id"
                                                >
                                                    {{ a.code }} — {{ a.name }}
                                                </option>
                                            </select>
                                            <InputError :message="errors.inventory_account_id" />
                                        </div>

                                        <div class="grid gap-2">
                                            <Label :for="`cogs-${product.id}`">Cost of sales</Label>
                                            <select
                                                :id="`cogs-${product.id}`"
                                                name="cogs_account_id"
                                                required
                                                :class="selectClass"
                                            >
                                                <option value="">Select…</option>
                                                <option
                                                    v-for="a in accountOptions.cogs"
                                                    :key="a.id"
                                                    :value="a.id"
                                                    :selected="a.id === product.cogs_account?.id"
                                                >
                                                    {{ a.code }} — {{ a.name }}
                                                </option>
                                            </select>
                                            <InputError :message="errors.cogs_account_id" />
                                        </div>

                                        <div class="grid gap-2">
                                            <Label :for="`rev-${product.id}`">Revenue (for later invoicing)</Label>
                                            <select
                                                :id="`rev-${product.id}`"
                                                name="revenue_account_id"
                                                :class="selectClass"
                                            >
                                                <option value="">Not configured</option>
                                                <option
                                                    v-for="a in accountOptions.revenue"
                                                    :key="a.id"
                                                    :value="a.id"
                                                    :selected="a.id === product.revenue_account?.id"
                                                >
                                                    {{ a.code }} — {{ a.name }}
                                                </option>
                                            </select>
                                            <InputError :message="errors.revenue_account_id" />
                                        </div>
                                    </div>

                                    <Button type="submit" size="sm" :disabled="processing">Save mapping</Button>
                                </Form>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            A product with no mapping has no accounting meaning. Once accounting is switched on,
            moving its stock is refused rather than guessed at — a wrong account written
            permanently into the books is worse than a failed posting. See the
            <Link :href="accountsIndex().url" class="underline">chart of accounts</Link>
            for what each account is.
        </p>
    </div>
</template>
