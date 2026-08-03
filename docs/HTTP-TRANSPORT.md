# HTTP transport

Herald exposes a Streamable HTTP endpoint at `POST /herald/mcp` for clients that do not speak stdio: hosted MCP setups, browser-based agents, and anything behind a remote agent. The HTTP transport requires the Pro edition.

The transport is **disabled by default**. Set `httpEnabled` to `true` (see [Configuration](CONFIGURATION.md#httpenabled)) to accept requests. While it is off, every request to `/herald/mcp` returns `503 Service Unavailable` regardless of headers or credentials.

Every request over HTTP authenticates and is authorized before a tool runs. Read [the security model](SECURITY.md) before enabling it on a deployed environment.

- [Bearer tokens](#bearer-tokens)
- [OAuth 2.1](#oauth-21)
- [Elevation for high-stakes operations](#elevation-for-high-stakes-operations)
- [Streaming](#streaming)

## Bearer tokens

A bearer token is a long-lived, admin-issued credential bound to one Craft user. Issue one from the console:

```shell
ddev craft herald/token/issue <user> --scopes=content:read,schema:read [--name=<name>] [--ttl=<seconds>]
```

`--scopes` is required and must name at least one known capability scope. A credential carrying no scope authorises nothing and is refused with `403` on its first request, so there is no such thing as an unscoped token that happens to work.

The plaintext token prints **exactly once**, at issue. Herald stores only its SHA-256 hash, so a lost plaintext cannot be recovered: revoke the token and issue a fresh one. Tokens never expire by default; pass `--ttl=<seconds>` for rotation, or set `tokenTtlDefault` for a project-wide default.

Send it on every request:

```
Authorization: Bearer <plaintext-token>
```

Two more actions manage the set:

```shell
ddev craft herald/token/list [--user=<email-or-username>]
ddev craft herald/token/revoke <id>
```

Revocation applies to the next request. An in-flight request on a revoked token completes; the one after it fails.

Tokens are also issuable and revocable from the **Tokens** control panel screen.

## OAuth 2.1

For clients that discover and self-register against a remote MCP server, Herald exposes a full OAuth 2.1 surface: Authorization Code with PKCE (S256 only, `plain` rejected), Refresh Token, RFC 7591 Dynamic Client Registration, RFC 7009 revocation, and RFC 8414 / RFC 9728 discovery metadata.

OAuth and bearer tokens coexist on the same endpoint. OAuth is checked first; a bearer token is the fallback for long-lived admin-issued credentials.

### One-time key setup

```shell
ddev craft herald/oauth/init-keys
```

This writes an RSA 2048-bit key pair to `storage/herald/oauth-keys/{private,public}.key`. The private key is chmodded `0600` and signs every JWT access token; the public key verifies them. The pair survives deploys because Git ignores `storage/`. Rotating with `--force` invalidates every access token in flight.

### Discovery

```
GET https://your-site.test/.well-known/oauth-authorization-server   # RFC 8414
GET https://your-site.test/.well-known/oauth-protected-resource     # RFC 9728
```

Spec-aware clients hit these on first contact to discover the authorization server, the registration endpoint, the supported capability scopes, and the supported PKCE methods. Neither endpoint requires auth.

### Dynamic Client Registration

```shell
curl -X POST https://your-site.test/oauth/register \
  -H 'Content-Type: application/json' \
  -d '{
    "client_name": "My MCP Client",
    "redirect_uris": ["https://my-client.test/callback"],
    "token_endpoint_auth_method": "none"
  }'
```

The response carries a fresh `client_id`, plus a `client_secret` when the auth method is not `none`. Registration is per-IP rate-limited.

A self-registered client starts **unapproved**: both the authorize and token flows reject it until an admin approves it on the **Clients** control panel screen, and its authorize request renders a "pending admin approval" page rather than a generic OAuth error. Set `dcrAutoApprove` to `true` for zero-friction self-registration on a trusted install, or `dcrEnabled` to `false` to require out-of-band provisioning entirely.

### The PKCE flow

1. Generate a code verifier (43 or more characters from `[A-Za-z0-9-._~]`) and SHA-256 it into the `code_challenge`.
2. Send the user to:

```
https://your-site.test/oauth/authorize?
  response_type=code&
  client_id=<from DCR>&
  redirect_uri=<one of the registered URIs>&
  code_challenge=<S256 challenge>&
  code_challenge_method=S256&
  scope=content:read+schema:read&
  state=<random>&
  resource=https://your-site.test/herald/mcp
```

3. The user lands on Herald's consent screen, which requires a live Craft control panel session. Approving redirects back to `redirect_uri` with `code` and `state`.
4. Exchange the code:

```shell
curl -X POST https://your-site.test/oauth/token \
  -d 'grant_type=authorization_code' \
  -d 'code=<from redirect>' \
  -d 'redirect_uri=<same as step 2>' \
  -d 'client_id=<from DCR>' \
  -d 'code_verifier=<original verifier>' \
  -d 'resource=https://your-site.test/herald/mcp'
```

5. Call the endpoint:

```shell
curl -X POST https://your-site.test/herald/mcp \
  -H 'MCP-Protocol-Version: 2025-11-25' \
  -H 'Authorization: Bearer <access_token>' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","clientInfo":{"name":"my-client","version":"1.0"}}}'
```

### Audience binding

The `resource` parameter on `/authorize` and `/token` lands in the access token's `aud` claim, and Herald verifies it against the canonical `herald/mcp` URL on every request (RFC 8707). A token minted for one resource cannot be replayed against another. Pass `resource=<absolute URL to /herald/mcp>` on both endpoints.

### Token lifetimes and rotation

Access tokens last 1 hour (`PT1H`) and refresh tokens 30 days (`P30D`) by default, both configurable through `oauthAccessTokenTtl` / `oauthRefreshTokenTtl`.

```shell
curl -X POST https://your-site.test/oauth/token \
  -d 'grant_type=refresh_token' \
  -d 'refresh_token=<from the previous exchange>' \
  -d 'client_id=<from DCR>'
```

Refresh tokens rotate on every exchange: a new access and refresh pair is issued and the old refresh token is revoked. Every token descended from one authorization shares a family id, so presenting an already-consumed refresh token (the canonical stolen-token signal) revokes the whole family, writes a security audit row, and returns an OAuth error. The legitimate client re-authorizes through the consent flow. This bounds a leaked refresh token to a single rotation window.

### Revocation

```shell
curl -X POST https://your-site.test/oauth/revoke -d 'token=<access or refresh>'
```

Per RFC 7009 §2.2 the endpoint returns 200 whether or not the token was known, so it leaks nothing about which tokens exist.

## Elevation for high-stakes operations

Craft's control panel guards credential and destructive operations behind an elevated session, meaning the user re-enters their password. Herald brings the same posture to HTTP with an in-band elevation flow, because the HTTP transport has no other re-authentication step.

The operations that require elevation over HTTP are password, email and admin-flag changes on the `users` tool, toggling an entry's publication status, and deleting an element. Attempting one without elevation returns a tool error naming the flow.

To elevate, the user opens `/oauth/elevate` in a browser, where Herald requires a live Craft session, calls Craft's own `requireElevatedSession()` for a fresh password re-entry, verifies the credential belongs to the signed-in user, and mints a short-lived elevation marker held server-side for `elevationTtl` seconds (300 by default).

The marker is never read from a client claim, and it binds to the resolved user rather than travelling on the wire. When the request context cannot be determined, Herald treats the request as un-elevated and refuses. stdio is the trusted local transport and is never gated.

**Elevation does not unlock code execution.** `craft_exec` stays stdio-only always. An elevated HTTP request that calls it is still rejected at the dispatcher.

## Streaming

Long-running tools emit progress frames between the `tools/call` request and its terminal response, and a client can cancel an in-flight call without dropping the connection. `resave`, `bulk_entries`, `scaffold_entries`, `content_audit` and `import_export` stream; every other tool degrades to a spec-correct single-frame response.

### Opting in

A client requests the SSE response by sending `Accept: text/event-stream` on the `tools/call` POST. Herald returns the plain JSON response when the header is absent or does not mention `text/event-stream`.

```
POST /herald/mcp
Accept: text/event-stream
Content-Type: application/json
Authorization: Bearer <token>
Mcp-Session-Id: <session-id>
MCP-Protocol-Version: 2025-11-25

{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"<tool>","arguments":{},"_meta":{"progressToken":"prog-1"}}}
```

### Wire format

```
id: <uuid>
event: message
data: {"jsonrpc":"2.0","method":"notifications/progress","params":{"progressToken":"prog-1","progress":1,"total":3}}

id: <uuid>
event: message
data: {"jsonrpc":"2.0","method":"notifications/progress","params":{"progressToken":"prog-1","progress":2,"total":3}}

id: <uuid>
event: message
data: {"jsonrpc":"2.0","id":2,"result":{"content":[],"isError":false}}
```

The terminal frame carries the original request id and the `tools/call` result envelope. Intermediate frames are `notifications/progress` envelopes carrying the client's `progressToken` when one was supplied in `_meta`, plus `progress` and optional `total` and `message` fields. Each frame's `id:` is a UUIDv4, emitted for forward compatibility with `Last-Event-ID` resumability. There is no replay buffer, so a reconnect after a dropped connection loses in-flight frames.

### Cancellation

Send a `notifications/cancelled` notification (a notification, so no `id` field) naming the in-flight request id:

```
POST /herald/mcp
Authorization: Bearer <token>
Mcp-Session-Id: <session-id>
MCP-Protocol-Version: 2025-11-25

{"jsonrpc":"2.0","method":"notifications/cancelled","params":{"requestId":2,"reason":"user requested"}}
```

Herald flips a cache-backed cancellation flag that the running tool observes between yields, and the stream ends with a terminal `notifications/cancelled` envelope. The contract is cooperative rather than preemptive, so a tool that never yields cannot be cancelled. The cancellation slot lives for an hour, which means a `notifications/cancelled` delayed by a network blip still lands on a running stream.

A dropped TCP connection is handled the same way: Herald cancels the in-flight token but keeps draining the generator, so the audit row is still written rather than lost mid-frame.

### Audit rows

A streamed invocation writes exactly one `herald_invocations` row per stream, not one per frame, with `durationMs` measured wall-clock from stream start to stream end. A cancellation lands as `kind=cancelled`, distinct from `tool_error`, `internal_error` and `rate_limited`, and leaves `errorClass` and `errorMessage` null, because a cancellation is not an error. Streamed and non-streamed calls produce forensically identical rows.

### Smoke-testing the wire

A fixture tool, `_streaming_test`, exists for end-to-end SSE health checks. It is gated behind `HERALD_STREAMING_FIXTURE` and never registers unless an operator sets it. Set the variable, restart your PHP workers, and:

```shell
curl -N -X POST https://your-site.test/herald/mcp \
  -H "Authorization: Bearer $TOKEN" \
  -H "Mcp-Session-Id: $SESSION" \
  -H 'Accept: text/event-stream' \
  -H 'MCP-Protocol-Version: 2025-11-25' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"_streaming_test","arguments":{},"_meta":{"progressToken":"prog-1"}}}'
```

Expect HTTP 200, `Content-Type: text/event-stream`, three progress frames, then a terminal response carrying `done: true`. Anything else (a proxy buffering the response into one block, workers not flushing, a proxy rewriting the content type) shows up against the fixture's deterministic output immediately.
