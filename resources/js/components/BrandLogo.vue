<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

/**
 * The company's own logo (Administration → Company details) when one is uploaded, otherwise the
 * default coconut mark. `class` sizes the logo; `iconClass` sizes and colours the mark.
 */
defineOptions({ inheritAttrs: false });

defineProps<{ iconClass?: string }>();

const page = usePage();
const logoUrl = computed(() => page.props.brandLogoUrl ?? null);
</script>

<template>
    <img v-if="logoUrl" v-bind="$attrs" :src="logoUrl" alt="" class="rounded-sm object-contain" />
    <AppLogoIcon v-else :class="iconClass" />
</template>
