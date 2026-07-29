<?php

namespace craftpulse\herald\console\controllers;

use Craft;
use craft\console\Controller;
use craftpulse\herald\Herald;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Issues, revokes, and lists the bearer tokens used by the HTTP
 * transport (`POST /herald/mcp`). Admin-issued only; Gate 7.3 lands
 * OAuth 2.1 for delegated / self-service flows.
 *
 * Usage:
 *   herald/token/issue <user> [--name=<name>] [--ttl=<seconds>]
 *   herald/token/revoke <id>
 *   herald/token/list [--user=<email-or-username>]
 *
 * `<user>` accepts an email address or a username; resolved through
 * `Users::getUserByUsernameOrEmail()`. The plaintext token is printed
 * exactly once at issuance, so operators that lose the token re-issue
 * a fresh one and revoke the old.
 *
 * Plaintext is never persisted, never logged, and never returned by
 * any service method beyond `issue()`. The console output is the only
 * surface it ever appears on.
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class TokenController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var string|null Human-readable identifier for the issued token.
     * Defaults to `cli-<unix-timestamp>` when omitted
     * so every row has something to grep on in the
     * CP listing.
     */
    public ?string $name = null;

    /**
     * @var int|null TTL in seconds for the issued token. Null (the
     * default) inherits from `Settings::$tokenTtlDefault`
     * which itself defaults to null = no expiry.
     */
    public ?int $ttl = null;

    /**
     * @var string|null List action only. Restrict output to one
     * user (email or username).
     */
    public ?string $user = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function options($actionID): array
    {
        return match ($actionID) {
            'issue' => array_merge(parent::options($actionID), ['name', 'ttl']),
            'list' => array_merge(parent::options($actionID), ['user']),
            default => parent::options($actionID),
        };
    }

    /**
     * Issue a fresh bearer token bound to the given user.
     *
     * Prints the plaintext exactly once. Operators that miss the
     * output should revoke the just-issued token (`herald/token/revoke
     * <id>`) and re-issue.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionIssue(string $user): int
    {
        $resolved = Craft::$app->getUsers()->getUserByUsernameOrEmail($user);
        if ($resolved === null) {
            $this->stderr("Unknown user '{$user}'. Provide a username or email.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $name = $this->name ?? ('cli-' . time());

        try {
            $result = Herald::getInstance()->tokens->issue(
                userId: (int) $resolved->id,
                name: $name,
                ttlSeconds: $this->ttl,
            );
        } catch (\Throwable $e) {
            $this->stderr("Failed to issue token: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::SOFTWARE;
        }

        $token = $result['token'];
        $model = $result['model'];

        $this->stdout("\n");
        $this->stdout("Bearer token issued.\n", Console::FG_GREEN);
        $this->stdout(str_repeat('=', 70) . "\n\n");
        $this->stdout("  id:        ", Console::FG_GREY);
        $this->stdout("{$model->id}\n");
        $this->stdout("  name:      ", Console::FG_GREY);
        $this->stdout("{$model->name}\n");
        $this->stdout("  user:      ", Console::FG_GREY);
        $this->stdout(sprintf("%s (#%d)\n", $resolved->username ?? $resolved->email, $model->userId));
        $this->stdout("  expiresAt: ", Console::FG_GREY);
        $this->stdout(($model->expiresAt ?? 'never') . "\n");
        $this->stdout("\n");
        $this->stdout("  token (save this, it will not be shown again):\n", Console::FG_YELLOW);
        $this->stdout("    {$token}\n\n", Console::FG_CYAN);
        $this->stdout("Configure your MCP client with:\n", Console::FG_GREY);
        $this->stdout("  Authorization: Bearer {$token}\n\n", Console::FG_GREY);

        return ExitCode::OK;
    }

    /**
     * Revoke a token by its primary-key id. A soft delete, so the row
     * stays in the table with `dateDeleted` set so audit history
     * survives. Subsequent lookups against the plaintext return null.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionRevoke(int $id): int
    {
        $tokens = Herald::getInstance()->tokens;
        $existing = $tokens->getById($id);
        if ($existing === null) {
            $this->stderr("No live token with id #{$id}.\n", Console::FG_RED);
            return ExitCode::NOUSER;
        }

        try {
            $ok = $tokens->revoke($id);
        } catch (\Throwable $e) {
            $this->stderr("Failed to revoke token #{$id}: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::SOFTWARE;
        }

        if (!$ok) {
            // Race — token revoked between our getById and revoke.
            // Surface as a benign "already revoked".
            $this->stdout("Token #{$id} was already revoked.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("Revoked token #{$id} ({$existing->tokenPrefix}…, {$existing->name}).\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * List bearer tokens: all live tokens by default, or restricted
     * to one user via `--user=<email-or-username>`. Plaintext is
     * never shown; only the 8-char prefix lets operators correlate
     * a row back to a client.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionList(): int
    {
        $tokens = Herald::getInstance()->tokens;

        if ($this->user !== null) {
            $resolved = Craft::$app->getUsers()->getUserByUsernameOrEmail($this->user);
            if ($resolved === null) {
                $this->stderr("Unknown user '{$this->user}'.\n", Console::FG_RED);
                return ExitCode::USAGE;
            }
            $rows = $tokens->getAllForUser((int) $resolved->id);
        } else {
            $rows = $tokens->getAll();
        }

        if ($rows === []) {
            $this->stdout("No live bearer tokens.\n", Console::FG_GREY);
            return ExitCode::OK;
        }

        $this->stdout("\n");
        $this->stdout(sprintf(
            "%-6s  %-24s  %-10s  %-24s  %-20s  %-20s  %s\n",
            'ID',
            'NAME',
            'PREFIX',
            'USER',
            'EXPIRES',
            'LAST USED',
            'CREATED',
        ), Console::FG_GREY);
        $this->stdout(str_repeat('-', 140) . "\n", Console::FG_GREY);

        foreach ($rows as $row) {
            $user = $row->getUser();
            $userLabel = $user !== null
                ? sprintf('%s (#%d)', $user->username ?? $user->email, $row->userId)
                : "(deleted, #{$row->userId})";

            $this->stdout(sprintf(
                "%-6d  %-24s  %-10s  %-24s  %-20s  %-20s  %s\n",
                $row->id ?? 0,
                $this->_truncate($row->name, 24),
                $row->tokenPrefix . '…',
                $this->_truncate($userLabel, 24),
                $row->expiresAt ?? 'never',
                $row->lastUsedAt ?? 'never',
                $row->dateCreated ?? '',
            ));
        }

        $this->stdout("\n");
        return ExitCode::OK;
    }

    // Private Methods
    // =========================================================================

    /**
     * Truncate a string to fit a fixed-width column. Trailing
     * ellipsis stays visible inside the column budget.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }
        return mb_substr($value, 0, $max - 1) . '…';
    }
}
