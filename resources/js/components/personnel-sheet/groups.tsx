import { DatePicker } from '@/components/date-picker';
import { FormGrid } from '@/components/form-grid';
import InputError from '@/components/input-error';
import { MonthPicker } from '@/components/month-picker';
import { MultiSelect } from '@/components/multi-select';
import {
    asMonth,
    at,
    DocumentInput,
    Field,
    firstFamilyMonth,
    firstYear,
    fromMonth,
    named,
    offered,
    RecordCard,
    Records,
    relations,
    thisMonth,
    years,
} from '@/components/personnel-sheet/pieces';
import {
    blankChild,
    blankEducation,
    blankJob,
    blankPaper,
    blankRelative,
    blankSpoken,
    type ChildRow,
    type EducationRow,
    type JobRow,
    type PaperRow,
    type RelativeRow,
    type Said,
    type SheetOptions,
    type SheetValues,
    type SpokenRow,
} from '@/components/personnel-sheet/values';
import { SearchableSelect } from '@/components/searchable-select';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { languageLevelLabels, languageLevels, sexLabels, type LanguageLevel, type Sex } from '@/lib/employee';
import { type ReactNode } from 'react';

/**
 * The eleven parts of «Личный листок по учёту кадров» as questions rather than
 * as a page: the fields, their order, their labels, their hints and the three
 * «Есть/Нет» answers that open a list.
 *
 * Two places ask them — «Новый сотрудник», which files a colleague, and the
 * «Личный листок» tab of a card, which corrects one — and they are the same
 * questions under the same rules. Written out twice they would have drifted
 * within a month: a hint reworded here, a year's floor raised there, a document
 * asked for on one page and not the other. So they are written once and both
 * draw from here, and the request they make carries one set of names.
 *
 * What is not here is layout. Where a part stands, what numbers it, whether it
 * shares a line with the part beside it — that is each page's own, and the two
 * pages do differ: one is a form read top to bottom, the other is a part of a
 * card that was being read a moment ago.
 */

/**
 * Writing one answer. Narrower than Inertia's own setter on purpose: a part of
 * the sheet has no business with the rest of whatever form it is drawn in, and
 * a page that asks more than the sheet does can still hand this over.
 */
export type SheetWriter = <K extends keyof SheetValues>(key: K, value: SheetValues[K]) => void;

/**
 * What every part needs: the answers so far, whatever the server said about
 * them, the one way of writing one, and the lists to offer.
 */
export interface GroupProps {
    data: SheetValues;
    errors: Record<string, string | undefined>;
    setData: SheetWriter;
    options: SheetOptions;
    /**
     * Which lines of this part the reader may answer. A line they may not is
     * drawn nowhere — the card goes on reading it as it did — because a field
     * somebody can type into and the server will drop is worse than no field:
     * they would believe they had corrected it.
     *
     * Nothing closed where nobody says so, which is «Новый сотрудник»: a card
     * being filled in for the first time is filled in whole.
     */
    can?: (line: string) => boolean;
}

/**
 * Everything in a part stands sixteen points under what is above it — the
 * answer that opens a list, the records under it, the fields beside them.
 *
 * The same sixteen the records keep between themselves, and the same the
 * fields of a record keep: a part used to put twenty-four between its blocks
 * and sixteen inside them, which read as a gap that meant something where
 * nothing was meant by it.
 */
function Group({ children }: { children: ReactNode }) {
    return <div className="flex min-w-0 flex-col gap-4">{children}</div>;
}

/** Nothing is closed unless somebody says it is. */
const anything = () => true;

/* -------------------------------------------------------------- 01. ФИО */

export function NameGroup({ data, errors, setData, can = anything }: Omit<GroupProps, 'options'>) {
    return (
        <Group>
            <FormGrid>
                {can('surname') && (
                    <Field label="Фамилия" name="surname" error={errors.surname} required>
                        <Input
                            value={data.surname}
                            onChange={(event) => setData('surname', event.target.value)}
                            aria-invalid={!!errors.surname}
                            aria-required
                        />
                    </Field>
                )}
                {can('name') && (
                    <Field label="Имя" name="name" error={errors.name} required>
                        <Input
                            value={data.name}
                            onChange={(event) => setData('name', event.target.value)}
                            aria-invalid={!!errors.name}
                            aria-required
                        />
                    </Field>
                )}
                {can('patronymic') && (
                    <Field label="Отчество" name="patronymic" error={errors.patronymic}>
                        <Input
                            value={data.patronymic}
                            onChange={(event) => setData('patronymic', event.target.value)}
                            aria-invalid={!!errors.patronymic}
                        />
                    </Field>
                )}
            </FormGrid>
        </Group>
    );
}

/* -------------------------------------------------- 02. Общие сведения */

