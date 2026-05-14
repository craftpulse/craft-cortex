<?php

namespace craftpulse\cortex\records;

use craft\db\ActiveRecord;
use craftpulse\cortex\db\Table;

/**
 * =========================================================================
 * Active record for the `cortex_invocations` audit-log table.
 *
 * One row per authenticated HTTP-transport `tools/call`. Mirrors the
 * column layout declared in `m260514_120200_cortex_invocations` so
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
 * @author Craftpulse
 * @since  5.0.0
 */
class Invocation extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
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
     * to match the five documented values
     * (`success` / `tool_error` / `internal_error` / `rate_limited` /
     * `cancelled`) — Gate 7.6 added `rate_limited` for throttle events,
     * Gate 7.7 added `cancelled` for streaming-tool cancellation events.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function rules(): array
    {
        return [
            [['toolName', 'kind', 'durationMs', 'transport'], 'required'],
            [['toolName'], 'string', 'max' => 64],
            [['kind'], 'string', 'max' => 20],
            [['kind'], 'in', 'range' => ['success', 'tool_error', 'internal_error', 'rate_limited', 'cancelled']],
            [['transport'], 'string', 'max' => 10],
            [['requestId', 'clientName', 'errorClass'], 'string', 'max' => 255],
            [['errorMessage'], 'string', 'max' => 1000],
            [['sessionId'], 'string', 'max' => 64],
            [['durationMs', 'userId', 'tokenId', 'rateLimitRemaining'], 'integer'],
            [['argsRedacted', 'responseExcerpt'], 'string'],
        ];
    }
}
