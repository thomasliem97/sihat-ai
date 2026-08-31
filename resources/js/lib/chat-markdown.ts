function escapeHtml(text: string): string {
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function applyBold(text: string): string {
    return text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
}

export function chatMarkdownHtml(source: string): string {
    const lines = escapeHtml(source).split(/\n/);
    const out: string[] = [];
    let items: string[] = [];
    let para: string[] = [];

    const flushList = (): void => {
        if (items.length === 0) {
            return;
        }

        out.push(
            `<ul>${items.map((item) => `<li>${applyBold(item)}</li>`).join('')}</ul>`,
        );
        items = [];
    };

    const flushPara = (): void => {
        if (para.length === 0) {
            return;
        }

        out.push(`<p>${applyBold(para.join('<br>'))}</p>`);
        para = [];
    };

    for (const line of lines) {
        const bullet = line.match(/^\s*[*+-]\s+(.*)$/);
        if (bullet) {
            flushPara();
            items.push(bullet[1] ?? '');
            continue;
        }

        flushList();

        if (line.trim() === '') {
            flushPara();
            continue;
        }

        para.push(line);
    }

    flushList();
    flushPara();

    return out.join('');
}

function eq(actual: string, expected: string, label: string): void {
    if (actual !== expected) {
        throw new Error(`${label}: ${JSON.stringify(actual)}`);
    }
}

function assertChatMarkdown(): void {
    eq(
        chatMarkdownHtml('**How high is the fever?**'),
        '<p><strong>How high is the fever?</strong></p>',
        'bold',
    );
    eq(
        chatMarkdownHtml('* one\n* two'),
        '<ul><li>one</li><li>two</li></ul>',
        'list',
    );
    eq(
        chatMarkdownHtml('<script>alert(1)</script>'),
        '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>',
        'escapes html',
    );
}

const nodeProcess = (globalThis as { process?: { argv?: string[] } }).process;

if (nodeProcess?.argv?.[1]?.includes('chat-markdown')) {
    assertChatMarkdown();
    console.log('OK chat-markdown');
}
