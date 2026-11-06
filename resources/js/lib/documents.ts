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
