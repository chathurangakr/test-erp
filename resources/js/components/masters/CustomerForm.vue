<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PartyContactFields from '@/components/masters/PartyContactFields.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Customer } from '@/types';
import { useT } from '@/lib/i18n';
import { moneyInput } from '@/lib/money';

defineProps<{
    action: Record<string, unknown>;
    customer?: Customer | null;
    submitLabel: string;
    cancelHref: string;
}>();

const { t } = useT();
</script>

<template>
    <Form v-bind="action" class="space-y-8" v-slot="{ errors, processing }">
        <section class="space-y-4">
            <h3 class="text-sm font-medium">{{ t('Identification') }}</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="code">{{ t('Customer code') }} <span class="text-destructive">*</span></Label>
                    <Input
                        id="code"
                        name="code"
                        :default-value="customer?.code ?? ''"
                        required
                        autocomplete="off"
                        :placeholder="t('e.g. CUS-001')"
                        class="uppercase"
                    />
                    <p class="text-muted-foreground text-xs">{{ t('Stored in uppercase. Must be unique.') }}</p>
                    <InputError :message="errors.code" />
                </div>
                <div class="grid gap-2">
                    <Label for="name">{{ t('Customer name') }} <span class="text-destructive">*</span></Label>
                    <Input
                        id="name"
                        name="name"
                        :default-value="customer?.name ?? ''"
                        required
                        autocomplete="off"
                    />
                    <InputError :message="errors.name" />
                </div>
            </div>
        </section>

        <section class="space-y-4">
            <h3 class="text-sm font-medium">{{ t('Credit terms') }}</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="credit_limit">{{ t('Credit limit') }}</Label>
                    <Input
                        id="credit_limit"
                        name="credit_limit"
                        type="number"
                        min="0"
                        step="0.01"
                        :default-value="moneyInput(customer?.credit_limit) || '0.00'"
                        required
                    />
                    <p class="text-muted-foreground text-xs">{{ t('Agreed limit. Not an outstanding balance.') }}</p>
                    <InputError :message="errors.credit_limit" />
                </div>
                <div class="grid gap-2">
                    <Label for="credit_days">{{ t('Credit days') }}</Label>
                    <Input
                        id="credit_days"
                        name="credit_days"
                        type="number"
                        min="0"
                        step="1"
                        :default-value="customer?.credit_days ?? 0"
                        required
                    />
                    <InputError :message="errors.credit_days" />
                </div>
            </div>
        </section>

        <PartyContactFields :party="customer" :errors="errors" />

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <Button type="submit" :disabled="processing">{{ t(submitLabel) }}</Button>
            <Button variant="outline" as-child><a :href="cancelHref">{{ t('Cancel') }}</a></Button>
        </div>
    </Form>
</template>
