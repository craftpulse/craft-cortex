<?php

/**
 * =========================================================================
 * Architecture tests — the Audit Kit module retrofit contract.
 *
 * Audit Kit 1.1.0 ships as a library-shipped Yii module rather than a
 * Craft plugin, so Craft never boots it: the dispatch bus does not exist
 * until a consumer calls `AuditKit::register()`. Herald makes that call
 * unconditionally from `Herald::init()`.
 *
 * Every assertion here guards a failure mode that is silent by
 * construction, on a product whose value is the trail:
 *
 *   - **`register()` is present.** Drop the call and nothing throws,
 *     nothing logs, the control panel looks healthy, and the chain simply
 *     never grows. No behavioural test can isolate this on an install
 *     running other Audit Kit consumers, because any one of them
 *     registering the module makes Herald's own call look unnecessary —
 *     so the guard is a token scan of Herald's boot path.
 *
 *   - **The bus resolution carries no null branch.** `_bus()` used to
 *     end in `AuditKit::getInstance()?->getBus()`; the nullsafe operator
 *     is what made a missing registration silent. `getInstance()` cannot
 *     return null on the module API, so a nullable return type here is
 *     the regression, and it is asserted on the reflected signature
 *     rather than on behaviour.
 *
 *   - **`Install` pumps the kit migrator, and `safeDown()` does not
 *     revert it.** The kit is shared by every installed consumer, so
 *     tearing its state down on one plugin's uninstall would break the
 *     others.
 *
 * Token scans rather than substring matches, so a mention inside a
 * docblock or a comment cannot satisfy them — the same approach
 * `ConventionsTest` takes.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\auditkit\AuditKit;
use craftpulse\auditkit\services\Bus;
use craftpulse\herald\Herald;
use craftpulse\herald\migrations\Install;
use craftpulse\herald\services\Audit;

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Whether the body of `$class::$method` contains a static call to
 * `$callee::$calleeMethod`.
 *
 * Tokenised rather than matched as a substring, so a mention inside a
 * docblock or a comment cannot satisfy it, and the imported short form
 * (`AuditKit::register()`), the qualified form, and the fully qualified
 * form all count. The method's own line range comes from reflection, so
 * moving the method inside its file does not break the scan and a
 * matching call elsewhere in the file cannot satisfy it.
 *
 * @param class-string $class        the class declaring the method
 * @param string       $method       the method whose body is scanned
 * @param string       $callee       the called class's short name, without
 *                                   its namespace
 * @param string       $calleeMethod the called static method's name
 *
 * @throws ReflectionException if the method does not exist.
 */
function herald_method_calls_statically(
    string $class,
    string $method,
    string $callee,
    string $calleeMethod,
): bool {
    $reflection = new ReflectionMethod($class, $method);
    $file = $reflection->getFileName();

    if ($file === false) {
        return false;
    }

    $tokens = array_values(array_filter(
        token_get_all((string) file_get_contents($file)),
        static fn(array|string $token): bool => !is_array($token)
            || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true),
    ));

    $nameTypes = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];
    $start = $reflection->getStartLine();
    $end = $reflection->getEndLine();

    foreach ($tokens as $i => $token) {
        if (!is_array($token) || !in_array($token[0], $nameTypes, true)) {
            continue;
        }

        if ($token[2] < $start || $token[2] > $end) {
            continue;
        }

        $segments = explode('\\', $token[1]);

        if (end($segments) !== $callee) {
            continue;
        }

        $separator = $tokens[$i + 1] ?? null;
        $target = $tokens[$i + 2] ?? null;

        if (!is_array($separator) || $separator[0] !== T_DOUBLE_COLON) {
            continue;
        }

        if (is_array($target) && $target[1] === $calleeMethod) {
            return true;
        }
    }

    return false;
}

// -----------------------------------------------------------------------------
// Module registration
// -----------------------------------------------------------------------------

it('registers the Audit Kit module from Herald::init()', function() {
    expect(herald_method_calls_statically(Herald::class, 'init', 'AuditKit', 'register'))
        ->toBeTrue(
            'Herald::init() must call AuditKit::register(). Without it the '
            . 'Audit Kit module is never constructed on an install where no '
            . 'other consumer registers it, and every Herald emission is '
            . 'silently discarded.',
        );
});

it('has the Audit Kit module attached to the application at boot', function() {
    expect(Craft::$app->getModule(AuditKit::ID))->toBeInstanceOf(AuditKit::class);
});

// -----------------------------------------------------------------------------
// Bus resolution has no null branch
// -----------------------------------------------------------------------------

it('resolves the dispatch bus to a non-nullable Bus', function() {
    $returnType = (new ReflectionMethod(Audit::class, '_bus'))->getReturnType();

    expect($returnType)->toBeInstanceOf(ReflectionNamedType::class);
    /** @var ReflectionNamedType $returnType */
    expect($returnType->getName())->toBe(Bus::class);
    expect($returnType->allowsNull())->toBeFalse(
        'Audit::_bus() must not be nullable. A null bus lets auditing stop '
        . 'with nothing thrown and nothing logged.',
    );
});

it('resolves a live bus through production resolution', function() {
    $audit = Herald::getInstance()->getAudit();
    $audit->setBus(null);

    $resolve = (new ReflectionMethod($audit, '_bus'));

    expect($resolve->invoke($audit))->toBeInstanceOf(Bus::class);
});

// -----------------------------------------------------------------------------
// Install pumps the kit migrator, safeDown does not revert it
// -----------------------------------------------------------------------------

it('pumps the Audit Kit migrator from Install::safeUp()', function() {
    expect(herald_method_calls_statically(Install::class, 'safeUp', 'AuditKit', 'getInstance'))
        ->toBeTrue(
            'Install::safeUp() must pump the Audit Kit migrator. It is the '
            . 'seam that lets a kit migration reach every install without a '
            . 'coordinated release across every consumer.',
        );
});

it('never reverts the Audit Kit migrator from Install::safeDown()', function() {
    expect(herald_method_calls_statically(Install::class, 'safeDown', 'AuditKit', 'getInstance'))
        ->toBeFalse(
            'Install::safeDown() must not touch the Audit Kit migrator. The '
            . 'kit is shared by every installed consumer and one plugin\'s '
            . 'uninstall must not tear down state the others rely on.',
        );
});
