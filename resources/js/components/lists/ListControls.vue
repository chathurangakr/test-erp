<script setup lang="ts">
import { FilterX } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useT } from '@/lib/i18n';

/**
 * The bar above every list: the screen's own filters (slot), a From / To period on the list's date
 * when it has one, how many rows to show, and a button that clears everything.
 */
withDefaults(defineProps<{ period?: boolean; periodLabel?: string }>(), { period: true, periodLabel: 'Date' });

const perPage = defineModel<number | 'all'>('perPage', { required: true });
const from = defineModel<string | null>('from', { default: null });
const to = defineModel<string | null>('to', { default: null });

const emit = defineEmits<{ clear: [] }>();

const { t } = useT();
</script>

<template>
    <div class="flex flex-wrap items-end gap-3">
        <slot />
        <template v-if="period">
            <label class="grid gap-1 text-xs">
                <span class="text-muted-foreground">{{ t(':label from', { label: t(periodLabel) }) }}</span>
                <Input :model-value="from ?? ''" type="date" class="w-40" @update:model-value="from = ($event as string) || null" />
            </label>
            <label class="grid gap-1 text-xs">
                <span class="text-muted-foreground">{{ t('To') }}</span>
                <Input :model-value="to ?? ''" type="date" class="w-40" @update:model-value="to = ($event as string) || null" />
            </label>
        </template>
        <label class="grid gap-1 text-xs">
            <span class="text-muted-foreground">{{ t('Rows') }}</span>
            <select
                :value="String(perPage)"
                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                @change="perPage = ($event.target as HTMLSelectElement).value === 'all' ? 'all' : Number(($event.target as HTMLSelectElement).value)"
            >
                <option v-for="size in [25, 50, 100]" :key="size" :value="String(size)">{{ size }}</option>
                <option value="all">{{ t('All') }}</option>
            </select>
        </label>
        <Button type="button" variant="ghost" size="sm" class="h-9" @click="emit('clear')"><FilterX class="size-4" />{{ t('Clear filters') }}</Button>
    </div>
</template>
