<?php

namespace craftpulse\cortex\console\controllers;

use craft\console\Controller;
use craftpulse\cortex\Plugin;
use craftpulse\cortex\services\Oauth;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * =========================================================================
 * Console — OAuth key-pair bootstrap.
 *
 * Usage:
 *   cortex/oauth/init-keys [--force]
 *
 * Generates a fresh RSA 2048-bit key pair at
 * `storage/cortex/oauth-keys/{private,public}.key` for league/oauth2-
 * server's JWT signing path. Idempotent — refuses to overwrite an
 * existing pair unless `--force` is passed. Sets 0600 on the private
 * key, 0644 on the public.
 *
 * Operators run this once per install. The pair stays put across
 * deploys (Git ignores the storage directory); rotating the keys
 * invalidates every in-flight JWT access token.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class OauthController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var bool When true, overwrites an existing key pair. Defaults
     *           to false — operators that genuinely want to rotate
     *           pass `--force` explicitly so accidental overwrites
     *           don't happen.
     */
    public bool $force = false;

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
            'init-keys' => array_merge(parent::options($actionID), ['force']),
            default => parent::options($actionID),
        };
    }

    /**
     * Generate the JWT key pair. Creates the storage directory if
     * missing.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionInitKeys(): int
    {
        $oauth = Plugin::getInstance()->oauth;
        $dir = $oauth->getKeysDirectory();
        $privatePath = $dir . DIRECTORY_SEPARATOR . Oauth::PRIVATE_KEY_FILE;
        $publicPath = $dir . DIRECTORY_SEPARATOR . Oauth::PUBLIC_KEY_FILE;

        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            $this->stderr("Failed to create directory: {$dir}\n", Console::FG_RED);
            return ExitCode::IOERR;
        }

        $exists = is_file($privatePath) || is_file($publicPath);
        if ($exists && !$this->force) {
            $this->stderr(
                "OAuth keys already exist at {$dir}.\n",
                Console::FG_YELLOW,
            );
            $this->stderr(
                "Pass --force to overwrite (this rotates the signing key and invalidates every in-flight access token).\n",
                Console::FG_GREY,
            );
            return ExitCode::SOFTWARE;
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            $this->stderr(
                "openssl_pkey_new() failed: " . (openssl_error_string() ?: 'unknown') . "\n",
                Console::FG_RED,
            );
            return ExitCode::SOFTWARE;
        }

        $privatePem = '';
        if (!openssl_pkey_export($resource, $privatePem)) {
            $this->stderr(
                "openssl_pkey_export() failed: " . (openssl_error_string() ?: 'unknown') . "\n",
                Console::FG_RED,
            );
            return ExitCode::SOFTWARE;
        }

        $publicDetails = openssl_pkey_get_details($resource);
        if ($publicDetails === false || !isset($publicDetails['key']) || !is_string($publicDetails['key'])) {
            $this->stderr("openssl_pkey_get_details() failed.\n", Console::FG_RED);
            return ExitCode::SOFTWARE;
        }
        $publicPem = $publicDetails['key'];

        if (file_put_contents($privatePath, $privatePem) === false) {
            $this->stderr("Failed to write {$privatePath}.\n", Console::FG_RED);
            return ExitCode::IOERR;
        }
        if (!chmod($privatePath, 0o600)) {
            $this->stderr("Failed to set 0600 on {$privatePath}.\n", Console::FG_RED);
            return ExitCode::IOERR;
        }

        if (file_put_contents($publicPath, $publicPem) === false) {
            $this->stderr("Failed to write {$publicPath}.\n", Console::FG_RED);
            return ExitCode::IOERR;
        }
        if (!chmod($publicPath, 0o644)) {
            $this->stderr("Failed to set 0644 on {$publicPath}.\n", Console::FG_RED);
            return ExitCode::IOERR;
        }

        $this->stdout("\n");
        $this->stdout("OAuth key pair generated.\n", Console::FG_GREEN);
        $this->stdout(str_repeat('=', 70) . "\n\n");
        $this->stdout("  private key: ", Console::FG_GREY);
        $this->stdout("{$privatePath} (0600)\n");
        $this->stdout("  public key:  ", Console::FG_GREY);
        $this->stdout("{$publicPath} (0644)\n\n");
        $this->stdout("These keys sign the JWT access tokens cortex's OAuth flow issues.\n", Console::FG_GREY);
        $this->stdout("Rotating them invalidates every in-flight access token.\n\n", Console::FG_GREY);

        return ExitCode::OK;
    }
}
