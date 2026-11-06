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
    Records,
    relations,
    thisMonth,
    years,
} from '@/components/personnel-sheet/pieces';
import {
    blankChild,
    blankEducation,
    blankJob,
    blankRelative,
    type ChildRow,
    type EducationRow,
    type JobRow,
    type RelativeRow,
    type Said,
    type SheetOptions,
    type SheetValues,
} from '@/components/personnel-sheet/values';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { languageLevelLabels, languageLevels, sexLabels, type LanguageLevel, type Sex } from '@/lib/employee';
import { Plus, Trash2 } from 'lucide-react';
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

/** Everything in a part stands twenty-four points under what is above it. */
function Group({ children }: { children: ReactNode }) {
    return <div className="flex min-w-0 flex-col gap-6 max-md:gap-4">{children}</div>;
}

/** Nothing is closed unless somebody says it is. */
const anything = () => true;

/* -------------------------------------------------------------- 01. ФИО */

export function NameGroup({ data, errors, setData, can = anything }: Omit<GroupProps, 'options'>) {
    return (
        <Group>
            <FormGrid>
                {can('surname') && (
                    <Field label="Фамилия" error={errors.surname} required>
                        <Input
                            value={data.surname}
                            onChange={(event) => setData('surname', event.target.value)}
                            aria-invalid={!!errors.surname}
                            aria-required
                        />
                    </Field>
                )}
                {can('name') && (
                    <Field label="Имя" error={errors.name} required>
                        <Input
                            value={data.name}
                            onChange={(event) => setData('name', event.target.value)}
                            aria-invalid={!!errors.name}
                            aria-required
                        />
                    </Field>
                )}
                {can('patronymic') && (
                    <Field label="Отчество" error={errors.patronymic}>
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
                    <Field label="Пол" error={errors.sex}>
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
                    <Field label="Дата рождения" error={errors.birth_date}>
                        <DatePicker
                            value={data.birth_date}
                            onChange={(value) => setData('birth_date', value)}
                            toDate={new Date()}
                            invalid={!!errors.birth_date}
                        />
                    </Field>
                )}
                {can('birth_place') && (
                    <Field label="Место рождения" error={errors.birth_place}>
                        <Input
                            value={data.birth_place}
                            onChange={(event) => setData('birth_place', event.target.value)}
                            placeholder="г. Душанбе"
                            aria-invalid={!!errors.birth_place}
                        />
                    </Field>
                )}
                {can('nationality') && (
                    <Field label="Национальность" error={errors.nationality}>
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

        if (said === 'yes' && data.educations.length === 0) setData('educations', [{ ...blankEducation }]);
    };

    return (
        <Group>
            <FormGrid>
                {/* Asked before the records are, as the children are: a card
                    with nothing under this part used to mean both «nowhere»
                    and «nobody asked», and the sheet printed the two alike.
                    Only this part's own refusal is said here — what is
                    wrong inside a record is said in the record. */}
                <Field label="Образование" error={errors.educations}>
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
                                            ...(value === '' ? { document: null, document_name: '', document_remove: false } : {}),
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
                                <>
                                    <Field
                                        label="Название документа"
                                        error={at(errors, `educations.${index}.document_name`)}
                                        required={record.document !== null}
                                    >
                                        <Input
                                            value={record.document_name}
                                            onChange={(event) => setEducation(index, { document_name: event.target.value })}
                                            placeholder="Диплом бакалавра"
                                            aria-invalid={!!at(errors, `educations.${index}.document_name`)}
                                        />
                                    </Field>

                                    <Field label="Документ" error={at(errors, `educations.${index}.document`)} hint="PDF или снимок, до 10 МБ.">
                                        <DocumentInput
                                            value={record.document}
                                            current={record.attached ?? null}
                                            onChange={(file) =>
                                                setEducation(index, {
                                                    document: file,
                                                    // A name to start from, so the common case is
                                                    // typed once rather than twice — the file's own
                                                    // name without the extension, which is a worse
                                                    // title than a person would write and a better
                                                    // one than an empty box. Only where nothing has
                                                    // been written: a title already there is the
                                                    // answer, and replacing the file is not a reason
                                                    // to throw it away.
                                                    ...(file !== null && record.document_name.trim() === ''
                                                        ? { document_name: file.name.replace(/\.[^.]+$/, '') }
                                                        : {}),
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
                                </>
                            )}
                        </FormGrid>
                    )}
                </Records>
            )}
        </Group>
    );
}