export function GeneralGroup({ data, errors, setData, options, can = anything }: GroupProps) {
    return (
        <Group>
            <FormGrid>
                {can('sex') && (
                    <Field label="Пол" name="sex" error={errors.sex}>
                        {/* A closed box says what is true of the field, not what to do
                            about it: the shape of the control already invites a choice,
                            and what a reader of a half-filled page is looking for is
                            which lines are still unanswered. Same word as the row in the
                            list that sets it, so choosing that row changes nothing. */}
                        <SearchableSelect
                            value={data.sex}
                            onChange={(value) => setData('sex', value as '' | Sex)}
                            options={[
                                // The entry standing for nothing, so an answer given by
                                // mistake can be taken back.
                                { value: '', label: 'Не выбрано' },
                                { value: 'male', label: sexLabels.male },
                                { value: 'female', label: sexLabels.female },
                            ]}
                            invalid={!!errors.sex}
                        />
                    </Field>
                )}
                {can('birth_date') && (
                    <Field label="Дата рождения" name="birth_date" error={errors.birth_date}>
                        <DatePicker
                            value={data.birth_date}
                            onChange={(value) => setData('birth_date', value)}
                            toDate={new Date()}
                            invalid={!!errors.birth_date}
                        />
                    </Field>
                )}
                {can('birth_place') && (
                    <Field label="Место рождения" name="birth_place" error={errors.birth_place}>
                        <Input
                            value={data.birth_place}
                            onChange={(event) => setData('birth_place', event.target.value)}
                            placeholder="г. Душанбе"
                            aria-invalid={!!errors.birth_place}
                        />
                    </Field>
                )}
                {can('nationality') && (
                    <Field label="Национальность" name="nationality" error={errors.nationality}>
                        {/* Offered rather than imposed, like the kind of work above:
                            what is already on the cards, so that «Таджичка» is not
                            spelled two ways across a hundred of them, and whatever
                            is typed instead is taken as it is. Left unanswered as
                            easily — it is nobody's business to insist. */}
                        <SearchableSelect
                            options={named(options.nationalities, data.nationality)}
                            value={data.nationality}
                            onChange={(value) => setData('nationality', value)}
                            onCreate={(value) => setData('nationality', value)}
                            placeholder="Выберите или впишите"
                            searchPlaceholder="Национальность"
                            invalid={!!errors.nationality}
                        />
                    </Field>
                )}
                {can('citizenship') && (
                    <Field
                        label="Гражданство"
                        name="citizenship"
                        // A wrong country is reported on its own line ("citizenship.1").
                        error={at(errors, 'citizenship')}
                    >
                        <MultiSelect
                            creatable
                            options={options.citizenships.map((value) => ({ value, label: value }))}
                            value={data.citizenship}
                            onChange={(value) => setData('citizenship', value)}
                            placeholder="Выберите или впишите страну"
                            searchPlaceholder="Страна"
                        />
                    </Field>
                )}
            </FormGrid>
        </Group>
    );
}

/* ------------------------------------------------------ 03. Образование */

