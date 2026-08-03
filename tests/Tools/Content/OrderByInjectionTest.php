<?php

/**
 * =========================================================================
 * Negative tests for remediation item 1.2 — `orderBy` SQL injection on
 * the four content read tools (`entries`, `assets`, `categories`,
 * `tags`).
 *
 * The attack: `orderBy` was forwarded verbatim into the element query.
 * Yii's `quoteColumnName()` returns a string unchanged once it contains
 * `(`, so a payload like
 *
 *     (CASE WHEN (SELECT password FROM users LIMIT 1) > 'm'
 *           THEN 1 ELSE 2 END)
 *
 * reaches the database as an ORDER BY expression. The response ordering
 * then leaks one bit per request — blind boolean extraction of
 * `users.password` for any authenticated caller.
 *
 * Payloads here are comma-free on purpose: Yii's `normalizeOrderBy()`
 * splits a string sort on commas, so a comma inside the payload shreds
 * it into fragments and muddies the demonstration. The real attack has
 * no such constraint (`SUBSTRING` has a comma-free `FROM … FOR …`
 * form), so the comma is an inconvenience for the test, never a
 * defence.
 *
 * Each test asserts the tool REFUSES the payload. Against unfixed code
 * these fail because the query executes (the sub-select is valid SQL),
 * which is the proof that the hole was reachable.
 *
 * The bar is a value allowlist, not a type check: every payload here is
 * a JSON string and satisfies the declared `orderBy` schema, so schema
 * validation (item 1.1) cannot close this on its own.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\herald\Herald;
use craftpulse\herald\tools\ToolException;

// -----------------------------------------------------------------------------
// Payloads
// -----------------------------------------------------------------------------

/**
 * Blind-boolean extraction payload. Valid SQL against Craft's schema,
 * so an unfixed tool runs it and returns rows.
 */
function _herald_orderby_injection_payload(): string
{
    return "(CASE WHEN (SELECT password FROM users LIMIT 1) > 'm' THEN 1 ELSE 2 END)";
}

/**
 * @return array<int,array{0:string,1:string}>
 */
dataset('contentReadTools', [
    'entries' => ['entries'],
    'assets' => ['assets'],
    'categories' => ['categories'],
    'tags' => ['tags'],
]);

// -----------------------------------------------------------------------------
// The injection
// -----------------------------------------------------------------------------

it('refuses a sub-select orderBy payload', function(string $toolName) {
    $tool = Herald::getInstance()->tools->getByName($toolName);
    expect($tool)->not->toBeNull();

    $tool->execute([
        'orderBy' => _herald_orderby_injection_payload(),
        'limit' => 1,
    ]);
})->with('contentReadTools')->throws(ToolException::class);

it('refuses a parenthesised expression in orderBy', function(string $toolName) {
    $tool = Herald::getInstance()->tools->getByName($toolName);
    expect($tool)->not->toBeNull();

    $tool->execute([
        'orderBy' => '(SELECT 1)',
        'limit' => 1,
    ]);
})->with('contentReadTools')->throws(ToolException::class);

it('refuses an unknown bare column name in orderBy', function(string $toolName) {
    $tool = Herald::getInstance()->tools->getByName($toolName);
    expect($tool)->not->toBeNull();

    $tool->execute([
        'orderBy' => 'users.password',
        'limit' => 1,
    ]);
})->with('contentReadTools')->throws(ToolException::class);

// -----------------------------------------------------------------------------
// The allowlist still has to do its day job
// -----------------------------------------------------------------------------

it('admits an allowlisted sort column with an explicit direction', function(string $toolName) {
    $tool = Herald::getInstance()->tools->getByName($toolName);
    expect($tool)->not->toBeNull();

    $result = $tool->execute([
        'orderBy' => 'title desc',
        'limit' => 2,
    ]);

    expect($result)->toBeArray();
})->with('contentReadTools');

it('admits a comma-separated multi-column sort', function(string $toolName) {
    $tool = Herald::getInstance()->tools->getByName($toolName);
    expect($tool)->not->toBeNull();

    $result = $tool->execute([
        'orderBy' => 'dateCreated desc, title asc',
        'limit' => 2,
    ]);

    expect($result)->toBeArray();
})->with('contentReadTools');
