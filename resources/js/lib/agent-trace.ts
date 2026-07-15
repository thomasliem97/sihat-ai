const GUARDRAIL_CODES: Record<string, string> = {
    ALLOW: 'Allowed to proceed',
    WARN: 'Proceed with caution',
};

const GUARDRAIL_FLAGS: Record<string, string> = {
    medical_disclaimer_required: 'Medical disclaimer required',
    not_a_diagnosis: 'Not a diagnosis',
    confidence_publish: 'Confidence high enough to publish',
    confidence_hedge: 'Mid confidence; patient language softened',
    low_confidence_abstention: 'Low confidence; patient report withheld',
    critical_value_escalation:
        'Critical finding; escalate and withhold patient copy',
    weak_guideline_grounding: 'Guideline grounding was weak',
};

const HOP_LABELS: Record<string, string> = {
    router: 'Chose study type',
    deidentify: 'Removed patient identifiers',
    imaging_specialist: 'Analyzed the image',
    doc_specialist: 'Analyzed the document',
    merge: 'Combined findings',
    rag: 'Found supporting guidelines',
    guardrail: 'Ran safety checks',
    compose: 'Wrote the reports',
};

const STATUS_LABELS: Record<string, string> = {
    completed: 'Done',
    skipped: 'Skipped',
    failed: 'Failed',
    pending: 'Pending',
    running: 'Running',
};

const MODALITY_LABELS: Record<string, string> = {
    xray: 'Chest X-ray',
    ct: 'CT Scan',
    mri: 'MRI',
    histopath: 'Histopathology',
    dermatology: 'Dermatology',
    ophthalmology: 'Ophthalmology',
    lab_pdf: 'Lab Report (PDF)',
    clinical_document: 'Clinical Document (PDF)',
    unknown: 'Unknown',
};

const LANGUAGE_LABELS: Record<string, string> = {
    en: 'English',
    ms: 'Bahasa Melayu',
    zh: 'Mandarin',
    ta: 'Tamil',
};

/**
 * Turn stored agent-hop keys into plain-language step titles.
 */
export function formatAgentHopLabel(hop: string): string {
    return HOP_LABELS[hop] ?? titleCase(hop.replaceAll('_', ' '));
}

/**
 * Turn hop status codes into readable labels.
 */
export function formatAgentHopStatus(status: string): string {
    return STATUS_LABELS[status] ?? titleCase(status.replaceAll('_', ' '));
}

/**
 * Turn stored agent-hop detail strings into plain language.
 * Handles both new humanized text and legacy machine strings.
 */
export function formatAgentHopDetail(detail: string): string {
    const trimmed = detail.trim();
    if (trimmed === '') {
        return 'No details recorded.';
    }

    const guardrail = trimmed.match(/^(ALLOW|WARN):(.+)$/i);
    if (guardrail) {
        const code =
            GUARDRAIL_CODES[guardrail[1].toUpperCase()] ?? guardrail[1];
        const flags = guardrail[2]
            .split(',')
            .map((flag) => flag.trim())
            .filter(Boolean)
            .map(
                (flag) =>
                    GUARDRAIL_FLAGS[flag] ??
                    titleCase(flag.replaceAll('_', ' ')),
            );

        return flags.length > 0
            ? `${code}. ${flags.join('; ')}.`
            : `${code}.`;
    }

    const known: Record<string, string> = {
        'safe_uri sibling ready':
            'Created a de-identified copy for analysis.',
        'de-identify completed': 'Patient identifiers were removed.',
        'De-identification completed':
            'Patient identifiers were removed.',
        'Created a de-identified copy for analysis':
            'Created a de-identified copy for analysis.',
        'partial_findings merged':
            'Combined specialist findings into one result set.',
        'Combined specialist findings into one result set':
            'Combined specialist findings into one result set.',
    };

    if (known[trimmed]) {
        return known[trimmed];
    }

    const modality =
        trimmed.match(/^Modality (.+)$/i) ??
        trimmed.match(/^Detected modality:\s*(.+)$/i);
    if (modality) {
        return `Detected study type: ${formatModalityLabel(modality[1])}.`;
    }

    const citations =
        trimmed.match(/^(\d+) citations \(BM25\+dense\+MMR\)$/i) ??
        trimmed.match(/^Retrieved (\d+) guideline citation\(s\)$/i);
    if (citations) {
        const count = Number(citations[1]);
        return count === 0
            ? 'No matching guideline citations were found.'
            : `Retrieved ${count} supporting guideline citation${count === 1 ? '' : 's'}.`;
    }

    const imaging =
        trimmed.match(/^(\d+) imaging findings$/i) ??
        trimmed.match(/^Found (\d+) imaging finding\(s\)$/i);
    if (imaging) {
        const count = Number(imaging[1]);
        return `Found ${count} imaging finding${count === 1 ? '' : 's'}.`;
    }

    const document =
        trimmed.match(/^(\d+) document findings$/i) ??
        trimmed.match(/^Found (\d+) document finding\(s\)$/i);
    if (document) {
        const count = Number(document[1]);
        return `Found ${count} document finding${count === 1 ? '' : 's'}.`;
    }

    const skipped =
        trimmed.match(/^N\/A for (.+)$/i) ??
        trimmed.match(/^Not used for (.+) studies$/i);
    if (skipped) {
        return `Skipped for ${formatModalityLabel(skipped[1].replace(/ studies$/i, ''))} studies.`;
    }

    const compose =
        trimmed.match(/^(\w+) dual reports$/i) ??
        trimmed.match(
            /^Wrote physician and patient reports \((.+)\)$/i,
        );
    if (compose) {
        return `Wrote physician and patient reports in ${formatLanguageLabel(compose[1])}.`;
    }

    // Last-resort cleanup for leftover snake_case tokens.
    if (trimmed.includes('_') && !trimmed.includes(' ')) {
        return `${titleCase(trimmed.replaceAll('_', ' '))}.`;
    }

    return /[.!?]$/.test(trimmed) ? trimmed : `${trimmed}.`;
}