export function EducationGroup({ data, errors, setData, options, can = anything }: GroupProps) {
    if (!can('educations')) return null;

    /** A row of the list, edited a field at a time. */
    const setEducation = (index: number, patch: Partial<EducationRow>) =>
        setData(
            'educations',
            data.educations.map((row, position) => (position === index ? { ...row, ...patch } : row)),
        );

    /**
     * The places of study are asked for before the list is filled, as the
     * children are, and the first record opens on «Есть» for the same reason:
     * whoever says there are some is about to name one, and a form that made
     * them press «Добавить» first would be asking twice.
     *
     * «Нет» and «не выбрано» leave the list alone: rows already typed are not
     * thrown away over a misread of the answer, and a diploma attached to one
     * of them even less so. What the card is filed with is settled on the way
     * out instead — the records travel only under «Есть», so a row left
     * standing behind «Нет» is never saved.
     */
    const setEducations = (said: Said) => {
        setData('has_educations', said);
        // The answer and the rows are one question, so the complaint about the
        // list goes when the answer is given again: «Добавьте хотя бы одно
        // учебное заведение» stops being true the moment somebody says there
        // are none. The list is written even where its rows are untouched —
        // that write is what tells the form this part has been answered, and
        // without it the red sentence sat under a box that had just been put
        // right. The rows themselves are kept: a row typed behind «Нет» is not
        // thrown away over a misread of the answer, and the save files the
        // list only under «Есть».
        setData('educations', said === 'yes' && data.educations.length === 0 ? [{ ...blankEducation }] : [...data.educations]);
    };

    return (
        <Group>
            <FormGrid>
                {/* Asked before the records are, as the children are: a card
                    with nothing under this part used to mean both «nowhere»
                    and «nobody asked», and the sheet printed the two alike.
                    Only this part's own refusal is said here — what is
                    wrong inside a record is said in the record. */}
                <Field label="Образование" name="educations" error={errors.educations}>
                    <SearchableSelect
                        invalid={!!errors.has_educations || !!errors.educations}
                        value={data.has_educations}
                        onChange={(value) => setEducations(value as Said)}
                        options={[
                            { value: '', label: 'Не выбрано' },
                            { value: 'yes', label: 'Есть' },
                            { value: 'no', label: 'Нет' },
                        ]}
                    />
                </Field>
            </FormGrid>

            {data.has_educations === 'yes' && (
                <Records
                    items={data.educations}
                    addLabel="Добавить образование"
                    onAdd={() => setData('educations', [...data.educations, { ...blankEducation }])}
                    onRemove={(index) =>
                        setData(
                            'educations',
                            data.educations.filter((_, position) => position !== index),
                        )
                    }
                >
                    {(record, index) => (
                        /* The list may be left empty altogether, but a record added
                           to it is answered in full: all of it but the diploma and
                           the year it ended with. */
                        <FormGrid>
                            <Field label="Учебное заведение" error={at(errors, `educations.${index}.institution`)} required>
                                <Input
                                    aria-required
                                    value={record.institution}
                                    onChange={(event) => setEducation(index, { institution: event.target.value })}
                                    aria-invalid={!!at(errors, `educations.${index}.institution`)}
                                />
                            </Field>
                            <Field label="Факультет" error={at(errors, `educations.${index}.faculty`)} required>
                                <Input
                                    aria-required
                                    value={record.faculty}
                                    onChange={(event) => setEducation(index, { faculty: event.target.value })}
                                    aria-invalid={!!at(errors, `educations.${index}.faculty`)}
                                />
                            </Field>
                            <Field label="Специальность" error={at(errors, `educations.${index}.specialty`)} required>
                                <Input
                                    aria-required
                                    value={record.specialty}
                                    onChange={(event) => setEducation(index, { specialty: event.target.value })}
                                    aria-invalid={!!at(errors, `educations.${index}.specialty`)}
                                />
                            </Field>
                            <Field label="Квалификация" error={at(errors, `educations.${index}.qualification`)} required>
                                {/* Offered rather than imposed, like the kind of work on
                                    the first part of the page: a diploma may name
                                    something no list has heard of, so what is typed is
                                    taken as it is. No entry standing for nothing,
                                    though — this one is asked for. */}
                                <SearchableSelect
                                    options={offered(options.qualifications, record.qualification)}
                                    value={record.qualification}
                                    onChange={(value) => setEducation(index, { qualification: value })}
                                    onCreate={(value) => setEducation(index, { qualification: value })}
                                    placeholder="Выберите или впишите"
                                    searchPlaceholder="Квалификация"
                                    invalid={!!at(errors, `educations.${index}.qualification`)}
                                    required
                                />
                            </Field>
                            <Field label="Год поступления" error={at(errors, `educations.${index}.started_year`)} required>
                                <SearchableSelect
                                    value={record.started_year}
                                    onChange={(value) => setEducation(index, { started_year: value })}
                                    options={years().map((year) => ({ value: year, label: year }))}
                                    searchPlaceholder="Год"
                                    invalid={!!at(errors, `educations.${index}.started_year`)}
                                    required
                                />
                            </Field>
                            <Field label="Год окончания" error={at(errors, `educations.${index}.graduated_year`)} hint="Пусто — ещё учится.">
                                <SearchableSelect
                                    value={record.graduated_year}
                                    onChange={(value) =>
                                        setEducation(index, {
                                            graduated_year: value,
                                            // The year taken back takes the file with
                                            // it, as the comment under this field says:
                                            // a scan left behind would travel with the
                                            // card and be refused on a field that is no
                                            // longer on the page. And the asking for the
                                            // paper on file to go goes with it too: an
                                            // instruction nobody can see any more is not
                                            // one the save should be carrying out.
                                            ...(value === '' ? { document: null, document_remove: false } : {}),
                                        })
                                    }
                                    options={[
                                        // The entry standing for «still studying», so a year
                                        // chosen by mistake can be taken back.
                                        { value: '', label: 'Не выбрано' },
                                        ...years().map((year) => ({ value: year, label: year })),
                                    ]}
                                    searchPlaceholder="Год"
                                    invalid={!!at(errors, `educations.${index}.graduated_year`)}
                                />
                            </Field>

                            {/* A year of graduation says the studying ended, and a
                                document is the proof of it — so the field is not
                                there to be filled in until there is something it
                                could prove. Taking the year back takes the file with
                                it, because the server refuses a document from
                                somebody still studying and a file left behind in the
                                form would be refused without ever having been
                                shown. */}
                            {record.graduated_year !== '' && (
                                <Field
                                    label="Документ"
                                    name={`educations.${index}.document`}
                                    error={at(errors, `educations.${index}.document`)}
                                    hint="PDF, до 10 МБ."
                                >
                                    <DocumentInput
                                        value={record.document}
                                        current={record.attached ?? null}
                                        onChange={(file) =>
                                            setEducation(index, {
                                                // The diploma and nothing beside it. What
                                                // it is called is the name of the file,
                                                // typed into the box the file is chosen in
                                                // — a field of its own asked the same
                                                // thing twice, and the two could disagree.
                                                document: file,
                                                // And a paper chosen takes back the
                                                // asking for the old one to go:
                                                // somebody who attached one plainly
                                                // wants it, which is the way round
                                                // the save reads it too. Written into
                                                // this patch rather than beside it,
                                                // because the whole record is one
                                                // answer and a second write of it
                                                // would be made from the row as it
                                                // was before this one.
                                                ...(file !== null ? { document_remove: false } : {}),
                                            })
                                        }
                                        // The diploma on file asked to go, and the
                                        // asking taken back. It waits for «Сохранить»
                                        // like every other answer on the sheet, which
                                        // is the whole reason it is a flag on the row
                                        // and not a request of its own: a paper gone
                                        // the moment a button was pressed would be
                                        // the one thing «Отмена» could not put back.
                                        removing={record.document_remove ?? false}
                                        onRemoving={(asked) => setEducation(index, { document_remove: asked })}
                                        invalid={!!at(errors, `educations.${index}.document`)}
                                    />
                                </Field>
                            )}
                        </FormGrid>
                    )}
                </Records>
            )}
        </Group>
    );
}

/* ------------------------------------------- 04. Учёная степень, звание */

/** The flag one of the three paper lists is answered by, before its rows. */
type PaperFlag = 'has_degrees' | 'has_awards' | 'has_military_records';

/** And the list itself, under the name its rows travel in. */
type PaperList = 'degrees' | 'awards' | 'military_records';

/**
 * The three parts that keep a list of records of a line of text and a paper
 * apiece: the degrees and titles, the state awards, the military record.
 *
 * One component for all three, because they are one question asked three
 * times — «Есть / Нет / Не выбрано», and under «Есть» as many records as there
 * are, each a line copied off a document and the document itself. Written out
 * three times they would drift, which is not a guess: the three single lines
 * these replaced had drifted already, down to the wording of the hint beside
 * the paper.
 *
 * Only the Russian differs between them, so only the Russian is passed in. The
 * shape — the answer first, the first record opened by «Есть», a record
 * answered in full — is «Образование»'s, and deliberately the same: a reader
 * who has filled that part in knows how to fill these.
 */
