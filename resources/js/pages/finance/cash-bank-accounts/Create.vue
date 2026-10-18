<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { index } from '@/routes/cash-bank-accounts';
import { useT } from '@/lib/i18n';

const props = defineProps<{
    availableAccounts: { id: number; code: string; name: string }[];
    kinds: { value: string; label: string }[];
}>();

const { t } = useT();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Cash / bank accounts', href: index() },
            { title: 'Configure', href: index() },
        ],
    },
});

const form = useForm({
    account_id: '' as string | number,
    account_kind: 'BANK',
    display_name: '',
    bank_name: '',
    branch_name: '',
    account_number: '',
    notes: '',
    is_active: true,
});

function submit(): void {
    form.post(index().url, { preserveScroll: true });
}

</script>

<template>
    <Head :title="t('Configure cash / bank account')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Configure a cash / bank account"
            description="Names an existing ledger account as somewhere the factory holds money."
        />

        <form class="max-w-3xl space-y-8" @submit.prevent="submit">
            <section class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="account_id">{{ t('Ledger account') }} <span class="text-destructive">*</span></Label>
                    <SearchableSelect
                        id="account_id"
                        v-model="form.account_id"
                        required
                        :options="[...(props.availableAccounts).map((a) => ({ value: a.id, label: `${a.code} — ${a.name}` }))]"
                        :placeholder="t('Select an asset account…')"
                    />
                    <InputError :message="form.errors.account_id" />
                    <p class="text-muted-foreground text-xs">
                        {{ t('Only active asset accounts that are not control accounts or group headers appear here, and each may be configured once.') }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="account_kind">{{ t('Kind') }} <span class="text-destructive">*</span></Label>
                    <SearchableSelect
                        id="account_kind"
                        v-model="form.account_kind"
                        required
                        :options="[...(props.kinds).map((k) => ({ value: k.value, label: `${(k.label) ?? ''}` }))]"
                    />
                    <InputError :message="form.errors.account_kind" />
                </div>

                <div class="grid gap-2">
                    <Label for="display_name">{{ t('Display name') }} <span class="text-destructive">*</span></Label>
                    <Input id="display_name" v-model="form.display_name" maxlength="150" required />
                    <InputError :message="form.errors.display_name" />
                </div>

                <template v-if="form.account_kind === 'BANK'">
                    <div class="grid gap-2">
                        <Label for="bank_name">{{ t('Bank name') }}</Label>
                        <Input id="bank_name" v-model="form.bank_name" maxlength="150" />
                        <InputError :message="form.errors.bank_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="branch_name">{{ t('Branch') }}</Label>
                        <Input id="branch_name" v-model="form.branch_name" maxlength="150" />
                        <InputError :message="form.errors.branch_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="account_number">{{ t('Account number') }}</Label>
                        <Input id="account_number" v-model="form.account_number" maxlength="100" />
                        <InputError :message="form.errors.account_number" />
                    </div>
                </template>

                <div class="grid gap-2 sm:col-span-2">
                    <Label for="notes">{{ t('Notes') }}</Label>
                    <Input id="notes" v-model="form.notes" maxlength="500" />
                    <InputError :message="form.errors.notes" />
                </div>
            </section>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <Button type="submit" :disabled="form.processing">{{ t('Save configuration') }}</Button>
                <Button variant="outline" as-child><a :href="index().url">{{ t('Cancel') }}</a></Button>
            </div>
        </form>

        <p class="text-muted-foreground max-w-3xl text-xs">
            {{ t('This creates no opening balance and posts no journal. If the account already holds money, that money is already in the general ledger.') }}
        </p>
    </div>
</template>
