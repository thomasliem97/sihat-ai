function nextIndex(text: string, index: number): number {
    if (index >= text.length) {
        return text.length;
    }

    const code = text.charCodeAt(index);

    if (code >= 0xd800 && code <= 0xdbff && index + 1 < text.length) {
        return index + 2;
    }

    return index + 1;
}

export function typewriterAdvance(
    shown: string,
    target: string,
    elapsedMs: number,
    reducedMotion = false,
): string {
    if (reducedMotion || shown === target) {
        return target;
    }

    if (!target.startsWith(shown)) {
        return target;
    }

    const remaining = target.length - shown.length;

    if (remaining <= 0) {
        return target;
    }

    const msPerChar = remaining > 200 ? 4 : remaining > 80 ? 8 : 16;
    let budget = Math.max(1, Math.floor(elapsedMs / msPerChar));
    let index = shown.length;

    while (budget > 0 && index < target.length) {
        index = nextIndex(target, index);
        budget -= 1;
    }

    return target.slice(0, index);
}

function assertTypewriter(): void {
    const eq = (actual: unknown, expected: unknown, label: string): void => {
        if (actual !== expected) {
            throw new Error(
                `${label}: ${JSON.stringify(actual)} !== ${JSON.stringify(expected)}`,
            );
        }
    };

    eq(
        typewriterAdvance('', 'Hello', 16, true),
        'Hello',
        'reduced motion snaps',
    );
    eq(
        typewriterAdvance('', 'Hello', 16, false),
        'H',
        'one character per tick',
    );
    eq(
        typewriterAdvance('H', 'Hello', 16, false),
        'He',
        'continues from prefix',
    );
    eq(
        typewriterAdvance('', 'x'.repeat(50), 16, false).length,
        1,
        'one character while backlog is small',
    );
    eq(
        typewriterAdvance('', 'y'.repeat(90), 16, false).length,
        2,
        'catch-up at 80+ queued',
    );
    eq(
        typewriterAdvance('', 'z'.repeat(210), 16, false).length,
        4,
        'burst at 200+ queued',
    );
    eq(typewriterAdvance('', 'A👍B', 16, false), 'A', 'ascii first');
    eq(
        typewriterAdvance('A', 'A👍B', 16, false),
        'A👍',
        'keeps surrogate pair intact',
    );
    eq(
        typewriterAdvance('Hi', 'Other', 16, false),
        'Other',
        'diverged target snaps',
    );
}

const nodeProcess = (globalThis as { process?: { argv?: string[] } }).process;

if (nodeProcess?.argv?.[1]?.includes('typewriter')) {
    assertTypewriter();
}
