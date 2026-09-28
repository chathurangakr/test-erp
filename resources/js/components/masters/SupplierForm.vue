<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PartyContactFields from '@/components/masters/PartyContactFields.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Supplier } from '@/types';
import { useT } from '@/lib/i18n';

defineProps<{
    action: Record<string, unknown>;
    supplier?: Supplier | null;
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
                    <Label for="code">{{ t('Supplier code') }} <span class="text-destructive">*</span></Label>
                    <Input
                        id="code"
                        name="code"
                        :default-value="supplier?.code ?? ''"
                        required
                        autocomplete="off"
                        :placeholder="t('e.g. SUP-001')"
                        class="uppercase"
                    />
                    <p class="text-muted-foreground text-xs">{{ t('Stored in uppercase. Must be unique.') }}</p>
                    <InputError :message="errors.code" />
                </div>
                <div class="grid gap-2">
                    <Label for="name">{{ t('Supplier name') }} <span class="text-destructive">*</span></Label>
                    <Input
                        id="name"
                        name="name"
                        :default-value="supplier?.name ?? ''"
                        required
                        autocomplete="off"
                    />
                    <InputError :message="errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="payment_terms_days">{{ t('Payment terms (days)') }}</Label>
                    <Input
                        id="payment_terms_days"
                        name="payment_terms_days"
                        type="number"
                        min="0"
                        step="1"
                        :default-value="supplier?.payment_terms_days ?? 0"
                        required
                    />
                    <p class="text-muted-foreground text-xs">{{ t('0 means payment on delivery.') }}</p>
                    <InputError :message="errors.payment_terms_days" />
                </div>
            </div>
        </section>

        <PartyContactFields :party="supplier" :errors="errors" />

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <Button type="submit" :disabled="processing">{{ t(submitLabel) }}</Button>
            <Button variant="outline" as-child><a :href="cancelHref">{{ t('Cancel') }}</a></Button>
        </div>
    </Form>
</template>
