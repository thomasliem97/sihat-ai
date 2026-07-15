<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppFooter from '@/components/AppFooter.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent
            variant="sidebar"
            class="h-svh max-h-svh min-h-0 overflow-hidden md:h-[calc(100svh-1rem)] md:max-h-[calc(100svh-1rem)]"
        >
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <div class="min-h-0 flex-1 overflow-x-hidden overflow-y-auto">
                <div class="atlas-field min-h-full w-full">
                    <div class="mx-auto w-full max-w-7xl p-4 md:p-6">
                        <slot />
                    </div>
                </div>
            </div>
            <AppFooter />
        </AppContent>
        <Toaster />
    </AppShell>
</template>
