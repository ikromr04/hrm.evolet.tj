import { type LanguageLevel, type Sex } from '@/lib/employee';

/**
 * What «Личный листок по учёту кадров» is answered with, in the names the
 * answers travel under.
 *
 * One shape for both places the sheet is answered — the page that files a new
 * colleague and the card that corrects one later — because they ask the same
 * eleven questions under the same rules, and a second shape would have drifted
 * from the first inside a month. The names are the ones «Новый сотрудник»
 * already sent, so the two doors take one request between them.
 *
 * Everything is kept as it is typed rather than as the server keeps it: «yes»
 * for an answer the card holds as a flag, a year as the string a list of years
 * hands back, a language the directory has not heard of under a name of its
 * own. What that turns into is sheetToSend()'s business, once, on the way out.
 */
export interface SheetValues {
    /* 01. ФИО */
    surname: string;
    name: string;
    patronymic: string;

    /* 02. Общие сведения */
    sex: '' | Sex;
    birth_date: string;
    birth_place: string;
    nationality: string;
    citizenship: string[];

    /* 03. Образование */
    has_educations: Said;
    educations: EducationRow[];

    /* 04. Учёная степень, звание */
    has_degrees: Said;
    degrees: PaperRow[];

    /* 05. Знание языков */
    languages: SpokenRow[];

    /* 06. Трудовая деятельность */
    has_experience: Said;
    experiences: JobRow[];

    /* 07. Государственные награды, поощрения */
    has_awards: Said;
    awards: PaperRow[];

    /* 08. Семейное положение и состав семьи */
    marital_status: string;
    spouse_name: string;
    /** A month, «1971-03», as every date of birth in the family is. */
    spouse_birth_date: string;
    spouse_occupation: string;
    has_children: Said;
    children: ChildRow[];
    relatives: RelativeRow[];

    /* 09. Паспортные данные */
    passport_series: string;
    passport_number: string;
    passport_issued_at: string;
    passport_issued_by: string;
    tax_number: string;

    /* 10. Адрес и контакты */
    home_address: string;
    phone: string;
    sos_phone: string;
    sos_contact: string;

    /* 11. Отношение к воинской обязанности */
    has_military_records: Said;
    military_records: PaperRow[];
}

/**
 * The form keys each part of the sheet owns — what the server may name a
 * complaint on when that part is wrong.
 *
 * Kept here with the questions rather than beside either page's layout, because
 * both pages have the same errand for it and it is the same answer: a save
 * refused over a year somebody mistyped nine screens down has to put that part
 * on screen, and this is the list that says which part «that» is. Written twice
 * it would drift, and a drifted copy fails silently — the refusal simply
 * scrolls nowhere, and the person is left pressing a button that seems dead.
 *
 * A key counts as a part's when the complaint is that key or anything under it:
 * the server names a row as «educations.2.started_year».
 */
export const SHEET_PART_FIELDS: Record<SheetPart, string[]> = {
    name: ['surname', 'name', 'patronymic'],
    general: ['sex', 'birth_date', 'birth_place', 'nationality', 'citizenship'],
    educations: ['has_educations', 'educations'],
    degree: ['has_degrees', 'degrees'],
    languages: ['languages'],
    experience: ['has_experience', 'experiences'],
    award: ['has_awards', 'awards'],
    family: ['marital_status', 'spouse_name', 'spouse_birth_date', 'spouse_occupation', 'has_children', 'children', 'relatives'],
    passport: ['passport_series', 'passport_number', 'passport_issued_at', 'passport_issued_by', 'tax_number'],
    contacts: ['home_address', 'phone', 'sos_phone', 'sos_contact'],
    military: ['has_military_records', 'military_records'],
};

/** The eleven parts, named rather than numbered: the paper's figures are the card's own. */
export type SheetPart =
    | 'name'
    | 'general'
    | 'educations'
    | 'degree'
    | 'languages'
    | 'experience'
    | 'award'
    | 'family'
    | 'passport'
    | 'contacts'
    | 'military';

/**
 * The answer to a part whose records are added one at a time: whether there are
 * any at all.
 *
 * Three answers and not two, because an empty list says «нет» and «никто не
 * спрашивал» at once, and a card that cannot tell those apart sends somebody to
 * ask a question that was already answered.
 */
export type Said = '' | 'yes' | 'no';

