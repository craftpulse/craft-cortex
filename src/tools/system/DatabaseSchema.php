<?php

namespace craftpulse\herald\tools\system;

use Craft;
use craft\helpers\StringHelper;
use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\IsReadOnly;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\support\Schema;
use craftpulse\herald\tools\ToolException;
use yii\db\TableSchema;

/**
 * =========================================================================
 * `database_schema` tool — Yii schema introspection, no query execution.
 *
 * Returns table / column / index / foreign-key metadata so the AI can
 * reason about Craft's schema without running raw SQL. Hard rule: this
 * tool never executes queries against table contents — only against the
 * DB's information_schema (or equivalent) via Yii.
 *
 * Modes:
 *   - default: every table with full structure.
 *   - `tables: ["table1", "table2"]`: only the listed tables.
 *   - `mode: "list"`: just the table names + row count estimates (cheap
 *     summary view; useful before requesting full structure).
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
#[IsReadOnly]
#[IsIdempotent]
class DatabaseSchema extends AbstractTool
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getName(): string
    {
        return 'database_schema';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Read the database schema: tables, columns (type / nullable / default), ' .
            'primary keys, indexes, foreign keys (with onDelete/onUpdate). Pass `tables: ' .
            '["x", "y"]` to scope to specific tables, or `mode: "list"` for a names-only ' .
            'summary. Never executes data queries.';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getInputSchema(): array
    {
        return Schema::object([
            'mode' => Schema::string()->enum(['default', 'list']),
            'tables' => Schema::array(Schema::string())
                ->description('Limit output to these table names (with or without prefix).'),
        ])->toArray();
    }

    /**
     * @inheritdoc
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array
    {
        $db = Craft::$app->getDb();
        $schema = $db->getSchema();
        $prefix = $db->tablePrefix;
        $allNames = $schema->getTableNames();

        $filter = $arguments['tables'] ?? null;
        if (is_array($filter) && $filter !== []) {
            $allNames = $this->_applyFilter($allNames, $filter, $prefix);
        }

        if ($this->_mode($arguments) === 'list') {
            return [
                'mode' => 'list',
                'driver' => $db->getDriverName(),
                'tablePrefix' => $prefix,
                'tables' => array_map(
                    static fn(string $name): array => ['name' => $name],
                    $allNames,
                ),
                'count' => count($allNames),
            ];
        }

        $tables = [];
        foreach ($allNames as $name) {
            $tableSchema = $schema->getTableSchema($name);
            if ($tableSchema === null) {
                continue;
            }

            $tables[] = $this->_serializeTable($tableSchema);
        }

        return [
            'driver' => $db->getDriverName(),
            'tablePrefix' => $prefix,
            'tables' => $tables,
            'count' => count($tables),
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * Filter table names against a caller-supplied list. Accepts both
     * prefixed and unprefixed forms.
     *
     * @param string[] $allNames
     * @param array<int|string,mixed> $filter
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _applyFilter(array $allNames, array $filter, string $prefix): array
    {
        $needles = [];
        foreach ($filter as $needle) {
            if (!is_string($needle) || $needle === '') {
                continue;
            }

            $needles[$needle] = true;
            // Also match the prefixed form.
            if (!StringHelper::startsWith($needle, $prefix)) {
                $needles[$prefix . $needle] = true;
            }
        }

        return array_values(array_filter(
            $allNames,
            static fn(string $name): bool => isset($needles[$name]) || isset($needles[ltrim($name, '{}%')]),
        ));
    }

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _serializeTable(TableSchema $table): array
    {
        $columns = [];
        foreach ($table->columns as $name => $col) {
            $columns[] = [
                'name' => $name,
                'type' => $col->type,
                'dbType' => $col->dbType,
                'phpType' => $col->phpType,
                'allowNull' => $col->allowNull,
                'autoIncrement' => $col->autoIncrement,
                'isPrimaryKey' => $col->isPrimaryKey,
                'enumValues' => $col->enumValues,
                'size' => $col->size,
                'precision' => $col->precision,
                'scale' => $col->scale,
                'unsigned' => $col->unsigned,
                'defaultValue' => $col->defaultValue,
                'comment' => $col->comment,
            ];
        }

        $foreignKeys = [];
        foreach ($table->foreignKeys as $fk) {
            // Yii returns FK as [refTable, localCol => refCol, ...]
            if (!is_array($fk) || $fk === []) {
                continue;
            }

            $refTable = array_shift($fk);
            $foreignKeys[] = [
                'refTable' => $refTable,
                'columnMap' => $fk,
            ];
        }

        return [
            'name' => $table->name,
            'fullName' => $table->fullName,
            'schemaName' => $table->schemaName,
            'primaryKey' => $table->primaryKey,
            'sequenceName' => $table->sequenceName,
            'columns' => $columns,
            'foreignKeys' => $foreignKeys,
        ];
    }
}
