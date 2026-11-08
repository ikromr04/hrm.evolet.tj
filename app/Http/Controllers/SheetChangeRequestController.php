<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitSheetRequest;
use App\Models\SheetChangeRequest;
use App\Models\User;
use App\Notifications\SheetChangeAccepted;
use App\Notifications\SheetChangeRejected;
use App\Notifications\SheetChangeRequested;
use App\Support\Access;
use App\Support\DocumentStore;
use App\Support\EmployeeCard;
use App\Support\PersonnelSheet;
use App\Support\SheetDiff;
use App\Support\SheetWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * «Личный листок» handed in by the person it is about, and answered by the
 * office.
 *
 * The two roles that are shown their own card may now retype their own sheet,
 * which they could not do before: an address changed by ringing the office and
 * reading it out. What they press is «Отправить» and not «Сохранить». Nothing
 * of what they typed reaches the card here — a row is filed, a sysadmin or
 * somebody in HR reads the sheet beside what is on file, and the card is
 * written only when one of them accepts it. The right to change a line and the
 * right to ask for it to be changed are two different things, and the second
 * one is this door.
 *
 * Which is not caution for its own sake. A card is where the company's papers
 * are made out from — a passport number, a tax number, the name of a spouse —
 * and a figure nobody checked is a figure that turns up wrong on a document
 * somebody has to sign.
 *
 * Accepting writes through App\Support\SheetWriter, the very code a direct save
 * goes through, so that a sheet accepted weeks after it was typed is written
 * exactly as it would have been written on the day. The request was validated
 * on the way in by the rules a save is validated by, for the same reason: a
 * request checked more gently than a save is one the office accepts and the
 * card then refuses.
 *
 * The papers are the one thing that cannot wait for the decision. A file is
 * kept the moment it arrives or it is lost — a browser cannot be asked to hold
 * a scanned diploma for three days — so the uploads go to the private disk at
 * once, through the same helpers a save puts them away with, and the payload
 * carries where they went instead of the files themselves. They hang «at the
 * request» until it is answered: accepted, the records that name them land on
 * the card and the files are already where the card expects them; refused, they
 * come off the disk, because a document nothing points at is a diploma lying
 * about in a folder.
 */
class SheetChangeRequestController extends Controller
{
    /**
     * The lists of the sheet that carry a paper: what the form calls each, and
     * the line of the sheet it hangs off.
     *
     * The line is for the reader. A request is discussed as the sheet it is, so
     * a diploma shows as «Образование» and a scan of a medal as
     * «Государственные награды, поощрения», rather than as the name of a
     * payload key — and the names are PersonnelSheet's, which is where every
     * other page of this card takes them from.
     *
     * @var array<string, string>
     */
    private const PAPERED = [
        'educations' => 'educations',
        'degrees' => 'degree',
        'awards' => 'award',
        'military_records' => 'military',
    ];

    /**
     * The sheet filed for the office to look at.
     *
     * One request per person, and «Отправить» again replaces the one waiting
     * rather than queueing behind it: the second sheet is the first with more
     * corrections on it, and two of them would have the office accepting the
     * stale one on top of the fresh. Its papers go with it — nothing on the
     * card ever pointed at them, so there is nothing left to point at them once
     * the row is gone. So do the notes the office was sent about it: a bell
     * holding two lines about one person's listok is a bell that has to be
     * worked out, and one of the two would open on a request that is no longer
     * there.
     *
     * The row is written and the old one dropped inside one transaction, so
     * there is never a moment with two pending requests nor one with none. The
     * files of the dropped request are deleted afterwards, for the reason the
     * sheet's own save deletes afterwards: a file removed inside a transaction
     * is gone whether or not the rows come back.
     *
     * A sheet that asks for nothing is refused. The office would otherwise be
     * handed a page of «без изменений» to read line by line and a decision to
     * make about nothing, and the person who sent it would be waiting on an
     * answer to a question they did not ask. It is refused here and not only
     * greyed out in the page, because the page can only compare what it was
     * given: the card may have been corrected by the office while this sheet
     * was open, and then the one thing that was typed is already there.
     */
    public function store(SubmitSheetRequest $request): RedirectResponse
    {
        $author = $request->user();

        /** @var list<string> $papers */
        $papers = [];
        $payload = $this->stored($author, $request->validated(), $papers);

        // Read against the card as the office would read it, which is the only
        // reading that answers «is anything being asked for». The papers are on
        // disk by now — a document is a change, and a change cannot be seen
        // before the file it is — so a refusal takes them off again.
        if (collect(SheetDiff::between($author, $payload))->doesntContain('changed', true)) {
            foreach ($papers as $path) {
                DocumentStore::forget($path);
            }

            throw ValidationException::withMessages([
                'sheet' => 'В листке ничего не изменилось — отправлять нечего.',
            ]);
        }

        $replaced = SheetChangeRequest::query()->where('user_id', $author->id)->pending()->get();

        $filed = DB::transaction(function () use ($author, $payload, $papers, $replaced) {
            if ($replaced->isNotEmpty()) {
                // And the notes the office was sent about them. The request
                // they point at is about to stop existing, so a bell left
                // holding them offers two lines about one sheet — one of them
                // opening on nothing — and the office has to work out which of
                // the two is the sheet to read. The one that is still true is
                // filed a moment later, below.
                //
                // Only the «пришёл листок» notes: a decision taken on an
                // earlier request is a thing that happened, and the person it
                // was sent to keeps it.
                DatabaseNotification::query()
                    ->where('type', SheetChangeRequested::class)
                    ->whereIn('data->request_id', $replaced->modelKeys())
                    ->delete();

                $replaced->toQuery()->delete();
            }

            return SheetChangeRequest::query()->create([
                'user_id' => $author->id,
                'status' => SheetChangeRequest::PENDING,
                'payload' => $payload,
                'papers' => $papers,
            ]);
        });

        foreach ($replaced->pluck('papers')->flatten() as $path) {
            DocumentStore::forget($path);
        }

        // Everybody who may answer it, because nobody is on duty for this in
        // particular: a sheet left waiting because the one person told about it
        // was on leave is a sheet the author assumes was ignored.
        Notification::send(
            User::query()->active()->role(Access::EVERYTHING)->get(),
            new SheetChangeRequested($filed),
        );

        return back();
    }

