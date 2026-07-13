<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import FieldLabel from '@/components/patterns/FieldLabel.vue';
import PageHeader from '@/components/patterns/PageHeader.vue';
import SectionTag from '@/components/patterns/SectionTag.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { index as recordsIndex, store } from '@/routes/records';

defineProps<{
    modalities: Array<{ value: string; label: string }>;
    languages: Array<{ value: string; label: string }>;
    patients: Array<{ id: number; name: string }>;
}>();

const modality = ref('unknown');
const language = ref('en');
const patientId = ref('');

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Medical Records', href: recordsIndex() },
            { title: 'Upload' },
        ],
    },
});
</script>

<template>
    <Head title="Upload Record" />

    <div class="space-y-6">
        <PageHeader
            tag="New specimen"
            title="Upload medical record"
            description="X-ray, CT, MRI, histopathology, derm/ophth images, or lab PDFs"
        />

        <Form
            v-bind="store.form()"
            enctype="multipart/form-data"
            v-slot="{ errors, processing }"
            class="paper-panel paper-panel--focal space-y-5 p-6 md:p-8"
        >
            <SectionTag>Intake sheet</SectionTag>

            <input type="hidden" name="modality" :value="modality" />
            <input type="hidden" name="language" :value="language" />
            <input
                v-if="patientId"
                type="hidden"
                name="patient_id"
                :value="patientId"
            />

            <div class="space-y-2">
                <FieldLabel for="title" required>Record title</FieldLabel>
                <Input
                    id="title"
                    name="title"
                    required
                    placeholder="e.g. Chest X-ray, cough 2 weeks"
                />
                <InputError :message="errors.title" />
            </div>

            <div v-if="patients.length" class="space-y-2">
                <FieldLabel>Patient</FieldLabel>
                <Select v-model="patientId">
                    <SelectTrigger>
                        <SelectValue placeholder="Select patient" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="p in patients"
                            :key="p.id"
                            :value="String(p.id)"
                        >
                            {{ p.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="space-y-2">
                    <FieldLabel>Modality</FieldLabel>
                    <Select v-model="modality">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Auto-detect if unsure" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="m in modalities"
                                :key="m.value"
                                :value="m.value"
                            >
                                {{ m.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="space-y-2">
                    <FieldLabel>Report language</FieldLabel>
                    <Select v-model="language">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Select language" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="l in languages"
                                :key="l.value"
                                :value="l.value"
                            >
                                {{ l.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <div class="space-y-2">
                <FieldLabel for="file" required>File</FieldLabel>
                <Input
                    id="file"
                    name="file"
                    type="file"
                    required
                    accept=".jpg,.jpeg,.png,.pdf,.dcm,.zip"
                />
                <InputError :message="errors.file" />
                <p class="font-mono text-xs tracking-wide text-ink-faint">
                    JPG, PNG, PDF, or DICOM · Max 50 MB
                </p>
            </div>

            <Button type="submit" :disabled="processing" class="w-full">
                <Spinner v-if="processing" />
                Upload and analyze
            </Button>
        </Form>
    </div>
</template>
