<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import RoleForm from '@/components/administration/RoleForm.vue';
import Heading from '@/components/Heading.vue';
import { index, store } from '@/routes/roles';
import type { PermissionGroup } from '@/types';
import { useT } from '@/lib/i18n';

defineProps<{ groups: PermissionGroup[] }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Roles & permissions', href: index() }] } });

const { t } = useT();
</script>

<template>
    <Head :title="t('New role')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="New role" description="A role is a named set of permissions. Assign it to users afterwards." />

        <div class="border-sidebar-border/70 dark:border-sidebar-border max-w-4xl rounded-xl border p-4 sm:p-6">
            <RoleForm :action="store()" :groups="groups" submit-label="Create role" :cancel-href="index().url" />
        </div>
    </div>
</template>
