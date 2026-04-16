/**
 * Text processing utilities for HAWKI Translation.
 */

export class TextProcessor {
    /**
     * Instance wrapper for compatibility with object-oriented call sites.
     * @param {string} text
     * @returns {string[]}
     */
    splitIntoSentences(text) {
        return TextProcessor.splitIntoSentences(text);
    }

    /**
     * Instance wrapper for compatibility with object-oriented call sites.
     * @param {string} sentence
     * @returns {string[]}
     */
    getSentenceTokens(sentence) {
        return TextProcessor.getSentenceTokens(sentence);
    }

    /**
     * Instance wrapper for compatibility with object-oriented call sites.
     * @param {string} text
     * @returns {string}
     */
    escapeHtml(text) {
        return TextProcessor.escapeHtml(text);
    }

    /**
     * Splits text into sentences while preserving trailing punctuation and whitespace.
     * Uses an abbreviation-aware approach to prevent incorrect splits.
     * @param {string} text
     * @returns {string[]}
     */
    static splitIntoSentences(text) {
        if (!text) return [];

        // Protected HTML: if text contains tags, we split as chunks to preserve
        // all formatting (whitespace, newlines, indentation) between segments.
        if (text.includes('<') && text.includes('>') && /<[a-z/][^>]*>/i.test(text)) {
            // Capture everything up to a closing block tag (plus any trailing whitespace/newlines) or the end.
            const matches = text.match(/[\s\S]*?(?:<\/(?:p|h[1-6]|div|li|tr|section|article|header|footer|td|table|ul|ol|blockquote|pre|hr)>|-->)\s*|[\s\S]+/g);
            if (matches && matches.length > 0) return matches;
            return [text];
        }

        const abbrevs = [
            'z.b', 'u.a', 'd.h', 'bzw', 'etc', 'vgl', 'usw', 'ca', 'inkl', 'exkl', 
            'm.e', 'i.d.r', 'u.v.m', 'o.ä', 'u.ä', 's.o', 'v.a',
            'dr', 'prof', 'st', 'fr', 'hr', 'dipl', 'ing', 'mag', 'nr', 'no',
            'jan', 'feb', 'mrz', 'mär', 'apr', 'mai', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dez',
            'min', 'std', 'sek', 'tel', 's', 'p.a', 'v.v', 'a.d', 'o.g'
        ];

        const titleRegex = /^(dr|prof|st|fr|hr|dipl|ing|mag|nr|no)$/i;

        // 1. Initial split at potential ends of sentences (.!? followed by whitespace or end)
        const rawParts = text.match(/.*?([.!?]+(?:\s+|$))|.+$/sg) || [];
        const result = [];
        let buffer = '';

        rawParts.forEach((part, index) => {
            buffer += part;
            const currentTrimmed = buffer.trim();
            const nextPart = rawParts[index + 1] || '';
            const nextTrimmed = nextPart.trim();
            const nextFirstChar = nextTrimmed.charAt(0);
            
            const isNextUpper = nextFirstChar && /[A-ZÄÖÜ]/.test(nextFirstChar);
            
            const words = currentTrimmed.split(/\s+/);
            const lastPart = words[words.length - 1];
            const lastWord = lastPart.toLowerCase().replace(/\.+$/, '');
            const secondLastWord = words.length > 1 ? words[words.length - 2].toLowerCase().replace(/\.+$/, '') : '';

            const nextWords = nextTrimmed.split(/\s+/);
            const nextWordRaw = nextWords[0].replace(/\.+$/, '');
            const lookaheadCombined = (lastWord + '.' + nextWordRaw).replace(/\s/g, '').toLowerCase();

            let isAbbrev = abbrevs.includes(lastWord) || abbrevs.includes(lastPart.toLowerCase().replace(/[.]$/, ''));
            
            if (!isAbbrev && lastWord.length === 1 && /[a-z]/i.test(lastWord)) isAbbrev = true;

            const combined = (secondLastWord + '.' + lastWord).replace(/\s/g, ''); 
            if (abbrevs.includes(combined)) isAbbrev = true;
            
            const isDigit = /^\d+$/.test(lastWord);
            if (isDigit) isAbbrev = true;

            let shouldBreak = true;
            
            if (abbrevs.includes(lookaheadCombined) || lookaheadCombined === 'z.b') {
                shouldBreak = false;
            } else if (isAbbrev) {
                if (titleRegex.test(lastWord)) {
                    shouldBreak = false;
                } else if (!isNextUpper && nextPart) {
                    shouldBreak = false;
                } else if (isDigit && isNextUpper && nextPart.length > 1) {
                    shouldBreak = false;
                }
            }

            if (shouldBreak || !nextPart) {
                result.push(buffer);
                buffer = '';
            }
        });

        if (buffer) {
            result.push(buffer);
        }

        return result.length > 0 ? result : [text.trim()];
    }

    /**
     * Get tokens (words, tags, whitespace) for a sentence.
     * @param {string} sentence
     * @returns {string[]}
     */
    static getSentenceTokens(sentence) {
        if (!sentence) return [];
        return sentence.match(/<!--[\s\S]*?-->|<[^>]+>|https?:\/\/[^\s<]+|[\wÄÖÜäöüß]+(?:[-.'][\wÄÖÜäöüß]+)*|[^\w\sÄÖÜäöüäöüß<]+|\s+/g) || [];
    }

    /**
     * Escape HTML special characters.
     * @param {string} text
     * @returns {string}
     */
    static escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
