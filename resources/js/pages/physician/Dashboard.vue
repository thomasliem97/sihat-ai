<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    FileText,
    Flag,
    Users,
} from '@lucide/vue';
import ClinicalBadge from '@/components/medical/ClinicalBadge.vue';
import AnnotationPill from '@/components/patterns/AnnotationPill.vue';
import AtlasEmptyState from '@/components/patterns/AtlasEmptyState.vue';
import IconDisc from '@/components/patterns/IconDisc.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import SectionTag from '@/components/patterns/SectionTag.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes/physician';
import { index as recordsIndex, show as recordShow } from '@/routes/records';

defineProps<{
    stats: {
        total_records: number;
        pending: number;
        completed: number;
        patients: number;
        critical_flags: number;
    };
    recentRecords: Array<{
        id: number;
        title: string;
        status: string;
        patient_name: string;
        modality_label: string;
        overall_confidence: number | null;
        created_at: string;
    }>;
    criticalBiomarkers: Array<{
        name: string;
        value: number;
        unit: string;
        status: string;
        patient_name: string;
    }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Physician Dashboard', href: dashboard() }],
    },
});
</script>

<template>
    <Head title="Physician Dashboard" />

    <div class="space-y-6">
        <PageHeader
            tag="Physician workspace"
            title="Dashboard"
            description="Multimodal records, AI findings, and clinical decision support"
            meta="Field overview"
        />

        <div class="grid gap-4 md:grid-cols-4">
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle
                        class="flex items-center gap-2 font-mono text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        <IconDisc size="sm">
                            <FileText class="size-3.5" />
                        </IconDisc>
                        Total records
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-3xl font-bold tabular-nums">
                    {{ stats.total_records }}
                </CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle
                        class="flex items-center gap-2 font-mono text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        <IconDisc size="sm">
                            <Activity class="size-3.5" />
                        </IconDisc>
                        Processing
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-3xl font-bold tabular-nums">
                    {{ stats.pending }}
                </CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle
                        class="flex items-center gap-2 font-mono text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        <IconDisc size="sm">
                            <Users class="size-3.5" />
                        </IconDisc>
                        Patients
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-3xl font-bold tabular-nums">
                    {{ stats.patients }}
                </CardContent>
            </Card>
            <Card class="paper-panel--focal border-0 shadow-offset">
                <CardHeader class="pb-2">
                    <CardTitle
                        class="flex items-center gap-2 font-mono text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        <IconDisc size="sm">
                            <AlertTriangle class="size-3.5" />
                        </IconDisc>
                        Critical flags
                    </CardTitle>
                </CardHeader>
                <CardContent
                    class="text-3xl font-bold text-clinical-critical tabular-nums"
                >
                    {{ stats.critical_flags }}
                </CardContent>
            </Card>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <SectionTag>Queue</SectionTag>
                    <CardTitle class="text-lg">Recent records</CardTitle>
                </CardHeader>
                <CardContent class="space-y-3">
                    <Link
                        v-for="record in recentRecords"
                        :key="record.id"
                        :href="recordShow(record.id)"
                        class="flex items-center justify-between rounded-xl border border-border p-3 transition-colors hover:bg-muted/50"
                    >
                        <div>
                            <p class="text-sm font-semibold">{{ record.title }}</p>
                            <p
                                class="font-mono text-xs tracking-wide text-muted-foreground"
                            >
                                {{ record.patient_name }} ·
                                {{ record.modality_label }}
                            </p>
                        </div>
                        <AnnotationPill
                            :variant="
                                record.status === 'completed' ? 'teal' : 'amber'
                            "
                        >
                            {{ record.status }}
                        </AnnotationPill>
                    </Link>
                    <Link
                        :href="recordsIndex()"
                        class="inline-flex font-mono text-xs font-semibold tracking-wide text-primary uppercase hover:underline"
                    >
                        View all records →
                    </Link>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <SectionTag>Watchlist</SectionTag>
                    <CardTitle class="text-lg">Abnormal biomarkers</CardTitle>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div
                        v-for="(bio, i) in criticalBiomarkers"
                        :key="i"
                        class="flex items-center justify-between rounded-xl border border-border p-3"
                    >
                        <div>
                            <p class="text-sm font-semibold">{{ bio.name }}</p>
                            <p
                                class="font-mono text-xs tracking-wide text-muted-foreground"
                            >
                                {{ bio.patient_name }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="font-mono text-sm tabular-nums">
                                {{ bio.value }} {{ bio.unit }}
                            </span>
                            <ClinicalBadge :status="bio.status" />
                        </div>
                    </div>
                    <AtlasEmptyState
                        v-if="criticalBiomarkers.length === 0"
                        :icon="Flag"
                        title="No abnormal biomarkers"
                        description="Flagged labs will appear here for rapid review."
                        class="!py-8"
                    />
                </CardContent>
            </Card>
        </div>
    </div>
</template>