/* ------------------------------------------- 04. Учёная степень, звание */

/** A line that may carry one paper, and the paper the card already holds for it. */
interface PaperGroupProps extends GroupProps {
    /** What is attached to this line on the card; nothing on a new colleague. */
    attached?: string | null;
}

export function DegreeGroup({ data, errors, setData, options, can = anything, attached }: PaperGroupProps) {
    if (!can('degree')) return null;

    /**
     * Whether the line names something to show a paper for.
     *
     * «Нет» is an answer and an empty line is the absence of one, and neither
     * of them has a diploma behind it: the document is asked for only once
     * there is a degree for it to be the proof of.
     */
    const degreeNamed = data.degree !== '' && data.degree !== 'Нет';

    /**
     * And the answer taken back takes the document with it. A scan left in the
     * form under «Нет» would travel with the card unseen and be refused on a
     * field nobody can see any more.
     *
     * The asking for the paper on file to go is dropped at the same time, and
     * not because the paper survives: «Нет» takes it anyway, which is what the
     * save has always done with an answer of none. It is dropped because the
     * control that said it is no longer on the page — and a line answered «Нет»
     * by mistake and typed back in should find its paper where it left it.
     */
    const setDegree = (value: string) => {
        setData('degree', value);

        if (value === '' || value === 'Нет') {
            setData('degree_document', null);
            setData('degree_document_remove', false);
        }
    };

    return (
        <Group>
            <FormGrid>
                {/* Written out in one line, because that is the question the
                    sheet asks and the answer a person gives: «кандидат
                    экономических наук, доцент» is one thing to know about one
                    colleague. Left empty on most cards, which is why nothing
                    here is insisted on. */}
                <Field label="Учёная степень, звание" error={errors.degree}>
                    <SearchableSelect
                        options={[
                            { value: '', label: 'Не выбрано' },
                            // Said rather than left empty. An empty line means
                            // nobody has asked yet; this means somebody asked
                            // and the answer was none, and a card that cannot
                            // tell the two apart sends HR to ask again.
                            { value: 'Нет', label: 'Нет' },
                            ...offered(options.degrees, data.degree).filter(({ value }) => value !== 'Нет'),
                        ]}
                        value={data.degree}
                        onChange={setDegree}
                        onCreate={setDegree}
                        placeholder="Выберите или впишите"
                        searchPlaceholder="Степень или звание"
                        invalid={!!errors.degree}
                    />
                </Field>

                {/* One paper, and no name asked for it: the line beside it has
                    already said what it is about, which is the one thing a
                    diploma of a place of study needs a name of its own for.
                    It stands here only while that line names a degree — and
                    an empty column stands in its place, so the row keeps its
                    three and the line does not stretch across half the page
                    the moment the answer is «Нет». */}
                {degreeNamed ? (
                    <Field label="Документ" error={errors.degree_document}>
                        <DocumentInput
                            value={data.degree_document}
                            current={attached ?? null}
                            onChange={(file) => {
                                setData('degree_document', file);

                                // A paper chosen takes back the asking for the
                                // old one to go: somebody who attached one
                                // plainly wants it, and the save reads the two
                                // the same way round.
                                if (file !== null) setData('degree_document_remove', false);
                            }}
                            // The paper asked to go, and the asking taken back.
                            // It waits for «Сохранить» with the rest of the
                            // sheet — the card's own window deletes a document
                            // outright, and a removal that happened at once
                            // would be the one thing here «Отмена» could not
                            // put back. Where there is nothing on file the
                            // control is never drawn, which is «Новый
                            // сотрудник» throughout.
                            removing={data.degree_document_remove}
                            onRemoving={(asked) => setData('degree_document_remove', asked)}
                            invalid={!!errors.degree_document}
                        />
                    </Field>
                ) : (
                    <div />
                )}
            </FormGrid>
        </Group>
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

    /** One row's language, by name: the rows are replaced whole on every edit. */
    const setLanguage = (index: number, name: string) =>
        setData(
            'languages',
            data.languages.map((item, position) => (position === index ? { ...item, name } : item)),
        );

    return (
        <Group>
            {/* Spaced as the lists of records are, so a page of five
                parts is not spaced unlike the four around it. */}
            <div className="flex flex-col gap-4">
                <InputError message={at(errors, 'languages')} />

                {/* A language and its level are two fields like any other
                    two, so they stand in the same columns: a row of its own
                    arithmetic would line up with nothing above it. */}
                {data.languages.map((spoken, index) => (
                    <FormGrid key={index}>
                        <SearchableSelect
                            className="min-w-0"
                            invalid={!!at(errors, `languages.${index}.name`)}
                            value={spoken.name}
                            onChange={(value) => setLanguage(index, value)}
                            // What other cards already say, and whatever is
                            // typed here instead — taken as it is typed, since
                            // there is no list of languages to add it to.
                            options={offered(options.languages, spoken.name)}
                            onCreate={(name) => setLanguage(index, name)}
                            placeholder="Язык"
                            searchPlaceholder="Поиск языка"
                            empty="Язык не найден"
                        />
                        {/* The level and the button that drops the row share the
                            second column: a column of its own for a 36px button
                            would leave a third of the row empty. */}
                        <div className="flex min-w-0 items-center gap-2">
                            {/* Four answers, every one of them in view at once: a
                                box with a search in it would be asking somebody
                                to look for what they are already looking at. */}
                            <Select
                                value={spoken.level}
                                onValueChange={(value) =>
                                    setData(
                                        'languages',
                                        data.languages.map((item, position) =>
                                            position === index ? { ...item, level: value as LanguageLevel } : item,
                                        ),
                                    )
                                }
                            >
                                <SelectTrigger className="min-w-0 flex-1" aria-invalid={!!at(errors, `languages.${index}.level`)}>
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
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="text-muted-foreground size-9 shrink-0"
                                aria-label={`Убрать язык ${index + 1}`}
                                onClick={() =>
                                    setData(
                                        'languages',
                                        data.languages.filter((_, position) => position !== index),
                                    )
                                }
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        </div>
                    </FormGrid>
                ))}

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="self-start"
                    onClick={() => setData('languages', [...data.languages, { name: '', level: 'intermediate' }])}
                >
                    <Plus />
                    Добавить язык
                </Button>
            </div>
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

        if (said === 'yes' && data.experiences.length === 0) setData('experiences', [{ ...blankJob }]);
    };

    return (
        <Group>
            <FormGrid>
                {/* The same question about the jobs before this one: a
                    first job and a job nobody asked about are not the
                    same card. Only this part's own refusal is said here —
                    what is wrong inside a record is said in the record. */}
                <Field label="Трудовая деятельность" error={errors.experiences}>
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

export function AwardGroup({ data, errors, setData, options, can = anything, attached }: PaperGroupProps) {
    if (!can('award')) return null;

    /** The awards are read and taken back exactly as the degree above. */
    const awardNamed = data.award !== '' && data.award !== 'Нет';

    const setAward = (value: string) => {
        setData('award', value);

        if (value === '' || value === 'Нет') {
            setData('award_document', null);
            setData('award_document_remove', false);
        }
    };

    return (
        <Group>
            <FormGrid>
                {/* One line, as the degree is: «Медаль „Хизмати шоиста“,
                    благодарность Правительства» is one answer about one
                    person, and a list of the kinds of award would be a
                    directory nobody keeps. Empty on most cards. */}
                <Field label="Государственные награды, поощрения" error={errors.award}>
                    <SearchableSelect
                        options={[
                            { value: '', label: 'Не выбрано' },
                            // «Нет» is an answer and an empty line is the
                            // absence of one, exactly as above.
                            { value: 'Нет', label: 'Нет' },
                            ...offered(options.awards, data.award).filter(({ value }) => value !== 'Нет'),
                        ]}
                        value={data.award}
                        onChange={setAward}
                        onCreate={setAward}
                        placeholder="Выберите или впишите"
                        searchPlaceholder="Награда или поощрение"
                        invalid={!!errors.award}
                    />
                </Field>

                {/* The decree, the certificate, the letter — one paper, and
                    here only while the line names something to show it for.
                    An empty column stands in its place for the reason the
                    degree's does: the row keeps its three. */}
                {awardNamed ? (
                    <Field label="Документ" error={errors.award_document}>
                        <DocumentInput
                            value={data.award_document}
                            current={attached ?? null}
                            onChange={(file) => {
                                setData('award_document', file);

                                if (file !== null) setData('award_document_remove', false);
                            }}
                            // Asked to go and put back on the degree's terms.
                            removing={data.award_document_remove}
                            onRemoving={(asked) => setData('award_document_remove', asked)}
                            invalid={!!errors.award_document}
                        />
                    </Field>
                ) : (
                    <div />
                )}
            </FormGrid>
        </Group>
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

        if (said === 'yes' && data.children.length === 0) {
            setData('children', [{ ...blankChild }]);
        }
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
                    <Field label="Семейное положение" error={errors.marital_status}>
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
            )}

            {can('children') && (
                <FormGrid>
                    {/* The refusal over an empty list is said here, where the
                        answer that asked for the list was given: «Есть» and no
                        rows is what the server will not take. */}
                    <Field label="Дети" error={errors.children}>
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
                    <Field label="Серия и номер" error={errors.passport_series ?? errors.passport_number}>
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
                    <Field label="Дата выдачи" error={errors.passport_issued_at}>
                        <DatePicker
                            value={data.passport_issued_at}
                            onChange={(value) => setData('passport_issued_at', value)}
                            toDate={new Date()}
                            invalid={!!errors.passport_issued_at}
                        />
                    </Field>
                )}
                {can('passport_issued_by') && (
                    <Field label="Кем выдан" error={errors.passport_issued_by}>
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
                    <Field label="ИНН" error={errors.tax_number}>
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
                    <Field label="Домашний адрес" error={errors.home_address}>
                        <Input
                            value={data.home_address}
                            onChange={(event) => setData('home_address', event.target.value)}
                            placeholder="г. Душанбе, улица, дом, квартира"
                            aria-invalid={!!errors.home_address}
                        />
                    </Field>
                )}

                {can('phone') && (
                    <Field label="Телефон" error={errors.phone}>
                        <Input
                            value={data.phone}
                            onChange={(event) => setData('phone', event.target.value)}
                            placeholder="+992 90 123 45 67"
                            aria-invalid={!!errors.phone}
                        />
                    </Field>
                )}
                {can('sos_phone') && (
                    <Field label="Экстренный контакт" error={errors.sos_phone}>
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

export function MilitaryGroup({ data, errors, setData, options, can = anything, attached }: PaperGroupProps) {
    if (!can('military')) return null;

    /** And the military line, which is the third of exactly the same thing. */
    const militaryNamed = data.military !== '' && data.military !== 'Нет';

    const setMilitary = (value: string) => {
        setData('military', value);

        if (value === '' || value === 'Нет') {
            setData('military_document', null);
            setData('military_document_remove', false);
        }
    };

    return (
        <Group>
            <FormGrid>
                {/* One line, as the two above are: «военнообязанный, старший
                    лейтенант запаса» is one answer copied off a document, and
                    a list of the ways to stand with the army is not a list a
                    company keeps. */}
                <Field label="Отношение к воинской обязанности" error={errors.military}>
                    <SearchableSelect
                        options={[
                            { value: '', label: 'Не выбрано' },
                            // Said rather than left empty, exactly as above.
                            { value: 'Нет', label: 'Нет' },
                            ...offered(options.militaries, data.military).filter(({ value }) => value !== 'Нет'),
                        ]}
                        value={data.military}
                        onChange={setMilitary}
                        onCreate={setMilitary}
                        placeholder="Выберите или впишите"
                        searchPlaceholder="Отношение к воинской обязанности"
                        invalid={!!errors.military}
                    />
                </Field>

                {/* The военный билет, or the page of it that says this —
                    here only while the line names something to show it for,
                    and an empty column in its place so the row keeps its
                    three. */}
                {militaryNamed ? (
                    <Field label="Документ" error={errors.military_document}>
                        <DocumentInput
                            value={data.military_document}
                            current={attached ?? null}
                            onChange={(file) => {
                                setData('military_document', file);

                                if (file !== null) setData('military_document_remove', false);
                            }}
                            // And the third of the same, asked to go and put back.
                            removing={data.military_document_remove}
                            onRemoving={(asked) => setData('military_document_remove', asked)}
                            invalid={!!errors.military_document}
                        />
                    </Field>
                ) : (
                    <div />
                )}
            </FormGrid>
        </Group>
    );
}
