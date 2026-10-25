<script setup lang="ts">
import { ArrowDown, ArrowUp, ArrowUpDown } from '@lucide/vue';
import { computed } from 'vue';
import { useT } from '@/lib/i18n';

/** A column heading that sorts the list; click again to turn the order round. Pages pass English. */
const props = withDefaults(defineProps<{ label: string; column: string; sort: string; direction: 'asc' | 'desc'; align?: 'left' | 'right' }>(), { align: 'left' });

const emit = defineEmits<{ sort: [column: string] }>();

const { t } = useT();
const active = computed(() => props.sort === props.column);
</script>

<template>
    <th class="px-4 py-3 font-medium" :class="align === 'right' ? 'text-right' : ''" :aria-sort="active ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'">
        <button type="button" class="hover:text-foreground inline-flex items-center gap-1" :class="active ? 'text-foreground' : ''" :title="t('Sort by :column', { column: t(label) })" @click="emit('sort', column)">
            <slot>{{ t(label) }}</slot>
            <ArrowUp v-if="active && direction === 'asc'" class="size-3.5" />
            <ArrowDown v-else-if="active" class="size-3.5" />
            <ArrowUpDown v-else class="size-3.5 opacity-40" />
        </button>
    </th>
</template>
