import { type FormDataType } from '@inertiajs/core';
import { useForm } from '@inertiajs/react';
import { useCallback, useRef } from 'react';

/** Read loosely inside, where a form is only a bag of named answers. */
type Bag = Record<string, unknown>;

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
            const forget = (keys: string[]) => {
                const wrong = keys.filter((key) => (complaints.current as Bag)[key] !== undefined);

                if (wrong.length > 0) {
                    (clearErrors as (...keys: string[]) => void)(...wrong);
                }
            };

            if (typeof keyOrData === 'string') {
                forget([keyOrData]);
                (setData as (key: string, value: unknown) => void)(keyOrData, value);

                return;
            }

            // A whole object, or a function that makes one from what is held.
            // Either way the keys it changes are the ones being put right.
            const next = typeof keyOrData === 'function' ? ((keyOrData as (previous: T) => T)(held.current) as Bag) : (keyOrData as Bag);

            forget(Object.keys(next).filter((key) => next[key] !== (held.current as Bag)[key]));
            (setData as (data: Bag) => void)(next);
        },
        [setData, clearErrors],
    );

    return { ...form, setData: write as typeof form.setData };
}
