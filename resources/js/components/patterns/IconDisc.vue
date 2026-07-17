<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        size?: 'default' | 'sm';
        class?: HTMLAttributes['class'];
    }>(),
    {
        size: 'default',
    },
);
</script>

<template>
    <div
        :class="cn('icon-disc', size === 'sm' && 'icon-disc--sm', $props.class)"
    >
        <slot />
    </div>
</template>
