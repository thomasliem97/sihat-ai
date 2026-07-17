<script setup lang="ts">
import {
    AlertTriangle,
    CheckCircle2,
    CircleAlert,
    TriangleAlert,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import { Badge } from '@/components/ui/badge';

const props = defineProps<{
    status: string;
}>();

const config = computed(() => {
    const map: Record<
        string,
        { label: string; class: string; icon: Component }
    > = {
        normal: {
            label: 'Normal',
            class: 'border-clinical-normal/30 bg-clinical-normal/10 font-mono text-xs font-semibold tracking-wide text-clinical-normal uppercase',
            icon: CheckCircle2,
        },
        borderline: {
            label: 'Borderline',
            class: 'border-clinical-borderline/30 bg-clinical-borderline/10 font-mono text-xs font-semibold tracking-wide text-clinical-borderline uppercase',
            icon: AlertTriangle,
        },
        abnormal: {
            label: 'Abnormal',
            class: 'border-clinical-abnormal/30 bg-clinical-abnormal/10 font-mono text-xs font-semibold tracking-wide text-clinical-abnormal uppercase',
            icon: TriangleAlert,
        },
        critical: {
            label: 'Critical',
            class: 'border-clinical-critical/30 bg-clinical-critical/10 font-mono text-xs font-bold tracking-wide text-clinical-critical uppercase',
            icon: CircleAlert,
        },
    };

    return (
        map[props.status] ?? {
            label: props.status,
            class: 'font-mono text-xs font-semibold tracking-wide uppercase',
            icon: AlertTriangle,
        }
    );
});
</script>

<template>
    <Badge variant="outline" :class="config.class" class="gap-1.5">
        <component :is="config.icon" class="size-3" aria-hidden="true" />
        <span>{{ config.label }}</span>
    </Badge>
</template>
