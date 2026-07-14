<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { FileText, Plus } from '@lucide/vue';
import AnnotationPill from '@/components/patterns/AnnotationPill.vue';
import AtlasEmptyState from '@/components/patterns/AtlasEmptyState.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import SectionTag from '@/components/patterns/SectionTag.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    create as recordsCreate,
    index as recordsIndex,
    show as recordShow,
} from '@/routes/records';

defineProps<{
    records: {
        data: Array<{
            id: number;
            title: string;
            modality: string;
            modality_label: string;
            status: string;
            overall_confidence: number | null;
            patient_name: string;
            created_at: string;
        }>;
        links: unknown;
        meta: unknown;
    };
    isPhysician: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Medical Records', href: recordsIndex() }],
    },
});
</script>

<template>
    <Head title="Medical Records" />

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <PageHeader
                tag="Specimen archive"
                title="Medical Records"
                description="Upload and review multimodal medical artifacts"
            />
            <Button as-child>
                <Link :href="recordsCreate()">
                    <Plus class="size-4" />
                    Upload record
                </Link>
            </Button>
        </div>

        <Card>
            <CardHeader class="space-y-2">
                <SectionTag>Index</SectionTag>
                <CardTitle class="text-lg">All records</CardTitle>
            </CardHeader>
            <CardContent>
                <div v-if="records.data.length" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="border-b border-border text-left font-mono text-xs tracking-wide text-muted-foreground uppercase"
                            >
                                <th class="pb-3 font-semibold">Title</th>
                                <th
                                    v-if="isPhysician"
                                    class="pb-3 font-semibold"
                                >
                                    Patient
                                </th>
                                <th class="pb-3 font-semibold">Modality</th>
                                <th class="pb-3 font-semibold">Status</th>
                                <th class="pb-3 font-semibold">Confidence</th>
                                <th class="pb-3 font-semibold">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="record in records.data"
                                :key="record.id"
                                class="border-b border-border last:border-0"
                            >
                                <td class="py-3">
                                    <Link
                                        :href="recordShow(record.id)"
                                        class="font-semibold text-primary hover:underline"
                                    >
                                        {{ record.title }}
                                    </Link>
                                </td>
                                <td
                                    v-if="isPhysician"
                                    class="py-3 text-muted-foreground"
                                >
                                    {{ record.patient_name }}
                                </td>
                                <td class="py-3 font-mono text-xs tracking-wide">
                                    {{ record.modality_label }}
                                </td>
                                <td class="py-3">
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
                                </td>
                                <td
                                    class="py-3 font-mono tabular-nums"
                                >
                                    {{
                                        record.overall_confidence
                                            ? `${Math.round(record.overall_confidence * 100)}%`
                                            : '-'
                                    }}
                                </td>
                                <td
                                    class="py-3 font-mono text-xs text-muted-foreground tabular-nums"
                                >
                                    {{
                                        new Date(
                                            record.created_at,
                                        ).toLocaleDateString()
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <AtlasEmptyState
                    v-else
                    :icon="FileText"
                    title="No records yet"
                    description="Upload your first medical artifact to start analysis."
                >
                    <template #action>
                        <Button as-child>
                            <Link :href="recordsCreate()">
                                <Plus class="size-4" />
                                Upload record
                            </Link>
                        </Button>
                    </template>
                </AtlasEmptyState>
            </CardContent>
        </Card>
    </div>
</template>
