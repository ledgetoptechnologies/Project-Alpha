# API v2 Directory create conflicts

The scoped client and organization create routes retain their existing authentication, feature flags, identity headers and transaction rules. This response clarification does not enable either route or grant additional permissions.

When a valid, non-exhausted authorization generation differs from the submitted generation, these routes return HTTP `409` with this exact envelope:

```json
{
  "apiVersion": "2",
  "sourceInstanceId": "<instance UUID>",
  "applicationId": "<application UUID>",
  "historyEpoch": "<history UUID>",
  "requestId": "<request UUID>",
  "error": { "code": "authorization_generation_conflict" }
}
```

The response uses `Content-Type: application/json`, `Cache-Control: no-store`, and an `X-Request-ID` equal to the envelope's request ID. Consumers must validate the complete envelope, configured identity and response headers before using the code. An empty, malformed or unknown conflict response is not authorization to refresh or retry with changed command bytes.

Other conflicts retain their existing generic HTTP `409` response, including changed reuse of a command ID, duplicate or tombstoned external identities, organization-name conflicts, relationship conflicts, missing or invalid authorization state, and an exhausted generation.

## Retry and history rules

- An exact successful receipt replay is checked before the current generation and still returns the original successful result, even after the generation advances.
- A rejected stale command creates no receipt or customer. Replaying its exact bytes can establish that its generation is stale **now**; it does not prove why an earlier generic conflict occurred.
- Refreshing a generation does not prove the external identity is available or that the rest of the command is valid. A subsequent duplicate or relationship conflict must still fail closed. Active and tombstoned external IDs remain non-reusable.
- Keep the original command immutable. An application implementing explicit recovery should retain its original history and audit a separately authorized successor rather than rewriting the failed request.

Existing consumers that treat every `409` as a generic conflict remain compatible. No schema migration or additional environment variable is required for this response clarification.
