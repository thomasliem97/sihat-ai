<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        variant?: 'teal' | 'coral' | 'amber';
        class?: HTMLAttributes['class'];
    }>(),
    {
        variant: 'teal',
    },
);
</script>

<template>
    <span
        :class="
            cn(
                'atlas-pill',
                variant === 'teal' && 'atlas-pill-teal',
                variant === 'coral' && 'atlas-pill-coral',
                variant === 'amber' && 'atlas-pill-amber',
                $props.class,
            )
        "
    >
        <slot />
    </span>
</template>
