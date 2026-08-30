export async function readSse(
    response: Response,
    onEvent: (data: Record<string, unknown>) => void,
): Promise<void> {
    const reader = response.body?.getReader();
    if (!reader) {
        throw new Error('No stream');
    }

    const decoder = new TextDecoder();
    let buffer = '';

    const consume = (chunk: string): void => {
        buffer += chunk.replace(/\r\n/g, '\n');
        let sep = buffer.indexOf('\n\n');
        while (sep !== -1) {
            emitBlock(buffer.slice(0, sep), onEvent);
            buffer = buffer.slice(sep + 2);
            sep = buffer.indexOf('\n\n');
        }
    };

    while (true) {
        const { done, value } = await reader.read();
        if (done) {
            break;
        }

        consume(decoder.decode(value, { stream: true }));
    }

    consume(decoder.decode());
    if (buffer.trim() !== '') {
        emitBlock(buffer, onEvent);
    }
}

function emitBlock(
    block: string,
    onEvent: (data: Record<string, unknown>) => void,
): void {
    for (const line of block.split('\n')) {
        if (!line.startsWith('data: ')) {
            continue;
        }

        try {
            const parsed = JSON.parse(line.slice(6));
            if (parsed && typeof parsed === 'object') {
                onEvent(parsed as Record<string, unknown>);
            }
        } catch {
            // Skip a malformed SSE chunk.
        }
    }
}
