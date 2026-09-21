<?php

namespace App\Libraries\Storage;

/**
 * Where supporting documents are kept: on the server's own disk (LocalStore) or
 * in an S3 bucket with Object Lock (S3Store). Config\Documents::$disk chooses.
 *
 * A file is named by its storage key, e.g. `documents/3f9c….pdf`. The attachments
 * table keeps that key with the file's name, type and SHA-256; no link to the
 * file is ever stored. A download is checked by the application and then served
 * from disk, or handed a presigned link that expires in minutes.
 */
interface DocumentStore
{
    /**
     * The folder new uploads wait in until a record claims them. Files there are
     * not locked, so an upload nobody attaches can be removed.
     */
    public function uploadDir(): string;

    /**
     * Stores a file under a key. `$lock` keeps it from being deleted or
     * overwritten for the retention period, where the store supports it.
     * `$sha256` (hex) lets the store check the file arrived whole.
     */
    public function put(string $key, string $source, string $mime, string $sha256, bool $lock): void;

    /**
     * Locks an upload that a record has claimed. Returns the key it is kept under
     * from now on, which may differ from the key it waited under.
     */
    public function lock(string $key): string;

    /**
     * Removes a file. A locked file cannot be removed before its retention ends:
     * the S3 store hides it, and the locked version stays in the bucket.
     */
    public function delete(string $key): void;

    public function exists(string $key): bool;

    /**
     * A link the browser can fetch the file from directly, valid for a few
     * minutes, or null when the store has none and the application serves the file.
     */
    public function link(string $key, string $filename, string $mime): ?string;

    /** The file on this server's disk, or null when it is not kept on disk. */
    public function path(string $key): ?string;
}
