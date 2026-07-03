<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <span :class="cn('section-tag', $props.class)">
        <slot />
    </span>
</template>