/** One place of study, as the form holds it while it is being typed. */
export type EducationRow = {
    /**
     * The row behind this record, where it came off a card that already has
     * one. Absent on a record somebody has just added, and that is the whole
     * difference the save turns on: a row that names itself is corrected where
     * it stands and keeps the diploma clipped to it, so not attaching a file
     * means not changing the file. A row with nothing on file to point at has
     * nothing to keep.
     */
    id?: number;
    institution: string;
    faculty: string;
    specialty: string;
    qualification: string;
    started_year: string;
    graduated_year: string;
    /**
     * The diploma about to be attached, under the name it will be filed with.
     * No name is asked for beside it: a paper on this card is a PDF, and the
     * name of the file is the name of the paper — the box the file is chosen in
     * is where it is renamed, so nothing but the file itself travels.
     */
    document: File | null;
    /**
     * The name of the diploma already on file, where the record has one.
     * Nothing but a label: it says what is attached, so that an empty file box
     * does not read as a paper that went missing. It is left out of the
     * request — the row's own id is what keeps the paper, and the card knows
     * what it holds without being told.
     */
    attached?: string;
    /**
     * Whether that diploma has been asked to go, which is an answer and does
     * travel. Absent on a record somebody has just added, as the id is and for
     * the same reason: a row with nothing on file has no paper to take off.
     */
    document_remove?: boolean;
};

/**
 * One record of the three parts that are a line of text and a paper apiece —
 * a degree or title, a state award, a line of the military book.
 *
 * All three are the same two answers, so all three are held in one shape: a
 * card may name two degrees as easily as it names two universities, and three
 * row types that differed only in the word «name» would be three places to fix
 * the next time a paper changes how it travels.
 *
 * The fields mean what they mean on a place of study, down to why: the id is
 * what says this record is already on file and keeps the paper clipped to it,
 * `attached` is a label and travels nowhere, and `document_remove` is the only
 * way of asking for a paper to go — an empty file box has always meant «leave
 * it alone».
 */
export type PaperRow = {
    id?: number;
    name: string;
    document: File | null;
    attached?: string;
    document_remove?: boolean;
};

/** A previous job, known to the month, as the form holds it. */
export type JobRow = {
    organization: string;
    position: string;
    started_month: string;
    started_year: string;
    ended_month: string;
    ended_year: string;
    /** Why it ended; empty while the person still works there. */
    reason: string;
};

/**
 * A child, asked after in the same three answers as the rest of the family: the
 * name, the month they were born in, and where they work or study. Whose child
 * they are is not asked, which is the one question a relative has and these
 * three rows do not.
 */
export type ChildRow = { full_name: string; birth_date: string; occupation: string };

/**
 * One of the family a card names besides the children: what they are to this
 * person, their name, the month they were born in and what they do.
 */
export type RelativeRow = { relation: string; full_name: string; birth_date: string; occupation: string };

/** A language and how well it is spoken; the id is a string while it may still be a name. */
export type SpokenRow = { name: string; level: LanguageLevel };

export const blankEducation: EducationRow = {
    institution: '',
    faculty: '',
    specialty: '',
    qualification: '',
    started_year: '',
    graduated_year: '',
    document: null,
};

/** A record of one of the three paper lists nobody has answered yet. */
export const blankPaper: PaperRow = { name: '', document: null };

/**
 * A language nobody has named yet, at the level most of them are claimed at.
 *
 * Opened at «средний» rather than at nothing, because the level is always
 * answered — there is no «не выбрано» among the four — and a row added to say
 * what somebody speaks is about the language, not about the box beside it.
 */
export const blankSpoken: SpokenRow = { name: '', level: 'intermediate' };

export const blankJob: JobRow = { organization: '', position: '', started_month: '', started_year: '', ended_month: '', ended_year: '', reason: '' };

export const blankChild: ChildRow = { full_name: '', birth_date: '', occupation: '' };

export const blankRelative: RelativeRow = { relation: '', full_name: '', birth_date: '', occupation: '' };

/**
 * A sheet nobody has answered yet: what «Новый сотрудник» opens on, and what
 * every line of a card is read into on the way to a form.
 *
 * Each default is a decision rather than a blank, which is why they are written
 * down once here instead of in each page: a field that started on «мужской»
 * would make a card nobody answered read as a card that said «man».
 */
