/** Pinyin tone numbers to marks, as she types: "Xu2 Wei1" → "Xú Wēi" (v or ü for ü; 5 is the light tone). */
const TONE: Record<string, string> = { a: 'āáǎà', e: 'ēéěè', i: 'īíǐì', o: 'ōóǒò', u: 'ūúǔù', ü: 'ǖǘǚǜ' };

export function tone(s: string): string {
    return s.replace(/([A-Za-zÜüVv]+)([1-5])/g, (_m, word: string, digit: string) => {
        const d = Number(digit);
        const syl = word.replace(/v/g, 'ü').replace(/V/g, 'Ü');
        if (d === 5) {
            return syl;
        }
        const lo = syl.toLowerCase();
        let i = lo.indexOf('a');
        if (i < 0) {
            i = lo.indexOf('e');
        }
        if (i < 0 && lo.includes('ou')) {
            i = lo.indexOf('o');
        }
        if (i < 0) {
            for (let k = lo.length - 1; k >= 0; k--) {
                if ('aeiouü'.includes(lo[k])) {
                    i = k;
                    break;
                }
            }
        }
        if (i < 0) {
            return syl;
        }
        const mark = TONE[lo[i]][d - 1];

        return syl.slice(0, i) + (syl[i] !== lo[i] ? mark.toUpperCase() : mark) + syl.slice(i + 1);
    });
}

/** The name without marks, as typed plainly ("Xú Wēi" → "Xu Wei"). */
export const plainName = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').normalize('NFC');

/** The tone-mark row under a name field. */
export const ACCENTS = 'āáǎàēéěèīíǐìōóǒòūúǔùǖǘǚǜ'.split('');
