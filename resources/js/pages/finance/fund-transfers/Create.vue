<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/fund-transfers';
import type { CashBankAccountOption } from '@/types';
import { businessToday } from '@/lib/businessDate';

const props = defineProps<{
    accounts: CashBankAccountOption[];
    currency: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Fund transfers', href: index() },
            { title: 'New', href: index() },
        ],
    },
});

const today = businessToday();

const form = useForm({
    transfer_date: today,
    from_cash_bank_account_id: '' as string | number,
    to_cash_bank_account_id: '' as string | number,
    amount: '',
    reference: '',
    description: '',
});

const sameAccount = computed(
    () =>
        form.from_cash_bank_account_id !== '' &&
        String(form.from_cash_bank_account_id) === String(form.to_cash_bank_account_id),
);

function submit(): void {
    form.post(index().url, { preserveScroll: true });
}

const selectClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
</script>

<template>
    <Head title="New fund transfer" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="New fund transfer"
            description="Saved as a draft. Nothing moves until you post it."
        />

        <form class="max-w-3xl space-y-8" @submit.prevent="submit">
            <section class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="transfer_date">Date <span class="text-destructive">*</span></Label>
                    <Input id="transfer_date" v-model="form.transfer_date" type="date" required />
                    <InputError :message="form.errors.transfer_date" />
                </div>

                <div class="grid gap-2">
                    <Label for="amount">Amount ({{ currency }}) <span class="text-destructive">*</span></Label>
                    <Input
                        id="amount"
                        v-model="form.amount"
                        type="number"
                        step="0.000001"
                        min="0"
                        required
                        class="text-right"
                    />
                    <InputError :message="form.errors.amount" />
                </div>

                <div class="grid gap-2">
                    <Label for="from">From <span class="text-destructive">*</span></Label>
                    <select id="from" v-model="form.from_cash_bank_account_id" required :class="selectClass">
                        <option value="" disabled>Select…</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">
                            {{ a.code }} — {{ a.name }} ({{ a.kind }})
                        </option>
                    </select>
                    <InputError :message="form.errors.from_cash_bank_account_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="to">To <span class="text-destructive">*</span></Label>
                    <select id="to" v-model="form.to_cash_bank_account_id" required :class="selectClass">
                        <option value="" disabled>Select…</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">
                            {{ a.code }} — {{ a.name }} ({{ a.kind }})
                        </option>
                    </select>
                    <InputError :message="form.errors.to_cash_bank_account_id" />
                    <p v-if="sameAccount" class="text-destructive text-xs">
                        A transfer must be between two different accounts.
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="reference">Reference</Label>
                    <Input id="reference" v-model="form.reference" maxlength="100" />
                    <InputError :message="form.errors.reference" />
                </div>

                <div class="grid gap-2">
                    <Label for="description">Description</Label>
                    <Input id="description" v-model="form.description" maxlength="500" />
                    <InputError :message="form.errors.description" />
                </div>
            </section>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <Button type="submit" :disabled="form.processing || sameAccount">Save draft</Button>
                <Button variant="outline" as-child><a :href="index().url">Cancel</a></Button>
            </div>
        </form>

        <p class="text-muted-foreground max-w-3xl text-xs">
            Posting debits the destination and credits the source with the same figure, so the
            two sides cannot disagree. No expense, no revenue, no payable, no receivable and no
            inventory — the company is neither richer nor poorer afterwards. There is no edit
            screen: cancel an incorrect draft and make a new one, or reverse it if it has posted.
        </p>
    </div>
</template>
