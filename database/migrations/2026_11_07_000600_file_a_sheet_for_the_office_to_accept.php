<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «Личный листок», retyped by the person it is about and handed in rather
     * than written.
     *
     * Two roles are shown their own card and nothing besides, and until now
     * every line of it was personnel's to correct: somebody who moved house
     * rang the office and read out the new address. They may now retype the
     * sheet themselves — but what they press is «Отправить» and not
     * «Сохранить», because a card nobody checks is a card that says whatever
     * its owner last felt like typing, and a passport number on it is a figure
     * other papers are made out from.
     *
     * So a row here is a request and not a save. It carries the whole sheet as
     * it was typed — the whole of it, not the lines that changed, because what
     * the office has to be shown is the sheet beside the card, and a diff
     * assembled out of a handful of keys cannot be read as the paper it is. It
     * is written onto the card when somebody in the office accepts it, and
     * nothing of it reaches the card before that.
     *
     * The papers come with it, which is the one part that cannot wait: a file is
     * on the disk the moment it is uploaded or it is nowhere, and a browser
     * cannot be asked to hold a scanned diploma until a decision is made. So
     * they go to the private disk at once and the paths are kept twice over —
     * once inside the payload, where the record they hang off names them, and
     * once in a list of their own. The list is what makes a refusal clean: the
     * payload is a nest of records and walking it to find the files of a request
     * nobody wants is work that goes wrong quietly, while a flat list of what
     * this request brought in can be deleted without reading anything.
     *
     * One request per person at a time, by the rule the application keeps rather
     * than by one the table enforces: a second «Отправить» replaces the first,
     * since the first was the same sheet with fewer corrections on it and a
     * queue of them would have the office accepting them in the wrong order.
     * Not a unique index, because the accepted and rejected ones stay on file
     * beside the pending one — a rejection is read by its author, with the
     * reason on it, until they send the sheet again.
     */
    public function up(): void
    {
        Schema::create('sheet_change_requests', function (Blueprint $table) {
            $table->id();
            // Whose sheet, which is also who typed it: these requests are
            // nobody's to file on somebody else's behalf. The card goes and the
            // requests about it go with it.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // pending | accepted | rejected.
            $table->string('status', 20);
            // The whole sheet as it was validated, the uploaded papers already
            // on the disk and written in as «document_path» and
            // «document_name»: by the time this row exists the request is a
            // thing that can be read on its own, with nothing left in flight.
            $table->json('payload');
            // And the files this request brought in, flat, so a refusal can take
            // them off the disk without walking the payload.
            $table->json('papers');
            // Why it was sent back, in the words of whoever sent it back. There
            // is no refusing without one — a sheet returned with no reason is a
            // sheet that comes back unchanged.
            $table->text('comment')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // The one question asked of this table on every card: has this
            // person anything waiting. Everything else about a request is read
            // by its id.
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheet_change_requests');
    }
};
