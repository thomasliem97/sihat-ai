<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, RefreshCw } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import AnalysisStepper from '@/components/medical/AnalysisStepper.vue';
import CitationChip from '@/components/medical/CitationChip.vue';
import ClinicalBadge from '@/components/medical/ClinicalBadge.vue';
import ConfidenceBadge from '@/components/medical/ConfidenceBadge.vue';
import ImageOverlay from '@/components/medical/ImageOverlay.vue';
import MedicalDisclaimer from '@/components/medical/MedicalDisclaimer.vue';
import AnnotationPill from '@/components/patterns/AnnotationPill.vue';
import FieldLabel from '@/components/patterns/FieldLabel.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import SectionTag from '@/components/patterns/SectionTag.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { index as recordsIndex, show as recordShow, sign as signReport } from '@/routes/records';
import { update as updateReport } from '@/routes/records/report';

const props = defineProps<{
    record: {
        id: number;
        title: string;
        modality_label: string;
        detected_modality_label?: string | null;
        status: string;
        overall_confidence: number | null;
        findings: Array<Record<string, unknown>> | null;
        physician_report: Record<string, unknown> | null;
        patient_report: Record<string, unknown> | null;
        patient_report_withheld?: boolean;
        patient_awaiting_sign?: boolean;
        citations: Array<Record<string, unknown>> | null;
        bounding_boxes: Array<Record<string, unknown>> | null;
        longitudinal_diff: Record<string, unknown> | null;
        volume_meta?: Record<string, unknown> | null;
        patch_meta?: Record<string, unknown> | null;
        partial_findings?: Record<string, unknown> | null;
        guardrail_flags: string[] | null;
        guardrail_code?: string | null;
        safe_uri?: string | null;
        pipeline_steps: Array<{
            step: string;
            label: string;
            status: string;
        }> | null;
        agent_trace?: Array<{
            hop: string;
            status: string;
            detail: string;
            duration_ms?: number | null;
            confidence?: number | null;
        }> | null;
        is_signed?: boolean;
        signed_at?: string | null;
        signed_by_name?: string | null;
        can_edit_report?: boolean;
        error_message: string | null;
        patient_name: string;
        file_url: string | null;
        created_at: string;
        analyzed_at: string | null;
    };
    similarCases?: Array<{
        id: number;
        title: string;
        modality: string | null;
        score: number;
        findings_preview: string;
        analyzed_at: string | null;
    }>;
    biomarkers: Array<{
        id: number;
        name: string;
        value: number;
        unit: string;
        reference_low?: number | null;
        reference_high?: number | null;
        status: string;
    }>;
    viewMode: 'physician' | 'patient';
}>();

const editing = ref(false);
const draftSummary = ref('');
const draftNotes = ref('');
const draftRecommendations = ref('');

watch(
    () => props.record.physician_report,
    (report) => {
        if (!report) {
            return;
        }
        draftSummary.value = String(report.summary ?? '');
        draftNotes.value = String(report.technical_notes ?? '');
        draftRecommendations.value = Array.isArray(report.recommendations)
            ? (report.recommendations as string[]).join('\n')
            : '';
    },
    { immediate: true },
);

const reportForm = useForm({
    summary: '',
    technical_notes: '',
    recommendations: [] as string[],
});

function saveDraft() {
    reportForm.summary = draftSummary.value;
    reportForm.technical_notes = draftNotes.value;
    reportForm.recommendations = draftRecommendations.value
        .split('\n')
        .map((l) => l.trim())
        .filter(Boolean);
    reportForm.patch(updateReport.url(props.record.id), {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false;
        },
    });
}

function signDraft() {
    router.post(signReport.url(props.record.id), {}, { preserveScroll: true });
}

const patchList = computed(() => {
    const patches = props.record.patch_meta?.patches;
    return Array.isArray(patches) ? patches : [];
});

let pollInterval: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    if (
        props.record.status === 'processing' ||
        props.record.status === 'pending'
    ) {
        pollInterval = setInterval(() => {
            router.reload({ only: ['record', 'biomarkers', 'similarCases'] });
        }, 3000);
    }
});

onUnmounted(() => {
    if (pollInterval) {
        clearInterval(pollInterval);
    }
});

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Medical Records', href: recordsIndex() },
            { title: 'Record detail' },
        ],
    },
});
</script>

