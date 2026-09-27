<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Boxes, Factory, Grid2x2, PackagePlus, Sun, Truck } from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import type { KioskTextKey } from '@/kiosk/text';
import { useKioskText } from '@/kiosk/text';
import { copra, dehusking, dispatch, huskChipping, receive, stock } from '@/routes/kiosk';

/**
 * The simple-mode home: one big tile per job the user's roles allow. The server decides the
 * list; every job route checks the permission again when used.
 */
const props = defineProps<{ tasks: string[] }>();

const page = usePage();
const { t } = useKioskText();

const tiles: Record<string, { icon: Component; href: ReturnType<typeof receive>; colour: string }> = {
    receive: { icon: PackagePlus, href: receive(), colour: 'bg-emerald-600' },
    dispatch: { icon: Truck, href: dispatch(), colour: 'bg-sky-600' },
    dehusking: { icon: Factory, href: dehusking(), colour: 'bg-amber-600' },
    'husk-chipping': { icon: Grid2x2, href: huskChipping(), colour: 'bg-orange-700' },
    copra: { icon: Sun, href: copra(), colour: 'bg-yellow-600' },
    stock: { icon: Boxes, href: stock(), colour: 'bg-slate-700' },
};

const visible = computed(() => props.tasks.filter((task) => task in tiles));
</script>

<template>
    <div class="flex flex-col gap-6">
        <div>
            <p class="text-muted-foreground text-xl">{{ t('home.hello') }}, {{ page.props.auth.user.name }}</p>
            <h1 class="text-3xl font-bold">{{ t('home.choose') }}</h1>
        </div>

        <p v-if="visible.length === 0" class="bg-background rounded-2xl p-8 text-center text-xl">{{ t('home.none') }}</p>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Link
                v-for="task in visible"
                :key="task"
                :href="tiles[task].href"
                class="flex min-h-36 items-center gap-5 rounded-3xl p-6 text-white shadow-sm transition active:scale-[0.98]"
                :class="tiles[task].colour"
            >
                <component :is="tiles[task].icon" class="size-16 shrink-0" />
                <span>
                    <span class="block text-3xl font-bold">{{ t(`task.${task}` as KioskTextKey) }}</span>
                    <span class="block text-lg opacity-90">{{ t(`task.${task}.hint` as KioskTextKey) }}</span>
                </span>
            </Link>
        </div>
    </div>
</template>