export const blankSheet: SheetValues = {
    surname: '',
    name: '',
    patronymic: '',
    // Nothing to begin with rather than «мужской»: a card nobody answered must
    // not read as a card that said «man».
    sex: '',
    birth_date: '',
    birth_place: '',
    nationality: '',
    citizenship: [],
    // Answered before the list is filled, as the children are: an empty list on
    // its own cannot say whether there is nothing to name or whether nobody has
    // been asked yet.
    has_educations: '',
    educations: [],
    // Asked the same way, and for the same reason: a card may name two degrees
    // as easily as two universities, and an empty list on its own cannot say
    // whether there are none or whether nobody has been asked.
    has_degrees: '',
    degrees: [],
    // One row from the start, because one language is asked for: everybody
    // speaks something, and a part that opened empty would read as a part that
    // may be skipped.
    languages: [{ name: '', level: 'intermediate' }],
    // The same question once more, about the jobs held before this one.
    has_experience: '',
    experiences: [],
    // What a state has given them, kept on the terms the degrees are kept on.
    has_awards: '',
    awards: [],
    marital_status: '',
    spouse_name: '',
    spouse_birth_date: '',
    spouse_occupation: '',
    has_children: '',
    children: [],
    relatives: [],
    passport_series: '',
    passport_number: '',
    passport_issued_at: '',
    passport_issued_by: '',
    tax_number: '',
    home_address: '',
    phone: '',
    sos_phone: '',
    sos_contact: '',
    // And how they stand with the army, asked as the two lists above are.
    has_military_records: '',
    military_records: [],
};

/** A flag the card keeps as three states, read into the three a list offers. */
export const asSaid = (said: boolean | null | undefined): Said => (said === null || said === undefined ? '' : said ? 'yes' : 'no');

/**
 * The lists the sheet offers an answer out of. Every one of them is a
 * suggestion and none of them is binding — a diploma may name a qualification
 * no list has heard of — so a page handed an empty list still takes whatever is
 * typed into the box.
 */
export interface SheetOptions {
    nationalities: string[];
    citizenships: string[];
    languages: string[];
    /** The usual qualifications and the ones already on file. */
    qualifications: string[];
    /** The degrees and titles the cards already filed have records of. */
    degrees: string[];
    /** And the awards and commendations theirs name. */
    awards: string[];
    /** And what their military records say. */
    militaries: string[];
    /** Why jobs have ended before, offered the same way and no more binding. */
    reasons: string[];
}

/**
 * The sheet as the server reads it. The form keeps what is easy to type —
 * «yes», a language the directory has not heard of, a row somebody started and
 * left blank — and this is where that turns into what the request means.
 *
 * Written once for both doors, because both read the same names: whatever
 * «Новый сотрудник» sends under `has_children`, the card's own save sends under
 * `has_children` too, and a rule about what an empty answer means cannot be
 * true at one door and false at the other.
 *
 * Only the lines that need turning come back, and the page spreads them over
 * the rest of what it holds. So a page that asks more than the sheet does —
 * «Новый сотрудник» also asks what the person was hired to do — keeps all of
 * it, and this says nothing about answers that are none of its business.
 */
/**
 * A paper asked to go, as the request puts it: the flag where somebody asked
 * for one, and nothing at all where they did not.
 *
 * Nothing rather than «нет», because the server reads a missing answer as
 * «leave the paper alone» — which is what a missing file has always meant on
 * this card, and what makes an ordinary save of an unchanged sheet safe. A
 * request that spelled the «нет» out would be carrying an instruction nobody
 * gave, and twenty-odd of them on every save.
 */
const askedToGo = (asked: boolean | undefined) => asked || undefined;

/**
 * One of the three paper lists on the way out: the records only under «Есть»,
 * each of them its id where it has one, its line of text and its paper.
 *
 * Written once for the three because they are one list three times over, and a
 * rule about what travels — the id yes, the name of the paper on file no — that
 * held for the degrees and not for the awards would be a bug nobody could see
 * from either call.
 */
const papersToSend = (said: Said, rows: PaperRow[]) =>
    said === 'yes'
        ? rows.map((row) => ({
              // A row already on the card is corrected where it stands and
              // keeps its paper; a row somebody has just added says nothing
              // here, and the save writes it anew.
              ...(row.id === undefined ? {} : { id: row.id }),
              name: row.name,
              document: row.document,
              // And the paper on file asked to go, where somebody asked. What
              // is on file is not sent back — `attached` was put there to be
              // read beside an empty file box, and the card knows what it holds
              // without being told.
              document_remove: askedToGo(row.document_remove),
          }))
        : [];