function PaperListGroup({
    data,
    errors,
    setData,
    can,
    line,
    flag,
    list,
    label,
    addLabel,
    searchPlaceholder,
    suggestions,
}: Omit<GroupProps, 'options' | 'can'> & {
    /** Which lines this reader may answer, as every part is told. */
    can: (line: string) => boolean;
    /**
     * The line's own key, as App\Support\EmployeeFields names the right over
     * it. It stayed `degree`, `award`, `military` when the data under it became
     * a list: what a person may read is the same question it always was.
     */
    line: string;
    flag: PaperFlag;
    list: PaperList;
    /** What the part is called — over its answer, and over each record's line. */
    label: string;
    addLabel: string;
    searchPlaceholder: string;
    /** What other cards say on this line, offered and in no way binding. */
    suggestions: string[];
}) {
    if (!can(line)) return null;

    const rows = data[list];

    /** A row of the list, edited a field at a time. */
    const setRow = (index: number, patch: Partial<PaperRow>) =>
        setData(
            list,
            rows.map((row, position) => (position === index ? { ...row, ...patch } : row)),
        );

    /**
     * The answer that opens the list, on «Образование»'s terms down to why:
     * whoever says there is something is about to name it, so «Есть» opens the
     * first record rather than making them press «Добавить» first.
     *
     * «Нет» and «не выбрано» leave the rows alone — a paper attached to one of
     * them is not thrown away over a misread of the answer. What the card is
     * filed with is settled on the way out instead, where the rows travel only
     * under «Есть».
     */
    const setSaid = (said: Said) => {
        setData(flag, said);
        // And the list with it, for the reason «Образование» writes its own:
        // the answer and the rows are one question, so answering it again
        // takes away the complaint about the list. Written even where the rows
        // do not change — that write is the answer being given, and without it
        // «Добавьте хотя бы одну степень или звание» stayed under a box that
        // had just said there are none.
        setData(list, said === 'yes' && rows.length === 0 ? [{ ...blankPaper }] : [...rows]);
    };

    return (
        <Group>
            <FormGrid>
                {/* Asked before the records are, as the schooling is: one line
                    of text used to stand here, and a card with nothing on it
                    meant «none» and «nobody asked» at once. Only this part's
                    own refusal is said here — what is wrong inside a record is
                    said in the record. */}
                <Field label={label} name={line} error={errors[list]}>
                    <SearchableSelect
                        invalid={!!errors[flag] || !!errors[list]}
                        value={data[flag]}
                        onChange={(value) => setSaid(value as Said)}
                        options={[
                            { value: '', label: 'Не выбрано' },
                            { value: 'yes', label: 'Есть' },
                            { value: 'no', label: 'Нет' },
                        ]}
                    />
                </Field>
            </FormGrid>

            {data[flag] === 'yes' && (
                <Records
                    items={rows}
                    addLabel={addLabel}
                    onAdd={() => setData(list, [...rows, { ...blankPaper }])}
                    onRemove={(index) =>
                        setData(
                            list,
                            rows.filter((_, position) => position !== index),
                        )
                    }
                >
                    {(record, index) => (
                        /* A record added to the list is answered in full: the
                           line itself is asked for, the paper behind it is not
                           — a degree conferred last week is a fact before the
                           scan of the diploma reaches the office. */
                        <FormGrid>
                            <Field label={label} error={at(errors, `${list}.${index}.name`)} required>
                                {/* Offered rather than imposed, as the
                                    qualification of a diploma is: «кандидат
                                    экономических наук, доцент» is one phrase
                                    copied off a document, and a directory of
                                    the ways to hold a degree is not one a
                                    company keeps. */}
                                <SearchableSelect
                                    options={offered(suggestions, record.name)}
                                    value={record.name}
                                    onChange={(value) => setRow(index, { name: value })}
                                    onCreate={(value) => setRow(index, { name: value })}
                                    placeholder="Выберите или впишите"
                                    searchPlaceholder={searchPlaceholder}
                                    invalid={!!at(errors, `${list}.${index}.name`)}
                                    required
                                />
                            </Field>

                            <Field
                                label="Документ"
                                name={`${list}.${index}.document`}
                                error={at(errors, `${list}.${index}.document`)}
                                hint="PDF, до 10 МБ."
                            >
                                <DocumentInput
                                    value={record.document}
                                    current={record.attached ?? null}
                                    onChange={(file) =>
                                        setRow(index, {
                                            // The paper and nothing beside it:
                                            // what it is called is the name of
                                            // the file, typed into the box the
                                            // file is chosen in.
                                            document: file,
                                            // And a paper chosen takes back the
                                            // asking for the old one to go:
                                            // somebody who attached one plainly
                                            // wants it, which is the way round
                                            // the save reads it too. Written
                                            // into this patch rather than beside
                                            // it, because the whole record is
                                            // one answer and a second write of
                                            // it would be made from the row as
                                            // it was before this one.
                                            ...(file !== null ? { document_remove: false } : {}),
                                        })
                                    }
                                    // The paper on file asked to go, and the
                                    // asking taken back. It waits for
                                    // «Сохранить» like every other answer on
                                    // the sheet, which is the whole reason it is
                                    // a flag on the row and not a request of its
                                    // own: a paper gone the moment a button was
                                    // pressed would be the one thing «Отмена»
                                    // could not put back.
                                    removing={record.document_remove ?? false}
                                    onRemoving={(asked) => setRow(index, { document_remove: asked })}
                                    invalid={!!at(errors, `${list}.${index}.document`)}
                                />
                            </Field>
                        </FormGrid>
                    )}
                </Records>
            )}
        </Group>
    );
}

export function DegreeGroup({ data, errors, setData, options, can = anything }: GroupProps) {
    return (
        <PaperListGroup
            data={data}
            errors={errors}
            setData={setData}
            can={can}
            line="degree"
            flag="has_degrees"
            list="degrees"
            label="Учёная степень, звание"
            addLabel="Добавить степень"
            searchPlaceholder="Степень или звание"
            suggestions={options.degrees}
        />
    );
}

