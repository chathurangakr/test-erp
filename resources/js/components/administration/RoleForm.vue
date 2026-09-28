<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { PermissionGroup, RoleDetail } from '@/types';
import { useT } from '@/lib/i18n';

const props = defineProps<{
    action: { url: string; method: 'post' | 'put' | 'patch' | 'get' | 'delete' };
    role?: RoleDetail | null;
    groups: PermissionGroup[];
    submitLabel: string;
    cancelHref: string;
}>();

const creating = computed(() => !props.role);
const locked = computed(() => props.role?.is_administrator === true);

const form = useForm<{
    code?: string;
    name: string;
    description: string;
    is_active: boolean;
    permission_ids: number[];
}>({
    ...(props.role ? {} : { code: '' }),
    name: props.role?.name ?? '',
    description: props.role?.description ?? '',
    is_active: props.role?.is_active ?? true,
    permission_ids: [...(props.role?.permission_ids ?? [])],
});

function groupIds(group: PermissionGroup): number[] {
    return group.permissions.map((permission) => permission.id);
}

function groupState(group: PermissionGroup): 'all' | 'some' | 'none' {
    const held = groupIds(group).filter((id) => form.permission_ids.includes(id)).length;

    return held === 0 ? 'none' : held === group.permissions.length ? 'all' : 'some';
}

function toggleGroup(group: PermissionGroup): void {
    const ids = groupIds(group);

    form.permission_ids =
        groupState(group) === 'all'
            ? form.permission_ids.filter((id) => !ids.includes(id))
            : [...new Set([...form.permission_ids, ...ids])];
}

function submit(): void {
    form.submit(props.action);
}

const { t } = useT();
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="code">{{ t('Code') }} <span v-if="creating" class="text-destructive">*</span></Label>
                <Input
                    v-if="creating"
                    id="code"
                    v-model="form.code"
                    required
                    autocomplete="off"
                    :placeholder="t('e.g. QUALITY_CONTROL')"
                    class="uppercase"
                />
                <p v-else class="font-mono text-sm">{{ role?.code }}</p>
                <p class="text-muted-foreground text-xs">
                    {{ creating ? t('Capital letters, digits and underscores. Cannot be changed later.') : t('Fixed once created.') }}
                </p>
                <InputError :message="form.errors.code" />
            </div>
            <div class="grid gap-2">
                <Label for="name">{{ t('Name') }} <span class="text-destructive">*</span></Label>
                <Input id="name" v-model="form.name" required autocomplete="off" />
                <InputError :message="form.errors.name" />
            </div>
        </div>

        <div class="grid gap-2">
            <Label for="description">{{ t('Description') }}</Label>
            <Input id="description" v-model="form.description" autocomplete="off" />
            <InputError :message="form.errors.description" />
        </div>

        <label class="flex items-start gap-3">
            <input v-model="form.is_active" type="checkbox" class="mt-1 size-4" :disabled="locked" />
            <span>
                <span class="block text-sm font-medium">{{ t('Active') }}</span>
                <span class="text-muted-foreground block text-xs">
                    {{ t('An inactive role grants nothing to anyone, but stays assigned so it can be switched back on.') }}
                </span>
            </span>
        </label>
        <InputError :message="form.errors.is_active" />

        <div
            v-if="locked"
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border border-dashed p-4 text-sm"
        >
            {{ t('The Administrator role always holds every permission and is always active, so the system can never be left without someone able to manage access. Only its name and description can change.') }}
        </div>

        <div class="space-y-4">
            <h3 class="text-sm font-medium">{{ t('Permissions (:count)', { count: form.permission_ids.length }) }}</h3>
            <InputError :message="form.errors.permission_ids" />

            <div
                v-for="group in groups"
                :key="group.module"
                class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border"
            >
                <label class="bg-muted/50 flex items-center gap-3 rounded-t-xl px-4 py-2">
                    <input
                        type="checkbox"
                        class="size-4"
                        :checked="groupState(group) === 'all'"
                        :indeterminate="groupState(group) === 'some'"
                        :disabled="locked"
                        @change="toggleGroup(group)"
                    />
                    <span class="text-sm font-medium">{{ t(group.module) }}</span>
                </label>
                <div class="grid gap-2 p-4 sm:grid-cols-2">
                    <label v-for="permission in group.permissions" :key="permission.id" class="flex items-start gap-3">
                        <input
                            v-model="form.permission_ids"
                            type="checkbox"
                            :value="permission.id"
                            class="mt-1 size-4"
                            :disabled="locked"
                        />
                        <span>
                            <span class="block text-sm">{{ t(permission.name) }}</span>
                            <span class="text-muted-foreground block font-mono text-xs">{{ permission.code }}</span>
                        </span>
                    </label>
                </div>
            </div>
        </div>

        <div class="flex gap-2">
            <Button type="submit" :disabled="form.processing">{{ t(submitLabel) }}</Button>
            <Button variant="outline" as-child><Link :href="cancelHref">{{ t('Cancel') }}</Link></Button>
        </div>
    </form>
</template>
