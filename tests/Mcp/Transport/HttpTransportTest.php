<?php

/**
 * =========================================================================
 * Tests for the Http transport adapter — body parsing, response
 * encoding, parse-error envelope.
 *
 * Pure unit tests — no Yii web stack required. Covers the parts of the
 * HTTP transport that don't touch the controller / dispatcher.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\mcp\Server;
use craftpulse\herald\mcp\transport\Http;

beforeEach(function() {
    $this->transport = new Http();
});

// -----------------------------------------------------------------------------
// parseRequest()
// -----------------------------------------------------------------------------

it('parseRequest decodes a valid JSON-RPC body into an array', function() {
    $body = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}';
    $decoded = $this->transport->parseRequest($body);

    expect($decoded)->toBeArray();
    expect($decoded)->toHaveKeys(['jsonrpc', 'id', 'method', 'params']);
    expect($decoded['method'])->toBe('initialize');
});

it('parseRequest returns null on empty body', function() {
    expect($this->transport->parseRequest(''))->toBeNull();
    expect($this->transport->parseRequest('   '))->toBeNull();
});

it('parseRequest returns null on malformed JSON', function() {
    expect($this->transport->parseRequest('{not-json}'))->toBeNull();
    expect($this->transport->parseRequest('jsonrpc=2.0'))->toBeNull();
});

it('parseRequest rejects bare JSON values that are not objects', function() {
    // The MCP spec requires a JSON object body — a string, number, or
    // bare array (batch) is not a valid single message.
    expect($this->transport->parseRequest('"string"'))->toBeNull();
    expect($this->transport->parseRequest('42'))->toBeNull();
    expect($this->transport->parseRequest('[1,2,3]'))->toBeNull();
});

it('parseRequest rejects batch arrays per spec', function() {
    // A JSON-RPC 2.0 batch (an array of requests) is intentionally
    // unsupported on the HTTP transport in 7.1 — sub-gate 7.7 may
    // revisit alongside the SSE upgrade.
    $batch = '[{"jsonrpc":"2.0","id":1,"method":"ping"}]';
    expect($this->transport->parseRequest($batch))->toBeNull();
});

// -----------------------------------------------------------------------------
// encodeResponse()
// -----------------------------------------------------------------------------

it('encodeResponse produces compact JSON', function() {
    $body = $this->transport->encodeResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => ['ok' => true],
    ]);

    // Compact, not pretty-printed.
    expect($body)->not->toContain("\n");
    $roundtrip = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    expect($roundtrip['result']['ok'])->toBeTrue();
});

it('encodeResponse preserves unicode without escaping', function() {
    $body = $this->transport->encodeResponse([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => ['greeting' => 'héllo 你好'],
    ]);

    expect($body)->toContain('héllo');
    expect($body)->toContain('你好');
});

// -----------------------------------------------------------------------------
// parseError()
// -----------------------------------------------------------------------------

it('parseError returns a JSON-RPC -32700 envelope with null id', function() {
    $envelope = $this->transport->parseError();

    expect($envelope)
        ->toHaveKeys(['jsonrpc', 'id', 'error'])
        ->and($envelope['jsonrpc'])->toBe('2.0')
        ->and($envelope['id'])->toBeNull()
        ->and($envelope['error']['code'])->toBe(Server::ERR_PARSE)
        ->and($envelope['error']['message'])->toBeString()->not->toBeEmpty();
});

// -----------------------------------------------------------------------------
// Header constants
// -----------------------------------------------------------------------------

it('exposes the spec-mandated header names as constants', function() {
    expect(Http::HEADER_PROTOCOL_VERSION)->toBe('MCP-Protocol-Version');
    expect(Http::HEADER_SESSION_ID)->toBe('Mcp-Session-Id');
    expect(Http::HEADER_ORIGIN)->toBe('Origin');
    expect(Http::CONTENT_TYPE_JSON)->toBe('application/json');
});
