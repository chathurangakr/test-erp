<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SearchableSelect from '@/components/SearchableSelect.vue';
import type { Product, UnitOfMeasure } from '@/types';
import { useT } from '@/lib/i18n';

const props = defineProps<{
    action: Record<string, unknown>;
    unitsOfMeasure: UnitOfMeasure[];
    product?: Product | null;
    submitLabel: string;
    cancelHref: string;
}>();

const primaryUomId = ref<string>(
    props.product?.primary_uom_id ? String(props.product.primary_uom_id) : '',
);
const secondaryUomId = ref<string>(
    props.product?.secondary_uom_id ? String(props.product.secondary_uom_id) : '',
);

/** Mirrors the server-side `different` rule so the user sees the problem immediately. */
const sameUomSelected = computed(
    () => primaryUomId.value !== '' && primaryUomId.value === secondaryUomId.value,
);


const { t } = useT();
</script>

<template>
    <Form v-bind="action" class="space-y-6" v-slot="{ errors, processing }">
        <div class="grid gap-6 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="code">
                    {{ t('Product code') }} <span class="text-destructive">*</span>
                </Label>
                <Input
                    id="code"
                    name="code"
                    :default-value="product?.code ?? ''"
                    required
                    autocomplete="off"
                    :placeholder="t('e.g. COCONUT')"
                    class="uppercase"
                />
                <p class="text-muted-foreground text-xs">
                    {{ t('Stored in uppercase automatically. Must be unique.') }}
                </p>
                <InputError :message="errors.code" />
            </div>

            <div class="grid gap-2">
                <Label for="name">
                    {{ t('Product name') }} <span class="text-destructive">*</span>
                </Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="product?.name ?? ''"
                    required
                    autocomplete="off"
                    :placeholder="t('e.g. Whole Coconut')"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="primary_uom_id">
                    {{ t('Primary unit of measure') }}
                    <span class="text-destructive">*</span>
                </Label>
                <SearchableSelect
                    id="primary_uom_id"
                    name="primary_uom_id"
                    v-model="primaryUomId"
                    required
                    :options="[...(unitsOfMeasure).map((uom) => ({ value: String(uom.id), label: `${uom.code} — ${uom.name}` }))]"
                    :placeholder="t('Select a unit…')"
                />
                <InputError :message="errors.primary_uom_id" />
            </div>

            <div class="grid gap-2">
                <Label for="secondary_uom_id">{{ t('Secondary unit of measure') }}</Label>
                <SearchableSelect
                    id="secondary_uom_id"
                    name="secondary_uom_id"
                    v-model="secondaryUomId"
                    :options="[{ value: '', label: t('None') }, ...(unitsOfMeasure).map((uom) => ({ value: String(uom.id), label: `${uom.code} — ${uom.name}` }))]"
                />
                <p class="text-muted-foreground text-xs">
                    {{ t('Optional. For products sold in two units, such as Raw Coconut Shell.') }}
                </p>
                <p v-if="sameUomSelected" class="text-destructive text-sm">
                    {{ t('The secondary unit must be different from the primary unit.') }}
                </p>
                <InputError :message="errors.secondary_uom_id" />
            </div>
        </div>

        <div class="grid gap-2">
            <Label for="description">{{ t('Description') }}</Label>
            <textarea
                id="description"
                name="description"
                rows="3"
                :value="product?.description ?? ''"
                :placeholder="t('Optional notes about this product')"
                class="border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex w-full rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
            />
            <InputError :message="errors.description" />
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <Button type="submit" :disabled="processing || sameUomSelected">
                {{ t(submitLabel) }}
            </Button>
            <Button variant="outline" as-child>
                <a :href="cancelHref">{{ t('Cancel') }}</a>
            </Button>
        </div>
    </Form>
</template>
