/**
 * textDiff.js — Word-level diff engine for the Show Changes feature.
 *
 * Exposes a single global: window.TextDiff
 *
 * Usage:
 *   const ops = TextDiff.compute(oldText, newText);
 *   const html = TextDiff.renderHTML(ops);
 *   const highlightHtml = TextDiff.renderHighlightHTML(ops);
 */
window.TextDiff = (() => {

    /**
     * Tokenize text into words and punctuation, each with trailing spaces.
     * Punctuation is split from words so that "Sometimes," becomes
     * ["Sometimes", ", "] — allowing the word to match independently.
     * Newlines are separate tokens.
     *
     * Example: "Sometimes, errors" → ["Sometimes", ", ", "errors"]
     */
    function tokenize(text) {
        // Match: (word chars + trailing spaces) | (punctuation + trailing spaces) | (newline)
        const tokens = text.match(/[\w\u00C0-\u024F''-]+[ \t]*|[^\w\u00C0-\u024F'' \t\n]+[ \t]*|\n/g);
        return tokens || [];
    }

    /**
     * Normalize a token for comparison by trimming trailing whitespace.
     * This prevents "Sometimes " ≠ "Sometimes" mismatches caused by
     * punctuation changes shifting the trailing space.
     */
    function norm(token) {
        return token.trimEnd();
    }

    /**
     * Compute a word-level diff between oldText and newText
     * using the Longest Common Subsequence (LCS) algorithm.
     *
     * @param {string} oldText
     * @param {string} newText
     * @returns {Array<{type: 'equal'|'delete'|'insert', text: string}>}
     */
    function compute(oldText, newText) {
        const oldTokens = tokenize(oldText);
        const newTokens = tokenize(newText);

        const m = oldTokens.length;
        const n = newTokens.length;

        // Build LCS table
        const dp = Array.from({ length: m + 1 }, () => new Uint16Array(n + 1));
        for (let i = 1; i <= m; i++) {
            for (let j = 1; j <= n; j++) {
                if (norm(oldTokens[i - 1]) === norm(newTokens[j - 1])) {
                    dp[i][j] = dp[i - 1][j - 1] + 1;
                } else {
                    dp[i][j] = Math.max(dp[i - 1][j], dp[i][j - 1]);
                }
            }
        }

        // Backtrack to produce diff operations
        const ops = [];
        let i = m, j = n;
        while (i > 0 || j > 0) {
            if (i > 0 && j > 0 && norm(oldTokens[i - 1]) === norm(newTokens[j - 1])) {
                ops.unshift({ type: 'equal', text: oldTokens[i - 1] });
                i--; j--;
            } else if (j > 0 && (i === 0 || dp[i][j - 1] >= dp[i - 1][j])) {
                ops.unshift({ type: 'insert', text: newTokens[j - 1] });
                j--;
            } else {
                ops.unshift({ type: 'delete', text: oldTokens[i - 1] });
                i--;
            }
        }

        // Merge consecutive operations of the same type
        const merged = [];
        for (const op of ops) {
            if (merged.length > 0 && merged[merged.length - 1].type === op.type) {
                merged[merged.length - 1].text += op.text;
            } else {
                merged.push({ ...op });
            }
        }

        return merged;
    }

    /**
     * Escape HTML special characters.
     */
    function escapeHtml(text) {
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    /**
     * Wrap text in a span, keeping trailing whitespace OUTSIDE the span
     * so that highlights/strikethroughs only cover the actual content.
     */
    function wrapSpan(className, text) {
        const trimmed = text.replace(/[ \t]+$/, '');
        const trailing = text.slice(trimmed.length);
        return `<span class="${className}">${escapeHtml(trimmed)}</span>${escapeHtml(trailing)}`;
    }

    /**
     * Render a list of diff operations into an inline HTML string.
     *
     * Collects consecutive delete/insert blocks and pairs them 1:1
     * so that each deletion is shown directly next to its replacement.
     *
     * @param {Array<{type: string, text: string}>} ops
     * @returns {string} HTML
     */
    function renderHTML(ops) {
        let html = '';
        let k = 0;

        while (k < ops.length) {
            const op = ops[k];

            if (op.type === 'equal') {
                html += escapeHtml(op.text);
                k++;
            } else {
                // Collect consecutive deletes
                const deletes = [];
                while (k < ops.length && ops[k].type === 'delete') {
                    deletes.push(ops[k]);
                    k++;
                }
                // Collect consecutive inserts
                const inserts = [];
                while (k < ops.length && ops[k].type === 'insert') {
                    inserts.push(ops[k]);
                    k++;
                }

                // Pair deletes with inserts 1:1
                const maxPairs = Math.max(deletes.length, inserts.length);
                for (let p = 0; p < maxPairs; p++) {
                    if (p < deletes.length && p < inserts.length) {
                        // Paired: del → ins
                        html += wrapSpan('diff-del', deletes[p].text);
                        html += `<span class="diff-arrow">&nbsp;\u2192&nbsp;</span>`;
                        html += wrapSpan('diff-ins', inserts[p].text);
                    } else if (p < deletes.length) {
                        html += wrapSpan('diff-del', deletes[p].text);
                    } else {
                        html += wrapSpan('diff-ins', inserts[p].text);
                    }
                }
            }
        }

        return html;
    }

    /**
     * Render only the NEW text, with changed/inserted words underlined in green.
     * Deletions are omitted (they only appear in the old text).
     *
     * Collects consecutive delete/insert blocks and pairs them 1:1
     * to correctly underline only the replacement text.
     *
     * @param {Array<{type: string, text: string}>} ops
     * @returns {string} HTML
     */
    function renderHighlightHTML(ops) {
        let html = '';
        let k = 0;

        while (k < ops.length) {
            const op = ops[k];

            if (op.type === 'equal') {
                html += escapeHtml(op.text);
                k++;
            } else {
                // Collect consecutive deletes
                const deletes = [];
                while (k < ops.length && ops[k].type === 'delete') {
                    deletes.push(ops[k]);
                    k++;
                }
                // Collect consecutive inserts
                const inserts = [];
                while (k < ops.length && ops[k].type === 'insert') {
                    inserts.push(ops[k]);
                    k++;
                }

                // Only render inserts (with highlight), skip deletes
                for (const ins of inserts) {
                    html += wrapSpan('diff-highlight', ins.text);
                }
            }
        }

        return html;
    }

    // Public API
    return { compute, renderHTML, renderHighlightHTML };

})();
