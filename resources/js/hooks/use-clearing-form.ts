import { type FormDataType } from '@inertiajs/core';
import { useForm } from '@inertiajs/react';
import { useCallback, useRef } from 'react';

/** Read loosely inside, where a form is only a bag of named answers. */
type Bag = Record<string, unknown>;

/** One row of a list, as a form holds it while it is being typed. */
const isRow = (value: unknown): value is Bag => typeof value === 'object' && value !== null && !Array.isArray(value);

/**
 * A form whose fields stop being wrong the moment they are being put right.
 *
 * Inertia keeps the server's complaints until the next send, so a box stays red
 * while somebody types the very answer it asked for, and the sentence under it
 * goes on saying what is no longer true. Here, writing a field takes that
 * field's complaint away.
 *
 * Only that field's. The rest stand until the form is sent again: clearing them
 * all at the first keystroke would hide, from somebody fixing one line, that
 * three others are still wrong — and they would learn it by pressing «Сохранить»
 * and being sent back a second time.
 *
 * It is done here rather than in every onChange because there are hundreds of
 * those, and one forgotten is a box that stays red however it is corrected.
 */
export function useClearingForm<T extends FormDataType<T>>(initial: T) {
    const form = useForm<T>(initial);
    const { setData, clearErrors } = form;

    // Read at the moment of writing rather than closed over, and only cleared
    // where there is something to clear: clearErrors() on a field with nothing
    // against it still rebuilds the errors bag, and a form rebuilt on every
    // keystroke is how a cursor leaves the box it was in.
    const complaints = useRef(form.errors);
    complaints.current = form.errors;

    const held = useRef(form.data);
    held.current = form.data;

    const write = useCallback(
        (keyOrData: unknown, value?: unknown) => {
            /**
             * Which complaints one written answer puts right.
             *
             * A plain field is its own name and nothing else. A list is not:
             * the form holds every row of it under one name and rewrites the
             * whole array to change one box, while the server complains about
             * the box — «educations.2.institution», «degrees.0.name». Clearing
             * the name of the list left those standing, so a row typed back
             * into shape stayed red with a sentence under it that was no longer
             * true, until the sheet was sent again. That is what this walks
             * into the rows to find.
             *
             * What it does not do is clear the list wholesale. A card refused
             * over three rows is refused over three rows, and fixing the first
             * must not make the other two look answered — the same reason the
             * rest of the form's complaints stand.
             */
            const answered = (key: string, now: unknown, was: unknown): string[] => {
                if (!Array.isArray(now) || !Array.isArray(was)) {
                    return [key];
                }

                // A row added or taken away shifts every row after it, and the
                // complaints are keyed by where a row stood — so «the second
                // record's year» is now about somebody else's record. None of
                // them can be believed, and a sentence pointing at the wrong
                // row is worse than none: they go, and the next save says what
                // is actually wrong with the list as it now stands.
                if (now.length !== was.length) {
                    return [key, ...Object.keys(complaints.current).filter((name) => name.startsWith(`${key}.`))];
                }

                const put = [key];

                now.forEach((row, index) => {
                    const before = was[index];

                    if (row === before) {
                        return;
                    }

                    // A row that is not a bag of fields — a list of plain
                    // strings, as the citizenships are — is answered whole.
                    if (!isRow(row) || !isRow(before)) {
                        put.push(`${key}.${index}`);

                        return;
                    }

                    for (const field of new Set([...Object.keys(row), ...Object.keys(before)])) {
                        if (row[field] !== before[field]) {
                            put.push(`${key}.${index}.${field}`);
                        }
                    }

                    // And the row itself, where the complaint was about the row
                    // rather than about one of its boxes.
                    put.push(`${key}.${index}`);
                });

                return put;
            };

            const forget = (keys: string[]) => {
                const wrong = keys.filter((key) => (complaints.current as Bag)[key] !== undefined);

                if (wrong.length > 0) {
                    (clearErrors as (...keys: string[]) => void)(...wrong);
                }
            };

            if (typeof keyOrData === 'string') {
                forget(answered(keyOrData, value, (held.current as Bag)[keyOrData]));
                (setData as (key: string, value: unknown) => void)(keyOrData, value);

                return;
            }

            // A whole object, or a function that makes one from what is held.
            // Either way the keys it changes are the ones being put right.
            const next = typeof keyOrData === 'function' ? ((keyOrData as (previous: T) => T)(held.current) as Bag) : (keyOrData as Bag);

            forget(
                Object.keys(next)
                    .filter((key) => next[key] !== (held.current as Bag)[key])
                    .flatMap((key) => answered(key, next[key], (held.current as Bag)[key])),
            );
            (setData as (data: Bag) => void)(next);
        },
        [setData, clearErrors],
    );

    return { ...form, setData: write as typeof form.setData };
}
