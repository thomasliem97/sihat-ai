<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { FileText, HeartPulse, TrendingDown } from '@lucide/vue';
import LabTrendChart from '@/components/medical/LabTrendChart.vue';
import AnnotationPill from '@/components/patterns/AnnotationPill.vue';
import IconDisc from '@/components/patterns/IconDisc.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import SectionTag from '@/components/patterns/SectionTag.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes/patient';
import { show as recordShow } from '@/routes/records';

defineProps<{
    stats: {
        total_records: number;
        completed: number;
        abnormal_results: number;
    };
    recentRecords: Array<{
        id: number;
        title: string;
        status: string;
        modality_label: string;
        overall_confidence: number | null;
        created_at: string;
    }>;
    biomarkerTrends: Record<
        string,
        {
            unit: string;
            reference_low: number | null;
            reference_high: number | null;
            points: Array<{ value: number; collected_at: string; status: string }>;
        }
    >;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'My Health', href: dashboard() }],
    },
});
</script>

<template>
    <Head title="My Health" />

    <div class="atlas-field mx-auto max-w-4xl space-y-8 p-4 md:p-6">
        <PageHeader
            tag="Patient view"
            title="My Health"
            description="Your records, results, and trends in plain language"
        />

        <div class="grid gap-4 sm:grid-cols-3">
            <Card class="space-y-0">
                <CardHeader class="pb-2">
                    <div class="flex items-center gap-3">
                        <IconDisc size="sm">
                            <FileText class="size-4" />
                        </IconDisc>
                        <CardTitle
                            class="font-mono text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Records
                        </CardTitle>
                    </div>
                </CardHeader>
                <CardContent class="text-3xl font-bold tabular-nums">
                    {{ stats.total_records }}
                </CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <div class="flex items-center gap-3">
                        <IconDisc size="sm">
                            <HeartPulse class="size-4" />
                        </IconDisc>
                        <CardTitle
                            class="font-mono text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Analyzed
                        </CardTitle>
                    </div>
                </CardHeader>
                <CardContent class="text-3xl font-bold tabular-nums">
                    {{ stats.completed }}
                </CardContent>
            </Card>
            <Card class="paper-panel--focal border-0 shadow-offset">
                <CardHeader class="pb-2">
                    <div class="flex items-center gap-3">
                        <IconDisc size="sm">
                            <TrendingDown class="size-4" />
                        </IconDisc>
                        <CardTitle
                            class="font-mono text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Need attention
                        </CardTitle>
                    </div>
                </CardHeader>
                <CardContent
                    class="text-3xl font-bold text-clinical-abnormal tabular-nums"
                >
                    {{ stats.abnormal_results }}
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader class="space-y-2">
                <SectionTag>Your specimens</SectionTag>
                <CardTitle class="text-lg">Recent records</CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
                <Link
                    v-for="record in recentRecords"
                    :key="record.id"
                    :href="recordShow(record.id)"
                    class="block rounded-2xl border border-border p-5 transition-colors hover:bg-muted/40"
                >
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-base font-semibold">{{ record.title }}</p>
                        <AnnotationPill
                            :variant="
                                record.status === 'completed' ? 'teal' : 'amber'
                            "
                        >
                            {{ record.status }}
                        </AnnotationPill>
                    </div>
                    <p
                        class="mt-2 font-mono text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        {{ record.modality_label }}
                    </p>
                </Link>
            </CardContent>
        </Card>

        <Card v-if="Object.keys(biomarkerTrends).length">
            <CardHeader class="space-y-2">
                <SectionTag>Trends</SectionTag>
                <CardTitle class="text-lg">Lab trends over time</CardTitle>
            </CardHeader>
            <CardContent class="grid gap-8 sm:grid-cols-2">
                <LabTrendChart
                    v-for="(series, name) in biomarkerTrends"
                    :key="name"
                    :name="name"
                    :unit="series.unit"
                    :data="series.points"
                    :reference-low="series.reference_low ?? undefined"
                    :reference-high="series.reference_high ?? undefined"
                />
            </CardContent>
        </Card>
    </div>
</template>
