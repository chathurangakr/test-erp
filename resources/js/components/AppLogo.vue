<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BrandLogo from '@/components/BrandLogo.vue';

const page = usePage();
const name = computed(() => page.props.name);
</script>

<template>
    <div class="flex aspect-square size-8 items-center justify-center">
        <BrandLogo class="size-8" icon-class="size-8" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-tight font-semibold">{{
            name
        }}</span>
    </div>
</template>