export const sheetToSend = (card: SheetValues) => ({
    languages: card.languages.filter((spoken) => spoken.name.trim() !== '').map((spoken) => ({ name: spoken.name.trim(), level: spoken.level })),
    // The three answers that open a list travel the same way: only «нет»
    // carries meaning on its own, and rows of their own say the rest.
    has_educations: card.has_educations === '' ? null : card.has_educations === 'yes',
    // And the rows go only under «Есть». A list left behind by an answer taken
    // back would reach the server as proof that there is something to name, and
    // the card would come back saying «есть» over «не выбрано».
    //
    // Written out field by field rather than handed over whole, because one of
    // the things the form keeps is not an answer: the name of the paper already
    // on file was put there to be read beside an empty file box, and the card
    // knows what it holds without being told. The row's id is an answer — it is
    // what says which record this is — so that travels.
    educations:
        card.has_educations === 'yes'
            ? card.educations.map((row) => ({
                  // A row already on the card, corrected where it stands and
                  // keeping its diploma; a row somebody has just added says
                  // nothing here, and the save writes it anew.
                  ...(row.id === undefined ? {} : { id: row.id }),
                  institution: row.institution,
                  faculty: row.faculty,
                  specialty: row.specialty,
                  qualification: row.qualification,
                  started_year: row.started_year,
                  graduated_year: row.graduated_year,
                  // The diploma itself and nothing beside it: the file's own
                  // name is the name of the paper, so there is no second field
                  // left to carry.
                  document: row.document,
                  // And the diploma on file asked to go, where somebody asked:
                  // the row keeps its id, so the save knows which paper is
                  // meant, and a row that is new has none to name.
                  document_remove: askedToGo(row.document_remove),
              }))
            : [],
    has_experience: card.has_experience === '' ? null : card.has_experience === 'yes',
    experiences: card.has_experience === 'yes' ? card.experiences : [],
    marital_status: card.marital_status || null,
    spouse_birth_date: card.spouse_birth_date || null,
    // Only «нет» carries meaning on its own; rows of their own say the rest.
    has_children: card.has_children === '' ? null : card.has_children === 'yes',
    children: card.has_children === 'yes' ? card.children.filter((child) => child.full_name.trim() !== '') : [],
    // The three parts that keep a list of records with a paper apiece, each
    // answered before its list is filled and each sending its rows only under
    // «Есть» — the educations' own terms, for the educations' own reasons.
    has_degrees: card.has_degrees === '' ? null : card.has_degrees === 'yes',
    degrees: papersToSend(card.has_degrees, card.degrees),
    has_awards: card.has_awards === '' ? null : card.has_awards === 'yes',
    awards: papersToSend(card.has_awards, card.awards),
    has_military_records: card.has_military_records === '' ? null : card.has_military_records === 'yes',
    military_records: papersToSend(card.has_military_records, card.military_records),
});

/**
 * Whether anything in the sheet is a file waiting to be uploaded.
 *
 * Asked because it decides how the save travels, and the two ways are not
 * interchangeable. A file can only go as multipart, and a multipart body cannot
 * say that a list is empty: a form field is a name and a value, a list with no
 * values appends no field, and the name never reaches the server at all — while
 * the sheet edits every one of its lists whole and the save asks for all of
 * them outright. So the sheet goes as plain JSON wherever it can, which is
 * every save that attaches nothing, and as multipart only when there is a paper
 * that leaves it no choice.
 */
export const carriesFile = (card: SheetValues): boolean =>
    card.educations.some((row) => row.document !== null) ||
    card.degrees.some((row) => row.document !== null) ||
    card.awards.some((row) => row.document !== null) ||
    card.military_records.some((row) => row.document !== null);

/** Said at all: «Нет» says something, an empty box does not. */
const said = (value: string) => value.trim() !== '';

/**
 * Whether each line of the sheet has an answer, keyed as
 * App\Support\PersonnelSheet keys its lines.
 *
 * This is that class's `answers()` said in the browser, and it has to go on
 * being the same rule: the figure over the sheet and the list beside it are
 * counted on the server while nothing is being changed and here while something
 * is, and a reader who filled a line in and watched it stay in «Не заполнено»
 * would have no way of telling which of the two was lying.
 *
 * A line is answered when it says something. «Нет» says something; an empty
 * line does not. Every part the card keeps as a flag over a list is read the
 * same way — «Есть дети» and «Нет» are both answers, while a card nobody has
 * asked holds neither — and what is counted is that answer rather than the rows
 * under it: how many universities a person went to is not a measure of how far
 * the sheet has got, and whether anybody asked them is.
 */
