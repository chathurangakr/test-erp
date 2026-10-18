<script setup lang="ts">
import { Check, ChevronsUpDown } from '@lucide/vue';
import {
    ComboboxAnchor,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxPortal,
    ComboboxRoot,
    ComboboxTrigger,
    ComboboxViewport,
} from 'reka-ui';
import type { HTMLAttributes } from 'vue';
import { computed, ref, watch } from 'vue';
import { cn } from '@/lib/utils';
import { useT } from '@/lib/i18n';

/**
 * A dropdown you can type into: the list narrows to the options whose label contains what was
 * typed (anywhere, any case), so a long list of suppliers, products or accounts is found by a
 * few letters. Used in place of a plain <select> everywhere.
 *
 * Works with v-model, or — for a plain HTML / Inertia <Form> — with `name` and `defaultValue`,
 * submitting the chosen value through a hidden input exactly as a <select name> would.
 */
type Value = string | number | null;
export type SearchableOption = { value: Value; label: string; disabled?: boolean };

const props = withDefaults(
    defineProps<{
        modelValue?: Value;
        defaultValue?: Value;
        options: SearchableOption[];
        placeholder?: string;
        disabled?: boolean;
        required?: boolean;
        id?: string;
        name?: string;
        ariaLabel?: string;
        class?: HTMLAttributes['class'];
    }>(),
    {
        modelValue: undefined,
        defaultValue: null,
        placeholder: '',
        disabled: false,
        required: false,
        id: undefined,
        name: undefined,
        ariaLabel: undefined,
        class: undefined,
    },
);

const emit = defineEmits<{ 'update:modelValue': [value: Value]; change: [value: Value] }>();

const { t } = useT();

const selected = ref<Value>(props.modelValue !== undefined ? props.modelValue : props.defaultValue);
watch(
    () => props.modelValue,
    (value) => {
        if (value !== undefined) {
            selected.value = value;
        }
    },
);

const same = (a: Value, b: Value): boolean => String(a ?? '') === String(b ?? '');

const keyOf = (index: number): string => `o${index}`;
const selectedKey = computed<string | undefined>(() => {
    const index = props.options.findIndex((option) => same(option.value, selected.value));

    return index === -1 ? undefined : keyOf(index);
});

const search = ref('');
const filtered = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.options
        .map((option, index) => ({ option, key: keyOf(index) }))
        .filter(({ option }) => term === '' || option.label.toLowerCase().includes(term));
});

function labelFor(key: unknown): string {
    if (typeof key !== 'string') return '';
    const option = props.options[Number(key.slice(1))];

    return option?.label ?? '';
}

function choose(key: unknown): void {
    if (typeof key !== 'string') return;
    const option = props.options[Number(key.slice(1))];

    if (!option) return;
    selected.value = option.value;
    emit('update:modelValue', option.value);
    emit('change', option.value);
}
</script>

<template>
    <ComboboxRoot
        :model-value="selectedKey"
        :disabled="disabled"
        ignore-filter
        open-on-click
        @update:model-value="choose"
        @update:open="(open: boolean) => { if (!open) search = '' }"
    >
        <ComboboxAnchor
            :class="
                cn(
                    'border-input bg-background focus-within:ring-ring dark:bg-input/30 relative flex h-9 w-full min-w-0 items-center rounded-md border text-sm shadow-xs focus-within:ring-2 focus-within:ring-offset-2',
                    disabled && 'cursor-not-allowed opacity-50',
                    props.class,
                )
            "
        >
            <ComboboxInput
                :id="id"
                v-model="search"
                :display-value="labelFor"
                :placeholder="placeholder"
                :aria-label="ariaLabel"
                :required="required && selectedKey === undefined"
                autocomplete="off"
                @focus="($event.target as HTMLInputElement).select()"
                class="placeholder:text-muted-foreground h-full w-full min-w-0 truncate bg-transparent py-1 pr-8 pl-3 outline-none disabled:cursor-not-allowed"
            />
            <ComboboxTrigger class="text-muted-foreground absolute inset-y-0 right-0 flex items-center px-2" tabindex="-1">
                <ChevronsUpDown class="size-4" />
            </ComboboxTrigger>
        </ComboboxAnchor>

        <ComboboxPortal>
            <ComboboxContent
                position="popper"
                :side-offset="4"
                class="bg-popover text-popover-foreground z-50 max-h-72 w-(--reka-combobox-trigger-width) min-w-[12rem] overflow-hidden rounded-md border shadow-md"
            >
                <ComboboxViewport class="max-h-72 overflow-y-auto p-1">
                    <ComboboxEmpty class="text-muted-foreground px-2 py-1.5 text-sm">{{ t('No matches') }}</ComboboxEmpty>
                    <ComboboxItem
                        v-for="{ option, key } in filtered"
                        :key="key"
                        :value="key"
                        :text-value="option.label"
                        :disabled="option.disabled"
                        class="data-[highlighted]:bg-accent data-[highlighted]:text-accent-foreground relative flex w-full cursor-default items-center rounded-sm py-1.5 pr-8 pl-2 text-sm outline-hidden select-none data-[disabled]:pointer-events-none data-[disabled]:opacity-50"
                    >
                        <span class="truncate">{{ option.label }}</span>
                        <Check v-if="key === selectedKey" class="absolute right-2 size-4" />
                    </ComboboxItem>
                </ComboboxViewport>
            </ComboboxContent>
        </ComboboxPortal>
        <input v-if="name" type="hidden" :name="name" :value="selected ?? ''" />
    </ComboboxRoot>
</template>
