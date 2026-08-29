<?php

namespace App\Libraries;

/**
 * Loads the ELOG prototype data (extracted from the original .dc.html mock-ups)
 * from app/Data/*.json. This stands in for a database while the ledger models
 * are still prototype-only.
 */
class Prototype
{
    protected static array $cache = [];

    public static function load(string $name): array
    {
        if (!isset(self::$cache[$name])) {
            $path = APPPATH . 'Data/' . $name . '.json';
            $raw  = is_file($path) ? file_get_contents($path) : '[]';
            self::$cache[$name] = json_decode($raw, true) ?? [];
        }

        return self::$cache[$name];
    }

    /** Persists a dataset back to app/Data/*.json and refreshes the in-memory cache. */
    public static function save(string $name, array $data): void
    {
        $path = APPPATH . 'Data/' . $name . '.json';
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        self::$cache[$name] = $data;
    }

    /** Format a KES amount the way the prototype does: "—" for 0, "(1,234)" for negatives. */
    public static function fmt(float|int $n): string
    {
        $n = (float) $n;
        if ($n === 0.0) {
            return '—';
        }
        $s = number_format(abs(round($n)));

        return $n < 0 ? "($s)" : $s;
    }
}
