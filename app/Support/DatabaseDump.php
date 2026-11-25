<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
use PDO;
use RuntimeException;

/**
 * The whole database as one SQL file (phase 35), written in PHP.
 *
 * Shared hosting has no `mysqldump` to call, and this system is deployed by
 * uploading files, so — like `Spreadsheet` — it uses only what PHP carries.
 * The file is plain SQL that phpMyAdmin or the `mysql` client imports.
 *
 * **One consistent moment.** On MySQL every table is read inside a single
 * `START TRANSACTION WITH CONSISTENT SNAPSHOT` at REPEATABLE READ, so a sale
 * posted while the backup runs is either wholly in it or wholly out of it —
 * never its stock without its batches, which `inventory:verify` would catch
 * on the restored books. That holds only for InnoDB tables, so another
 * engine is refused rather than dumped half-consistent.
 *
 * Rows stream to the file a thousand at a time; a table with a whole-number
 * `id` is read by that key, any other by its primary key. The data of the
 * session and cache tables is left out — it is not the books, and a restored
 * session would only sign somebody back in. Their structure stays.
 *
 * SQLite is supported for the tests, which run on it.
 */
final class DatabaseDump
{
    /** Tables kept empty in the dump: their rows are not the books. */
    public const DATA_SKIPPED = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

    private const CHUNK = 1000;

    private const ROWS_PER_INSERT = 200;

    private PDO $pdo;

    /** @var resource */
    private $file;

    public function __construct(private ConnectionInterface $connection)
    {
        $this->pdo = $connection->getPdo();
    }

    /**
     * Write the dump to `$path`.
     *
     * @return array{tables:int, rows:int}
     */
    public function writeTo(string $path): array
    {
        $this->file = fopen($path, 'wb') ?: throw new RuntimeException("Cannot write {$path}.");

        try {
            return $this->isMySql() ? $this->dumpMySql() : $this->dumpSqlite();
        } finally {
            fclose($this->file);
        }
    }