    /**
     * One request read as the sheet beside the card.
     *
     * Every line of the sheet is shown and not only the ones that differ. The
     * question being answered is «is this the right sheet», which cannot be
     * answered from the corrections alone — and a page built out of what a form
     * claimed to have touched would be taking the form's word for it.
     *
     * The author reads it too. They may follow the link out of their own card
     * to see what they sent and what was said about it; what they may not do is
     * answer it, which is what «canReview» is for.
     */
    public function show(Request $request, SheetChangeRequest $sheetRequest): Response
    {
        $this->mayRead($request, $sheetRequest);

        // The note that led here is read, because this is the reading of it.
        // The bell marks a row read when it is pressed, but that is only one of
        // the ways in: the card carries a callout straight to a waiting sheet,
        // the breadcrumb comes back to it, and an address can be kept. A line
        // still bold in the bell after its page has been read is a list that
        // counts work twice.
        //
        // This reader's own notes and no others, and all of them about this
        // request — the office was sent one note apiece, and the author may
        // hold both the refusal and the sheet that answered it.
        $request->user()->unreadNotifications()
            ->whereIn('data->request_id', [$sheetRequest->id])
            ->update(['read_at' => now()]);

        $employee = $sheetRequest->user;
        $reviewer = $sheetRequest->reviewer;

        return Inertia::render('sheet-requests/show', [
            'request' => [
                'id' => $sheetRequest->id,
                'status' => $sheetRequest->status,
                // When it was sent, which is when the row was filed: a request
                // is never written twice, so «created_at» is the sending.
                'submitted_at' => $sheetRequest->created_at?->toIso8601String(),
                'reviewed_at' => $sheetRequest->reviewed_at?->toIso8601String(),
                'comment' => $sheetRequest->comment,
                'employee' => [
                    'id' => $employee->id,
                    // In full, patronymic and all: this is the personnel sheet
                    // of one person being accepted onto their card, and «Иванов
                    // И.» is not how that is signed off.
                    'name' => trim("{$employee->surname} {$employee->name} {$employee->patronymic}"),
                    'number' => $employee->employee_number,
                ],
                // Who answered it, for the author reading their own request:
                // «Сотрудник — я сам» is a line that says nothing, and the one
                // thing they want off this page is whose decision it was. Null
                // while nobody has answered, and null on a row answered before
                // the application started writing that down.
                'reviewer' => $reviewer === null ? null : [
                    'id' => $reviewer->id,
                    'name' => trim("{$reviewer->surname} {$reviewer->name} {$reviewer->patronymic}"),
                ],
            ],
            // The comparison kept at the decision where there is one, and the
            // live one where there is not. A request still waiting is read
            // against the card as it stands this second, because the question
            // is whether anything is still being asked for; an answered one is
            // read against the card as it stood when it was answered, because
            // the question is what was decided — and after an acceptance the
            // live comparison would answer «без изменений» to every line.
            'lines' => $sheetRequest->lines ?? SheetDiff::between($employee, $sheetRequest->payload ?? []),
            // Each with the address to open it by and not the place it lies:
            // where a file is kept is the application's business, and a path on
            // a page is a folder somebody will try to walk.
            'papers' => array_map(
                fn (array $paper) => Arr::except($paper, 'path'),
                $this->papers($sheetRequest),
            ),
            // Whether this reader may answer it — and whether there is anything
            // left to answer. A request already accepted or sent back is read
            // and not decided, by anybody.
            'canReview' => Access::everything($request->user()) && $sheetRequest->status === SheetChangeRequest::PENDING,
            // Which of the two readers this is. The page is the same page for
            // both — the whole sheet, the papers, the decision — but the facts
            // over it are not: the office is told whose sheet it is, and the
            // person whose sheet it is already knows that and is told who read
            // it instead.
            'isAuthor' => $request->user()->is($employee),
        ]);
    }

