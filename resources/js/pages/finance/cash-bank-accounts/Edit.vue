<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, update } from '@/routes/cash-bank-accounts';

const props = defineProps<{
    cashBankAccount: {
        id: number;
        account_code: string | null;
        account_name: string | null;
        account_kind: string;
        display_name: string;
        bank_name: string | null;
        branch_name: string | null;
        account_number: string | null;
        notes: string | null;
        is_active: boolean;
    };
    kinds: { value: string; label: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Cash / bank accounts', href: index() },
            { title: 'Edit', href: index() },
        ],
    },
});

const form = useForm({
    account_kind: props.cashBankAccount.account_kind,
    display_name: props.cashBankAccount.display_name,
    bank_name: props.cashBankAccount.bank_name ?? '',
    branch_name: props.cashBankAccount.branch_name ?? '',
    account_number: props.cashBankAccount.account_number ?? '',
    notes: props.cashBankAccount.notes ?? '',
    is_active: props.cashBankAccount.is_active,
});

function submit(): void {
    form.put(update(props.cashBankAccount.id).url, { preserveScroll: true });
}

const selectClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
</script>

<template>
    <Head :title="`Edit ${props.cashBankAccount.display_name}`" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            :title="props.cashBankAccount.display_name"
            :description="`${props.cashBankAccount.account_code} — ${props.cashBankAccount.account_name}`"
        />

        <form class="max-w-3xl space-y-8" @submit.prevent="submit">
            <section class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="account_kind">Kind <span class="text-destructive">*</span></Label>
                    <select id="account_kind" v-model="form.account_kind" required :class="selectClass">
                        <option v-for="k in props.kinds" :key="k.value" :value="k.value">{{ k.label }}</option>
                    </select>
                    <InputError :message="form.errors.account_kind" />
                </div>

                <div class="grid gap-2">
                    <Label for="display_name">Display name <span class="text-destructive">*</span></Label>
                    <Input id="display_name" v-model="form.display_name" maxlength="150" required />
                    <InputError :message="form.errors.display_name" />
                </div>

                <template v-if="form.account_kind === 'BANK'">
                    <div class="grid gap-2">
                        <Label for="bank_name">Bank name</Label>
                        <Input id="bank_name" v-model="form.bank_name" maxlength="150" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="branch_name">Branch</Label>
                        <Input id="branch_name" v-model="form.branch_name" maxlength="150" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="account_number">Account number</Label>
                        <Input id="account_number" v-model="form.account_number" maxlength="100" />
                    </div>
                </template>

                <div class="grid gap-2 sm:col-span-2">
                    <Label for="notes">Notes</Label>
                    <Input id="notes" v-model="form.notes" maxlength="500" />
                </div>

                <div class="flex items-center gap-2 sm:col-span-2">
                    <input id="is_active" v-model="form.is_active" type="checkbox" class="size-4" />
                    <Label for="is_active">Active</Label>
                </div>
            </section>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <Button type="submit" :disabled="form.processing">Save changes</Button>
                <Button variant="outline" as-child><a :href="index().url">Cancel</a></Button>
            </div>
        </form>

        <p class="text-muted-foreground max-w-3xl text-xs">
            The ledger account cannot be changed. Repointing this configuration at a different
            account would silently rewrite the meaning of every document already recorded
            against it — deactivate this one and configure the other instead. Deactivating stops
            new payments, receipts, vouchers and transfers from using it; everything already
            posted stays exactly as it is.
        </p>
    </div>
</template>
