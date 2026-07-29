<?php

namespace craftpulse\herald\tools\content;

use CommerceGuys\Addressing\Exception\UnknownCountryException;
use Craft;
use craft\elements\Address as AddressElement;
use craft\elements\User;
use craftpulse\herald\attributes\IsDestructive;
use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\Title;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\IdempotencyTrait;
use craftpulse\herald\tools\PermissionedToolTrait;
use craftpulse\herald\tools\ProToolTrait;
use craftpulse\herald\tools\support\ElementSerializer;
use craftpulse\herald\tools\support\Schema;
use craftpulse\herald\tools\ToolException;
use Throwable;

/**
 * =========================================================================
 * `address` Pro tool — list / get / create / update / delete user-owned
 * addresses.
 *
 * Five modes dispatched off the `mode` argument:
 *
 *   - `list` — return addresses for `ownerId` (required). Filters to
 *     `ownerType === 'user'` for Gate 8.4; other owner types are
 *     deferred to Commerce edition.
 *   - `get` — return a single address by `id` or `uid`. Permission
 *     gated against the owner via `Elements::canView`.
 *   - `create` — instantiate a fresh `Address`, set owner + attributes
 *     + custom fields, save through `Craft::$app->getElements()`.
 *     Permission via `Elements::canSave` on the owner.
 *   - `update` — load by `id` / `uid`, mutate, save. Refuses ownership
 *     changes (returns `-32002` ToolException naming "ownership
 *     change"). Permission via `Elements::canSave` on the owner.
 *   - `delete` — soft-delete by default; `hardDelete: true` removes the
 *     row entirely. Permission via `Elements::canDelete` on the owner.
 *
 * Permission contract (locked decision 4 of `docs/plans/gate-8.md`):
 *   - `_requiredPermissions()` returns `['editUsers']` — coarse gate for
 *     `filterFor()` whole-tool visibility plus the trait's wildcard
 *     sentinel rejection.
 *   - Per-address authorisation is delegated to Craft's native
 *     `Elements::canSave / canView / canDelete`, which honours the
 *     owner's element-class permission contract (see
 *     `vendor/craftcms/cms/src/elements/Address.php:419`). On a user-
 *     owned address this resolves to `editUsers` on the owner plus
 *     Craft's per-target ownership rules.
 *
 * Country validation: `countryCode` is validated at execute-time
 * against `Craft::$app->getAddresses()->getCountryRepository()`. Invalid
 * codes surface in the validation envelope (`errors.countryCode`),
 * NOT a thrown ToolException — the LLM iterates.
 *
 * Owner-type contract: Phase 1 supports `ownerType === 'user'` only.
 * Commerce ownerType values (`commerce-customer`, `commerce-order`)
 * are accepted in the schema but rejected at execute-time with a
 * Commerce-deferred hint (out of scope per PLANNING.md §4.8).
 *
 * Idempotency contract: `idempotencyKey` is server-side dedup for
 * `create` and `update`. Cache prefix `herald:address:idem:`. TTL 24h.
 * Skipped on stdio.
 *
 * Cancellation: single-mutation tool, not streaming. The Gate 7.4
 * InvocationContext is irrelevant here per locked decision 8 of
 * `docs/plans/gate-8.md`.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
#[IsDestructive]
#[IsIdempotent(false)]
#[Title('Address — list / get / create / update / delete (user-owned)')]
class Address extends AbstractTool
{
    use IdempotencyTrait;
    use PermissionedToolTrait;
    use ProToolTrait;

    // Constants
    // =========================================================================

    /**
     * Default page size for `list` mode.
     *
     * @since 5.0.0
     */
    public const DEFAULT_LIMIT = 50;

    /**
     * Hard cap for `list` mode page size.
     *
     * @since 5.0.0
     */
    public const MAX_LIMIT = 200;

    /**
     * Idempotency cache key prefix. Consumed by `IdempotencyTrait`.
     *
     * @since 5.0.0
     */
    public const IDEMPOTENCY_CACHE_PREFIX = 'herald:address:idem:';

    /**
     * The only owner type Phase 1 supports. Commerce owner types are
     * deferred to the Commerce edition per PLANNING.md §4.8.
     *
     * @since 5.0.0
     */
    public const OWNER_TYPE_USER = 'user';

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
        return 'address';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Write tool for Craft addresses. Modes: list / get / create / update / delete ' .
            'on user-owned addresses. Per-owner permission gating delegated to Craft\'s ' .
            'native Elements::canSave / canView / canDelete — for user-owned addresses this ' .
            'resolves to editUsers on the owner. countryCode is validated at execute-time ' .
            'against the ISO 3166-1 alpha-2 list — bad codes surface in the validation ' .
            'envelope (errors.countryCode), not as a thrown error, so the LLM iterates. ' .
            'Owner type: only `user` is supported — other owner types are rejected. ' .
            'Address fields (countryCode, addressLine1, locality, etc.) pass through to ' .
            'Craft\'s save validation, which honours the per-country required-field map. ' .
            'Returns the serialised address on success; on validation failure returns ' .
            '{success: false, errors: {handle: [messages]}, mode, id}. Throws only for ' .
            'permission denial, missing arguments, mode misuse, ownership-change attempts, ' .
            'or address-not-found. `idempotencyKey` (create / update only) caches the ' .
            'result for 24h. Pro edition only.';
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
            'mode' => Schema::string()
                ->enum(['list', 'get', 'create', 'update', 'delete'])
                ->required()
                ->description('Operation to perform.'),

            // Element resolution (get / update / delete).
            'id' => Schema::integer()->description('Address id. Required for get / update / delete unless `uid` is supplied.'),
            'uid' => Schema::string()->description('Address uid. Alternative to `id` for get / update / delete.'),

            // Owner resolution.
            'ownerId' => Schema::integer()->description('Owner id. Required for create + list. On update, ownership changes are rejected.'),
            'ownerType' => Schema::string()
                ->default(self::OWNER_TYPE_USER)
                ->description('Owner element type. Only `user` is supported; other owner types are rejected. Defaults to `user`.'),

            // Site targeting.
            'siteId' => Schema::integer()->description('Target site id. Defaults to the primary site.'),
            'siteHandle' => Schema::string()->description('Target site handle. Alternative to `siteId`.'),

            // Optional address label (Craft uses the `title` attribute as the address card label).
            'title' => Schema::string()->description('Human-readable label for the address card (e.g. `Home`, `Work`). Subject to the field layout\'s `title` requirement.'),

            // Address fields (full ISO 3166-1 coverage — see Craft's Address::defineRules()).
            'countryCode' => Schema::string()->description('ISO 3166-1 alpha-2 country code (e.g. `US`, `BE`). Validated at execute-time. Required for create. Non-nullable on the underlying element — missing on save surfaces as a validation envelope.'),
            'administrativeArea' => Schema::string()->description('State / province / region (per country format).'),
            'locality' => Schema::string()->description('City / town.'),
            'dependentLocality' => Schema::string()->description('Neighbourhood / district (per country format).'),
            'postalCode' => Schema::string()->description('Postal / ZIP code.'),
            'sortingCode' => Schema::string()->description('Sorting code (per country format — rare).'),
            'addressLine1' => Schema::string()->description('Street address line 1.'),
            'addressLine2' => Schema::string()->description('Street address line 2.'),
            'addressLine3' => Schema::string()->description('Street address line 3 (rare).'),
            'organization' => Schema::string()->description('Organisation name.'),
            'organizationTaxId' => Schema::string()->description('Organisation tax id (when the OrganizationTaxIdField is in the field layout).'),
            'fullName' => Schema::string()->description('Full name (single-field mode — default).'),
            'firstName' => Schema::string()->description('First name (when `showFirstAndLastNameFields` is enabled). Read AddressInterface::getGivenName() maps here.'),
            'lastName' => Schema::string()->description('Last name (when `showFirstAndLastNameFields` is enabled). Read AddressInterface::getFamilyName() maps here.'),
            'latitude' => Schema::string()->description('Latitude in decimal degrees (-90 to 90). String to preserve precision.'),
            'longitude' => Schema::string()->description('Longitude in decimal degrees (-180 to 180). String to preserve precision.'),

            // Custom-field values — pass-through to Craft's setFieldValues().
            'fields' => Schema::object()
                ->additionalProperties(true)
                ->description('Custom field values keyed by handle. Forwarded verbatim to Address::setFieldValues(); Craft normalises per field type.'),

            // List pagination.
            'limit' => Schema::integer()->minimum(1)->maximum(self::MAX_LIMIT)->description('list mode: page size. Default 50, max 200.'),
            'offset' => Schema::integer()->minimum(0)->description('list mode: offset. Default 0.'),

            // Mode-specific flags.
            'hardDelete' => Schema::boolean()->description('delete only: when true, removes the row entirely (no restore possible). Default false.'),

            // Idempotency.
            'idempotencyKey' => Schema::string()
                ->maxLength(self::IDEMPOTENCY_KEY_MAX_LENGTH)
                ->description('create / update only: server-side dedup token. Same key issued twice within 24h returns the cached envelope without re-saving.'),
        ])->toArray();
    }

    /**
     * @inheritdoc
     *
     * Per-user visibility check. Returns `true` for stdio (trusted)
     * and for any HTTP caller with `editUsers` permission. The
     * per-address re-check inside `execute()` delegates to Craft's
     * `Elements::canSave / canView / canDelete` for the precise
     * owner-delegation semantics.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function filterFor(?User $user = null): bool
    {
        if ($user === null) {
            // stdio path — trusted local user, no per-request identity.
            return true;
        }

        if ($user->admin) {
            return true;
        }

        return (bool) $user->can('editUsers');
    }

    /**
     * @inheritdoc
     *
     * Address's mode-enum filtering is uniform — every mode requires
     * `editUsers` at the coarse level, so a user without it never
     * reaches `inputSchemaFor()` (filterFor hides the tool). For users
     * with `editUsers`, the full enum surfaces; per-address denials
     * happen in `execute()` via `Elements::canSave / canView /
     * canDelete`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function inputSchemaFor(?User $user = null): array
    {
        // stdio + admin + permitted user — same full enum. The
        // per-owner denial path lives in execute(), not here.
        return static::getInputSchema();
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
        $mode = $this->_mode($arguments);
        if ($mode === null) {
            throw new ToolException('address: `mode` is required.');
        }

        return match ($mode) {
            'list' => $this->_list($arguments),
            'get' => $this->_get($arguments),
            'create' => $this->_create($arguments),
            'update' => $this->_update($arguments),
            'delete' => $this->_delete($arguments),
            default => throw new ToolException(
                "address: unknown mode `{$mode}`. Allowed: list / get / create / update / delete."
            ),
        };
    }

    // Protected Methods
    // =========================================================================

    /**
     * Address's permission gate is two-layered:
     *   1. Coarse `editUsers` requirement enforced here — drives both
     *      `filterFor()` whole-tool visibility and the trait's in-
     *      execute() re-check.
     *   2. Per-address re-check via Craft's `Elements::canSave / canView
     *      / canDelete` inside each mode body. This delegates to the
     *      owner's permission contract (see
     *      `vendor/craftcms/cms/src/elements/Address.php:419`) — for a
     *      user-owned address that means `editUsers` plus Craft's
     *      per-target ownership semantics.
     *
     * @param array<string,mixed> $arguments
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _requiredPermissions(array $arguments): array
    {
        return ['editUsers'];
    }

    /**
     * Override `PermissionedToolTrait::_buildPermissionDeniedMessage()`
     * to emit the address-specific rich format (mode + missing
     * permission). The per-address denials (from `Elements::canSave /
     * canView / canDelete`) emit their own messages in the mode
     * methods.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _buildPermissionDeniedMessage(string $missingPermission, array $arguments): string
    {
        $mode = $this->_mode($arguments) ?? '?';

        return sprintf(
            'permission denied: mode `%s` requires `%s`.',
            $mode,
            $missingPermission,
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * List-mode dispatch. Loads user-owned addresses for `ownerId`,
     * paginated by `limit / offset`.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _list(array $arguments): array
    {
        $this->_assertPermission($arguments);
        $this->_assertOwnerType($this->_ownerType($arguments));

        $ownerId = $this->_requireOwnerId($arguments);

        $limit = $this->_limit($arguments, self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $offset = $this->_offset($arguments);

        $query = AddressElement::find()
            ->ownerId($ownerId)
            ->status(null)
            ->limit($limit)
            ->offset($offset);

        $siteId = $this->_resolveOptionalSiteId($arguments);
        if ($siteId !== null) {
            $query->siteId($siteId);
        }

        $addresses = $query->all();
        $serializer = new ElementSerializer();
        $serialised = [];
        foreach ($addresses as $address) {
            $serialised[] = $serializer->serializeElement($address);
        }

        return [
            'success' => true,
            'mode' => 'list',
            'addresses' => $serialised,
            'count' => count($serialised),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Get-mode dispatch. Loads a single address by id / uid, checks
     * per-address view permission via `Elements::canView`.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _get(array $arguments): array
    {
        $this->_assertPermission($arguments);

        $element = $this->_resolveAddress($arguments);
        $this->_assertCanView($element);

        return [
            'success' => true,
            'mode' => 'get',
            'address' => $this->_serializeAddress($element),
        ];
    }

    /**
     * Create-mode dispatch. Resolves owner, instantiates a fresh
     * `Address`, applies attributes + fields, validates country code,
     * saves. Returns the success envelope or a validation envelope.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _create(array $arguments): array
    {
        // Permission was checked when this response was originally
        // computed; userId is in the cache key — see IdempotencyTrait.
        $cacheHit = $this->_idempotencyCacheHit($arguments);
        if ($cacheHit !== null) {
            return $cacheHit;
        }

        $this->_assertPermission($arguments);
        $this->_assertOwnerType($this->_ownerType($arguments));

        $owner = $this->_resolveOwner($this->_requireOwnerId($arguments));

        $element = new AddressElement();
        $element->setOwner($owner);
        $element->setPrimaryOwner($owner);

        $this->_applyAddressAttributes($element, $arguments);
        $this->_applyFields($element, $arguments);

        // Per-owner canSave re-check (defense in depth — the trait
        // gate is coarse `editUsers`; this resolves the owner-specific
        // delegation per `Address::canSave()`).
        $caller = Craft::$app->getUser()->getIdentity();
        if ($caller !== null && !Craft::$app->getElements()->canSave($element, $caller)) {
            throw new ToolException(
                'address: create denied. Caller cannot save addresses for the resolved owner.'
            );
        }

        $countryEnvelope = $this->_validateCountryCode($element, 'create');
        if ($countryEnvelope !== null) {
            return $countryEnvelope;
        }

        if (!Craft::$app->getElements()->saveElement($element, runValidation: true)) {
            return $this->_validationEnvelope($element, 'create');
        }

        $envelope = $this->_successEnvelope($element, 'create');
        $this->_cacheIdempotencyEnvelope($arguments, $envelope);

        return $envelope;
    }

    /**
     * Update-mode dispatch. Resolves the address by id / uid (excludes
     * trashed), refuses ownership-change attempts, re-checks per-owner
     * `canSave`, applies attributes + fields, saves.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _update(array $arguments): array
    {
        // Permission was checked when this response was originally
        // computed; userId is in the cache key — see IdempotencyTrait.
        $cacheHit = $this->_idempotencyCacheHit($arguments);
        if ($cacheHit !== null) {
            return $cacheHit;
        }

        $this->_assertPermission($arguments);

        $element = $this->_resolveAddress($arguments);

        // Reject ownership-change attempts. The schema accepts `ownerId`
        // but on update, mutating it would re-parent the address —
        // refuse with -32002 per locked decision.
        if (array_key_exists('ownerId', $arguments)) {
            $newOwnerId = $arguments['ownerId'];
            if (is_int($newOwnerId) || (is_string($newOwnerId) && ctype_digit($newOwnerId))) {
                $newOwnerId = (int) $newOwnerId;
                $currentOwnerId = $element->getOwnerId();
                if ($currentOwnerId !== null && $newOwnerId !== (int) $currentOwnerId) {
                    return $this->_ownershipChangeEnvelope($element, 'update');
                }
            }
        }

        $caller = Craft::$app->getUser()->getIdentity();
        if ($caller !== null && !Craft::$app->getElements()->canSave($element, $caller)) {
            throw new ToolException(
                'address: update denied. Caller cannot save this address.'
            );
        }

        $this->_applyAddressAttributes($element, $arguments);
        $this->_applyFields($element, $arguments);

        $countryEnvelope = $this->_validateCountryCode($element, 'update');
        if ($countryEnvelope !== null) {
            return $countryEnvelope;
        }

        if (!Craft::$app->getElements()->saveElement($element, runValidation: true)) {
            return $this->_validationEnvelope($element, 'update');
        }

        $envelope = $this->_successEnvelope($element, 'update');
        $this->_cacheIdempotencyEnvelope($arguments, $envelope);

        return $envelope;
    }

    /**
     * Delete-mode dispatch. Soft-deletes by default; `hardDelete: true`
     * removes the row entirely.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _delete(array $arguments): array
    {
        $this->_assertPermission($arguments);

        $element = $this->_resolveAddress($arguments);

        $caller = Craft::$app->getUser()->getIdentity();
        if ($caller !== null && !Craft::$app->getElements()->canDelete($element, $caller)) {
            throw new ToolException(
                'address: delete denied. Caller cannot delete this address.'
            );
        }

        $hardDelete = (bool) ($arguments['hardDelete'] ?? false);

        if (!Craft::$app->getElements()->deleteElement($element, hardDelete: $hardDelete)) {
            throw new ToolException(
                "address: delete failed for id={$element->id}. See Craft logs for details."
            );
        }

        return [
            'success' => true,
            'mode' => 'delete',
            'id' => (int) $element->id,
            'uid' => $element->uid,
            'hardDeleted' => $hardDelete,
        ];
    }

    /**
     * Resolve the address by `id` or `uid` for get / update / delete.
     * Excludes trashed addresses; on miss, probes the trashed slot and
     * surfaces a hint message ("restore via Craft CP" — Address has no
     * `restore` mode in Gate 8.4).
     *
     * @param array<string,mixed> $arguments
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _resolveAddress(array $arguments): AddressElement
    {
        $id = $arguments['id'] ?? null;
        $uid = $arguments['uid'] ?? null;

        $query = AddressElement::find()->status(null)->site('*');

        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $query->id((int) $id);
        } elseif (is_string($uid) && $uid !== '') {
            $query->uid($uid);
        } else {
            throw new ToolException('address: `id` or `uid` is required.');
        }

        $siteId = $this->_resolveOptionalSiteId($arguments);
        if ($siteId !== null) {
            $query->siteId($siteId);
        }

        $element = $query->one();
        if ($element instanceof AddressElement) {
            return $element;
        }

        // Slow-path probe: does this id/uid match a TRASHED address?
        $trashedProbe = (clone $query)->trashed(true)->one();
        if ($trashedProbe instanceof AddressElement) {
            $probeKey = $trashedProbe->id !== null ? "id={$trashedProbe->id}" : "uid={$trashedProbe->uid}";
            throw new ToolException(
                "address: {$probeKey} is trashed. To remove permanently, use mode=delete " .
                    'with hardDelete=true; to recover, restore the address via the Craft CP.'
            );
        }

        $key = (is_int($id) || (is_string($id) && ctype_digit($id))) ? "id={$id}" : "uid={$uid}";
        throw new ToolException("address: no address found for {$key}.");
    }

    /**
     * Resolve the address owner (a User in Phase 1). Throws when the
     * owner doesn't exist.
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _resolveOwner(int $ownerId): User
    {
        $owner = Craft::$app->getUsers()->getUserById($ownerId);
        if (!$owner instanceof User) {
            throw new ToolException("address: owner user id={$ownerId} not found.");
        }
        return $owner;
    }

    /**
     * Return the resolved `ownerId` or throw if the argument is
     * missing / not an int. Used by `list` (filter) and `create`
     * (assignment).
     *
     * @param array<string,mixed> $arguments
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _requireOwnerId(array $arguments): int
    {
        $ownerId = $arguments['ownerId'] ?? null;
        if (!is_int($ownerId) && !(is_string($ownerId) && ctype_digit($ownerId))) {
            throw new ToolException('address: `ownerId` is required.');
        }
        return (int) $ownerId;
    }

    /**
     * Return the resolved `ownerType` (defaults to `user`).
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _ownerType(array $arguments): string
    {
        $type = $arguments['ownerType'] ?? self::OWNER_TYPE_USER;
        return is_string($type) && $type !== '' ? $type : self::OWNER_TYPE_USER;
    }

    /**
     * Assert that the supplied owner type is `user`. Commerce owner
     * types are accepted by the schema but deferred in Phase 1 per
     * PLANNING.md §4.8.
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _assertOwnerType(string $ownerType): void
    {
        if ($ownerType === self::OWNER_TYPE_USER) {
            return;
        }

        throw new ToolException(sprintf(
            'address: ownerType `%s` is not supported. Only `user` is currently supported.',
            $ownerType,
        ));
    }

    /**
     * Per-address view check delegated to Craft's
     * `Elements::canView`. Skips the stdio path. Throws ToolException
     * on miss.
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _assertCanView(AddressElement $address): void
    {
        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null) {
            return;
        }

        if (!Craft::$app->getElements()->canView($address, $user)) {
            throw new ToolException(
                'address: view denied. Caller cannot view this address.'
            );
        }
    }

    /**
     * Validate `countryCode` against ISO 3166-1 alpha-2 list. Returns
     * a validation envelope to short-circuit the save path when the
     * code is invalid; returns `null` when the code is valid or
     * absent (Craft's own `required` rule catches the latter at save
     * time).
     *
     * @return array<string,mixed>|null
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _validateCountryCode(AddressElement $element, string $mode): ?array
    {
        $countryCode = $element->countryCode ?? null;
        if (!is_string($countryCode) || $countryCode === '') {
            // Craft's `required` validator handles missing country on save.
            return null;
        }

        try {
            Craft::$app->getAddresses()->getCountryRepository()->get($countryCode);
        } catch (UnknownCountryException) {
            $element->addError('countryCode', sprintf(
                'Country code `%s` is not a valid ISO 3166-1 alpha-2 code.',
                $countryCode,
            ));
            return $this->_validationEnvelope($element, $mode);
        } catch (Throwable $e) {
            $element->addError('countryCode', sprintf(
                'Country code `%s` could not be validated: %s',
                $countryCode,
                $e->getMessage(),
            ));
            return $this->_validationEnvelope($element, $mode);
        }

        return null;
    }

    /**
     * Apply the address-shape attributes from arguments to the
     * element. Missing key = leave alone. Null = clear. Empty string
     * = pass through to Craft (which will reject required-but-empty
     * at validation time).
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _applyAddressAttributes(AddressElement $element, array $arguments): void
    {
        // Title (Address card label). When the field layout's `title`
        // field is required, the save will reject a missing title via
        // the validation envelope.
        if (array_key_exists('title', $arguments) && is_string($arguments['title'])) {
            $element->title = $arguments['title'];
        }

        // countryCode is non-nullable on Address. Set only when supplied
        // as a non-null string; let Craft's `required` validator surface
        // missing-on-save.
        if (array_key_exists('countryCode', $arguments) && is_string($arguments['countryCode'])) {
            $element->countryCode = $arguments['countryCode'];
        }

        // Nullable string attributes — `null` clears, missing key leaves
        // alone, string value passes through.
        $nullableStringFields = [
            'administrativeArea',
            'locality',
            'dependentLocality',
            'postalCode',
            'sortingCode',
            'addressLine1',
            'addressLine2',
            'addressLine3',
            'organization',
            'organizationTaxId',
            'fullName',
            'firstName',
            'lastName',
            'latitude',
            'longitude',
        ];

        foreach ($nullableStringFields as $attr) {
            if (!array_key_exists($attr, $arguments)) {
                continue;
            }
            $value = $arguments[$attr];
            if ($value === null) {
                $element->{$attr} = null;
                continue;
            }
            if (is_string($value)) {
                $element->{$attr} = $value;
            }
        }
    }

    /**
     * Success envelope shape for get / create / update.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _successEnvelope(AddressElement $element, string $mode): array
    {
        return [
            'success' => true,
            'mode' => $mode,
            'address' => $this->_serializeAddress($element),
        ];
    }

    /**
     * Validation envelope shape for an attempted ownership change.
     * Returned (not thrown) so the LLM can iterate by retrying
     * without the ownerId field.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _ownershipChangeEnvelope(AddressElement $element, string $mode): array
    {
        return [
            'success' => false,
            'mode' => $mode,
            'id' => $element->id !== null ? (int) $element->id : null,
            'uid' => $element->uid,
            'errors' => [
                'ownerId' => [
                    'Address ownership change is not supported. Delete the address and ' .
                        'create a new one against the target owner.',
                ],
            ],
        ];
    }

    /**
     * Serialise the address through the standard ElementSerializer.
     * Wrapped in a dedicated method so future PII-style redaction can
     * land here in one place rather than touching every call site.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _serializeAddress(AddressElement $address): array
    {
        $serializer = new ElementSerializer();
        return $serializer->serializeElement($address);
    }
}
