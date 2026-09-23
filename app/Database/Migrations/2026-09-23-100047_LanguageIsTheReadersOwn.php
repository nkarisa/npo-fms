<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * The interface language becomes one person's preference rather than one
 * browser's.
 *
 * It used to live only in the elog_locale cookie: it followed the machine, not
 * the reader. Two people sharing a workstation at a field office read in
 * whichever language the last of them had picked, and someone who chose
 * Kiswahili at the office was back in English on their own laptop. Neither is
 * what a reading preference should do.
 *
 * users.locale_id has been on the table since the schema was laid down but
 * nothing ever read it, and every user was stamped with the organisation's
 * baseline language on the way in. It now records what a reader actually chose in
 * the top bar (App\Libraries\I18n::forRequest), so that stamp has to go: left in
 * place it would read as "this person chose English" and would quietly override
 * the browser's own language for everyone who has never touched the switcher.
 * Null means not yet chosen, and the browser's Accept-Language answers as before.
 *
 * Nobody's screen reads differently the morning after: an instance running today
 * resolves its language from the cookie or the browser, and both still do exactly
 * that until someone picks a language for themselves.
 */
class LanguageIsTheReadersOwn extends SchemaMigration
{
    public function up(): void
    {
        $this->db->table('users')->where('locale_id IS NOT NULL', null, false)->update(['locale_id' => null]);
    }

    /**
     * Irreversible by intention. Going back would mean inventing a choice for
     * everyone who has made one, and the column reads the same either way to the
     * code that came before — nothing read it.
     */
    public function down(): void
    {
    }
}
