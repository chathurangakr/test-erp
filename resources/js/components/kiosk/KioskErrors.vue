<script setup lang="ts">
import { AlertTriangle } from '@lucide/vue';
import { computed } from 'vue';
import { useKioskText } from '@/kiosk/text';

/**
 * The server's refusal, in plain words. Messages arrive already translated where the
 * server has a translation (lang/si.json); the server always decides, never the screen.
 */
const props = defineProps<{ errors: Record<string, string | undefined> }>();

const { t } = useKioskText();
const messages = computed(() => [...new Set(Object.values(props.errors).filter((m): m is string => !!m))]);
</script>

<template>
    <div v-if="messages.length > 0" class="flex gap-3 rounded-2xl border-2 border-red-600 bg-red-50 p-4 text-red-900 dark:bg-red-950 dark:text-red-100" role="alert">
        <AlertTriangle class="size-7 shrink-0" />
        <div>
            <p class="font-semibold">{{ t('common.error') }}</p>
            <ul class="mt-1 list-disc pl-5">
                <li v-for="message in messages" :key="message">{{ message }}</li>
            </ul>
        </div>
    </div>
</template>
