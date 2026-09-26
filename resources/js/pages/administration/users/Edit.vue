<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { index, show, update } from '@/routes/users';
import type { AdminUser, RoleOption } from '@/types';

const props = defineProps<{ user: AdminUser; roles: RoleOption[] }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Users', href: index() }] } });

const form = useForm({
    is_active: props.user.is_active,
    role_ids: props.user.roles.map((role) => role.id),
});

function submit(): void {
    form.submit(update(props.user.id));
}
</script>

<template>
    <Head :title="`Edit access — ${user.name}`" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            :title="`Edit access — ${user.name}`"
            description="Roles and sign-in status only. Name, email and password belong to the user's own settings."
        />

        <form class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl space-y-6 rounded-xl border p-4 sm:p-6" @submit.prevent="submit">
            <label class="flex items-start gap-3">
                <input v-model="form.is_active" type="checkbox" class="mt-1 size-4" />
                <span>
                    <span class="block text-sm font-medium">Active</span>
                    <span class="text-muted-foreground block text-xs">
                        An inactive user cannot sign in and holds no permissions. Everything they
                        recorded keeps their name.
                    </span>
                </span>
            </label>
            <InputError :message="form.errors.is_active" />

            <fieldset class="space-y-3">
                <legend class="text-sm font-medium">Roles</legend>
                <p class="text-muted-foreground text-xs">A user's permissions are everything their active roles grant, combined.</p>
                <label
                    v-for="role in roles"
                    :key="role.id"
                    class="border-sidebar-border/70 dark:border-sidebar-border flex items-start gap-3 rounded-lg border p-3"
                >
                    <input v-model="form.role_ids" type="checkbox" :value="role.id" class="mt-1 size-4" />
                    <span>
                        <span class="block text-sm font-medium">
                            {{ role.name }}
                            <span class="text-muted-foreground font-mono text-xs">{{ role.code }}</span>
                            <span v-if="!role.is_active" class="text-muted-foreground text-xs"> — inactive, grants nothing</span>
                        </span>
                        <span v-if="role.description" class="text-muted-foreground block text-xs">{{ role.description }}</span>
                    </span>
                </label>
                <InputError :message="(form.errors as Record<string, string | undefined>).roles ?? form.errors.role_ids" />
            </fieldset>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing">Save access</Button>
                <Button variant="outline" as-child><Link :href="show(user.id)">Cancel</Link></Button>
            </div>
        </form>
    </div>
</template>
