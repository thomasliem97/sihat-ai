<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
</script>

<template>
    <div class="space-y-6">
        <Heading
            variant="small"
            title="Delete account"
            description="Delete your account and all of its resources"
        />
        <div
            class="space-y-4 rounded-2xl border border-clinical-critical/25 bg-clinical-critical/10 p-4"
        >
            <div class="relative space-y-0.5 text-clinical-critical">
                <p class="font-medium">Warning</p>
                <p class="text-sm text-muted-foreground">
                    Please proceed with caution, this cannot be undone.
                </p>
            </div>
            <div class="space-y-3">
                <Button
                    type="button"
                    variant="destructive"
                    disabled
                    data-test="delete-user-button"
                >
                    Delete account
                </Button>
                <p class="text-sm text-muted-foreground">
                    Account deletion is disabled in demo mode.
                </p>
            </div>
        </div>
    </div>
</template>
