<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\Storage\DocumentStore;
use Config\Documents;

/**
 * Supporting documents: the invoice behind a bill, the receipts behind an advance,
 * the signed agreement behind an award.
 *
 * A file is uploaded on its own first (upload()) and held against the person who
 * uploaded it. The form that creates or changes a record then names the files it
 * carries by id, and the record's repository claims them (claim()) in the same
 * transaction that writes the record — so a record refused by a rule leaves its
 * files unclaimed, to be tidied away after Config\Documents::$unclaimedHours,
 * rather than attached to nothing. Files are kept in the document store
 * (service('documents'): the server's disk, or S3 with Object Lock) under a
 * random name. The table records that storage key with the file's name, type and
 * SHA-256 — never a link to the file.
 *
 * Attached documents are evidence and are not removed from the record once it
 * has left draft; a journal still in draft can drop one (JournalRepository).
 */
final class AttachmentRepository extends Repository
{
    /** A file uploaded but not yet attached to a record. */
    public const UPLOAD = 'upload';

    /**
     * Records documents can be attached to after they exist: the table, how the
     * record is named on screen, and the permission that may add a document.
     */
    public const KINDS = [
        'journal'             => ['table' => 'journals',             'ref' => 'reference', 'label' => 'journal',          'permission' => 'journal.prepare'],
        'bill'                => ['table' => 'bills',                'ref' => 'reference', 'label' => 'bill',             'permission' => 'journal.prepare'],
        'advance'             => ['table' => 'advances',             'ref' => 'reference', 'label' => 'advance',          'permission' => 'journal.prepare'],
        'grant'               => ['table' => 'grants',               'ref' => 'award_ref', 'label' => 'award',            'permission' => 'settings.ledger'],
        'invoice'             => ['table' => 'invoices',             'ref' => 'reference', 'label' => 'invoice',          'permission' => 'journal.prepare'],
        'asset'               => ['table' => 'assets',               'ref' => 'tag',       'label' => 'asset',            'permission' => 'journal.prepare'],
        'verification_result' => ['table' => 'verification_results', 'ref' => 'id',        'label' => 'count',            'permission' => 'journal.prepare'],
        'donor_report'        => ['table' => 'donor_reports',        'ref' => 'reference', 'label' => 'donor report',     'permission' => 'journal.prepare'],
    ];

    private const DIR = 'documents';

    private Documents $config;

    private DocumentStore $store;

    public function __construct(?\CodeIgniter\Database\BaseConnection $db = null)
    {
        parent::__construct($db);
        $this->config = config(Documents::class);
        $this->store = service('documents');
    }

    // ------------------------------------------------------------------
    // Uploading and attaching
    // ------------------------------------------------------------------

    /**
     * Checks a file the browser sent: present, whole, not too large, and a type the
     * audit file accepts. Returns it in the shape store() and upload() take.
     *
     * @return array{path: string, name: string, size: int, mime: string}
     */
    public function accept(?\CodeIgniter\HTTP\Files\UploadedFile $file): array
    {
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            throw new RuleViolation('Choose the file to upload.');
        }
        if (!$file->isValid()) {
            throw new RuleViolation($file->getClientName() . ' did not upload: ' . $file->getErrorString());
        }

