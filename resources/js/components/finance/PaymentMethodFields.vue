<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import type { PaymentMethodOption } from '@/types';

/**
 * Cash, cheque or bank transfer — and a cheque's number, date and bank.
 *
 * "Credit" is not offered: buying or selling on credit is an invoice left outstanding, settled
 * later by a payment or receipt like this one.
 */
defineProps<{
    methods: PaymentMethodOption[];
    errors: Partial<Record<string, string>>;
}>();

const method = defineModel<string>('method', { required: true });
const chequeNumber = defineModel<string>('chequeNumber', { required: true });
const chequeDate = defineModel<string>('chequeDate', { required: true });
const chequeBank = defineModel<string>('chequeBank', { required: true });

const { t } = useT();
</script>

<template>
    <div class="space-y-4">
        <div class="grid gap-2">
            <Label>{{ t('Paid by') }} <span class="text-destructive">*</span></Label>
            <div class="flex flex-wrap gap-2">
                <label
                    v-for="m in methods"
                    :key="m.value"
                    class="border-input flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm"
                    :class="method === m.value ? 'border-primary bg-primary/5 font-medium' : ''"
                >
                    <input v-model="method" type="radio" name="payment_method" :value="m.value" class="size-4" />
                    {{ m.label }}
                </label>
            </div>
            <InputError :message="errors.payment_method" />
        </div>

        <div v-if="method === 'CHEQUE'" class="grid gap-4 sm:grid-cols-3">
            <div class="grid gap-2">
                <Label for="cheque_number">{{ t('Cheque number') }} <span class="text-destructive">*</span></Label>
                <Input id="cheque_number" v-model="chequeNumber" maxlength="50" required />
                <InputError :message="errors.cheque_number" />
            </div>
            <div class="grid gap-2">
                <Label for="cheque_date">{{ t('Cheque date') }} <span class="text-destructive">*</span></Label>
                <Input id="cheque_date" v-model="chequeDate" type="date" required />
                <InputError :message="errors.cheque_date" />
            </div>
            <div class="grid gap-2">
                <Label for="cheque_bank">{{ t('Cheque bank') }} <span class="text-destructive">*</span></Label>
                <Input id="cheque_bank" v-model="chequeBank" maxlength="150" required />
                <InputError :message="errors.cheque_bank" />
            </div>
        </div>
    </div>
</template>