const ANSWERED: Record<string, (card: SheetValues) => boolean> = {
    surname: (card) => said(card.surname),
    name: (card) => said(card.name),
    patronymic: (card) => said(card.patronymic),
    sex: (card) => card.sex !== '',
    birth_date: (card) => said(card.birth_date),
    birth_place: (card) => said(card.birth_place),
    nationality: (card) => said(card.nationality),
    // Kept as a list of countries rather than as a line, but answered once like
    // a line: a person has a citizenship or has not said.
    citizenship: (card) => card.citizenship.length > 0,
    // The three lists that used to be a line apiece are counted by their
    // answer now, as the educations below are: «Есть» and «Нет» are both
    // answers, and how many degrees somebody holds is not a measure of how far
    // the sheet has got.
    degree: (card) => card.has_degrees !== '',
    award: (card) => card.has_awards !== '',
    military: (card) => card.has_military_records !== '',
    educations: (card) => card.has_educations !== '',
    work_experiences: (card) => card.has_experience !== '',
    marital_status: (card) => said(card.marital_status),
    children: (card) => card.has_children !== '',
    // The series and the number are one line of the sheet, as they are one line
    // of a passport.
    passport_number: (card) => said(card.passport_series) || said(card.passport_number),
    passport_issued_at: (card) => said(card.passport_issued_at),
    passport_issued_by: (card) => said(card.passport_issued_by),
    tax_number: (card) => said(card.tax_number),
    home_address: (card) => said(card.home_address),
    phone: (card) => said(card.phone),
    sos_phone: (card) => said(card.sos_phone),
};

/**
 * One line of the sheet, answered or not, as the list of what is still wanted
 * reads it while somebody is typing.
 *
 * A line this browser has never heard of counts as unanswered rather than as
 * answered: the server is the one that says which lines there are, and a line
 * added there and not here must show up as work to do rather than quietly
 * disappear from the count.
 */
export const isAnswered = (line: string, card: SheetValues): boolean => ANSWERED[line]?.(card) ?? false;

/**
 * The records still short of their paper, as the list beside the sheet reads
 * them while somebody is typing: AppSupportPersonnelSheet::papers() said in the
 * browser, on the same rule and for the same reason isAnswered() is here — the
 * server counts while nothing is being changed, this counts while something is,
 * and the two have to agree or neither can be believed.
 *
 * Not part of what isAnswered() counts: a paper is not an answer. A part naming
 * two degrees has been answered; whether either diploma has been handed in is
 * the other question.
 *
 * `title` says what the sheet calls a line, and says nothing for a line this
 * reader may not read — the server sends the titles of the lines it counted for
 * them, so a line that is not theirs to see is one this list cannot name and
 * therefore does not.
 *
 * Nothing is asked about what the card holds, which is what the old `held`
 * argument was for: every paper now hangs off a record, and each record carries
 * what is attached to it as `attached`. So the four lists answer for themselves,
 * and the one reading of «has a paper» below is the same for all of them.
 */
export const papersWanted = (card: SheetValues, title: (line: string) => string | undefined): string[] => {
    const wanted: string[] = [];

    /**
     * Whether a record has a paper behind it — one just chosen, or one on file
     * that nobody has asked to take off. The asking counts because it is an
     * answer already given: a sheet saved as it stands would leave that record
     * with nothing, and a list that waited for the save to say so would be
     * telling the reader what used to be true.
     */
    const has = (row: { document: File | null; attached?: string; document_remove?: boolean }) =>
        row.document !== null || (row.attached !== undefined && row.document_remove !== true);

    /**
     * One list named record by record. Named by what the record says — a card
     * may hold three degrees, and «Учёная степень, звание» three times over
     * says how many papers are missing without saying which — and by the part
     * alone where the record has not been filled in yet.
     */
    const over = (line: string, rows: { name: string; document: File | null; attached?: string; document_remove?: boolean }[]) => {
        const name = title(line);

        if (name === undefined) return;

        for (const row of rows) {
            if (!has(row)) wanted.push(said(row.name) ? `${name} — ${row.name.trim()}` : name);
        }
    };

    const schooling = title('educations');

    if (schooling !== undefined) {
        for (const record of card.educations) {
            // Asked for once the studying has ended: a diploma is what
            // finishing leaves behind, and somebody in their second year has
            // nothing to hand in yet. The three lists below have no such
            // moment — a degree conferred or an award given is a paper already
            // written — so every record of theirs wants one.
            if (record.graduated_year !== '' && !has(record)) {
                wanted.push(said(record.institution) ? `${schooling} — ${record.institution.trim()}` : schooling);
            }
        }
    }

    over('degree', card.degrees);
    over('award', card.awards);
    over('military', card.military_records);

    return wanted;
};
