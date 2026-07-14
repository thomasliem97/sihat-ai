<script setup lang="ts">
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

defineProps<{
    citation: {
        source: string;
        section?: string | null;
        excerpt: string;
        relevance?: number;
    };
    index: number;
}>();

function padIndex(n: number): string {
    return String(n).padStart(2, '0');
}
</script>

<template>
    <TooltipProvider>
        <Tooltip>
            <TooltipTrigger as-child>
                <button type="button" class="citation-stamp gap-1.5">
                    <span
                        class="rounded bg-primary px-1.5 py-0.5 font-mono text-[0.65rem] font-bold text-primary-foreground"
                    >
                        [{{ padIndex(index) }}]
                    </span>
                    <span class="max-w-28 truncate">{{ citation.source }}</span>
                </button>
            </TooltipTrigger>
            <TooltipContent class="max-w-xs space-y-1.5 border-dashed border-primary/40">
                <p class="font-mono text-xs font-semibold tracking-wide uppercase">
                    {{ citation.source }}
                </p>
                <p
                    v-if="citation.section"
                    class="font-mono text-xs text-muted-foreground"
                >
                    {{ citation.section }}
                </p>
                <p class="text-xs leading-relaxed">{{ citation.excerpt }}</p>
                <p
                    v-if="citation.relevance != null"
                    class="font-mono text-xs font-semibold text-secondary-foreground"
                >
                    Relevance {{ Math.round(citation.relevance * 100) }}%
                </p>
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
