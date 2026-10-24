<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';

/** The loan details that may be corrected later: who lent it and the reminder schedule. */
defineProps<{ errors: Record<string, string | undefined>; currency: string }>();

const lender = defineModel<string>('lender', { required: true });
const purpose = defineModel<string>('purpose', { required: true });
const hasInterest = defineModel<boolean>('hasInterest', { required: true });
const installment = defineModel<string>('installment', { required: true });
const dueDay = defineModel<string>('dueDay', { required: true });
const notes = defineModel<string>('notes', { required: true });

const { t } = useT();
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="grid gap-2">
            <Label for="lender">{{ t('Lender') }}</Label>
            <Input id="lender" v-model="lender" required maxlength="150" :placeholder="t('e.g. People\'s Bank, or the machine seller')" />
            <InputError :message="errors.lender" />
        </div>
        <div class="grid gap-2">
            <Label for="purpose">{{ t('What it is for') }}</Label>
            <Input id="purpose" v-model="purpose" maxlength="255" :placeholder="t('e.g. Dehusking machine')" />
            <InputError :message="errors.purpose" />
        </div>
        <div class="grid gap-2">
            <Label for="installment_amount">{{ t('Usual installment (:currency)', { currency }) }}</Label>
            <Input id="installment_amount" v-model="installment" inputmode="decimal" />
            <InputError :message="errors.installment_amount" />
        </div>
        <div class="grid gap-2">
            <Label for="due_day">{{ t('Due day of the month') }}</Label>
            <Input id="due_day" v-model="dueDay" type="number" min="1" max="31" :placeholder="t('e.g. 15')" />
            <p class="text-muted-foreground text-xs">{{ t('Reminders go out 7 days before, 2 days before and on this day.') }}</p>
            <InputError :message="errors.due_day" />
        </div>
        <label class="flex items-center gap-3 text-sm sm:col-span-2">
            <input v-model="hasInterest" type="checkbox" class="border-input size-4 rounded" />
            <span>{{ t('This loan carries interest') }} <span class="text-muted-foreground">— {{ t('untick for machinery paid in installments with no interest') }}</span></span>
        </label>
        <div class="grid gap-2 sm:col-span-2">
            <Label for="notes">{{ t('Notes') }}</Label>
            <Input id="notes" v-model="notes" maxlength="2000" />
            <InputError :message="errors.notes" />
        </div>
    </div>
</template>
