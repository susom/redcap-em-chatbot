import React, { useState } from 'react';

const parseMdTable = (md) => {
    if (!md) return null;
    const lines = md.trim().split('\n').filter(l => l.trim().startsWith('|'));
    if (lines.length < 2) return null;
    // Split rows on UNESCAPED pipes. Markdown escapes pipes as \| so we swap
    // them with a NUL placeholder, split on raw |, then restore. Without
    // this, a value like 'before | after' (escaped as 'before \| after') gets
    // parsed as TWO columns, breaking the table layout.
    const parseRow = (l) => {
        const trimmed = l.trim().replace(/^\|/, '').replace(/\|$/, '');
        return trimmed
            .replace(/\\\|/g, '\x00')
            .split('|')
            .map(c => c.trim().replace(/\x00/g, '|'));
    };
    return { cols: parseRow(lines[0]), rows: lines.slice(2).map(parseRow) };
};

export const PaginatedTable = ({ paging }) => {
    const initial = parseMdTable(paging.preview_markdown);
    const pageSize = initial ? Math.max(1, initial.rows.length) : 20;

    const [offset, setOffset] = useState(0);
    const [markdown, setMarkdown] = useState(paging.preview_markdown);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const total = paging.total || 0;
    const table = parseMdTable(markdown);
    const page = Math.floor(offset / pageSize) + 1;
    const pages = Math.max(1, Math.ceil(total / pageSize));

    const goTo = (newOffset) => {
        setBusy(true);
        setError(null);
        window.chatbot_jsmo_module.cappyPage({
            pid: window.cappy_project_config?.pid,
            reference: paging.reference,
            offset: newOffset,
            limit: pageSize
        }, (res) => {
            setBusy(false);
            if (res && !res.error && res.preview_markdown) {
                setMarkdown(res.preview_markdown);
                setOffset(res.offset);
            } else {
                setError(res?.message || 'Could not load that page (cache may have expired).');
            }
        }, () => {
            setBusy(false);
            setError('Paging request failed.');
        });
    };

    if (!table) return null;

    return (
        <div className="cappy-paginated-table">
            <table>
                <thead>
                    <tr>{table.cols.map((c, i) => <th key={i}>{c}</th>)}</tr>
                </thead>
                <tbody>
                    {table.rows.map((r, i) => (
                        <tr key={i}>{r.map((c, j) => <td key={j} title={c}>{c}</td>)}</tr>
                    ))}
                </tbody>
            </table>
            {total > pageSize && (
                <div className="cappy-pagination">
                    <button type="button" disabled={busy || offset <= 0}
                        onClick={() => goTo(Math.max(0, offset - pageSize))}>‹ Prev</button>
                    <span className="cappy-pagination-info">
                        {busy
                            ? 'Loading…'
                            : `Rows ${total ? offset + 1 : 0}–${Math.min(offset + pageSize, total)} of ${total} · page ${page}/${pages}`}
                    </span>
                    <button type="button" disabled={busy || offset + pageSize >= total}
                        onClick={() => goTo(offset + pageSize)}>Next ›</button>
                </div>
            )}
            {error && <div className="cappy-pagination-error">{error}</div>}
        </div>
    );
};

export default PaginatedTable;
