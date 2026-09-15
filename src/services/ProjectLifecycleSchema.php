<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/** Compatibility bridge for processes/tests that inspect a pre-0100 schema. */
final class ProjectLifecycleSchema
{
    /** @var \WeakMap<PDO,array<string,bool>>|null */
    private static ?\WeakMap $columns = null;

    public static function visibility(PDO $pdo, string $alias = 'projects', bool $portal = false): string
    {
        $prefix = $alias === '' ? '' : $alias . '.';
        $parts = [];
        if (self::hasProjectColumn($pdo, 'archived_at')) $parts[] = $prefix . 'archived_at IS NULL';
        if ($portal && self::hasProjectColumn($pdo, 'portal_publish_enabled')) $parts[] = $prefix . 'portal_publish_enabled=1';
        return $parts === [] ? '1=1' : implode(' AND ', $parts);
    }

    public static function hasProjectColumn(PDO $pdo, string $column): bool
    {
        self::$columns ??= new \WeakMap();
        if (!isset(self::$columns[$pdo])) {
            $columns = [];
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                foreach ($pdo->query('PRAGMA table_info(projects)')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $columns[(string)$row['name']] = true;
                }
            } else {
                $statement = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=\'projects\'');
                $statement->execute();
                foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $name) $columns[(string)$name] = true;
            }
            self::$columns[$pdo] = $columns;
        }
        return isset(self::$columns[$pdo][$column]);
    }

    public static function hasTable(PDO $pdo, string $table): bool
    {
        if (preg_match('/^[a-z0-9_]+$/D', $table) !== 1) return false;
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
        } else {
            $statement = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        }
        $statement->execute([$table]);
        return $statement->fetchColumn() !== false;
    }
}
