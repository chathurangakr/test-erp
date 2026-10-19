<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { CompanyLetterhead } from '@/types';

/**
 * The company's logo, name and contact details at the top of a printout.
 */
const page = usePage();
const company = computed(() => page.props.company as CompanyLetterhead | null);

const contact = computed(() =>
    [company.value?.phone, company.value?.email, company.value?.registration_number].filter(Boolean).join(' · '),
);
</script>

<template>
    <div v-if="company" class="flex items-center gap-4 border-b border-neutral-300 pb-3">
        <img v-if="company.logo_url" :src="company.logo_url" alt="" class="h-20 w-auto object-contain" />
        <div class="min-w-0">
            <p class="text-xl font-semibold tracking-wide">{{ company.name }}</p>
            <p v-if="company.address" class="text-sm whitespace-pre-line">{{ company.address }}</p>
            <p v-if="contact" class="text-sm">{{ contact }}</p>
        </div>
    </div>
</template>
