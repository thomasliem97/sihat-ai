<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { BarChart3, Brain, Shield } from '@lucide/vue';
import AnnotationPill from '@/components/patterns/AnnotationPill.vue';
import IconDisc from '@/components/patterns/IconDisc.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import SectionTag from '@/components/patterns/SectionTag.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { index, run } from '@/routes/evaluation';

const props = defineProps<{
    runs: Array<{
        id: number;
        run_type: string;
        sample_count: number;
        avg_score: number | null;
        metrics: Record<string, unknown> | null;
        created_at: string;
    }>;
    summary: {
        medqa_accuracy: number;
        report_quality: number;
        safety_compliance: number;
    };
    canRun: boolean;
}>();

function runSuite(suite: string) {
    router.post(run.url(), { suite }, { preserveScroll: true });
}

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Evaluation', href: index() }],
    },
});
</script>

<template>
    <Head title="AI Evaluation" />

    <div class="space-y-6">
        <PageHeader
            tag="Eval harness"
            title="Evaluation"
            description="MedQA benchmark, LLM-as-judge report quality, and safety compliance metrics"
            meta="Trace tape"
        />

        <Card v-if="canRun">
            <CardHeader class="space-y-2">
                <SectionTag>Live runner</SectionTag>
                <CardTitle class="text-lg">Run evaluation suite</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-wrap gap-2">
                <Button type="button" variant="outline" @click="runSuite('medqa')">
                    Run MedQA
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    @click="runSuite('llm_judge')"
                >
                    Run LLM judge
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    @click="runSuite('safety')"
                >
                    Run safety
                </Button>
            </CardContent>
        </Card>

        <div class="grid gap-4 md:grid-cols-3">
            <Card class="paper-panel--focal border-0 shadow-offset">
                <CardHeader class="pb-2">
                    <div class="flex items-center gap-3">
                        <IconDisc size="sm">
                            <Brain class="size-4" />
                        </IconDisc>
                        <CardTitle
                            class="font-mono text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            MedQA accuracy
                        </CardTitle>
                    </div>
                </CardHeader>
                <CardContent class="text-3xl font-bold tabular-nums">
                    {{ summary.medqa_accuracy }}%
                </CardContent>
            </Card>
            <Card class="paper-panel--focal border-0 shadow-offset">
                <CardHeader class="pb-2">
                    <div class="flex items-center gap-3">
                        <IconDisc size="sm">
                            <BarChart3 class="size-4" />
                        </IconDisc>
                        <CardTitle
                            class="font-mono text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Report quality
                        </CardTitle>
                    </div>
                </CardHeader>
                <CardContent class="text-3xl font-bold tabular-nums">
                    {{ summary.report_quality }}/5
                </CardContent>
            </Card>
            <Card class="paper-panel--focal border-0 shadow-offset">
                <CardHeader class="pb-2">
                    <div class="flex items-center gap-3">
                        <IconDisc size="sm">
                            <Shield class="size-4" />
                        </IconDisc>
                        <CardTitle
                            class="font-mono text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Safety compliance
                        </CardTitle>
                    </div>
                </CardHeader>
                <CardContent class="text-3xl font-bold tabular-nums">
                    {{ summary.safety_compliance }}%
                </CardContent>
            </Card>
        </div>

        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            <Card v-for="run in runs" :key="run.id">
                <CardHeader class="space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <SectionTag>Run</SectionTag>
                        <AnnotationPill variant="teal">
                            {{ run.run_type.replace('_', ' ') }}
                        </AnnotationPill>
                    </div>
                    <CardTitle class="text-lg capitalize">
                        {{ run.run_type.replace('_', ' ') }}
                    </CardTitle>
                </CardHeader>
                <CardContent class="space-y-2 text-sm">
                    <p class="font-mono text-xs tracking-wide tabular-nums">
                        <span class="text-muted-foreground">Samples</span>
                        {{ run.sample_count }}
                    </p>
                    <p class="font-mono text-xs tracking-wide tabular-nums">
                        <span class="text-muted-foreground">Score</span>
                        {{ run.avg_score }}
                    </p>
                    <pre
                        v-if="run.metrics"
                        class="mt-2 overflow-x-auto rounded-xl border border-dashed border-primary/40 bg-ink px-3 py-2 font-mono text-xs text-paper-blue"
                        >{{ JSON.stringify(run.metrics, null, 2) }}</pre
                    >
                </CardContent>
            </Card>
        </div>
    </div>
</template>
