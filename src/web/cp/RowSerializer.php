<?php

namespace craftpulse\herald\web\cp;

use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craftpulse\herald\models\Token;
use craftpulse\herald\records\OauthClient as OauthClientRecord;
use yii\base\InvalidArgumentException;

/**
 * =========================================================================
 * View-model mapper for Herald's CP VueAdminTable feeds.
 *
 * Every row shape the Settings screens hand to `Craft.VueAdminTable` is
 * built here, so each table's tuple is defined in exactly one place and
 * the controller stays an HTTP boundary. The tuples are architecture
 * invariants — `tests/Controllers/SettingsController*Test.php` assert them
 * key-for-key, so drift is a test failure rather than a silently broken
 * table.
 *
 * Two constraints shape every method below:
 *
 *   - **VueAdminTable column callbacks receive only the cell value, never
 *     the row.** Anything a cell renderer needs (a detail-slideout trigger
 *     id, an expired flag, an approval state) has to travel inside that
 *     cell's own value, which is why several cells are composites rather
 *     than scalars.
 *   - **Datetimes go out offset-bearing.** Herald's columns store naive
 *     UTC strings; the browser parses an offset-less datetime as local
 *     time. See `date()`.
 *
 * Stateless, so the controller holds one instance per request and the
 * class is directly unit-testable.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
final class RowSerializer
{
    // Public Methods
    // =========================================================================

    /**
     * Serialise a `herald_invocations` row into the locked Activity
     * VueAdminTable data tuple.
     *
     * Row shape:
     *   - `id`          — int primary key.
     *   - `tool`        — `{id, tool, mode}` composite. VueAdminTable
     *                      column callbacks receive ONLY the cell value
     *                      (never the row), so everything the tool cell
     *                      renders — including the detail-slideout
     *                      trigger id — must travel inside the value.
     *   - `kind`        — string audit kind (status pill colour).
     *   - `user`        — `{id, label, cpEditUrl}` or null (anonymous /
     *                      deleted user).
     *   - `durationMs`  — int wall-clock duration.
     *   - `dateCreated` — string|null offset-bearing ISO-8601 (see `date`).
     *
     * Never surfaces `argsRedacted` / `responseExcerpt` / error payloads —
     * those live only in the detail slideout (locked decision 11).
     *
     * @param array<string,mixed> $row The raw DB row from `InvocationQuery::all`.
     * @return array<string,mixed>
     * @throws \Exception from `date()`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function serializeActivity(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'tool' => [
                'id' => (int) ($row['id'] ?? 0),
                'tool' => (string) ($row['toolName'] ?? ''),
                'mode' => $this->extractMode($row['argsRedacted'] ?? null),
            ],
            'kind' => (string) ($row['kind'] ?? ''),
            'user' => $this->resolveUser($row['userId'] ?? null),
            'durationMs' => (int) ($row['durationMs'] ?? 0),
            'dateCreated' => $this->date($row['dateCreated'] ?? null),
        ];
    }

    /**
     * Serialise an `OauthClient` record into the locked Clients
     * VueAdminTable data tuple.
     *
     * Row shape:
     *   - `id`            — int primary key.
     *   - `clientName`    — string operator-facing label.
     *   - `clientId`      — string public client identifier.
     *   - `type`          — `public` (PKCE-only) or `confidential`.
     *   - `approved`      — bool; the DCR approval gate state.
     *   - `approveAction` — `{id, approved}` composite for the inline button.
     *   - `redirectUris`  — string[] decoded from the JSON column.
     *   - `dateCreated`   — string|null offset-bearing ISO-8601.
     *
     * Never surfaces `clientSecretHash` — the hashed secret is internal.
     *
     * @return array<string,mixed>
     * @throws \Exception from `date()`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function serializeClient(OauthClientRecord $client): array
    {
        $redirectUris = [];
        $decoded = json_decode((string) $client->redirectUris, true);
        if (is_array($decoded)) {
            $redirectUris = array_values(array_filter($decoded, 'is_string'));
        }

        return [
            'id' => (int) $client->id,
            'clientName' => (string) $client->clientName,
            'clientId' => (string) $client->clientId,
            'type' => (bool) $client->isPublic ? 'public' : 'confidential',
            'approved' => (bool) $client->approved,
            // VueAdminTable column callbacks receive only the cell value,
            // never the row — so the inline approve button needs both the
            // id and the approval flag projected into its own cell value.
            'approveAction' => [
                'id' => (int) $client->id,
                'approved' => (bool) $client->approved,
            ],
            'redirectUris' => $redirectUris,
            'dateCreated' => $this->date($client->dateCreated),
        ];
    }

    /**
     * Serialise a `RuntimeOverride` row into the locked grants
     * VueAdminTable data tuple. Shared by the table feed and the success
     * branch of the issue flow so the row an issue returns has the same
     * shape a table refresh would render.
     *
     * Row shape:
     *   - `id`           — int primary key.
     *   - `pattern`      — `{pattern, isExpired}` composite. VueAdminTable
     *                       column callbacks receive ONLY the cell value
     *                       (never the row), so the expired flag the cell
     *                       renderer mutes on must travel inside the value.
     *   - `note`         — string|null (admin freeform).
     *   - `expiresAt`    — `{value, isExpired}` composite; `value` is
     *                       string|null offset-bearing ISO-8601, null =
     *                       never.
     *   - `createdBy`    — `{id, label, cpEditUrl}` or null when the
     *                       issuing user record is missing.
     *   - `subject`      — same shape; null = a global grant that applies
     *                       to every caller (rendered distinctly).
     *   - `dateCreated`  — string|null offset-bearing ISO-8601.
     *
     * @param array<string,mixed> $row The raw DB row from `Allowlist::getAllOverrides`
     *                                 or `RuntimeOverride::toArray()`.
     * @return array<string,mixed>
     * @throws \Exception from `date()` / `DateTimeHelper::now()`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function serializeOverride(array $row): array
    {
        $expiresAt = $row['expiresAt'] ?? null;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'pattern' => [
                'pattern' => (string) ($row['pattern'] ?? ''),
                'isExpired' => $this->isExpired($expiresAt),
            ],
            'note' => isset($row['note']) && $row['note'] !== '' ? (string) $row['note'] : null,
            'expiresAt' => [
                'value' => $this->date($expiresAt),
                'isExpired' => $this->isExpired($expiresAt),
            ],
            'createdBy' => $this->resolveUser($row['createdByUserId'] ?? null),
            'subject' => $this->resolveUser($row['subjectUserId'] ?? null),
            'dateCreated' => $this->date($row['dateCreated'] ?? null),
        ];
    }

    /**
     * Serialise a `Token` model into the locked Tokens VueAdminTable data
     * tuple. Shared by the table feed and the success branch of the issue
     * flow so the row an issue returns has the same shape a table refresh
     * would render.
     *
     * The plaintext is NEVER part of this tuple — only the operator-safe
     * `tokenPrefix` hint surfaces. The plaintext exists exactly once, in
     * the issue action's separate `token` response key.
     *
     * Row shape:
     *   - `id`          — int primary key.
     *   - `name`        — string operator label.
     *   - `tokenPrefix` — string (first 8 chars of the plaintext, a hint).
     *   - `user`        — `{id, label, cpEditUrl}` or null when the bound
     *                      user record is missing.
     *   - `expiresAt`   — string|null offset-bearing ISO-8601, null = never.
     *   - `lastUsedAt`  — string|null offset-bearing ISO-8601, null = never
     *                      used.
     *   - `dateCreated` — string|null offset-bearing ISO-8601.
     *
     * @return array<string,mixed>
     * @throws \Exception from `date()`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function serializeToken(Token $token): array
    {
        $user = null;
        $boundUser = $token->getUser();
        if ($boundUser instanceof User) {
            $user = $this->userCell($boundUser);
        }

        return [
            'id' => (int) $token->id,
            'name' => $token->name,
            'tokenPrefix' => $token->tokenPrefix,
            'user' => $user,
            'expiresAt' => $this->date($token->expiresAt),
            'lastUsedAt' => $this->date($token->lastUsedAt),
            'dateCreated' => $this->date($token->dateCreated),
        ];
    }

    /**
     * Render a datetime cell for a VueAdminTable row as an offset-bearing
     * ISO-8601 string, or null when the value is absent.
     *
     * Every datetime column in Herald's tables stores a **naive** UTC
     * string (`Y-m-d H:i:s`, no offset) because that is what
     * `Db::prepareDateForDb()` writes. Handing that string to the table
     * unchanged is not ISO-8601, and the browser's `new Date(...)` parses
     * an offset-less datetime as **local** time — so every rendered
     * timestamp landed shifted by the viewer's UTC offset, and the
     * client-side "expired" comparison in `tokens.twig` flipped by the
     * same amount. Naming the offset on the wire fixes both at the source
     * and keeps the cell renderers free of timezone logic.
     *
     * @throws \Exception from `DateTimeHelper::toDateTime()` when the
     *         system timezone cannot be resolved.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return DateTimeHelper::toIso8601($value) ?: null;
    }

    /**
     * Best-effort extraction of the `mode` key from a redacted-args JSON
     * column. Returns null when the column is absent, not JSON, or carries
     * no `mode`. The args are already redacted in the DB — this only reads
     * the (non-sensitive) routing discriminator most Herald tools carry.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function extractMode(mixed $argsRedacted): ?string
    {
        if (!is_string($argsRedacted) || $argsRedacted === '') {
            return null;
        }

        $decoded = json_decode($argsRedacted, true);
        if (!is_array($decoded)) {
            return null;
        }

        $mode = $decoded['mode'] ?? null;

        return is_string($mode) && $mode !== '' ? $mode : null;
    }

    /**
     * Pretty-print a redacted JSON audit column for the detail slideout.
     * The `responseExcerpt` column is clipped to a fixed length by the
     * logger, so the stored string is frequently NOT valid JSON — fall
     * back to the raw text instead of letting `Json::decode` throw (a
     * truncated excerpt 500'd the slideout; gate-9 browser smoke).
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function prettyJson(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $decoded = Json::decode($raw);
        } catch (InvalidArgumentException) {
            return $raw;
        }

        if (!is_array($decoded)) {
            return $raw;
        }

        return Json::encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Resolve a raw user-id cell into the `{id, label, cpEditUrl}` shape
     * the VueAdminTable cells and the activity-detail slideout consume, or
     * null when the id is absent or the user record no longer resolves
     * (soft-deleted, hard-deleted behind a SET NULL foreign key).
     *
     * @return array{id:int,label:string,cpEditUrl:string|null}|null
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function resolveUser(mixed $userId): ?array
    {
        if (!is_int($userId) && !(is_string($userId) && ctype_digit($userId))) {
            return null;
        }

        $user = User::find()->id((int) $userId)->status(null)->one();
        if (!$user instanceof User) {
            return null;
        }

        return $this->userCell($user);
    }

    // Private Methods
    // =========================================================================

    /**
     * Whether a stored expiry has already passed. A null / empty expiry is
     * "never expires" and therefore never expired.
     *
     * @throws \Exception from `DateTimeHelper::now()`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function isExpired(mixed $expiresAt): bool
    {
        if (!is_string($expiresAt) || $expiresAt === '') {
            return false;
        }

        $expiry = DateTimeHelper::toDateTime($expiresAt);

        return $expiry !== false && $expiry < DateTimeHelper::now();
    }

    /**
     * Project a resolved user onto the cell shape.
     *
     * @return array{id:int,label:string,cpEditUrl:string|null}
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function userCell(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'label' => $user->getName(),
            'cpEditUrl' => $user->getCpEditUrl(),
        ];
    }
}
