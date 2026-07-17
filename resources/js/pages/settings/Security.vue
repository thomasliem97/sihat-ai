<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import FieldLabel from '@/components/patterns/FieldLabel.vue';
import { Button } from '@/components/ui/button';
import { edit } from '@/routes/security';

defineProps<{
    passwordRules: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Security settings',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Security settings" />

    <h1 class="sr-only">Security settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Update password"
            description="Ensure your account is using a long, random password to stay secure"
        />

        <form class="space-y-6" @submit.prevent>
            <div class="grid gap-2">
                <FieldLabel html-for="current_password"
                    >Current password</FieldLabel
                >
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                    placeholder="Enter current password"
                    disabled
                />
            </div>

            <div class="grid gap-2">
                <FieldLabel html-for="password">New password</FieldLabel>
                <PasswordInput
                    id="password"
                    name="password"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="Enter new password"
                    disabled
                />
            </div>

            <div class="grid gap-2">
                <FieldLabel html-for="password_confirmation"
                    >Confirm password</FieldLabel
                >
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="Re-enter new password"
                    disabled
                />
            </div>

            <div class="space-y-3">
                <Button
                    type="button"
                    disabled
                    data-test="update-password-button"
                >
                    Save
                </Button>
                <p class="text-sm text-muted-foreground">
                    Password updates are disabled in demo mode.
                </p>
            </div>
        </form>
    </div>
</template>
