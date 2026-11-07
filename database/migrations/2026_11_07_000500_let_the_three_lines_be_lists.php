<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «Учёная степень, звание», «Государственные награды, поощрения» and
     * «Отношение к воинской обязанности», kept as lists instead of as one line
     * apiece.
     *
     * Each of the three was a column of free text with one document behind it,
     * and each of the three was being asked to hold more than one answer: a
     * person has a degree and a title, two medals and a letter of thanks, a
     * military card and the page of it that was reissued. Written into one line
     * they came out as «кандидат экономических наук, доцент» — two answers in
     * one box, with one scan to prove both of them, and no way to attach the
     * second.
     *
     * So they are now what «Образование» is: answered «есть или нет» first, and
     * then filled a record at a time, each record one line and one paper of its
     * own. The flag is what makes «нет» tellable from «nobody has asked» — the
     * word «Нет» used to be written into the column itself for that, and a list
     * has no column to write it in.
     *
     * Nothing anybody wrote is lost. Every line that said something becomes one
     * record carrying that line's own document, every «Нет» becomes the flag
     * saying so, and an empty column stays the unanswered question it was.
     */
    public function up(): void
    {
        foreach (self::TABLES as $list) {
            Schema::create($list, function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                // The answer itself: «кандидат экономических наук», «Медаль
                // „Хизмати шоиста“», «военнообязанный, старший лейтенант
                // запаса». As long as the line it replaces was.
                $table->string('name', 200);
                // And the paper behind it, which is a file rather than a column:
                // the row keeps where it went and what it was called, the way a
                // diploma hangs off a place of study.
                $table->string('document_path')->nullable();
                $table->string('document_name')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('user_details', function (Blueprint $table) {
            // Three-valued, as the two beside them: null is the card nobody has
            // asked, false is «нет», and true is «есть» — which the rows on file
            // imply anyway.
            $table->boolean('has_degrees')->nullable()->after('has_experience');
            $table->boolean('has_awards')->nullable()->after('has_degrees');
            $table->boolean('has_military_records')->nullable()->after('has_awards');
        });

        // What the cards say, copied across line by line rather than in one
        // statement: the rows have to be read before they can be sorted into «a
        // record», «нет» and «nobody asked», and there are three columns of them
        // on a table with one row per colleague.
        foreach (self::LINES as $line => $table) {
            $said = [];
            $none = [];

            foreach (DB::table('user_details')->get(['user_id', $line, "{$line}_document_path", "{$line}_document_name"]) as $details) {
                $answer = trim((string) ($details->{$line} ?? ''));

                if ($answer === '') {
                    continue;
                }

                // «Нет» is the answer that there is none of it, and it is the
                // flag that carries it now — not a record saying «Нет», which
                // would be a list with one entry in it meaning the list is
                // empty.
                if ($answer === self::NONE) {
                    $none[] = $details->user_id;

                    continue;
                }

                $said[] = [
                    'user_id' => $details->user_id,
                    'name' => mb_substr($answer, 0, 200),
                    'document_path' => $details->{"{$line}_document_path"},
                    'document_name' => $details->{"{$line}_document_name"},
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // A record on file says «есть» by standing there, but the flag is
            // written all the same: it is what the sheet counts, and a card
            // whose list is emptied later must not fall back to «nobody asked».
            foreach (array_chunk($said, 100) as $rows) {
                DB::table($table)->insert($rows);
            }

            if ($said !== []) {
                DB::table('user_details')
                    ->whereIn('user_id', array_column($said, 'user_id'))
                    ->update([self::FLAGS[$line] => true]);
            }

            if ($none !== []) {
                DB::table('user_details')->whereIn('user_id', $none)->update([self::FLAGS[$line] => false]);
            }
        }

        Schema::table('user_details', function (Blueprint $table) {
            $table->dropColumn([
                'degree', 'degree_document_path', 'degree_document_name',
                'award', 'award_document_path', 'award_document_name',
                'military', 'military_document_path', 'military_document_name',
            ]);
        });
    }

    /**
     * The three lines again, each holding the first record of its list.
     *
     * One line cannot hold three records, which is the whole reason the lists
     * exist — so a card that named two degrees comes back naming the first of
     * them, and the papers of the rest are left on the disk for somebody to
     * look at rather than quietly dropped. A card that said «нет» says it in
     * the old words again.
     */
    public function down(): void
    {
        Schema::table('user_details', function (Blueprint $table) {
            $table->string('degree', 200)->nullable()->after('nationality');
            $table->string('degree_document_path')->nullable()->after('degree');
            $table->string('degree_document_name')->nullable()->after('degree_document_path');
            $table->string('award', 200)->nullable()->after('degree_document_name');
            $table->string('award_document_path')->nullable()->after('award');
            $table->string('award_document_name')->nullable()->after('award_document_path');
            $table->string('military', 200)->nullable()->after('award_document_name');
            $table->string('military_document_path')->nullable()->after('military');
            $table->string('military_document_name')->nullable()->after('military_document_path');
        });

        foreach (self::LINES as $line => $table) {
            foreach (DB::table($table)->orderBy('id')->get() as $record) {
                // The first record of the list and no other: later ones would
                // overwrite it, and the line has room for one.
                DB::table('user_details')
                    ->where('user_id', $record->user_id)
                    ->whereNull($line)
                    ->update([
                        $line => $record->name,
                        "{$line}_document_path" => $record->document_path,
                        "{$line}_document_name" => $record->document_name,
                    ]);
            }

            DB::table('user_details')
                ->where(self::FLAGS[$line], false)
                ->update([$line => self::NONE]);
        }

        Schema::table('user_details', function (Blueprint $table) {
            $table->dropColumn(['has_degrees', 'has_awards', 'has_military_records']);
        });

        foreach (self::TABLES as $list) {
            Schema::dropIfExists($list);
        }
    }

    /** What a line used to say when the answer was that there is none. */
    private const NONE = 'Нет';

    /** @var array<string, string> the column it was, and the table it becomes */
    private const LINES = [
        'degree' => 'user_degrees',
        'award' => 'user_awards',
        'military' => 'user_military_records',
    ];

    /** @var array<string, string> and the flag that now answers «есть или нет» for it */
    private const FLAGS = [
        'degree' => 'has_degrees',
        'award' => 'has_awards',
        'military' => 'has_military_records',
    ];

    /** @var list<string> */
    private const TABLES = ['user_degrees', 'user_awards', 'user_military_records'];
};
