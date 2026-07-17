<script setup lang="ts">
import { Label } from '@/components/ui/label';

defineProps<{
    htmlFor?: string;
    required?: boolean;
}>();
</script>

<template>
    <Label :for="htmlFor" class="text-sm font-medium">
        <slot />
        <span v-if="required" class="text-destructive"> *</span>
    </Label>
</template>