    /**
     * Accepted: the sheet written onto the card, in the words it was sent in.
     *
     * The whole of it in one transaction with the answer on the request itself,
     * so that a card written from a request is never left with the request
     * still waiting — the office would read it again and write the same sheet
     * twice, and the second writing would be against a card the first one had
     * already changed.
     *
     * The files the writing stops pointing at come off the disk after the
     * transaction and not inside it, for the reason a direct save does it that
     * way: a write that fails leaves the card with the papers it still names.
     * The request's own papers are left alone — the card points at them now.
     */
    public function accept(Request $request, SheetChangeRequest $sheetRequest): RedirectResponse
    {
        $this->mayReview($request);

        if ($sheetRequest->status !== SheetChangeRequest::PENDING) {
            return back()->with('notice', 'Эта заявка уже разобрана.');
        }

        $employee = $sheetRequest->user;

        // Read before it is written, and kept. A second from now the card will
        // say what the sheet says, and this same comparison would answer «без
        // изменений» to every line of it — leaving whoever accepted the sheet,
        // and the person who sent it, looking at a record of nothing. What was
        // decided is what the card said at the moment of deciding.
        $decided = SheetDiff::between($employee, $sheetRequest->payload ?? []);

        $replaced = DB::transaction(function () use ($request, $sheetRequest, $employee, $decided) {
            $orphaned = SheetWriter::write($employee, $sheetRequest->payload ?? []);

            $sheetRequest->update([
                'status' => SheetChangeRequest::ACCEPTED,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'lines' => $decided,
                // Nothing to say: the sheet is on the card, which is the answer.
                'comment' => null,
            ]);

            return $orphaned;
        });

        foreach ($replaced as $path) {
            DocumentStore::forget($path);
        }

        $employee->notify(new SheetChangeAccepted($sheetRequest));

        return back();
    }

