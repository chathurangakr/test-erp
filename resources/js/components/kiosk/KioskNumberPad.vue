<script setup lang="ts">
import { Delete } from '@lucide/vue';
import { plainNumber, useKioskText } from '@/kiosk/text';

/**
 * A large on-screen keypad for counts, weights and prices.
 *
 * The value stays a plain string of digits (e.g. "208.75") and is sent to the server exactly
 * as typed — never through a floating-point number.
 */
const props = withDefaults(defineProps<{ modelValue: string; label: string; decimals?: number }>(), { decimals: 3 });
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const { t } = useKioskText();

function press(key: string): void {
    let value = props.modelValue;

    if (key === '.') {
        if (props.decimals === 0 || value.includes('.')) {
            return;
        }

        value = value === '' ? '0.' : `${value}.`;
    } else {
        const [, fraction] = value.split('.');

        if (fraction !== undefined && fraction.length >= props.decimals) {
            return;
        }

        value = value === '0' ? key : `${value}${key}`;
    }

    if (value.replace('.', '').length > 12) {
        return;
    }

    emit('update:modelValue', value);
}

function backspace(): void {
    emit('update:modelValue', props.modelValue.slice(0, -1));
}

function clear(): void {
    emit('update:modelValue', '');
}

const keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9'];
</script>

<template>
    <div class="flex flex-col gap-3">
        <p class="text-lg font-medium">{{ label }}</p>

        <div class="bg-background border-sidebar-border/70 flex min-h-20 items-center justify-end rounded-2xl border-2 px-5 font-mono text-4xl font-semibold tabular-nums">
            {{ modelValue === '' ? '0' : modelValue.endsWith('.') ? modelValue : plainNumber(modelValue) }}
        </div>

        <div class="grid grid-cols-3 gap-2">
            <button
                v-for="key in keys"
                :key="key"
                type="button"
                class="bg-background border-sidebar-border/70 min-h-16 rounded-xl border text-3xl font-semibold active:scale-95"
                @click="press(key)"
            >
                {{ key }}
            </button>
            <button
                type="button"
                class="bg-background border-sidebar-border/70 min-h-16 rounded-xl border text-3xl font-semibold active:scale-95 disabled:opacity-30"
                :disabled="decimals === 0"
                @click="press('.')"
            >
                .
            </button>
            <button type="button" class="bg-background border-sidebar-border/70 min-h-16 rounded-xl border text-3xl font-semibold active:scale-95" @click="press('0')">
                0
            </button>
            <button
                type="button"
                class="bg-background border-sidebar-border/70 flex min-h-16 items-center justify-center rounded-xl border active:scale-95"
                :aria-label="t('common.clear')"
                @click="backspace"
                @dblclick="clear"
            >
                <Delete class="size-8" />
            </button>
        </div>
    </div>
</template>
