<script setup lang="ts">
import BackButton from '@/components/BackButton.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import LanguageSwitch from '@/components/LanguageSwitch.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);
</script>

<template>
    <header
        class="border-sidebar-border/70 flex h-16 shrink-0 items-center gap-2 border-b px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <BackButton :breadcrumbs="breadcrumbs" />
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>
        <div class="ml-auto">
            <LanguageSwitch />
        </div>
    </header>
</template>
