<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
</script>

<template>
    <div
        class="flex aspect-square size-8 items-center justify-center rounded-xl bg-sidebar-primary text-sidebar-primary-foreground shadow-sm"
    >
        <AppLogoIcon class="size-5 fill-current" />
    </div>
    <div class="ml-2 grid flex-1 text-left text-sm leading-tight">
        <span class="truncate font-bold tracking-tight">Sihat AI</span>
        <span
            class="truncate font-mono text-[0.65rem] font-semibold tracking-wider text-muted-foreground uppercase"
        >
            Clinical atlas
        </span>
    </div>
</template>
