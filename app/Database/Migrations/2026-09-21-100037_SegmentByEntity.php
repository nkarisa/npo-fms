<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;
use App\Libraries\EntityCalendar;

/**
 * Each entity's books are kept apart: a user works in one entity at a time, chosen
 * at the top of the page (App\Libraries\EntityScope).
 *
 * - users.last_entity_id: the entity someone last chose, so they come back to it
 *   at their next sign-in. Null until they choose one.
 * - every entity is given the head office's financial years and months, in the
 *   state the head office holds them, since a journal can only be posted into a
 *   period of its own entity (App\Libraries\EntityCalendar).
 */
class SegmentByEntity extends SchemaMigration
{
    public function up(): void
    {
        // Added without constraints: altering users on SQLite would rebuild it, which
        // the schema's triggers and views would not survive. EntityScope only honours
        // an entity the user still holds a role at.
        $this->forge->addColumn('users', ['last_entity_id' => $this->fk(true)]);

        EntityCalendar::fill($this->db);
    }

    public function down(): void
    {
        // Dropped in place: Forge rebuilds the table on SQLite. The periods given to
        // other entities stay: they may already hold postings.
        $this->db->query($this->sql('ALTER TABLE {users} DROP COLUMN last_entity_id'));
    }
}