<template>
    <Head :title="record.title" />

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <PageHeader
                :tag="viewMode === 'physician' ? 'Specimen' : 'Your result'"
                :title="record.title"
                :description="
                    [
                        record.modality_label,
                        record.detected_modality_label
                            ? `detected ${record.detected_modality_label}`
                            : null,
                        viewMode === 'physician' ? record.patient_name : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')
                "
                :meta="`REC-${String(record.id).padStart(4, '0')}`"
            />
            <div class="flex flex-wrap items-center gap-2">
                <AnnotationPill
                    :variant="
                        record.status === 'completed'
                            ? 'teal'
                            : record.status === 'failed'
                              ? 'coral'
                              : 'amber'
                    "
                >
                    {{ record.status }}
                </AnnotationPill>
                <ConfidenceBadge
                    v-if="record.overall_confidence"
                    :confidence="record.overall_confidence"
                />
            </div>
        </div>

        <Alert
            v-if="
                record.guardrail_flags?.includes('critical_value_escalation')
            "
            variant="destructive"
        >
            <AlertTriangle class="size-4" />
            <AlertTitle>Critical value detected</AlertTitle>
            <AlertDescription>
                Immediate clinical review recommended. Do not rely on AI alone.
                Patient-facing copy is withheld until a clinician reviews.
            </AlertDescription>
        </Alert>

        <Alert
            v-if="
                record.guardrail_flags?.includes('low_confidence_abstention')
            "
            class="border-clinical-borderline/40 bg-clinical-borderline/10"
        >
            <AlertTriangle class="size-4 text-clinical-borderline" />
            <AlertTitle>Low confidence: abstain</AlertTitle>
            <AlertDescription>
                Model confidence is below the publish threshold. Automatic
                patient release is withheld pending clinician review.
            </AlertDescription>
        </Alert>

        <Alert
            v-if="record.patient_report_withheld && viewMode === 'patient'"
            class="border-line bg-paper-blue"
        >
            <AlertTitle>Results under clinical review</AlertTitle>
            <AlertDescription>
                Your care team is reviewing this study before sharing the
                detailed AI summary. Please contact your clinic if you have
                urgent concerns.
            </AlertDescription>
        </Alert>

        <Alert
            v-else-if="record.patient_awaiting_sign && viewMode === 'patient'"
            class="border-line bg-paper-blue"
        >
            <AlertTitle>Awaiting physician sign-off</AlertTitle>
            <AlertDescription>
                Your report is ready for clinician signature. The detailed
                patient summary will appear after your physician signs the
                draft.
            </AlertDescription>
        </Alert>

        <div
            v-if="
                record.status === 'processing' || record.status === 'pending'
            "
            class="space-y-4"
        >
            <Card class="paper-panel--focal border-0 shadow-offset">
                <CardHeader class="space-y-2">
                    <SectionTag>Pipeline</SectionTag>
                    <CardTitle class="flex items-center gap-2 text-lg">
                        <RefreshCw class="size-4 animate-spin" />
                        Analysis in progress
                    </CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <AnalysisStepper
                        v-if="record.pipeline_steps"
                        :steps="record.pipeline_steps"
                    />
                </CardContent>
            </Card>
        </div>

        <template v-else-if="record.status === 'completed'">
            <AnalysisStepper
                v-if="record.pipeline_steps"
                :steps="record.pipeline_steps"
            />

            <div
                class="grid gap-6"
                :class="
                    viewMode === 'physician' && record.bounding_boxes?.length
                        ? 'lg:grid-cols-2'
                        : ''
                "
            >
                <ImageOverlay
                    v-if="record.bounding_boxes?.length"
                    :image-url="record.file_url"
                    :boxes="(record.bounding_boxes as any)"
                />

                <Card
                    :class="
                        !record.bounding_boxes?.length
                            ? 'paper-panel--focal border-0 shadow-offset'
                            : ''
                    "
                >
                    <CardHeader class="space-y-2">
                        <SectionTag>Findings</SectionTag>
                        <CardTitle class="text-lg">
                            {{
                                viewMode === 'physician'
                                    ? 'Findings'
                                    : 'Your results'
                            }}
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div
                            v-for="(finding, i) in record.findings"
                            :key="i"
                            class="rounded-xl border border-border p-4"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <span class="font-semibold">{{
                                    finding.label
                                }}</span>
                                <div class="flex flex-wrap items-center gap-2">
                                    <ClinicalBadge
                                        v-if="finding.severity"
                                        :status="String(finding.severity)"
                                    />
                                    <ConfidenceBadge
                                        v-if="finding.confidence"
                                        :confidence="
                                            Number(finding.confidence)
                                        "
                                    />
                                </div>
                            </div>
                            <p
                                v-if="finding.description"
                                class="mt-2 max-w-prose text-sm leading-relaxed text-muted-foreground"
                            >
                                {{ finding.description }}
                            </p>
                            <p
                                v-if="finding.value"
                                class="mt-2 font-mono text-sm tabular-nums"
                            >
                                {{ finding.value }} {{ finding.unit }}
                                <span
                                    v-if="finding.reference"
                                    class="text-muted-foreground"
                                >
                                    (ref: {{ finding.reference }})
                                </span>
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <Card
                v-if="viewMode === 'physician' && record.longitudinal_diff"
            >
                <CardHeader class="space-y-2">
                    <SectionTag>Comparison</SectionTag>
                    <CardTitle class="text-lg">Longitudinal comparison</CardTitle>
                </CardHeader>
                <CardContent class="space-y-3">
                    <p class="max-w-prose text-sm leading-relaxed">
                        {{ record.longitudinal_diff.summary }}
                    </p>
                    <ul
                        v-if="
                            Array.isArray(record.longitudinal_diff.changes) &&
                            record.longitudinal_diff.changes.length
                        "
                        class="space-y-1 text-sm"
                    >
                        <li
                            v-for="(change, i) in record.longitudinal_diff
                                .changes as Array<Record<string, unknown>>"
                            :key="i"
                            class="flex flex-wrap items-center justify-between gap-2 border-b border-border py-2"
                        >
                            <span>{{ change.finding }}</span>
                            <span
                                class="font-mono text-xs tracking-wide text-muted-foreground uppercase"
                            >
                                {{ change.change }}
                                <template v-if="change.prior_date">
                                    · prior {{ change.prior_date }}
                                </template>
                            </span>
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <Card
                v-if="viewMode === 'physician' && record.agent_trace?.length"
            >
                <CardHeader class="space-y-2">
                    <SectionTag>Agent relay</SectionTag>
                    <CardTitle class="text-lg">Hop traces</CardTitle>
                </CardHeader>
                <CardContent>
                    <ol class="space-y-2">
                        <li
                            v-for="(hop, i) in record.agent_trace"
                            :key="i"
                            class="flex flex-wrap items-start justify-between gap-2 rounded-xl border border-border p-4"
                        >
                            <div>
                                <p
                                    class="font-mono text-xs font-semibold tracking-wide uppercase"
                                >
                                    {{ hop.hop }}
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    {{ hop.detail }}
                                </p>
                            </div>
                            <span
                                class="font-mono text-xs tabular-nums text-muted-foreground"
                            >
                                <template v-if="hop.duration_ms != null">
                                    {{ hop.duration_ms }}ms
                                </template>
                                · {{ hop.status }}
                            </span>
                        </li>
                    </ol>
                </CardContent>
            </Card>

            <Card
                v-if="
                    viewMode === 'physician' &&
                    similarCases &&
                    similarCases.length
                "
            >
                <CardHeader class="space-y-2">
                    <SectionTag>Similar cases</SectionTag>
                    <CardTitle class="text-lg">Embedding neighbors</CardTitle>
                </CardHeader>
                <CardContent class="space-y-2">
                    <a
                        v-for="c in similarCases"
                        :key="c.id"
                        :href="recordShow.url(c.id)"
                        class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border p-4 text-sm transition-colors hover:bg-muted/40"
                    >
                        <div>
                            <p class="font-semibold">{{ c.title }}</p>
                            <p class="text-muted-foreground">
                                {{ c.findings_preview }}
                            </p>
                        </div>
                        <span
                            class="font-mono text-xs tabular-nums text-muted-foreground"
                        >
                            {{ Math.round(c.score * 100) }}%
                            <template v-if="c.modality">
                                · {{ c.modality }}
                            </template>
                        </span>
                    </a>
                </CardContent>
            </Card>

            <Card v-if="viewMode === 'physician' && record.volume_meta">
                <CardHeader class="space-y-2">
                    <SectionTag>Volume</SectionTag>
                    <CardTitle class="text-lg">CT/MRI montage meta</CardTitle>
                </CardHeader>
                <CardContent class="space-y-2 font-mono text-xs tracking-wide">
                    <p>
                        <span class="text-muted-foreground">Slices</span>
                        {{ record.volume_meta.slice_count }}
                    </p>
                    <p>
                        <span class="text-muted-foreground">Used</span>
                        {{
                            Array.isArray(record.volume_meta.used_slices)
                                ? (
                                      record.volume_meta.used_slices as number[]
                                  ).join(', ')
                                : '-'
                        }}
                    </p>
                    <p class="text-muted-foreground">
                        {{ record.volume_meta.note }}
                    </p>
                </CardContent>
            </Card>

            <Card v-if="viewMode === 'physician' && record.patch_meta">
                <CardHeader class="space-y-2">
                    <SectionTag>Patches</SectionTag>
                    <CardTitle class="text-lg">Histopath grid</CardTitle>
                </CardHeader>
                <CardContent class="space-y-3">
                    <p class="font-mono text-xs tracking-wide">
                        {{ record.patch_meta.grid }} ·
                        {{ record.patch_meta.patch_count }} patches
                    </p>
                    <ul class="space-y-1 text-sm">
                        <li
                            v-for="(p, i) in patchList as Array<
                                Record<string, unknown>
                            >"
                            :key="i"
                            class="flex justify-between gap-2 border-b border-border py-1"
                        >
                            <span class="font-mono text-xs">{{ p.id }}</span>
                            <span class="text-muted-foreground">{{
                                p.finding || '-'
                            }}</span>
                        </li>
                    </ul>
                    <p class="font-mono text-xs text-muted-foreground">
                        {{ record.patch_meta.note }}
                    </p>
                </CardContent>
            </Card>

            <Card class="paper-panel--focal border-0 shadow-offset">
                <CardHeader class="space-y-2">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <SectionTag>Report</SectionTag>
                        <AnnotationPill
                            v-if="record.is_signed"
                            variant="teal"
                        >
                            Signed
                        </AnnotationPill>
                    </div>
                    <CardTitle class="text-lg">
                        {{
                            viewMode === 'physician'
                                ? 'Clinical report'
                                : 'What this means'
                        }}
                    </CardTitle>
                    <p
                        v-if="
                            viewMode === 'physician' &&
                            record.is_signed &&
                            record.signed_by_name
                        "
                        class="font-mono text-xs tracking-wide text-muted-foreground"
                    >
                        Signed by {{ record.signed_by_name }}
                        <template v-if="record.signed_at">
                            · {{ record.signed_at }}
                        </template>
                    </p>
                </CardHeader>
                <CardContent
                    class="max-w-prose space-y-4 text-sm leading-relaxed"
                >
                    <template
                        v-if="
                            viewMode === 'physician' &&
                            record.physician_report &&
                            editing
                        "
                    >
                        <div class="space-y-2">
                            <FieldLabel required>Summary</FieldLabel>
                            <Textarea
                                v-model="draftSummary"
                                rows="4"
                                placeholder="Clinical summary for the signed report"
                            />
                        </div>
                        <div class="space-y-2">
                            <FieldLabel>Recommendations</FieldLabel>
                            <Textarea
                                v-model="draftRecommendations"
                                rows="3"
                                placeholder="One recommendation per line"
                            />
                        </div>
                        <div class="space-y-2">
                            <FieldLabel>Technical notes</FieldLabel>
                            <Textarea
                                v-model="draftNotes"
                                rows="2"
                                placeholder="Pipeline or review notes"
                            />
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                :disabled="reportForm.processing"
                                @click="saveDraft"
                            >
                                Save draft
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                @click="editing = false"
                            >
                                Cancel
                            </Button>
                        </div>
                    </template>
                    <template
                        v-else-if="
                            viewMode === 'physician' && record.physician_report
                        "
                    >
                        <p>{{ record.physician_report.summary }}</p>
                        <div
                            v-if="
                                record.physician_report.differential_diagnosis
                            "
                        >
                            <h3
                                class="mb-2 font-mono text-xs font-semibold tracking-wide uppercase"
                            >
                                Differential diagnosis
                            </h3>
                            <ul class="space-y-1">
                                <li
                                    v-for="(ddx, i) in record.physician_report
                                        .differential_diagnosis as any[]"
                                    :key="i"
                                    class="flex justify-between tabular-nums"
                                >
                                    <span>{{ ddx.condition }}</span>
                                    <span
                                        class="font-mono text-muted-foreground"
                                    >
                                        {{ Math.round(ddx.confidence * 100) }}%
                                    </span>
                                </li>
                            </ul>
                        </div>
                        <div v-if="record.physician_report.recommendations">
                            <h3
                                class="mb-2 font-mono text-xs font-semibold tracking-wide uppercase"
                            >
                                Recommendations
                            </h3>
                            <ul
                                class="list-inside list-disc space-y-1 text-muted-foreground"
                            >
                                <li
                                    v-for="(rec, i) in record.physician_report
                                        .recommendations as string[]"
                                    :key="i"
                                >
                                    {{ rec }}
                                </li>
                            </ul>
                        </div>
                        <p
                            v-if="record.physician_report.technical_notes"
                            class="font-mono text-xs text-muted-foreground"
                        >
                            {{ record.physician_report.technical_notes }}
                        </p>
                        <div
                            v-if="record.can_edit_report"
                            class="flex flex-wrap gap-2 pt-2"
                        >
                            <Button
                                type="button"
                                variant="outline"
                                @click="editing = true"
                            >
                                Edit draft
                            </Button>
                            <Button type="button" @click="signDraft">
                                Sign report
                            </Button>
                        </div>
                    </template>
                    <template v-else-if="record.patient_report">
                        <p>{{ record.patient_report.summary }}</p>
                        <p class="text-muted-foreground">
                            {{ record.patient_report.what_this_means }}
                        </p>
                        <div v-if="record.patient_report.questions_for_doctor">
                            <h3
                                class="mb-2 font-mono text-xs font-semibold tracking-wide uppercase"
                            >
                                Questions for your doctor
                            </h3>
                            <ul
                                class="list-inside list-disc space-y-1 text-muted-foreground"
                            >
                                <li
                                    v-for="(q, i) in record.patient_report
                                        .questions_for_doctor as string[]"
                                    :key="i"
                                >
                                    {{ q }}
                                </li>
                            </ul>
                        </div>
                        <div v-if="record.patient_report.action_plan">
                            <h3
                                class="mb-2 font-mono text-xs font-semibold tracking-wide uppercase"
                            >
                                Action plan
                            </h3>
                            <ul
                                class="list-inside list-disc space-y-1 text-muted-foreground"
                            >
                                <li
                                    v-for="(a, i) in record.patient_report
                                        .action_plan as string[]"
                                    :key="i"
                                >
                                    {{ a }}
                                </li>
                            </ul>
                        </div>
                    </template>
                    <template
                        v-else-if="
                            viewMode === 'patient' &&
                            record.patient_report_withheld
                        "
                    >
                        <p class="text-muted-foreground">
                            A clinician is reviewing this result before it is
                            released to you.
                        </p>
                    </template>
                </CardContent>
            </Card>

            <Card v-if="record.citations?.length">
                <CardHeader class="space-y-2">
                    <SectionTag>Evidence</SectionTag>
                    <CardTitle class="text-lg">Guideline citations</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-wrap items-start gap-2">
                    <CitationChip
                        v-for="(citation, i) in record.citations"
                        :key="i"
                        :index="i + 1"
                        :citation="(citation as any)"
                    />
                </CardContent>
            </Card>

            <Card v-if="biomarkers.length">
                <CardHeader class="space-y-2">
                    <SectionTag>Labs</SectionTag>
                    <CardTitle class="text-lg">Extracted biomarkers</CardTitle>
                </CardHeader>
                <CardContent>
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="border-b border-border text-left font-mono text-xs tracking-wide text-muted-foreground uppercase"
                            >
                                <th class="pb-2 font-semibold">Test</th>
                                <th class="pb-2 font-semibold">Value</th>
                                <th class="pb-2 font-semibold">Reference</th>
                                <th class="pb-2 font-semibold">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="b in biomarkers"
                                :key="b.id"
                                class="border-b border-border"
                            >
                                <td class="py-2">{{ b.name }}</td>
                                <td class="py-2 font-mono tabular-nums">
                                    {{ b.value }} {{ b.unit }}
                                </td>
                                <td
                                    class="py-2 font-mono text-xs tabular-nums text-muted-foreground"
                                >
                                    <template
                                        v-if="
                                            b.reference_low != null &&
                                            b.reference_high != null
                                        "
                                    >
                                        {{ b.reference_low }}–{{
                                            b.reference_high
                                        }}
                                    </template>
                                    <template v-else>-</template>
                                </td>
                                <td class="py-2">
                                    <ClinicalBadge :status="b.status" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <MedicalDisclaimer />
        </template>

        <Alert v-else-if="record.status === 'failed'" variant="destructive">
            <AlertTitle>Analysis failed</AlertTitle>
            <AlertDescription>
                {{ record.error_message ?? 'Unknown error' }}
            </AlertDescription>
        </Alert>
    </div>
</template>
