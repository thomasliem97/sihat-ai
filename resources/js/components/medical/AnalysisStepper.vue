<script setup lang="ts">
import { Check, LoaderCircle } from '@lucide/vue';

defineProps<{
    steps: Array<{
        step: string;
        label: string;
        status: string;
    }>;
}>();
</script>

<template>
    <ol class="flex flex-wrap items-center gap-2">
        <template v-for="(step, i) in steps" :key="step.step">
            <li
                class="flex items-center gap-2 rounded-full border px-2.5 py-1.5"
                :class="{
                    'border-primary/40 bg-primary/5 text-primary':
                        step.status === 'completed' ||
                        step.status === 'running',
                    'border-border bg-muted/50 text-muted-foreground':
                        step.status !== 'completed' &&
                        step.status !== 'running',
                }"
            >
                <span
                    class="flex size-6 items-center justify-center rounded-full border font-mono text-[0.65rem] font-bold"
                    :class="{
                        'border-primary bg-primary text-primary-foreground':
                            step.status === 'completed',
                        'border-primary bg-primary/15 text-primary':
                            step.status === 'running',
                        'border-line-strong bg-paper-blue text-ink-soft':
                            step.status !== 'completed' &&
                            step.status !== 'running',
                    }"
                >
                    <Check
                        v-if="step.status === 'completed'"
                        class="size-3.5"
                        aria-hidden="true"
                    />
                    <LoaderCircle
                        v-else-if="step.status === 'running'"
                        class="size-3.5 animate-spin"
                        aria-hidden="true"
                    />
                    <template v-else>{{ i + 1 }}</template>
                </span>
                <span
                    class="font-mono text-xs font-semibold tracking-wide uppercase"
                >
                    {{ step.label }}
                </span>
            </li>
            <span
                v-if="i < steps.length - 1"
                class="hidden h-px w-6 border-t border-dashed border-line-strong sm:block"
                aria-hidden="true"
            />
        </template>
    </ol>
</template>
