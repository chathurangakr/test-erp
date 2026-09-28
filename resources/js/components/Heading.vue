<script setup lang="ts">
import { useT } from '@/lib/i18n';

type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
});

// Pages pass English; the heading shows it in the user's language.
const { t } = useT();
</script>

<template>
    <header :class="variant === 'small' ? '' : 'mb-8 space-y-0.5'">
        <h2
            :class="
                variant === 'small'
                    ? 'mb-0.5 text-base font-medium'
                    : 'text-xl font-semibold tracking-tight'
            "
        >
            {{ t(title) }}
        </h2>
        <p v-if="description" class="text-muted-foreground text-sm">
            {{ t(description) }}
        </p>
    </header>
</template>
