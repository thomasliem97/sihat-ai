export type FindingMeasurementRow = {
    name: string;
    value: string;
    reference: string | null;
    status: string | null;
};

type BiomarkerLike = {
    name: string;
    status: string;
};

/**
 * Turn packed finding value/reference strings into table rows when possible.
 * Falls back to null for simple single-value findings.
 */
export function parseFindingMeasurements(
    value: unknown,
    reference?: unknown,
    biomarkers: BiomarkerLike[] = [],
): FindingMeasurementRow[] | null {
    const valueText = normalizeLabGlyphs(String(value ?? '').trim());
    if (valueText === '') {
        return null;
    }

    const valueParts = valueText
        .split(';')
        .map((part) => part.trim())
        .filter(Boolean);

    if (valueParts.length < 2) {
        return null;
    }

    const namedParts = valueParts
        .map(splitNamedMeasurement)
        .filter((row): row is { name: string; value: string } => row !== null);

    if (namedParts.length < 2) {
        return null;
    }

    const refByName = new Map<string, string>();
    for (const part of String(reference ?? '')
        .split(';')
        .map((item) => item.trim())
        .filter(Boolean)) {
        const parsed = splitNamedMeasurement(normalizeLabGlyphs(part));
        if (parsed) {
            refByName.set(normalizeKey(parsed.name), parsed.value);
        }
    }

    const biomarkerByKey = new Map(
        biomarkers.map((marker) => [normalizeKey(marker.name), marker.status]),
    );

    return namedParts.map((row) => {
        const referenceText = refByName.get(normalizeKey(row.name)) ?? null;

        return {
            name: row.name,
            value: row.value,
            reference: referenceText,
            status: resolveMeasurementStatus(
                row.name,
                row.value,
                referenceText,
                biomarkerByKey,
            ),
        };
    });
}

export function resolveMeasurementStatus(
    name: string,
    valueText: string,
    referenceText: string | null,
    biomarkerByKey: Map<string, string>,
): string | null {
    for (const key of measurementKeys(name)) {
        const status = biomarkerByKey.get(key);
        if (status) {
            return status;
        }
    }

    const numericValue = parseLeadingNumber(valueText);
    const range = parseReferenceRange(referenceText);
    if (numericValue === null || range === null) {
        return null;
    }

    if (numericValue < range.low || numericValue > range.high) {
        return 'abnormal';
    }

    return 'normal';
}

function measurementKeys(name: string): string[] {
    const key = normalizeKey(name);
    const aliases: Record<string, string[]> = {
        haemoglobin: ['haemoglobin', 'hemoglobin', 'hb', 'hgb'],
        hemoglobin: ['haemoglobin', 'hemoglobin', 'hb', 'hgb'],
        hb: ['haemoglobin', 'hemoglobin', 'hb', 'hgb'],
        hgb: ['haemoglobin', 'hemoglobin', 'hb', 'hgb'],
        hct: ['hct', 'haematocrit', 'hematocrit'],
        haematocrit: ['hct', 'haematocrit', 'hematocrit'],
        hematocrit: ['hct', 'haematocrit', 'hematocrit'],
        rbc: ['rbc', 'redbloodcell', 'redbloodcells'],
        wbc: ['wbc', 'whitebloodcell', 'whitebloodcells'],
    };

    return aliases[key] ?? [key];
}

function parseLeadingNumber(text: string): number | null {
    const match = text.match(/-?\d+(?:\.\d+)?/);
    if (!match) {
        return null;
    }

    const value = Number(match[0]);
    return Number.isFinite(value) ? value : null;
}

function parseReferenceRange(
    text: string | null,
): { low: number; high: number } | null {
    if (!text) {
        return null;
    }

    const match = text.match(
        /(-?\d+(?:\.\d+)?)\s*[-–—to]+\s*(-?\d+(?:\.\d+)?)/i,
    );
    if (!match) {
        return null;
    }

    const low = Number(match[1]);
    const high = Number(match[2]);
    if (!Number.isFinite(low) || !Number.isFinite(high)) {
        return null;
    }

    return { low, high };
}

function splitNamedMeasurement(
    part: string,
): { name: string; value: string } | null {
    const match = part.match(
        /^([A-Za-z][A-Za-z0-9%/().\s-]{0,40}?)\s+(\d.*)$/,
    );
    if (!match) {
        return null;
    }

    return {
        name: match[1].trim(),
        value: match[2].trim(),
    };
}

function normalizeKey(name: string): string {
    return name.toLowerCase().replaceAll(/[^a-z0-9]/g, '');
}

/** Repair common OCR where % becomes a trailing "8". */
function normalizeLabGlyphs(text: string): string {
    return text.replaceAll(/(\d(?:\.\d+)?)\s+8\b/g, '$1 %');
}