/**
 * Normalize physician-report technical notes for display, including legacy machine strings.
 */
export function formatTechnicalNotes(detail: string): string {
    const trimmed = detail.trim();
    if (trimmed === '') {
        return '';
    }

    const legacy = trimmed.match(
        /^Analysis engine=([^;]+);\s*adapter=([^;]+);\s*modality=([^;]+);\s*guardrail=([A-Z]+)\.\s*RAG:.*$/i,
    );

    if (legacy) {
        const engine = legacy[1].trim();
        const adapter = legacy[2].trim();
        const modality = legacy[3].trim();
        const code = legacy[4].trim().toUpperCase();

        const engineLabel = engine.includes('+')
            ? 'MedGemma + secondary LLM'
            : /medgemma/i.test(engine)
              ? 'MedGemma'
              : engine;

        const adapterLabel =
            adapter === 'none' || adapter === ''
                ? 'none'
                : adapter.startsWith('loaded:')
                  ? 'LoRA (loaded)'
                  : adapter === 'configured'
                    ? 'LoRA (configured)'
                    : 'LoRA';

        let text = `Engine: ${engineLabel}. Adapter: ${adapterLabel}. Modality: ${modality}. Guardrail: ${code}. Retrieval: hybrid RAG.`;

        if (/WARN:\s*patient-facing/i.test(trimmed)) {
            text += ' Patient-facing prose vetoed (WARN).';
        }

        return text;
    }

    return trimmed
        .replace(
            /^Analyzed with (.+?) using (.+?)\. Study type: (.+?)\. (?:Safety checks passed|Safety checks raised a warning)\. Supporting guidelines were retrieved with hybrid search\./i,
            (
                _match: string,
                engine: string,
                adapter: string,
                modality: string,
            ) => {
                const engineLabel = /secondary language model/i.test(engine)
                    ? 'MedGemma + secondary LLM'
                    : engine;
                const adapterLabel = /no custom adapter/i.test(adapter)
                    ? 'none'
                    : /loaded custom adapter/i.test(adapter)
                      ? 'LoRA (loaded)'
                      : /configured custom adapter/i.test(adapter)
                        ? 'LoRA (configured)'
                        : 'LoRA';
                const code = /raised a warning/i.test(trimmed)
                    ? 'WARN'
                    : 'ALLOW';

                return `Engine: ${engineLabel}. Adapter: ${adapterLabel}. Modality: ${modality}. Guardrail: ${code}. Retrieval: hybrid RAG.`;
            },
        )
        .replace(
            /Patient-facing wording was withheld after a safety warning\.?/i,
            'Patient-facing prose vetoed (WARN).',
        )
        .replace(
            /WARN:\s*patient-facing model prose vetoed\.?/i,
            'Patient-facing prose vetoed (WARN).',
        )
        .replace(
            /Report withheld from automatic patient release due to low confidence or weak guideline grounding\.?/i,
            'Patient report withheld: low confidence or weak guideline grounding.',
        )
        .replace(
            /Patient report was withheld because confidence was too low or guideline grounding was weak\.?/i,
            'Patient report withheld: low confidence or weak guideline grounding.',
        );
}

/**
 * Format hop durations: ms under 1s, seconds under 1m, otherwise minutes.
 */
export function formatDurationMs(durationMs: number): string {
    if (durationMs < 1) {
        return '<1 ms';
    }

    if (durationMs < 1000) {
        return `${Math.round(durationMs)} ms`;
    }

    const seconds = durationMs / 1000;
    if (seconds < 60) {
        const rounded =
            seconds >= 10 ? Math.round(seconds) : Number(seconds.toFixed(1));

        return `${rounded} sec`;
    }

    const minutes = seconds / 60;
    const rounded =
        minutes >= 10 ? Math.round(minutes) : Number(minutes.toFixed(1));

    return `${rounded} min`;
}

function formatModalityLabel(value: string): string {
    const key = value.trim().toLowerCase().replaceAll(' ', '_');
    return MODALITY_LABELS[key] ?? titleCase(value.replaceAll('_', ' '));
}

function formatLanguageLabel(value: string): string {
    const key = value.trim().toLowerCase();
    return LANGUAGE_LABELS[key] ?? titleCase(value);
}

function titleCase(value: string): string {
    return value
        .split(/\s+/)
        .filter(Boolean)
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}