    /**
     * Sent back, with the reason on it.
     *
     * The reason is insisted on. A sheet returned with nothing said about it is
     * a sheet that comes back unchanged — and the author cannot ask, because
     * they do not know who read it.
     *
     * The papers go with the refusal. They were uploaded for a request nothing
     * will ever point at, and a diploma left in a folder with no row naming it
     * is a scan of somebody's name and date of birth that nothing will come
     * looking for again.
     */
    public function reject(Request $request, SheetChangeRequest $sheetRequest): RedirectResponse
    {
        $this->mayReview($request);

        if ($sheetRequest->status !== SheetChangeRequest::PENDING) {
            return back()->with('notice', 'Эта заявка уже разобрана.');
        }

        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:1000'],
        ], [
            'comment.required' => 'Напишите, что нужно исправить.',
        ], [
            'comment' => 'причина',
        ]);

        $sheetRequest->update([
            'status' => SheetChangeRequest::REJECTED,
            'comment' => $validated['comment'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            // What was turned down, as it read when it was turned down. The
            // card is left alone by a refusal, so this is the same comparison
            // the page would make live today — but not the one it would make
            // next month, after the office has corrected two of these lines
            // themselves, and the refusal would then be a page about lines
            // nobody ever asked to change.
            'lines' => SheetDiff::between($sheetRequest->user, $sheetRequest->payload ?? []),
        ]);

        foreach ($sheetRequest->papers ?? [] as $path) {
            DocumentStore::forget($path);
        }

        $sheetRequest->user->notify(new SheetChangeRejected($sheetRequest));

        return back();
    }

    /**
     * One paper of a request, handed over to be looked at.
     *
     * Read by the same pair the request itself is read by: the office has to
     * open the diploma to accept the record that names it, and the author may
     * check that the right file went.
     *
     * Which files may be read here is the request's own list and nothing
     * assembled out of what was asked for. The address carries a fingerprint of
     * the path rather than the path, so there is nothing to climb out of and no
     * folder to guess at — a name that is not one of this request's papers is
     * simply not found.
     */
    public function paper(Request $request, SheetChangeRequest $sheetRequest, string $paper): BinaryFileResponse
    {
        $this->mayRead($request, $sheetRequest);

        $papers = collect($this->papers($sheetRequest))->firstWhere('id', $paper);

        abort_if($papers === null, 404);

        // Or the request outlived its files: a refusal takes them off the disk
        // and leaves the row to be read.
        abort_unless(DocumentStore::disk()->exists($papers['path']), 404);

        return DocumentStore::answer($request, $papers['path'], $papers['name']);
    }

    /**
     * The sheet as validated, with its uploads put away.
     *
     * The files are stored here and now, through the very helpers a save puts
     * them away with — EmployeeCard::educationRow() for a diploma and
     * paperRow() for the three lists that are a line of text and one paper
     * apiece — so that a record comes out of this carrying «document_path» and
     * «document_name» exactly as it would have gone onto the card. Which is
     * what lets the payload be written as it stands and read back weeks later
     * with nothing in flight.
     *
     * The flag asking for a paper on the card to be taken off is left where it
     * is. It is not about the file being uploaded, it is an instruction about a
     * document already on the card, and SheetWriter honours it when the request
     * is accepted — honouring it now would be changing a card nobody has
     * agreed to change.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $papers  every path this request put on the disk, filled in as it goes
     * @return array<string, mixed>
     */
    private function stored(User $author, array $data, array &$papers): array
    {
        foreach (array_keys(self::PAPERED) as $list) {
            if (! array_key_exists($list, $data) || ! is_array($data[$list])) {
                continue;
            }

            $data[$list] = array_map(function (mixed $record) use ($author, $list, &$papers) {
                $row = $list === 'educations'
                    ? EmployeeCard::educationRow($author, is_array($record) ? $record : [])
                    : EmployeeCard::paperRow($author, is_array($record) ? $record : []);

                if (isset($row['document_path'])) {
                    $papers[] = $row['document_path'];
                }

                return $row;
            }, $data[$list]);
        }

        return $data;
    }

    /**
     * The papers of a request, as the page that reads it shows them.
     *
     * Read out of the payload rather than off the flat list of paths, because
     * the payload is the only place that says what each file is: which line of
     * the sheet it hangs off, and what the person called it. The flat list
     * exists for the other job — deleting them when the request is refused,
     * which has nothing to read and must not depend on walking a nest of
     * records correctly.
     *
     * The id is a fingerprint of the path and not the path. A path in an
     * address is a folder somebody can walk out of; a fingerprint is only ever
     * looked up in this request's own payload, so it names one of these files
     * or it names nothing. Which is also why the path travels in these rows but
     * not to the page: this list answers two questions, and only one of them is
     * the page's.
     *
     * @return list<array{id: string, line: string, name: string, url: string, path: string}>
     */
    private function papers(SheetChangeRequest $sheetRequest): array
    {
        $papers = [];

        foreach (self::PAPERED as $list => $line) {
            foreach ((array) ($sheetRequest->payload[$list] ?? []) as $record) {
                $path = is_array($record) ? ($record['document_path'] ?? null) : null;

                if (! is_string($path) || $path === '') {
                    continue;
                }

                $id = sha1($path);

                $papers[] = [
                    'id' => $id,
                    'line' => PersonnelSheet::LINES[$line],
                    // What they called it, or what the disk called it where
                    // somehow nobody did: a link with no words on it is a link
                    // nobody presses.
                    'name' => is_string($record['document_name'] ?? null) && $record['document_name'] !== ''
                        ? $record['document_name']
                        : basename($path),
                    'url' => route('sheet_requests.paper', [$sheetRequest, $id]),
                    // Where it actually lies. Never sent anywhere — the page is
                    // given this list without it.
                    'path' => $path,
                ];
            }
        }

        return $papers;
    }

    /**
     * Whose business this request is to read: the office, and the person whose
     * sheet it is.
     *
     * The author is in it because they are shown it — the card links to the
     * request they sent, so that what was asked for and what was said about it
     * is one page and not a sentence under a button.
     */
    private function mayRead(Request $request, SheetChangeRequest $sheetRequest): void
    {
        abort_unless(
            Access::everything($request->user()) || $request->user()->is($sheetRequest->user),
            403,
        );
    }

    /**
     * And whose it is to answer: the office alone.
     *
     * Asked here rather than on the route because the roles this feature is for
     * pass no ordinary gate at all — Gate::before refuses them anything that is
     * not on their own short list, and «may read my own request» is not a right
     * in that list but a question about the pair of them.
     */
    private function mayReview(Request $request): void
    {
        abort_unless(Access::everything($request->user()), 403);
    }
}
