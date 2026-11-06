import { plural } from '@/lib/plural';

/**
 * How many files one document is made of, said in words: the two numbers a
 * paper is described by, read as one phrase.
 *
 * Both ends mean something of their own. The first says how few will do — «две
 * фотографии» is not answered by one — and the second how many are too many,
 * where there is such a number at all: a list of certificates is as long as
 * somebody's courses were, and a paper that says so leaves it empty.
 */
export function filesWanted(min: number, max: number | null): string {
    const files = (count: number) => plural(count, ['файл', 'файла', 'файлов']);

    if (max === null) {
        return min > 1 ? `от ${min} ${files(min)}` : 'сколько угодно файлов';
    }

    if (max === min) {
        return min === 1 ? 'один файл' : `${min} ${files(min)}`;
    }

    return min > 1 ? `от ${min} до ${max} файлов` : `до ${max} файлов`;
}

/**
 * How heavy one file is, in the unit somebody would say it in.
 *
 * Read beside a limit of ten megabytes, so the unit is the one that limit is
 * written in once a file is anywhere near it, and a smaller one below that:
 * «0,3 МБ» says less about a scan than «341 КБ» does. A tenth of a megabyte is
 * as fine as it is worth being — the figure is read to tell a photograph from a
 * whole album, not to add it up.
 */
export function fileSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} Б`;
    }

    const kilobytes = bytes / 1024;

    if (kilobytes < 1024) {
        return `${Math.round(kilobytes)} КБ`;
    }

    return `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 }).format(kilobytes / 1024)} МБ`;
}
