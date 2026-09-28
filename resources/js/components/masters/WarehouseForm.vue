<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Warehouse } from '@/types';
import { useT } from '@/lib/i18n';

defineProps<{
    action: Record<string, unknown>;
    warehouse?: Warehouse | null;
    submitLabel: string;
    cancelHref: string;
}>();

const textareaClass =
    'border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex w-full rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';

const { t } = useT();
</script>

<template>
    <Form v-bind="action" class="space-y-6" v-slot="{ errors, processing }">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="code">{{ t('Warehouse code') }} <span class="text-destructive">*</span></Label>
                <Input
                    id="code"
                    name="code"
                    :default-value="warehouse?.code ?? ''"
                    required
                    autocomplete="off"
                    :placeholder="t('e.g. MAIN')"
                    class="uppercase"
                />
                <p class="text-muted-foreground text-xs">{{ t('Stored in uppercase. Must be unique.') }}</p>
                <InputError :message="errors.code" />
            </div>
            <div class="grid gap-2">
                <Label for="name">{{ t('Warehouse name') }} <span class="text-destructive">*</span></Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="warehouse?.name ?? ''"
                    required
                    autocomplete="off"
                />
                <InputError :message="errors.name" />
            </div>
        </div>

        <div class="grid gap-2">
            <Label for="description">{{ t('Description') }}</Label>
            <textarea
                id="description"
                name="description"
                rows="2"
                :value="warehouse?.description ?? ''"
                :class="textareaClass"
            />
            <InputError :message="errors.description" />
        </div>

        <div class="grid gap-2">
            <Label for="address">{{ t('Address') }}</Label>
            <textarea
                id="address"
                name="address"
                rows="3"
                :value="warehouse?.address ?? ''"
                :class="textareaClass"
            />
            <InputError :message="errors.address" />
        </div>

        <div
            v-if="!warehouse?.is_default"
            class="border-sidebar-border/70 dark:border-sidebar-border flex items-start gap-3 rounded-lg border p-4"
        >
            <Checkbox id="is_default" name="is_default" value="1" />
            <div class="grid gap-1">
                <Label for="is_default" class="font-medium">{{ t('Make this the default warehouse') }}</Label>
                <p class="text-muted-foreground text-xs">
                    {{ t('Only one active warehouse can be the default. Choosing this will clear the current default.') }}
                </p>
                <InputError :message="errors.is_default" />
            </div>
        </div>
        <p v-else class="text-muted-foreground text-xs">
            {{ t('This is the default warehouse. To change it, set another warehouse as default.') }}
        </p>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <Button type="submit" :disabled="processing">{{ t(submitLabel) }}</Button>
            <Button variant="outline" as-child><a :href="cancelHref">{{ t('Cancel') }}</a></Button>
        </div>
    </Form>
</template>
