<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const page = usePage();
const name = computed(() => page.props.name);
// The company's own logo (Administration → Company details) replaces the default mark.
const logoUrl = computed(() => page.props.company?.logo_url ?? null);
</script>

<template>
    <div class="flex aspect-square size-8 items-center justify-center">
        <img
            v-if="logoUrl"
            :src="logoUrl"
            alt=""
            class="size-8 rounded-sm object-contain"
        />
        <AppLogoIcon v-else class="size-8" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-tight font-semibold">{{
            name
        }}</span>
    </div>
</template>
