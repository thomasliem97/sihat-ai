<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import IconDisc from '@/components/patterns/IconDisc.vue';
import { cn } from '@/lib/utils';
import type { NavItem } from '@/types';

defineProps<{
    items: NavItem[];
}>();
</script>

<template>
    <nav aria-label="Main" class="flex flex-col gap-2 p-3">
        <Link
            v-for="item in items"
            :key="item.title"
            :href="item.href"
            :aria-current="item.isActive ? 'page' : undefined"
            :class="
                cn(
                    'flex items-center gap-3 rounded-2xl border px-3 py-3 text-left transition-colors',
                    'focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none',
                    item.isActive
                        ? 'border-primary bg-paper-blue text-sidebar-accent-foreground'
                        : 'border-sidebar-border bg-paper/70 text-sidebar-foreground hover:bg-paper',
                )
            "
        >
            <IconDisc
                size="sm"
                :class="item.isActive ? 'border-primary' : undefined"
            >
                <component :is="item.icon" class="size-5" />
            </IconDisc>
            <span class="min-w-0">
                <span class="block text-sm font-semibold tracking-tight">
                    {{ item.title }}
                </span>
                <span
                    v-if="item.description"
                    class="mt-0.5 block text-xs leading-snug text-muted-foreground"
                >
                    {{ item.description }}
                </span>
            </span>
        </Link>
    </nav>
</template>
