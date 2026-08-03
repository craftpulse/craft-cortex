<?php

namespace craftpulse\herald\models;

use Craft;
use craft\base\Model;
use craft\elements\User;

/**
 * =========================================================================
 * Bearer-token model.
 *
 * Thin model wrapping the `herald_tokens` Record. Carries the validated
 * attributes the service layer (`services/Tokens`) uses to operate on
 * a token and surfaces a `getUser()` helper for the HTTP transport to
 * resolve the bound Craft user without a second `getIdentity()` round-
 * trip.
 *
 * `tokenHash` and `tokenPrefix` are pre-computed by the service at
 * issuance — the plaintext is never stored on the model and never
 * round-trips through it. The service surfaces the plaintext exactly
 * once, in `Tokens::issue()`'s return value.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
class Token extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var int|null Primary key. Null until the row is persisted.
     */
    public ?int $id = null;

    /**
     * @var string Human-readable identifier surfaced in the CP listing
     *             and the `herald/token/list` console output. Free
     *             text up to 64 chars; not used for lookup.
     */
    public string $name = '';

    /**
     * @var string SHA-256 hex digest of the plaintext token. Indexed
     *             unique on the DB side; never the plaintext itself.
     */
    public string $tokenHash = '';

    /**
     * @var string First 8 chars of the plaintext token, kept so the
     *             CP listing can show a partial identifier without
     *             exposing the secret. Operator-readable hint, not
     *             a lookup key.
     */
    public string $tokenPrefix = '';

    /**
     * @var int Craft user id this token authenticates as. FK
     *          cascades on user delete.
     */
    public int $userId = 0;

    /**
     * @var string|null Space-delimited capability scopes this token
     *                  carries, matching the shape an OAuth access
     *                  token's `scope` claim uses so both credential
     *                  paths parse identically.
     *
     *                  Null is the DENY sentinel, not "unscoped": the
     *                  HTTP transport refuses a credential with no
     *                  scope. Tokens issued before scope enforcement
     *                  landed all carry null and have to be re-minted.
     */
    public ?string $scope = null;

    /**
     * @var string|null Absolute expiry timestamp (DB datetime string).
     *                  Null means no expiry — the bearer-token default
     *                  per `Settings::$tokenTtlDefault`.
     */
    public ?string $expiresAt = null;

    /**
     * @var string|null Most recent successful lookup timestamp (DB
     *                  datetime string). Updated by `Tokens::lookup()`
     *                  on every hit so operators can spot tokens that
     *                  have gone idle.
     */
    public ?string $lastUsedAt = null;

    /**
     * @var string|null Soft-delete timestamp. Non-null rows are
     *                  treated as revoked by every lookup path.
     */
    public ?string $dateDeleted = null;

    /**
     * @var string|null Creation timestamp.
     */
    public ?string $dateCreated = null;

    /**
     * @var string|null Last-write timestamp.
     */
    public ?string $dateUpdated = null;

    /**
     * @var string|null Stable UID.
     */
    public ?string $uid = null;

    // Public Methods
    // =========================================================================

    /**
     * Resolve the Craft user this token is bound to. Returns null if
     * the user has since been deleted — though the FK cascade in the
     * migration means a deleted user takes its tokens with it, so
     * this is defensive against pre-FK rows or out-of-band deletes.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getUser(): ?User
    {
        if ($this->userId === 0) {
            return null;
        }
        return Craft::$app->getUsers()->getUserById($this->userId);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return array<int,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['name', 'tokenHash', 'tokenPrefix', 'userId'], 'required'];
        $rules[] = [['name'], 'string', 'max' => 64];
        $rules[] = [['tokenHash'], 'string', 'length' => 64];
        $rules[] = [['tokenPrefix'], 'string', 'length' => 8];
        $rules[] = [['userId'], 'integer'];
        return $rules;
    }
}
