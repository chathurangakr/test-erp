<script setup lang="ts">
/**
 * A page that is a printout (an invoice): no menu, a white A4 sheet on screen.
 */
</script>

<template>
    <div class="min-h-screen bg-neutral-200 py-6 text-neutral-900 print:bg-white print:py-0">
        <slot />
    </div>
</template>
