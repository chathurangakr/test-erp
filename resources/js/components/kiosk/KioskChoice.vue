<script setup lang="ts">
import { Check, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useKioskText } from '@/kiosk/text';

export type KioskChoiceItem = { id: number | string; title: string; subtitle?: string | null; badge?: string | null };

/**
 * A list of big buttons — one tap chooses. A search box appears when there are many.
 */
const props = defineProps<{ items: KioskChoiceItem[]; modelValue: number | string | null }>();
const emit = defineEmits<{ 'update:modelValue': [value: number | string]; chosen: [value: number | string] }>();

const { t } = useKioskText();
const search = ref('');

const visible = computed(() => {
    const term = search.value.trim().toLowerCase();

    return term === ''
        ? props.items
        : props.items.filter((item) => `${item.title} ${item.subtitle ?? ''}`.toLowerCase().includes(term));
});

function choose(item: KioskChoiceItem): void {
    emit('update:modelValue', item.id);
    emit('chosen', item.id);
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <label v-if="items.length > 8" class="bg-background border-sidebar-border/70 flex min-h-14 items-center gap-3 rounded-xl border px-4">
            <Search class="text-muted-foreground size-6 shrink-0" />
            <input v-model="search" type="search" :placeholder="t('common.search')" class="w-full bg-transparent text-lg outline-none" />
        </label>

        <p v-if="visible.length === 0" class="text-muted-foreground py-8 text-center">{{ t('common.none') }}</p>

        <div class="grid gap-3 sm:grid-cols-2">
            <button
                v-for="item in visible"
                :key="item.id"
                type="button"
                class="bg-background flex min-h-20 items-center gap-3 rounded-2xl border-2 px-5 py-3 text-left transition active:scale-[0.98]"
                :class="modelValue === item.id ? 'border-green-600 ring-2 ring-green-600/30' : 'border-sidebar-border/70'"
                @click="choose(item)"
            >
                <span class="flex-1">
                    <span class="block text-xl font-semibold">{{ item.title }}</span>
                    <span v-if="item.subtitle" class="text-muted-foreground block text-base">{{ item.subtitle }}</span>
                </span>
                <span v-if="item.badge" class="bg-muted rounded-lg px-3 py-1 text-base font-medium">{{ item.badge }}</span>
                <Check v-if="modelValue === item.id" class="size-7 shrink-0 text-green-600" />
            </button>
        </div>
    </div>
</template>
