/** Chapter and part numbers in a work's chosen style, as readers see them ("chapter eight", "Part II", "12"). */
const ONES = 'zero one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen'.split(' ');
const TENS = ',,twenty,thirty,forty,fifty,sixty,seventy,eighty,ninety'.split(',');

export function spell(n: number): string {
    if (n < 20) {
        return ONES[n];
    }
    if (n < 100) {
        return TENS[Math.floor(n / 10)] + (n % 10 ? '-' + ONES[n % 10] : '');
    }
    if (n < 1000) {
        return ONES[Math.floor(n / 100)] + ' hundred' + (n % 100 ? ' ' + spell(n % 100) : '');
    }

    return String(n);
}

export function roman(n: number): string {
    const table: [number, string][] = [
        [1000, 'M'], [900, 'CM'], [500, 'D'], [400, 'CD'], [100, 'C'], [90, 'XC'],
        [50, 'L'], [40, 'XL'], [10, 'X'], [9, 'IX'], [5, 'V'], [4, 'IV'], [1, 'I'],
    ];
    let out = '';
    for (const [v, c] of table) {
        while (n >= v) {
            out += c;
            n -= v;
        }
    }

    return out;
}

const cap = (s: string) => s.replace(/(^|[\s-])([a-z])/g, (_, a: string, b: string) => a + b.toUpperCase());

/**
 * One number in a style: chapter_word "Chapter Eight", chapter_word_lower "chapter eight", chapter_number "Chapter 8",
 * chapter_roman "Chapter VIII", word "Eight", number "8". `word` is "Chapter", "Part", "Book"…
 */
export function heading(n: number, word: string, style: string): string {
    switch (style) {
        case 'chapter_word':
            return `${word} ${cap(spell(n))}`;
        case 'chapter_word_lower':
            return `${word.toLowerCase()} ${spell(n)}`;
        case 'chapter_roman':
            return `${word} ${roman(n)}`;
        case 'word':
            return cap(spell(n));
        case 'number':
            return String(n);
        default:
            return `${word} ${n}`;
    }
}
