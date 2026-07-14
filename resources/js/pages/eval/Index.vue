<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { BarChart3, Brain, Shield } from '@lucide/vue';
import { ref } from 'vue';
import AnnotationPill from '@/components/patterns/AnnotationPill.vue';
import IconDisc from '@/components/patterns/IconDisc.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import SectionTag from '@/components/patterns/SectionTag.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { index, run } from '@/routes/evaluation';

defineProps<{
    runs: Array<{
        id: number;
        run_type: string;
        sample_count: number;
        avg_score: number | null;
        metrics: Record<string, unknown> | null;
        demo_seed?: boolean;
        created_at: string;
    }>;
    summary: {
        medqa_accuracy: number;
        report_quality: number;
        safety_compliance: number;
        medqa_demo_seed?: boolean;
        report_quality_demo_seed?: boolean;
        safety_demo_seed?: boolean;
    };
    canRun: boolean;
}>();

const suites = [
    {
        id: 'medqa',
        label: 'Run MedQA',
        description:
            'Asks the AI medical multiple-choice questions and scores % correct.',
    },
    {
        id: 'llm_judge',
        label: 'Run LLM judge',
        description:
            'Rates recent AI physician reports from 1 to 5 for clarity and quality.',
    },
    {
        id: 'safety',
        label: 'Run safety',
        description:
            'Checks that the AI refuses definitive diagnoses, keeps disclaimers, and escalates emergencies.',
    },
] as const;

const runningSuite = ref<string | null>(null);

function runSuite(suite: string) {
    if (runningSuite.value) {
        return;
    }

    runningSuite.value = suite;
    router.post(
        run.url(),
        { suite },
        {
            preserveScroll: true,
            onFinish: () => {
                runningSuite.value = null;
            },
        },
    );
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
            description="Internal quality scoreboard for SihatAI. These checks measure medical knowledge, report quality, and safety behaviour. Patients never see this page."
        />

        <Card v-if="canRun">
            <CardHeader class="space-y-2">
                <SectionTag>Live runner</SectionTag>
                <CardTitle class="text-lg">Run evaluation suite</CardTitle>
                <CardDescription>
                    Queue a live check. Results appear in the summary cards and
                    run history below once the job finishes.
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-3">
                <div
                    v-for="suite in suites"
                    :key="suite.id"
                    class="flex flex-col gap-2 rounded-xl border border-border/70 bg-field/40 p-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <p class="max-w-prose text-sm leading-relaxed text-muted-foreground">
                        {{ suite.description }}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        class="shrink-0"
                        :disabled="runningSuite !== null"
                        @click="runSuite(suite.id)"
                    >
                        <Spinner v-if="runningSuite === suite.id" />
                        {{
                            runningSuite === suite.id
                                ? 'Evaluating…'
                                : suite.label
                        }}
                    </Button>
                </div>
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
                <CardContent class="space-y-2">
                    <p class="text-3xl font-bold tabular-nums">
                        {{ summary.medqa_accuracy }}%
                    </p>
                    <p class="max-w-prose text-sm leading-relaxed text-muted-foreground">
                        Share of medical quiz questions the AI answered
                        correctly (imaging, labs, red-flag care, and similar).
                    </p>
                    <AnnotationPill
                        v-if="summary.medqa_demo_seed"
                        variant="amber"
                    >
                        Demo seed
                    </AnnotationPill>
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
                <CardContent class="space-y-2">
                    <p class="text-3xl font-bold tabular-nums">
                        {{ summary.report_quality }}/5
                    </p>
                    <p class="max-w-prose text-sm leading-relaxed text-muted-foreground">
                        Average quality score from another model judging recent
                        AI physician reports (1 = poor, 5 = excellent).
                    </p>
                    <AnnotationPill
                        v-if="summary.report_quality_demo_seed"
                        variant="amber"
                    >
                        Demo seed
                    </AnnotationPill>
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
                <CardContent class="space-y-2">
                    <p class="text-3xl font-bold tabular-nums">
                        {{ summary.safety_compliance }}%
                    </p>
                    <p class="max-w-prose text-sm leading-relaxed text-muted-foreground">
                        Pass rate on safety prompts: refuse definitive
                        diagnosis, keep disclaimers, escalate emergencies.
                    </p>
                    <AnnotationPill
                        v-if="summary.safety_demo_seed"
                        variant="amber"
                    >
                        Demo seed
                    </AnnotationPill>
                </CardContent>
            </Card>
        </div>

        <section class="space-y-6 border-t border-border/70 pt-6">
            <header class="space-y-2">
                <SectionTag>History</SectionTag>
                <div class="space-y-1">
                    <h2 class="text-2xl font-bold tracking-tight md:text-3xl">
                        Recent evaluation runs
                    </h2>
                    <p
                        class="max-w-prose text-sm leading-relaxed text-muted-foreground"
                    >
                        Each card is one completed suite. Sample count is how
                        many cases were scored; Score is the headline metric for
                        that suite.
                    </p>
                </div>
            </header>

            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <Card v-for="run in runs" :key="run.id">
                    <CardHeader class="space-y-2">
                        <div class="flex items-center justify-between gap-2">
                            <SectionTag>Run</SectionTag>
                            <div class="flex flex-wrap gap-1">
                                <AnnotationPill
                                    v-if="run.demo_seed || run.metrics?.demo_seed"
                                    variant="amber"
                                >
                                    Demo seed
                                </AnnotationPill>
                                <AnnotationPill variant="teal">
                                    {{ run.run_type.replace('_', ' ') }}
                                </AnnotationPill>
                            </div>
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
        </section>
    </div>
</template>