/* ----------------------------------------------------- 05. Знание языков */

export function LanguagesGroup({
    data,
    errors,
    setData,
    options,
    can = anything,
}: GroupProps & {
    /**
     * Languages typed in that the directory has not heard of yet. Kept by the
     * page rather than here, because a page that empties its form for the next
     * colleague has to empty this too.
     */
}) {
    if (!can('languages')) return null;

    /** A row of the list, edited a field at a time, as every other list is. */
    const setSpoken = (index: number, patch: Partial<SpokenRow>) =>
        setData(
            'languages',
            data.languages.map((row, position) => (position === index ? { ...row, ...patch } : row)),
        );

    return (
        <Group>
            {/* What is wrong with the list rather than with a row of it: that
                there is not one language in it. Said over the records, where
                the «есть или нет» of the other lists says its own refusal. */}
            <InputError message={errors.languages} />

            {/* Records, like the places of study and the degrees beside them.
                A language used to be a line of two boxes with a bin at the end
                — which read as a row of a table while every list around it read
                as «Запись 1», «Запись 2». Nothing about a language made it the
                exception; it was simply written first. */}
            <Records
                items={data.languages}
                addLabel="Добавить язык"
                onAdd={() => setData('languages', [...data.languages, { ...blankSpoken }])}
                onRemove={(index) =>
                    setData(
                        'languages',
                        data.languages.filter((_, position) => position !== index),
                    )
                }
            >
                {(spoken, index) => (
                    <FormGrid>
                        <Field label="Язык" error={at(errors, `languages.${index}.name`)} required>
                            {/* What other cards already say, and whatever is
                                typed here instead — taken as it is typed, since
                                there is no list of languages to add it to. */}
                            <SearchableSelect
                                className="min-w-0"
                                invalid={!!at(errors, `languages.${index}.name`)}
                                value={spoken.name}
                                onChange={(value) => setSpoken(index, { name: value })}
                                options={offered(options.languages, spoken.name)}
                                onCreate={(name) => setSpoken(index, { name })}
                                placeholder="Выберите или впишите"
                                searchPlaceholder="Поиск языка"
                                empty="Язык не найден"
                                required
                            />
                        </Field>

                        <Field label="Уровень" error={at(errors, `languages.${index}.level`)} required>
                            {/* Four answers, every one of them in view at once: a
                                box with a search in it would be asking somebody
                                to look for what they are already looking at. */}
                            <Select value={spoken.level} onValueChange={(value) => setSpoken(index, { level: value as LanguageLevel })}>
                                <SelectTrigger className="w-full min-w-0" aria-invalid={!!at(errors, `languages.${index}.level`)}>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {languageLevels.map((level) => (
                                        <SelectItem key={level} value={level}>
                                            {languageLevelLabels[level]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                    </FormGrid>
                )}
            </Records>
        </Group>
    );
}

/* ----------------------------------------------- 06. Трудовая деятельность */

export function ExperienceGroup({ data, errors, setData, options, can = anything }: GroupProps) {
    if (!can('work_experiences')) return null;

    const setJob = (index: number, patch: Partial<JobRow>) =>
        setData(
            'experiences',
            data.experiences.map((row, position) => (position === index ? { ...row, ...patch } : row)),
        );

    /** The jobs held before this one, asked and opened exactly as the schools above. */
    const setExperience = (said: Said) => {
        setData('has_experience', said);
        // The list is written with the answer, as the schooling's is: the two
        // are one question, and the complaint about the list cannot outlive
        // the answer being given again.
        setData('experiences', said === 'yes' && data.experiences.length === 0 ? [{ ...blankJob }] : [...data.experiences]);
    };

    return (
        <Group>
            <FormGrid>
                {/* The same question about the jobs before this one: a
                    first job and a job nobody asked about are not the
                    same card. Only this part's own refusal is said here —
                    what is wrong inside a record is said in the record. */}
                <Field label="Трудовая деятельность" name="work_experiences" error={errors.experiences}>
                    <SearchableSelect
                        invalid={!!errors.has_experience || !!errors.experiences}
                        value={data.has_experience}
                        onChange={(value) => setExperience(value as Said)}
                        options={[
                            { value: '', label: 'Не выбрано' },
                            { value: 'yes', label: 'Есть' },
                            { value: 'no', label: 'Нет' },
                        ]}
                    />
                </Field>
            </FormGrid>

            {data.has_experience === 'yes' && (
                <Records
                    items={data.experiences}
                    addLabel="Добавить место работы"
                    onAdd={() => setData('experiences', [...data.experiences, { ...blankJob }])}
                    onRemove={(index) =>
                        setData(
                            'experiences',
                            data.experiences.filter((_, position) => position !== index),
                        )
                    }
                >
                    {(record, index) => (
                        /* As with education: the section is optional, a record in it
                           is not — only the leaving date may stay open. */
                        <FormGrid>
                            <Field label="Организация" error={at(errors, `experiences.${index}.organization`)} required>
                                <Input
                                    aria-required
                                    value={record.organization}
                                    onChange={(event) => setJob(index, { organization: event.target.value })}
                                    aria-invalid={!!at(errors, `experiences.${index}.organization`)}
                                />
                            </Field>
                            <Field label="Должность" error={at(errors, `experiences.${index}.position`)} required>
                                <Input
                                    aria-required
                                    value={record.position}
                                    onChange={(event) => setJob(index, { position: event.target.value })}
                                    aria-invalid={!!at(errors, `experiences.${index}.position`)}
                                />
                            </Field>
                            {/*
                             * One box per date, picked as a month: that is the precision a
                             * previous job is remembered to, and the record keeps the two
                             * halves apart as the server does.
                             */}
                            <Field label="Дата вступления" error={at(errors, `experiences.${index}.started_year`)} required>
                                <MonthPicker
                                    required
                                    max={thisMonth}
                                    min={`${firstYear}-01`}
                                    value={asMonth(record.started_year, record.started_month)}
                                    onChange={(value) => setJob(index, fromMonth(value, 'started'))}
                                    invalid={!!at(errors, `experiences.${index}.started_month`) || !!at(errors, `experiences.${index}.started_year`)}
                                />
                            </Field>
                            <Field label="Дата ухода" error={at(errors, `experiences.${index}.ended_year`)} hint="Пусто — ещё работает.">
                                <MonthPicker
                                    max={thisMonth}
                                    min={`${firstYear}-01`}
                                    value={asMonth(record.ended_year, record.ended_month)}
                                    onChange={(value) =>
                                        setJob(index, {
                                            ...fromMonth(value, 'ended'),
                                            // Still working there is no reason to
                                            // give, so taking the date back takes
                                            // the answer with it rather than
                                            // sending one the server would refuse.
                                            ...(value === '' ? { reason: '' } : {}),
                                        })
                                    }
                                    invalid={!!at(errors, `experiences.${index}.ended_month`) || !!at(errors, `experiences.${index}.ended_year`)}
                                />
                            </Field>

                            {/* Asked for once the job has an end: a book says why
                                it ended, and nobody still working somewhere has
                                an answer. Offered rather than imposed, like the
                                kind of work on the first part of the page — the
                                wording is copied from the book, whatever the
                                list has heard of. */}
                            {record.ended_year !== '' && (
                                <Field label="Причина ухода" error={at(errors, `experiences.${index}.reason`)} required>
                                    <SearchableSelect
                                        options={offered(options.reasons, record.reason)}
                                        value={record.reason}
                                        onChange={(value) => setJob(index, { reason: value })}
                                        onCreate={(value) => setJob(index, { reason: value })}
                                        placeholder="Выберите или впишите"
                                        searchPlaceholder="Причина ухода"
                                        invalid={!!at(errors, `experiences.${index}.reason`)}
                                        required
                                    />
                                </Field>
                            )}
                        </FormGrid>
                    )}
                </Records>
            )}
        </Group>
    );
}

/* ------------------------------ 07. Государственные награды, поощрения */

export function AwardGroup({ data, errors, setData, options, can = anything }: GroupProps) {
    return (
        <PaperListGroup
            data={data}
            errors={errors}
            setData={setData}
            can={can}
            line="award"
            flag="has_awards"
            list="awards"
            label="Государственные награды, поощрения"
            addLabel="Добавить награду"
            searchPlaceholder="Награда или поощрение"
            suggestions={options.awards}
        />
    );
}

/* ------------------------- 08. Семейное положение и состав семьи */

export function FamilyGroup({ data, errors, setData, can = anything }: Omit<GroupProps, 'options'>) {
    /**
     * «Есть» with nothing under it is what the server will not take, so the
     * answer opens the first row itself: whoever says there are children is
     * about to name one, and a form that made them press «Добавить» first
     * would be asking twice. «Нет» and «не выбрано» leave the list alone —
     * rows already typed are not thrown away over a misread of the answer.
     */
    const setChildren = (said: Said) => {
        setData('has_children', said);
        // And the list beside it, on the terms the other three lists are
        // answered: one question, one write, so «Добавьте хотя бы одного
        // ребёнка» does not stand under an answer that has just been changed.
        setData('children', said === 'yes' && data.children.length === 0 ? [{ ...blankChild }] : [...data.children]);
    };

    /**
     * One answer of one row, written back into the list it belongs to. Written
     * once for the children and once for the rest of the family, and no oftener
     * than that: the two lists ask the same three questions, so a field that
     * spelled the rewriting out for itself would spell it out six times.
     */
    const setChild = (index: number, patch: Partial<ChildRow>) =>
        setData(
            'children',
            data.children.map((row, position) => (position === index ? { ...row, ...patch } : row)),
        );

    const setRelative = (index: number, patch: Partial<RelativeRow>) =>
        setData(
            'relatives',
            data.relatives.map((row, position) => (position === index ? { ...row, ...patch } : row)),
        );

    return (
        <Group>
            {/* The status, and under it whom it is about: the spouse follows
                the answer that says there is one, and the children follow
                after both. Two questions on one line would read as one. */}
            {can('marital_status') && (
                <FormGrid>
                    <Field label="Семейное положение" name="marital_status" error={errors.marital_status}>
                        <SearchableSelect
                            value={data.marital_status}
                            onChange={(value) => setData('marital_status', value)}
                            options={[
                                // Nothing chosen is an answer of its own: a card
                                // nobody has asked must not read as «не женат».
                                { value: '', label: 'Не выбрано' },
                                { value: 'single', label: 'Не женат / не замужем' },
                                { value: 'married', label: 'Женат / замужем' },
                            ]}
                            invalid={!!errors.marital_status}
                        />
                    </Field>
                </FormGrid>
            )}

            {can('spouse') && data.marital_status === 'married' && (
                // In the frame a record of the sheet is read in, because that
                // is what it is: a person named under a heading, with the same
                // three questions about them as everybody else in the block.
                // There is nothing to press in the corner — a spouse is not one
                // of a list, and the way to say there is none is «Не женат / не
                // замужем» above, which takes these fields off the page.
                <RecordCard title="Супруг(а)">
                    <FormGrid>
                        {/* The same three questions the rest of the family is
                        asked, in the same order and under the same labels, less
                        the one a spouse has no need of: what they are to this
                        person is what «Семейное положение» has just said.

                        The first two are asked for: «женат» with nobody named
                        is half an answer, and the server says the same. */}
                        <Field label="ФИО супруга" error={errors.spouse_name} required>
                            <Input
                                aria-required
                                value={data.spouse_name}
                                onChange={(event) => setData('spouse_name', event.target.value)}
                                aria-invalid={!!errors.spouse_name}
                            />
                        </Field>
                        <Field label="Дата рождения" error={errors.spouse_birth_date} required>
                            <MonthPicker
                                required
                                value={data.spouse_birth_date}
                                onChange={(value) => setData('spouse_birth_date', value)}
                                min={firstFamilyMonth}
                                max={thisMonth}
                                invalid={!!errors.spouse_birth_date}
                            />
                        </Field>
                        <Field label="Работа или учёба" error={errors.spouse_occupation}>
                            <Input
                                value={data.spouse_occupation}
                                onChange={(event) => setData('spouse_occupation', event.target.value)}
                                placeholder="Студент, ТТУ им. Осими"
                                aria-invalid={!!errors.spouse_occupation}
                            />
                        </Field>
                    </FormGrid>
                </RecordCard>
            )}

            {can('children') && (
                <FormGrid>
                    {/* The refusal over an empty list is said here, where the
                        answer that asked for the list was given: «Есть» and no
                        rows is what the server will not take. */}
                    <Field label="Дети" name="children" error={errors.children}>
                        <SearchableSelect
                            invalid={!!errors.has_children || !!errors.children}
                            value={data.has_children}
                            onChange={(value) => setChildren(value as Said)}
                            options={[
                                // «Детей нет» and «ещё не спрашивали» look the same
                                // in an empty list, so the answer is one of three.
                                { value: '', label: 'Не выбрано' },
                                { value: 'yes', label: 'Есть' },
                                { value: 'no', label: 'Нет' },
                            ]}
                        />
                    </Field>
                </FormGrid>
            )}

            {can('children') && data.has_children === 'yes' && (
                <Records
                    items={data.children}
                    addLabel="Добавить ребёнка"
                    onAdd={() => setData('children', [...data.children, { ...blankChild }])}
                    onRemove={(index) =>
                        setData(
                            'children',
                            data.children.filter((_, position) => position !== index),
                        )
                    }
                >
                    {(child, index) => (
                        /* A record apiece, as the rest of the family is kept: the
                           strip at the top says which row this is and takes it
                           away. Nobody has to be entered with children at all,
                           but a row that has been added is a child, and a child
                           has a name.

                           The same three questions a relative is asked, less
                           «Родство»: a row of this list is a child, and that is
                           the answer. */
                        <FormGrid>
                            <Field label="ФИО ребёнка" error={at(errors, `children.${index}.full_name`)} required>
                                <Input
                                    aria-required
                                    value={child.full_name}
                                    onChange={(event) => setChild(index, { full_name: event.target.value })}
                                    aria-invalid={!!at(errors, `children.${index}.full_name`)}
                                />
                            </Field>

                            <Field label="Дата рождения" error={at(errors, `children.${index}.birth_date`)}>
                                <MonthPicker
                                    value={child.birth_date}
                                    onChange={(value) => setChild(index, { birth_date: value })}
                                    min={firstFamilyMonth}
                                    max={thisMonth}
                                    invalid={!!at(errors, `children.${index}.birth_date`)}
                                />
                            </Field>

                            <Field label="Работа или учёба" error={at(errors, `children.${index}.occupation`)}>
                                <Input
                                    value={child.occupation}
                                    onChange={(event) => setChild(index, { occupation: event.target.value })}
                                    placeholder="Студент, ТТУ им. Осими"
                                    aria-invalid={!!at(errors, `children.${index}.occupation`)}
                                />
                            </Field>
                        </FormGrid>
                    )}
                </Records>
            )}

            {/* The rest of the family the card names. The sheet prints them
                in a table of four columns — who they are, what they are
                called, the month they were born, the work — and the card
                asks in the same four. A record apiece, because four answers
                in a row would be a row nobody can read. */}
            {can('relatives') && (
                <div className="flex flex-col gap-2">
                    <Label>Состав семьи</Label>

                    <Records
                        items={data.relatives}
                        addLabel="Добавить родственника"
                        onAdd={() => setData('relatives', [...data.relatives, { ...blankRelative }])}
                        onRemove={(index) =>
                            setData(
                                'relatives',
                                data.relatives.filter((_, position) => position !== index),
                            )
                        }
                    >
                        {(record, index) => (
                            /* The part may be left empty altogether, but a row added
                               to it says at least who the person is and what they
                               are called: a line that said «Мать» and no name would
                               be a line nobody can do anything with. */
                            <FormGrid>
                                <Field label="Родство" error={at(errors, `relatives.${index}.relation`)} required>
                                    <SearchableSelect
                                        options={offered(relations, record.relation)}
                                        value={record.relation}
                                        onChange={(value) => setRelative(index, { relation: value })}
                                        onCreate={(value) => setRelative(index, { relation: value })}
                                        placeholder="Выберите или впишите"
                                        searchPlaceholder="Родство"
                                        invalid={!!at(errors, `relatives.${index}.relation`)}
                                        required
                                    />
                                </Field>

                                <Field label="ФИО" error={at(errors, `relatives.${index}.full_name`)} required>
                                    <Input
                                        aria-required
                                        value={record.full_name}
                                        onChange={(event) => setRelative(index, { full_name: event.target.value })}
                                        aria-invalid={!!at(errors, `relatives.${index}.full_name`)}
                                    />
                                </Field>

                                {/* The month and not the day: the day a brother was
                                    born is not something a personnel department is
                                    told, and the same box asks it of a spouse and of
                                    a child, so the three answers are one kind of
                                    answer. */}
                                <Field label="Дата рождения" error={at(errors, `relatives.${index}.birth_date`)}>
                                    <MonthPicker
                                        value={record.birth_date}
                                        onChange={(value) => setRelative(index, { birth_date: value })}
                                        min={firstFamilyMonth}
                                        max={thisMonth}
                                        invalid={!!at(errors, `relatives.${index}.birth_date`)}
                                    />
                                </Field>

                                <Field label="Работа или учёба" error={at(errors, `relatives.${index}.occupation`)}>
                                    <Input
                                        value={record.occupation}
                                        onChange={(event) => setRelative(index, { occupation: event.target.value })}
                                        placeholder="Студент, ТТУ им. Осими"
                                        aria-invalid={!!at(errors, `relatives.${index}.occupation`)}
                                    />
                                </Field>
                            </FormGrid>
                        )}
                    </Records>
                </div>
            )}
        </Group>
    );
}

/* ---------------------------------------------------- 09. Паспортные данные */

export function PassportGroup({ data, errors, setData, can = anything }: Omit<GroupProps, 'options'>) {
    return (
        <Group>
            <FormGrid>
                {/* One line of the card, as the card reads it: «A 01234567» is
                    how a passport says what it is, and the two columns behind
                    it are the application's business rather than the reader's.
                    Whichever of the two is refused is said under both, because
                    the line they stand on is one. */}
                {can('passport_number') && (
                    <Field label="Серия и номер" name="passport_number" error={errors.passport_series ?? errors.passport_number}>
                        <div className="flex min-w-0 items-center gap-2">
                            <Input
                                className="w-16 shrink-0"
                                value={data.passport_series}
                                onChange={(event) => setData('passport_series', event.target.value)}
                                placeholder="A"
                                aria-label="Серия паспорта"
                                aria-invalid={!!errors.passport_series}
                            />
                            <Input
                                className="min-w-0 flex-1"
                                value={data.passport_number}
                                onChange={(event) => setData('passport_number', event.target.value)}
                                placeholder="01234567"
                                aria-label="Номер паспорта"
                                aria-invalid={!!errors.passport_number}
                            />
                        </div>
                    </Field>
                )}
                {can('passport_issued_at') && (
                    <Field label="Дата выдачи" name="passport_issued_at" error={errors.passport_issued_at}>
                        <DatePicker
                            value={data.passport_issued_at}
                            onChange={(value) => setData('passport_issued_at', value)}
                            toDate={new Date()}
                            invalid={!!errors.passport_issued_at}
                        />
                    </Field>
                )}
                {can('passport_issued_by') && (
                    <Field label="Кем выдан" name="passport_issued_by" error={errors.passport_issued_by}>
                        <Input
                            value={data.passport_issued_by}
                            onChange={(event) => setData('passport_issued_by', event.target.value)}
                            aria-invalid={!!errors.passport_issued_by}
                        />
                    </Field>
                )}

                {/* The number the tax office files them under: read by the
                    people who read the passport and for the same errands, so
                    it stands with it rather than in a part of its own. */}
                {can('tax_number') && (
                    <Field label="ИНН" name="tax_number" error={errors.tax_number}>
                        <Input
                            value={data.tax_number}
                            onChange={(event) => setData('tax_number', event.target.value)}
                            placeholder="012345678"
                            aria-invalid={!!errors.tax_number}
                        />
                    </Field>
                )}
            </FormGrid>
        </Group>
    );
}

/* ------------------------------------------------------ 10. Адрес и контакты */

export function ContactsGroup({ data, errors, setData, can = anything }: Omit<GroupProps, 'options'>) {
    return (
        <Group>
            <FormGrid>
                {can('home_address') && (
                    <Field label="Домашний адрес" name="home_address" error={errors.home_address}>
                        <Input
                            value={data.home_address}
                            onChange={(event) => setData('home_address', event.target.value)}
                            placeholder="г. Душанбе, улица, дом, квартира"
                            aria-invalid={!!errors.home_address}
                        />
                    </Field>
                )}

                {can('phone') && (
                    <Field label="Телефон" name="phone" error={errors.phone}>
                        <Input
                            value={data.phone}
                            onChange={(event) => setData('phone', event.target.value)}
                            placeholder="+992 90 123 45 67"
                            aria-invalid={!!errors.phone}
                        />
                    </Field>
                )}
                {can('sos_phone') && (
                    <Field label="Экстренный контакт" name="sos_phone" error={errors.sos_phone}>
                        <Input
                            value={data.sos_phone}
                            onChange={(event) => setData('sos_phone', event.target.value)}
                            placeholder="+992 90 765 43 21"
                            aria-invalid={!!errors.sos_phone}
                        />
                    </Field>
                )}
                {/* Whose number it is goes with the number: one line of the card,
                    and the same right over both. */}
                {can('sos_phone') && (
                    <Field label="Чей это номер" error={errors.sos_contact}>
                        <Input
                            value={data.sos_contact}
                            onChange={(event) => setData('sos_contact', event.target.value)}
                            placeholder="Супруга, Нигина"
                            aria-invalid={!!errors.sos_contact}
                        />
                    </Field>
                )}
            </FormGrid>
        </Group>
    );
}

/* -------------------------- 11. Отношение к воинской обязанности */

export function MilitaryGroup({ data, errors, setData, options, can = anything }: GroupProps) {
    return (
        <PaperListGroup
            data={data}
            errors={errors}
            setData={setData}
            can={can}
            line="military"
            flag="has_military_records"
            list="military_records"
            label="Отношение к воинской обязанности"
            addLabel="Добавить запись"
            searchPlaceholder="Отношение к воинской обязанности"
            suggestions={options.militaries}
        />
    );
}
