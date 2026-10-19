<script setup lang="ts">
import { Printer } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';

const { t } = useT();

function print(): void {
    window.print();
}
</script>

<template>
    <Button type="button" variant="outline" class="print:hidden" @click="print">
        <Printer class="size-4" />
        {{ t('Print') }}
    </Button>
</template>