    private function isMySql(): bool
    {
        return in_array($this->connection->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /* ------------------------------------------------------------- MySQL */

    /** @return array{tables:int, rows:int} */
    private function dumpMySql(): array
    {
        $tables = $this->pdo->query(
            'SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
        )->fetchAll(PDO::FETCH_ASSOC);

        $base = array_values(array_filter($tables, fn ($t) => $t['TABLE_TYPE'] === 'BASE TABLE'));
        $views = array_values(array_filter($tables, fn ($t) => $t['TABLE_TYPE'] === 'VIEW'));

        foreach ($base as $table) {
            if (strcasecmp((string) $table['ENGINE'], 'InnoDB') !== 0) {
                throw new RuntimeException("Table {$table['TABLE_NAME']} is not InnoDB, so it cannot be backed up at one consistent moment.");
            }
        }

        $this->write("-- GO ENTERPRISES INVENTORY — database backup\n");
        $this->write('-- Made '.now()->format('Y-m-d H:i:s T')." from MySQL/MariaDB.\n");
        $this->write("-- Import into an EMPTY database. It drops and re-creates every table it holds.\n\n");
        $this->write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET time_zone='+00:00';\n\n");

        // Everything below reads the books as they stood at this instant.
        // TIMESTAMP columns are stored in UTC and shown in the session's zone.
        // The file says `SET time_zone='+00:00'`, so they must be read in UTC
        // too, or every created_at and posted_at would come back shifted by
        // the server's offset (eight hours here). The zone is put back after.
        $zone = (string) $this->pdo->query('SELECT @@session.time_zone')->fetchColumn();
        $this->pdo->exec("SET time_zone = '+00:00'");

        $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        $rows = 0;

        try {
            foreach ($base as $table) {
                $name = $table['TABLE_NAME'];
                $create = $this->pdo->query('SHOW CREATE TABLE '.$this->name($name))->fetch(PDO::FETCH_NUM)[1];

                $this->write("--\n-- {$name}\n--\n");
                $this->write('DROP TABLE IF EXISTS '.$this->name($name).";\n");
                $this->write($create.";\n");

                if (! in_array($name, self::DATA_SKIPPED, true)) {
                    $rows += $this->rows($name, $this->primaryKeyMySql($name));
                }

                $this->write("\n");
            }

            foreach ($views as $view) {
                $create = $this->pdo->query('SHOW CREATE VIEW '.$this->name($view['TABLE_NAME']))->fetch(PDO::FETCH_NUM)[1];
                $this->write('DROP VIEW IF EXISTS '.$this->name($view['TABLE_NAME']).";\n{$create};\n\n");
            }

            foreach ($this->pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC) as $trigger) {
                $create = $this->pdo->query('SHOW CREATE TRIGGER '.$this->name($trigger['Trigger']))->fetch(PDO::FETCH_ASSOC);
                $this->write("DELIMITER ;;\n".$create['SQL Original Statement'].";;\nDELIMITER ;\n\n");
            }
        } finally {
            $this->pdo->exec('COMMIT');
            $this->pdo->exec('SET time_zone = '.$this->pdo->quote($zone));
        }

        $this->write("SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n");

        return ['tables' => count($base), 'rows' => $rows];
    }

    /** @return list<string> */
    private function primaryKeyMySql(string $table): array
    {
        $statement = $this->pdo->prepare(
            "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY'
             ORDER BY ORDINAL_POSITION"
        );
        $statement->execute([$table]);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /* ------------------------------------------------------------ SQLite */

    /** @return array{tables:int, rows:int} */
    private function dumpSqlite(): array
    {
        $tables = $this->pdo->query(
            "SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->write("-- GO ENTERPRISES INVENTORY — database backup (SQLite)\n\nPRAGMA foreign_keys=OFF;\n\n");

        $rows = 0;

        foreach ($tables as $table) {
            $this->write('DROP TABLE IF EXISTS '.$this->name($table['name']).";\n{$table['sql']};\n");

            if (! in_array($table['name'], self::DATA_SKIPPED, true)) {
                $rows += $this->rows($table['name'], ['rowid']);
            }

            $this->write("\n");
        }

        foreach ($this->pdo->query("SELECT sql FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $index) {
            $this->write("{$index};\n");
        }

        $this->write("\nPRAGMA foreign_keys=ON;\n");

        return ['tables' => count($tables), 'rows' => $rows];
    }

    /* ------------------------------------------------------------ Shared */

    /**
     * Stream one table's rows as INSERT statements. Read by its key when the
     * key is one column, so the thousandth chunk costs what the first did;
     * otherwise in key order with an offset.
     *
     * @param  list<string>  $key
     */
    private function rows(string $table, array $key): int
    {
        $count = 0;
        $after = null;
        $offset = 0;
        $byKey = count($key) === 1;
        $order = $key === [] ? '' : ' ORDER BY '.implode(', ', array_map(fn ($c) => $c === 'rowid' ? 'rowid' : $this->name($c), $key));
        $select = $key === ['rowid'] ? 'SELECT rowid AS __rowid, * FROM ' : 'SELECT * FROM ';

        while (true) {
            if ($byKey) {
                $column = $key[0] === 'rowid' ? 'rowid' : $this->name($key[0]);
                $sql = $select.$this->name($table)
                    .($after === null ? '' : " WHERE {$column} > ".$this->pdo->quote((string) $after))
                    .$order.' LIMIT '.self::CHUNK;
            } else {
                $sql = $select.$this->name($table).$order.' LIMIT '.self::CHUNK.' OFFSET '.$offset;
            }

            $chunk = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            if ($chunk === []) {
                break;
            }

            if ($byKey) {
                $last = end($chunk);
                $after = $key[0] === 'rowid' ? $last['__rowid'] : $last[$key[0]];
            }

            $chunk = array_map(function (array $row) {
                unset($row['__rowid']);

                return $row;
            }, $chunk);

            foreach (array_chunk($chunk, self::ROWS_PER_INSERT) as $batch) {
                $columns = implode(', ', array_map(fn ($c) => $this->name($c), array_keys($batch[0])));
                $values = implode(",\n", array_map(fn (array $row) => '('.implode(', ', array_map(fn ($v) => $this->value($v), $row)).')', $batch));

                $this->write('INSERT INTO '.$this->name($table)." ({$columns}) VALUES\n{$values};\n");
            }

            $count += count($chunk);
            $offset += count($chunk);

            if (count($chunk) < self::CHUNK) {
                break;
            }
        }

        return $count;
    }

    private function value(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $value = (string) $value;

        // Bytes that are not text go as hex, so no character set can bend them.
        if (! mb_check_encoding($value, 'UTF-8')) {
            return $this->isMySql() ? '0x'.bin2hex($value) : "X'".bin2hex($value)."'";
        }

        return $this->pdo->quote($value);
    }

    private function name(string $name): string
    {
        return $this->isMySql()
            ? '`'.str_replace('`', '``', $name).'`'
            : '"'.str_replace('"', '""', $name).'"';
    }

    private function write(string $text): void
    {
        fwrite($this->file, $text);
    }
}
