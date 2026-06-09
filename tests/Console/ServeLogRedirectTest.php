<?php

/**
 * =========================================================================
 * Tests for `ServeController::_redirectStdoutLogHandlers()` (FIX 5).
 *
 * The stdio transport owns STDOUT — it IS the JSON-RPC channel. When
 * `CRAFT_STREAM_LOG` is on, Craft's `MonologTarget` pushes a
 * `StreamHandler` to `php://stdout`, which would corrupt the channel
 * mid-dispatch. The redirect re-points any stdout handler to stderr
 * while leaving file handlers (the default) untouched.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\log\MonologTarget;
use craftpulse\cortex\console\controllers\ServeController;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Psr\Log\LogLevel;

/**
 * Build a `MonologTarget` whose Monolog logger carries the supplied
 * handlers, bypassing the default-logger construction.
 *
 * @param array<int,\Monolog\Handler\HandlerInterface> $handlers
 */
function _cortex_target_with_handlers(array $handlers): MonologTarget
{
    return new MonologTarget([
        'name' => 'cortex-test',
        'level' => LogLevel::WARNING,
        'logger' => static function() use ($handlers): \Monolog\Logger {
            $logger = new \Monolog\Logger('cortex-test');
            $logger->setHandlers($handlers);
            return $logger;
        },
    ]);
}

/**
 * Invoke the private redirect on a controller with a swapped-in log
 * dispatcher carrying just the supplied targets.
 *
 * @param array<int,MonologTarget> $targets
 */
function _cortex_run_redirect(array $targets): void
{
    $original = Yii::$app->log->targets;
    Yii::$app->log->targets = $targets;
    try {
        $controller = new ServeController('cortex/serve', Craft::$app);
        $ref = new ReflectionMethod($controller, '_redirectStdoutLogHandlers');
        $ref->setAccessible(true);
        $ref->invoke($controller);
    } finally {
        Yii::$app->log->targets = $original;
    }
}

it('re-points a stdout StreamHandler to stderr', function() {
    $target = _cortex_target_with_handlers([
        new StreamHandler('php://stdout', Level::Warning, false),
    ]);

    _cortex_run_redirect([$target]);

    $handlers = $target->getLogger()->getHandlers();
    expect($handlers)->toHaveCount(1)
        ->and($handlers[0])->toBeInstanceOf(StreamHandler::class);

    /** @var StreamHandler $handler */
    $handler = $handlers[0];
    expect($handler->getUrl())->toBe('php://stderr')
        // Level + bubble preserved from the original stdout handler.
        ->and($handler->getLevel())->toBe(Level::Warning)
        ->and($handler->getBubble())->toBeFalse();
});

it('leaves a file handler untouched (default file-logging mode)', function() {
    $fileHandler = new RotatingFileHandler('/tmp/cortex-test.log', 5, Level::Warning);
    $target = _cortex_target_with_handlers([$fileHandler]);

    _cortex_run_redirect([$target]);

    $handlers = $target->getLogger()->getHandlers();
    expect($handlers)->toHaveCount(1)
        ->and($handlers[0])->toBe($fileHandler);
});

it('re-points only the stdout handler in a mixed handler stack', function() {
    $fileHandler = new RotatingFileHandler('/tmp/cortex-test.log', 5, Level::Warning);
    $target = _cortex_target_with_handlers([
        new StreamHandler('php://stderr', Level::Warning, false),
        new StreamHandler('php://stdout', Level::Info, false),
        $fileHandler,
    ]);

    _cortex_run_redirect([$target]);

    $handlers = $target->getLogger()->getHandlers();
    $urls = array_map(
        static fn($h) => $h instanceof StreamHandler ? $h->getUrl() : get_class($h),
        $handlers,
    );

    // No handler points at stdout anymore; the file handler is intact.
    expect($urls)->not->toContain('php://stdout')
        ->and($handlers[2])->toBe($fileHandler);
});
