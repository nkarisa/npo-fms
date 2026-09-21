<?php

namespace App\Libraries\Storage;

use App\Repositories\RuleViolation;

/**
 * Documents on the server's own disk, under writable/uploads. There is no lock:
 * whoever can reach the disk can remove a file, so back the folder up with the
 * database. Good for development and a single server; S3Store for production.
 */
final class LocalStore implements DocumentStore
{
    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? WRITEPATH . 'uploads', '/') . '/';
    }

    public function uploadDir(): string
    {
        // Uploads are filed where they will stay: claiming one only changes its row.
        return 'documents';
    }

    public function put(string $key, string $source, string $mime, string $sha256, bool $lock): void
    {
        $path = $this->root . $key;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            throw new RuleViolation('Supporting documents cannot be stored right now.');
        }
        // Copied, not moved: one uploaded file can back more than one record (a quotation
        // document shared by two quotes), and PHP removes the upload itself afterwards.
        if (!copy($source, $path)) {
            throw new RuleViolation('The file could not be stored.');
        }
    }

    public function lock(string $key): string
    {
        return $key;
    }

    public function delete(string $key): void
    {
        @unlink($this->root . $key);
    }

    public function exists(string $key): bool
    {
        return is_file($this->root . $key);
    }

    public function link(string $key, string $filename, string $mime): ?string
    {
        return null;
    }

    public function path(string $key): ?string
    {
        return $this->root . $key;
    }
}