        return $this->check(['path' => $file->getTempName(), 'name' => $file->getClientName(), 'size' => (int) $file->getSize(), 'mime' => (string) $file->getMimeType()]);
    }

    /**
     * Checks a file already on disk against the size and type rules.
     *
     * @param array{path: string, name: string, size: int, mime: string} $file
     */
    public function check(array $file): array
    {
        if ($file['size'] > $this->config->maxBytes) {
            throw new RuleViolation($file['name'] . ' is larger than ' . self::size($this->config->maxBytes) . '.');
        }
        if ($file['size'] === 0) {
            throw new RuleViolation($file['name'] . ' is empty.');
        }
        if (!in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), $this->config->types, true)) {
            throw new RuleViolation($file['name'] . ' is not a document type the audit file accepts (PDF, image, Office, CSV, text or email).');
        }

        return $file;
    }

    /**
     * Keeps a file until a record claims it. Returns what the form shows while it
     * waits: its id, name and size.
     *
     * @param array{path: string, name: string, size: int, mime: string} $file
     * @return array{id: int, name: string, size: string}
     */
    public function upload(array $file, int $actorId): array
    {
        $this->check($file);
        $this->purgeUnclaimed();
        $key = null;

        try {
            $id = $this->transaction(function () use ($file, $actorId, &$key) {
                [$id, $key] = $this->store(self::UPLOAD, $actorId, $file, $actorId, $this->store->uploadDir());

                return $id;
            });
        } catch (\Throwable $e) {
            if ($key !== null) {
                $this->store->delete($key);
            }

            throw $e;
        }

        return ['id' => $id, 'name' => $file['name'], 'size' => self::size($file['size'])];
    }

    /**
     * Puts a file in the document store and records it against a record. Returns
     * the new attachment's id and its storage key, so a failed save can remove the
     * file (removeFiles()). A file stored with its record is locked at once; an upload
     * is locked when a record claims it.
     *
     * @param array{path: string, name: string, size: int, mime: string} $file
     * @return array{0: int, 1: string}
     */
    public function store(string $objectType, int $objectId, array $file, int $actorId, string $dir = self::DIR): array
    {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $key = $dir . '/' . bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
        $sha = hash_file('sha256', $file['path']);
        $mime = mb_substr($file['mime'] ?: 'application/octet-stream', 0, 100);
        $this->store->put($key, $file['path'], $mime, $sha, $objectType !== self::UPLOAD);

        $id = $this->insert('attachments', [
            'entity_id' => (new Lookups())->entityId(), 'object_type' => $objectType, 'object_id' => $objectId,
            'filename' => mb_substr($file['name'], 0, 255), 'mime_type' => $mime, 'size_bytes' => $file['size'],
            'storage_key' => $key, 'sha256' => $sha, 'uploaded_by' => $actorId, 'uploaded_at' => Clock::timestamp(),
        ]);

        return [$id, $key];
    }

    /**
     * Removes stored files whose rows are gone: a record that failed to save, a
     * journal draft that dropped a document. A locked file only disappears from
     * view; its locked version stays in the bucket until the retention ends.
     *
     * @param list<string> $keys
     */
    public function removeFiles(array $keys): void
    {
        foreach ($keys as $key) {
            $this->store->delete($key);
        }
    }

    /**
     * The uploads a form names, checked to be this person's and still unattached.
     * Call before writing, so a wrong id is refused before anything changes.
     *
     * @param mixed $ids what the form sent as `documents`
     * @return list<int>
     */
    public function pending(mixed $ids, int $actorId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : []), static fn ($id) => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $found = array_map('intval', array_column($this->rows(
            'SELECT id FROM {attachments} WHERE object_type = ? AND object_id = ? AND id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
            [self::UPLOAD, $actorId, ...$ids]
        ), 'id'));
        if (count($found) !== count($ids)) {
            throw new RuleViolation('A document on this form is no longer waiting to be attached — it may have been attached already, or removed after ' . $this->config->unclaimedHours . ' hours. Upload it again.');
        }

        return $ids;
    }

    /**
     * Attaches checked uploads (pending()) to a record and locks them in the store.
     * Call inside the record's transaction.
     */
    public function claim(array $ids, string $objectType, int $objectId): void
    {
        if ($ids === []) {
            return;
        }
        foreach ($this->rows('SELECT id, storage_key FROM {attachments} WHERE object_type = ? AND id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', [self::UPLOAD, ...$ids]) as $row) {
            $key = $this->store->lock($row['storage_key']);
            if ($key !== $row['storage_key']) {
                $this->db->table('attachments')->where('id', (int) $row['id'])->update(['storage_key' => $key]);
            }
        }
        $this->db->table('attachments')->whereIn('id', $ids)->where('object_type', self::UPLOAD)
            ->update(['object_type' => $objectType, 'object_id' => $objectId]);
    }

    /**
     * Attaches uploads to a record that already exists, from its own screen: the
     * signed agreement that arrived after the award was recorded, a photo taken at
     * the count, the report as submitted to the donor.
     *
     * @return list<array{id: int, name: string, size: string}> the record's documents now
     */
    public function attach(string $kind, string $ref, mixed $ids, int $actorId): array
    {
        $spec = self::KINDS[$kind] ?? throw new RuleViolation('Documents cannot be attached to a ' . $kind . '.');
        $id = $this->value('SELECT id FROM {' . $spec['table'] . '} WHERE ' . $this->db->escapeIdentifiers($spec['ref']) . ' = ?', [$ref])
            ?? throw new RuleViolation('There is no ' . $spec['label'] . ' ' . $ref . '.');
        $ids = $this->pending($ids, $actorId);
        if ($ids === []) {
            throw new RuleViolation('Choose at least one document to attach.');
        }

        $this->transaction(function () use ($ids, $kind, $id, $ref, $spec, $actorId) {
            $this->claim($ids, $kind, (int) $id);
            $names = array_column($this->rows('SELECT filename FROM {attachments} WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', $ids), 'filename');
            $this->audit($kind, (int) $id, (string) $ref, mb_substr('Supporting document' . (count($names) === 1 ? '' : 's') . ' added: ' . implode(', ', $names), 0, 255), $actorId);
        });

        return $this->for($kind, (int) $id);
    }

    /** Removes an upload that has not been attached yet. Only its uploader may. */
    public function discard(int $id, int $actorId): void
    {
        $row = $this->row('SELECT * FROM {attachments} WHERE id = ? AND object_type = ? AND object_id = ?', [$id, self::UPLOAD, $actorId])
            ?? throw new RuleViolation('Only a document still waiting to be attached can be removed. Documents on a record stay with it as evidence.');
        $this->db->table('attachments')->where('id', $id)->delete();
        $this->store->delete($row['storage_key']);
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /** @return list<array{id: int, name: string, size: string, by: string, on: string}> */
    public function for(string $objectType, int $objectId): array
    {
        return $this->byObject($objectType, [$objectId])[$objectId] ?? [];
    }

    /**
     * Documents for many records of one kind at once, by record id.
     *
     * @param list<int>|null $objectIds null for every record of the kind
     * @return array<int, list<array{id: int, name: string, size: string, by: string, on: string}>>
     */
    public function byObject(string $objectType, ?array $objectIds = null): array
    {
        if ($objectIds === []) {
            return [];
        }
        $in = $objectIds === null ? '' : ' AND a.object_id IN (' . implode(', ', array_fill(0, count($objectIds), '?')) . ')';
        $out = [];
        foreach ($this->rows(
            'SELECT a.id, a.object_id, a.filename, a.size_bytes, a.uploaded_at, u.short_name FROM {attachments} a LEFT JOIN {users} u ON u.id = a.uploaded_by
             WHERE a.object_type = ?' . $in . ' ORDER BY a.id',
            [$objectType, ...($objectIds ?? [])]
        ) as $a) {
            $out[(int) $a['object_id']][] = [
                'id' => (int) $a['id'], 'name' => $a['filename'], 'size' => self::size((int) $a['size_bytes']),
                'by' => (string) ($a['short_name'] ?? ''), 'on' => self::dmy(substr((string) $a['uploaded_at'], 0, 10)),
            ];
        }

        return $out;
    }

    /** How many documents a record carries. */
    public function count(string $objectType, int $objectId): int
    {
        return (int) $this->value('SELECT COUNT(*) FROM {attachments} WHERE object_type = ? AND object_id = ?', [$objectType, $objectId]);
    }

    /**
     * One document's row, for download, if this person may have it: they uploaded
     * it, or they hold a role at the entity it belongs to. The controller serves it
     * through BaseApiController::sendDocument().
     */
    public function file(int $id, int $actorId): ?array
    {
        $row = $this->row('SELECT * FROM {attachments} WHERE id = ?', [$id]);
        if ($row === null) {
            return null;
        }
        $mine = (int) $row['uploaded_by'] === $actorId;
        if ($row['object_type'] === self::UPLOAD && !$mine) {
            return null;
        }
        $reaches = $mine || (int) $this->value('SELECT COUNT(*) FROM {user_entity_roles} WHERE user_id = ? AND entity_id = ?', [$actorId, (int) $row['entity_id']]) > 0;

        return $reaches ? $row : null;
    }

    public static function size(int $bytes): string
    {
        return $bytes < 1024000 ? max(1, (int) round($bytes / 1024)) . ' KB' : number_format($bytes / 1048576, 1) . ' MB';
    }

    // ------------------------------------------------------------------

    /** Removes uploads nobody attached within the allowed time. */
    private function purgeUnclaimed(): void
    {
        $before = date('Y-m-d H:i:s', strtotime(Clock::timestamp()) - $this->config->unclaimedHours * 3600);
        foreach ($this->rows('SELECT id, storage_key FROM {attachments} WHERE object_type = ? AND uploaded_at < ?', [self::UPLOAD, $before]) as $row) {
            $this->db->table('attachments')->where('id', (int) $row['id'])->delete();
            $this->store->delete($row['storage_key']);
        }
    }
}
