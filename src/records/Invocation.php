<?php

namespace craftpulse\herald\records;

use craft\db\ActiveRecord;
use craftpulse\herald\db\Table;
use craftpulse\herald\tools\support\InvocationLogger;

/**
 * =========================================================================
 * Active record for the `herald_invocations` audit-log table.
 *
 * One row per authenticated HTTP-transport `tools/call`. Mirrors the
 * column layout declared in `Install::_createInvocationsTable()` so
 * Yii's ActiveRecord can read/write rows without a hand-rolled query.
 *
 * The Record carries DateTime-shaped timestamps via Yii's standard
 * column auto-population. `Invocations` service writes happen through
 * this Record; the service is the only sanctioned write path. Direct
 * Record-level writes outside `Invocations::record()` bypass the
 * soft-write contract and risk a DB failure breaking the dispatch.
 *
 * Property table — kept in sync with the migration. PHPStan reads
 * `@property` for type-safe property access against the
 * dynamic-property ActiveRecord magic.
 * =========================================================================
 *
 * @property int $id
 * @property string $toolName
 * @property string $kind
 * @property int $durationMs
 * @property string $transport
 * @property string|null $requestId
 * @property int|null $userId
 * @property string|null $clientName
 * @property string|null $argsRedacted
 * @property string|null $responseExcerpt
 * @property string|null $errorClass
 * @property string|null $errorMessage
 * @property int|null $tokenId
 * @property string|null $sessionId
 * @property int|null $rateLimitRemaining
 * @property string $dateCreated
 * @property string $uid
 *
 * @author CraftPulse
 * @since  5.0.0
 */
class Invocation extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function tableName(): string
    {
        return Table::INVOCATIONS;
    }

    /**
     * @inheritdoc
     *
     * Mirrors the migration's column constraints at the model layer so
     * `save()` fails cleanly via validation rather than as a raw DB
     * exception. The `kind` enum is enforced as a list-membership check
     * sourced from `InvocationLogger`'s `KIND_*` constants — the single
     * source of truth — so a new kind (e.g. `security` for the
     * refresh-token theft event) only has to be declared in one place.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function rules(): array
    {
        return [
            [['toolName', 'kind', 'durationMs', 'transport'], 'required'],
            [['toolName'], 'string', 'max' => 64],
            [['kind'], 'string', 'max' => 20],
            [['kind'], 'in', 'range' => [
                InvocationLogger::KIND_SUCCESS,
                InvocationLogger::KIND_TOOL_ERROR,
                InvocationLogger::KIND_INTERNAL_ERROR,
                InvocationLogger::KIND_RATE_LIMITED,
                InvocationLogger::KIND_CANCELLED,
                InvocationLogger::KIND_SECURITY,
            ]],
            [['transport'], 'string', 'max' => 10],
            [['requestId', 'clientName', 'errorClass'], 'string', 'max' => 255],
            [['errorMessage'], 'string', 'max' => 1000],
            [['sessionId'], 'string', 'max' => 64],
            [['durationMs', 'userId', 'tokenId', 'rateLimitRemaining'], 'integer'],
            [['argsRedacted', 'responseExcerpt'], 'string'],
        ];
    }
}
