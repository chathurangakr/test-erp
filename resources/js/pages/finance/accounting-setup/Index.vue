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
import SearchableSelect from '@/components/SearchableSelect.vue';
import { usePermissions } from '@/composables/usePermissions';
import { index } from '@/routes/accounting-setup';
import { index as accountsIndex } from '@/routes/accounts';
import type {
    AccountingSettingsView,
    AccountOption,
    AccountRef,
    ProductMappingRow,
} from '@/types';
import { useT } from '@/lib/i18n';

defineProps<{
    settings: AccountingSettingsView;
    referenceAccounts: {
        trade_payables: AccountRef;
        trade_receivables: AccountRef;
    };
    products: ProductMappingRow[];
    unmappedCount: number;
    accountOptions: {
        inventory: AccountOption[];
        cogs: AccountOption[];
        revenue: AccountOption[];
        liability: AccountOption[];
        expense: AccountOption[];
        asset: AccountOption[];
        equity: AccountOption[];
    };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Accounting setup', href: index() }] },
});

const { can } = usePermissions();

const editing = ref<number | null>(null);

function label(account: AccountRef): string {
    return account ? `${account.code} — ${account.name}` : '—';
}


const { t } = useT();
</script>

<template>
    <Head :title="t('Accounting setup')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Accounting setup"
            description="Where stock movements meet the general ledger: when accounting starts, and which accounts each product uses."
        />

        <div
            v-if="!settings.operational_accounting_start_date"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-4 text-sm"
        >
            <strong>{{
                t('Operational accounting is not switched on.')
            }}</strong>
            {{
                t(
                    'Goods receipts, deliveries and production runs move stock exactly as before and generate no journal entries. Set a start date below when the books are ready — nothing before that date will be accounted for, and no history is rewritten.',
                )
            }}
        </div>

        <div
            v-else-if="unmappedCount > 0"
            class="border-destructive text-destructive rounded-xl border p-4 text-sm"
        >
            <strong>{{
                t(':count product(s) have no account mapping.', {
                    count: unmappedCount,
                })
            }}</strong>
            {{
                t(
                    'Any new stock movement of an unmapped product will be refused, because there is no account to post it to. Map them below, or leave them unmapped deliberately if they should not be transacted.',
                )
            }}
        </div>

        <div
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 sm:p-6"
        >
            <h3 class="text-sm font-medium">{{ t('Configuration') }}</h3>

            <Form
                v-bind="AccountingSetupController.updateSettings.form()"
                v-slot="{ errors, processing }"
                class="mt-4 space-y-4"
            >
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="currency">{{ t('Base currency') }}</Label>
                        <Input
                            id="currency"
                            :model-value="settings.base_currency"
                            readonly
                            tabindex="-1"
                            class="bg-muted/50"
                        />
                        <p class="text-muted-foreground text-xs">
                            {{
                                t(
                                    'Configuration, not a literal in the code. Multi-currency is not in scope.',
                                )
                            }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="start_date">{{
                            t('Operational accounting start date')
                        }}</Label>
                        <Input
                            id="start_date"
                            name="operational_accounting_start_date"
                            type="date"
                            :default-value="
                                settings.operational_accounting_start_date ?? ''
                            "
                            :disabled="
                                settings.cutover_locked ||
                                !can('finance.setup_manage')
                            "
                        />
                        <p
                            v-if="settings.cutover_locked"
                            class="text-muted-foreground text-xs"
                        >
                            {{
                                t(
                                    'Frozen: :count operational journal(s) exist. Moving this date would leave documents accounted for that should not be, or unaccounted for that should be.',
                                    {
                                        count: settings.operational_journal_count,
                                    },
                                )
                            }}
                        </p>
                        <p v-else class="text-muted-foreground text-xs">
                            {{
                                t(
                                    'Documents dated on or after this must create a journal. Earlier ones never will.',
                                )
                            }}
                        </p>
                        <InputError
                            :message="errors.operational_accounting_start_date"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="grni">{{
                            t('Goods Received Not Invoiced')
                        }}</Label>
                        <SearchableSelect
                            id="grni"
                            name="grni_account_id"
                            :disabled="!can('finance.setup_manage')"
                            :options="[{ value: '', label: t('Not configured') }, ...(accountOptions.liability).map((account) => ({ value: account.id, label: `${account.code} — ${account.name}` }))]"
                            :default-value="(settings.grni_account?.id) ?? ''"
                        />
                        <InputError :message="errors.grni_account_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="wages">{{ t('Wages (payroll)') }}</Label>
                        <SearchableSelect
                            id="wages"
                            name="wages_account_id"
                            :disabled="!can('finance.setup_manage')"
                            :options="[{ value: '', label: t('Not configured') }, ...(accountOptions.expense).map((account) => ({ value: account.id, label: `${account.code} — ${account.name}` }))]"
                            :default-value="(settings.wages_account?.id) ?? ''"
                        />
                        <p class="text-muted-foreground text-xs">
                            {{ t('The expense monthly salaries are charged to.') }}
                        </p>
                        <InputError :message="errors.wages_account_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="employee_advances">{{ t('Employee advances (payroll)') }}</Label>
                        <SearchableSelect
                            id="employee_advances"
                            name="employee_advances_account_id"
                            :disabled="!can('finance.setup_manage')"
                            :options="[{ value: '', label: t('Not configured') }, ...(accountOptions.asset).map((account) => ({ value: account.id, label: `${account.code} — ${account.name}` }))]"
                            :default-value="(settings.employee_advances_account?.id) ?? ''"
                        />
                        <p class="text-muted-foreground text-xs">
                            {{ t('Where salary advances sit until a salary takes them back.') }}
                        </p>
                        <InputError :message="errors.employee_advances_account_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="stock_loss">{{ t('Stock damage and losses') }}</Label>
                        <SearchableSelect
                            id="stock_loss"
                            name="stock_loss_account_id"
                            :disabled="!can('finance.setup_manage')"
                            :options="[{ value: '', label: t('Not configured') }, ...(accountOptions.expense).map((account) => ({ value: account.id, label: `${account.code} — ${account.name}` }))]"
                            :default-value="(settings.stock_loss_account?.id) ?? ''"
                        />
                        <p class="text-muted-foreground text-xs">
                            {{ t('The expense damaged stock is written off to.') }}
                        </p>
                        <InputError :message="errors.stock_loss_account_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="loans">{{ t('Loans') }}</Label>
                        <SearchableSelect
                            id="loans"
                            name="loans_account_id"
                            :disabled="!can('finance.setup_manage')"
                            :options="[{ value: '', label: t('Not configured') }, ...(accountOptions.liability).map((account) => ({ value: account.id, label: `${account.code} — ${account.name}` }))]"
                            :default-value="(settings.loans_account?.id) ?? ''"
                        />
                        <p class="text-muted-foreground text-xs">
                            {{ t('The liability every loan sits in until it is paid off.') }}
                        </p>
                        <InputError :message="errors.loans_account_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="loan_interest">{{ t('Loan interest') }}</Label>
                        <SearchableSelect
                            id="loan_interest"
                            name="loan_interest_account_id"
                            :disabled="!can('finance.setup_manage')"
                            :options="[{ value: '', label: t('Not configured') }, ...(accountOptions.expense).map((account) => ({ value: account.id, label: `${account.code} — ${account.name}` }))]"
                            :default-value="(settings.loan_interest_account?.id) ?? ''"
                        />
                        <p class="text-muted-foreground text-xs">
                            {{ t('The expense the interest part of each loan payment is charged to.') }}
                        </p>
                        <InputError :message="errors.loan_interest_account_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="opening_balances">{{ t('Opening balances') }}</Label>
                        <SearchableSelect
                            id="opening_balances"
                            name="opening_balance_account_id"
                            :disabled="!can('finance.setup_manage')"
                            :options="[{ value: '', label: t('Not configured') }, ...(accountOptions.equity).map((account) => ({ value: account.id, label: `${account.code} — ${account.name}` }))]"
                            :default-value="(settings.opening_balance_account?.id) ?? ''"
                        />
                        <p class="text-muted-foreground text-xs">
                            {{ t('Balances a loan entered already running, until your accountant moves it.') }}
                        </p>
                        <InputError :message="errors.opening_balance_account_id" />
                    </div>
                </div>

                <Button
                    v-if="can('finance.setup_manage')"
                    type="submit"
                    :disabled="processing"
                >
                    {{ t('Save configuration') }}
                </Button>
            </Form>

            <dl
                class="border-sidebar-border/70 dark:border-sidebar-border mt-6 grid gap-6 border-t pt-6 sm:grid-cols-3"
            >
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">
                        {{ t('Trade Payables') }}
                    </dt>
                    <dd class="mt-1 font-mono text-sm">
                        {{ label(referenceAccounts.trade_payables) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">
                        {{ t('Trade Receivables') }}
                    </dt>
                    <dd class="mt-1 font-mono text-sm">
                        {{ label(referenceAccounts.trade_receivables) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground text-sm font-medium">
                        {{ t('Mapping completeness') }}
                    </dt>
                    <dd class="mt-1 text-sm">
                        {{
                            t(':mapped of :total products mapped', {
                                mapped: products.length - unmappedCount,
                                total: products.length,
                            })
                        }}
                    </dd>
                </div>
            </dl>
        </div>

        <div
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4 text-sm sm:p-6"
        >
            <h3 class="text-sm font-medium">
                {{ t('What each event posts') }}
            </h3>
            <dl
                class="text-muted-foreground mt-3 grid gap-3 text-xs sm:grid-cols-2"
            >
                <div>
                    <dt class="font-medium">{{ t('Goods receipt') }}</dt>
                    <dd class="font-mono">
                        {{ t('Dr Inventory / Cr Goods Received Not Invoiced') }}
                    </dd>
                </div>
                <div>
                    <dt class="font-medium">{{ t('Delivery') }}</dt>
                    <dd class="font-mono">
                        {{ t('Dr Cost of Sales / Cr Inventory') }}
                    </dd>
                </div>
                <div>
                    <dt class="font-medium">{{ t('Production') }}</dt>
                    <dd class="font-mono">
                        {{ t('Dr Output Inventory / Cr Input Inventory') }}
                    </dd>
                </div>
                <div>
                    <dt class="font-medium">{{ t('Warehouse transfer') }}</dt>
                    <dd class="font-mono">
                        {{
                            t(
                                'No journal — same product, same value, same account',
                            )
                        }}
                    </dd>
                </div>
            </dl>
            <p class="text-muted-foreground mt-3 max-w-3xl text-xs">
                {{
                    t(
                        'A delivery records the cost of goods sold only. Revenue and receivables belong to the sales invoice, and the supplier invoice moves the GRNI balance to Trade Payables.',
                    )
                }}
            </p>
        </div>

        <div
            class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border"
        >
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">
                            {{ t('Product') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ t('Inventory') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ t('Cost of sales') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ t('Revenue') }}
                        </th>
                        <th class="px-4 py-3 font-medium">{{ t('Status') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="product in products" :key="product.id">
                        <tr
                            class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                        >
                            <td class="px-4 py-3">
                                <span class="font-mono text-xs font-medium">{{
                                    product.code
                                }}</span>
                                <span
                                    class="text-muted-foreground block text-xs"
                                    >{{ product.name }}</span
                                >
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">
                                {{ label(product.inventory_account) }}
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">
                                {{ label(product.cogs_account) }}
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">
                                {{ label(product.revenue_account) }}
                            </td>
                            <td class="px-4 py-3">
                                <Badge
                                    :variant="
                                        product.is_mapped
                                            ? 'default'
                                            : 'destructive'
                                    "
                                >
                                    {{
                                        product.is_mapped
                                            ? t('Mapped')
                                            : t('Unmapped')
                                    }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Button
                                    v-if="can('finance.setup_manage')"
                                    variant="outline"
                                    size="sm"
                                    @click="
                                        editing =
                                            editing === product.id
                                                ? null
                                                : product.id
                                    "
                                >
                                    {{
                                        editing === product.id
                                            ? t('Close')
                                            : t('Map')
                                    }}
                                </Button>
                            </td>
                        </tr>

                        <tr
                            v-if="
                                editing === product.id &&
                                can('finance.setup_manage')
                            "
                            class="bg-muted/20"
                        >
                            <td colspan="6" class="px-4 py-4">
                                <Form
                                    v-bind="
                                        AccountingSetupController.updateMapping.form(
                                            product.id,
                                        )
                                    "
                                    v-slot="{ errors, processing }"
                                    class="space-y-3"
                                >
                                    <div class="grid gap-4 sm:grid-cols-3">
                                        <div class="grid gap-2">
                                            <Label :for="`inv-${product.id}`">{{
                                                t('Inventory (asset)')
                                            }}</Label>
                                            <SearchableSelect
                                                :id="`inv-${product.id}`"
                                                name="inventory_account_id"
                                                required
                                                :options="[{ value: '', label: t('Select…') }, ...(accountOptions.inventory).map((a) => ({ value: a.id, label: `${a.code} — ${a.name}` }))]"
                                                :default-value="(product.inventory_account?.id) ?? ''"
                                            />
                                            <InputError
                                                :message="
                                                    errors.inventory_account_id
                                                "
                                            />
                                        </div>

                                        <div class="grid gap-2">
                                            <Label
                                                :for="`cogs-${product.id}`"
                                                >{{ t('Cost of sales') }}</Label
                                            >
                                            <SearchableSelect
                                                :id="`cogs-${product.id}`"
                                                name="cogs_account_id"
                                                required
                                                :options="[{ value: '', label: t('Select…') }, ...(accountOptions.cogs).map((a) => ({ value: a.id, label: `${a.code} — ${a.name}` }))]"
                                                :default-value="(product.cogs_account?.id) ?? ''"
                                            />
                                            <InputError
                                                :message="
                                                    errors.cogs_account_id
                                                "
                                            />
                                        </div>

                                        <div class="grid gap-2">
                                            <Label :for="`rev-${product.id}`">{{
                                                t(
                                                    'Revenue (for later invoicing)',
                                                )
                                            }}</Label>
                                            <SearchableSelect
                                                :id="`rev-${product.id}`"
                                                name="revenue_account_id"
                                                :options="[{ value: '', label: t('Not configured') }, ...(accountOptions.revenue).map((a) => ({ value: a.id, label: `${a.code} — ${a.name}` }))]"
                                                :default-value="(product.revenue_account?.id) ?? ''"
                                            />
                                            <InputError
                                                :message="
                                                    errors.revenue_account_id
                                                "
                                            />
                                        </div>
                                    </div>

                                    <Button
                                        type="submit"
                                        size="sm"
                                        :disabled="processing"
                                        >{{ t('Save mapping') }}</Button
                                    >
                                </Form>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            {{
                t(
                    'A product with no mapping has no accounting meaning. Once accounting is switched on, moving its stock is refused rather than guessed at — a wrong account written permanently into the books is worse than a failed posting.',
                )
            }}
            <Link :href="accountsIndex().url" class="underline">{{
                t('See the chart of accounts for what each account is.')
            }}</Link>
        </p>
    </div>
</template>
