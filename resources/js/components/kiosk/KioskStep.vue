<script setup lang="ts">
import { ArrowLeft, ArrowRight, CheckCircle2, LoaderCircle } from '@lucide/vue';
import { useKioskText } from '@/kiosk/text';

/**
 * One question per screen, with big Back and Next (or Done) buttons at the bottom.
 */
withDefaults(
    defineProps<{
        title: string;
        step: number;
        total: number;
        canNext?: boolean;
        final?: boolean;
        busy?: boolean;
        hint?: string | null;
    }>(),
    { canNext: true, final: false, busy: false, hint: null },
);

defineEmits<{ back: []; next: [] }>();

const { t } = useKioskText();
</script>

<template>
    <section class="flex flex-1 flex-col gap-5">
        <div>
            <p class="text-muted-foreground text-base">{{ t('step.of', { n: step, total }) }}</p>
            <h1 class="text-2xl font-bold sm:text-3xl">{{ title }}</h1>
            <p v-if="hint" class="text-muted-foreground mt-1 text-base">{{ hint }}</p>
        </div>

        <div class="flex-1">
            <slot />
        </div>

        <div class="bg-muted/40 sticky bottom-0 grid grid-cols-2 gap-3 py-3">
            <button
                type="button"
                class="bg-background border-sidebar-border/70 flex min-h-16 items-center justify-center gap-2 rounded-2xl border-2 text-xl font-semibold"
                :disabled="busy"
                @click="$emit('back')"
            >
                <ArrowLeft class="size-6" />
                {{ t('common.back') }}
            </button>
            <button
                type="button"
                class="flex min-h-16 items-center justify-center gap-2 rounded-2xl text-xl font-semibold text-white disabled:opacity-40"
                :class="final ? 'bg-green-600' : 'bg-blue-700'"
                :disabled="!canNext || busy"
                @click="$emit('next')"
            >
                <template v-if="busy">
                    <LoaderCircle class="size-6 animate-spin" />
                    {{ t('common.saving') }}
                </template>
                <template v-else-if="final">
                    <CheckCircle2 class="size-7" />
                    {{ t('common.done') }}
                </template>
                <template v-else>
                    {{ t('common.next') }}
                    <ArrowRight class="size-6" />
                </template>
            </button>
        </div>
    </section>
</template>
