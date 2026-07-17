<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import FieldLabel from '@/components/patterns/FieldLabel.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { login } from '@/routes';

defineOptions({
    layout: {
        title: 'Forgot password',
        description: 'Password reset is not available in this demo',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head title="Forgot password" />

    <div class="space-y-6">
        <form @submit.prevent>
            <div class="grid gap-2">
                <FieldLabel html-for="email" required>Email address</FieldLabel>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="off"
                    disabled
                    placeholder="name@example.com"
                />
            </div>

            <div class="my-6 space-y-3">
                <Button
                    type="button"
                    class="w-full"
                    disabled
                    data-test="email-password-reset-link-button"
                >
                    Email password reset link
                </Button>
                <p class="text-center text-sm text-muted-foreground">
                    Password reset is disabled in demo mode.
                </p>
            </div>
        </form>

        <div class="space-x-1 text-center text-sm text-muted-foreground">
            <span>Or, return to</span>
            <TextLink :href="login()">log in</TextLink>
        </div>
    </div>
</template>
