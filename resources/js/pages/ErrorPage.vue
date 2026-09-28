<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ShieldAlert } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { useT } from '@/lib/i18n';

const props = defineProps<{ status: number }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Access denied', href: dashboard() }] } });

const title = computed(() => (props.status === 403 ? 'You do not have access to this' : 'Something went wrong'));

const { t } = useT();
</script>

<template>
    <Head :title="t(title)" />

    <div class="flex h-full flex-1 flex-col items-start gap-6 p-4">
        <div class="border-sidebar-border/70 dark:border-sidebar-border flex max-w-xl flex-col gap-4 rounded-xl border p-6">
            <ShieldAlert class="text-muted-foreground size-8" />
            <Heading
                :title="title"
                description="Your account does not have the permission this page or action needs. If you should have it, ask an administrator to add it to one of your roles."
            />
            <p class="text-muted-foreground font-mono text-xs">{{ t('Error :status', { status }) }}</p>
            <Button as-child variant="outline" class="w-fit">
                <Link :href="dashboard()">{{ t('Back to the dashboard') }}</Link>
            </Button>
        </div>
    </div>
</template>
