<script setup lang="ts">
import { Check, Sparkles } from '@lucide/vue';
import { BEAUTY_THEMES, useBeautyMode } from '@/composables/useBeautyMode';
import { useT } from '@/lib/i18n';

const { beautyMode, beautyTheme, updateBeautyMode, updateBeautyTheme } =
    useBeautyMode();
const { t } = useT();
</script>

<template>
    <div class="space-y-4 rounded-lg border p-4">
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-start gap-3">
                <Sparkles class="mt-0.5 h-5 w-5 text-amber-500" />
                <div class="space-y-1">
                    <p class="text-sm font-medium">{{ t('Beauty mode') }}</p>
                    <p class="text-sm text-muted-foreground">
                        {{
                            t(
                                'Colourful screens with animations. Only for this device; printouts stay plain.',
                            )
                        }}
                    </p>
                </div>
            </div>
            <button
                type="button"
                role="switch"
                :aria-checked="beautyMode"
                :aria-label="t('Beauty mode')"
                @click="updateBeautyMode(!beautyMode, $event)"
                :class="[
                    'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    beautyMode
                        ? 'bg-gradient-to-r from-emerald-500 via-cyan-500 to-violet-500'
                        : 'bg-neutral-300 dark:bg-neutral-600',
                ]"
            >
                <span
                    :class="[
                        'inline-block h-5 w-5 rounded-full bg-white shadow transition-transform',
                        beautyMode ? 'translate-x-5' : 'translate-x-0.5',
                    ]"
                />
            </button>
        </div>

        <div>
            <p class="mb-2 text-sm font-medium">{{ t('Style') }}</p>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <button
                    v-for="option in BEAUTY_THEMES"
                    :key="option.value"
                    type="button"
                    :aria-pressed="beautyMode && beautyTheme === option.value"
                    @click="updateBeautyTheme(option.value, $event)"
                    :class="[
                        'flex items-center gap-3 rounded-lg border p-3 text-left transition hover:shadow-md',
                        beautyMode && beautyTheme === option.value
                            ? 'border-transparent ring-2 ring-ring'
                            : '',
                    ]"
                >
                    <span
                        class="flex h-8 shrink-0 overflow-hidden rounded-md shadow-sm"
                    >
                        <span
                            v-for="colour in option.swatches"
                            :key="colour"
                            class="h-full w-4"
                            :style="{ backgroundColor: colour }"
                        />
                    </span>
                    <span class="flex-1 text-sm font-medium">{{
                        t(option.label)
                    }}</span>
                    <Check
                        v-if="beautyMode && beautyTheme === option.value"
                        class="h-4 w-4 text-primary"
                    />
                </button>
            </div>
        </div>
    </div>
</template>
