<?php

namespace Turso\Driver\Laravel\Database;

use Exception;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\App;
use LibSQL;

class LibSQLConnection extends Connection
{
    public LibSQLDatabase $db;

    protected array $bindings = [];

    public function __construct(LibSQLDatabase $db, string $database = ':memory:', string $tablePrefix = '', array $config = [])
    {
        $libsqlDb = function () use ($db) {
            return $db;
        };
        parent::__construct($libsqlDb, $database, $tablePrefix, $config);

        $this->db = $db;
        $this->setReadPdo($libsqlDatabase);

        $this->schemaGrammar = $this->getDefaultSchemaGrammar();
    }

    public function sync(): void
    {
        $this->db->sync();
    }

    public function getDb(): LibSQL
    {
        return $this->db->getDb();
    }

    public function getConnectionMode(): string
    {
        return $this->db->getConnectionMode();
    }

    public function statement($query, $bindings = [])
    {
        $this->select($query, $bindings);

        return $this->isRunningMigrations();
    }

    public function getPdo()
    {
        return $this->db;
    }

    public function getReadPdo()
    {
        return $this->db;
    }

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        // Example method where query execution and fetching might occur
        $result = (array) parent::select($query, $bindings, $useReadPdo);

        // Convert result objects to arrays if they are not already
        $resultArray = array_map(function ($item) {
            return (array) $item;
        }, $result);

        return $resultArray;
    }

    protected function getDefaultQueryGrammar()
    {
        ($grammar = new LibSQLQueryGrammar())->setConnection($this);

        return $grammar;
    }

    /**
     * Get the default schema grammar instance.
     *
     * @return \Illuminate\Database\Schema\Grammars\Grammar
     */
    protected function getDefaultSchemaGrammar()
    {
        ($grammar = new LibSQLSchemaGrammar)->setConnection($this);

        return $this->withTablePrefix($grammar);
    }

    // You might already have this method, but ensure it correctly sets the schema grammar
    public function useDefaultSchemaGrammar()
    {
        if (is_null($this->schemaGrammar)) {
            $this->schemaGrammar = $this->getDefaultSchemaGrammar();
        }
    }

    public function createReadPdo(array $config): ?LibSQLDatabase
    {
        $db = function () use ($config) {
            return new LibSQLDatabase($config);
        };
        $this->setReadPdo($db);

        return $db();
    }

    protected function escapeBinary(mixed $value): string
    {
        $hex = bin2hex($value);

        return "x'{$hex}'";
    }

    protected function getDefaultPostProcessor(): LibSQLQueryProcessor
    {
        return new LibSQLQueryProcessor();
    }

    public function getSchemaBuilder(): LibSQLSchemaBuilder
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new LibSQLSchemaBuilder($this->db, $this);
    }

    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null): LibSQLSchemaState
    {
        return new LibSQLSchemaState($this, $files, $processFactory);
    }

    protected function isUniqueConstraintError(Exception $exception): bool
    {
        return boolval(preg_match('#(column(s)? .* (is|are) not unique|UNIQUE constraint failed: .*)#i', $exception->getMessage()));
    }

    public function escapeString($value)
    {
        // DISCUSSION: Open PR if you have best approach
        $escaped_value = str_replace(
            ['\\', "\x00", "\n", "\r", "\x1a", "'", '"'],
            ['\\\\', '\\0', '\\n', '\\r', '\\Z', "\\'", '\\"'],
            $value
        );

        return $escaped_value;
    }

    public function quote(string $value): string
    {
        return $this->escapeString($value);
    }

    protected function isRunningMigrations()
    {
        $commands = [
            'tenants:migrate',
            'tenants:rollback',
        ];

        return App::runningInConsole() && in_array($_SERVER['argv'][1], $commands);
    }
}
