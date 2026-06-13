# Cloud API Contract

This package talks to `moo-scaffold-cloud` through JSON POST endpoints. Every
request includes the project access `token` in the JSON body.

Common failure response:

```json
{ "ok": false, "error": "message" }
```

## Intake

### Runtime Errors

`POST /api/v1/runtimes/intake`

```json
{
  "token": "moo_xxx",
  "records": []
}
```

Success:

```json
{ "ok": true, "saved": 1 }
```

`saved` must equal `records.length`. A smaller value is treated as a failed
batch so the local cursor does not advance.

### Slow Queries

`POST /api/v1/slow-queries/intake`

Body and response match the runtime intake endpoint.

## Status

### Summary

`POST /api/v1/summary`

```json
{ "token": "moo_xxx", "limit": 5 }
```

Returns project stats and recent runtime, slow query, and todo records.

### Heartbeat

`POST /api/v1/heartbeat`

```json
{ "token": "moo_xxx" }
```

Used by `moo:cloud:push` to show that the push pipeline is alive, even when
there are no new records to upload.

## MCP Runtime Tools

### List Runtimes

`POST /api/v1/runtimes/list`

```json
{ "token": "moo_xxx", "limit": 20, "status": "open" }
```

`status` is optional.

### Get Runtime

`POST /api/v1/runtimes/get`

```json
{ "token": "moo_xxx", "hash": "abc123abc123", "with_payload": true }
```

`hash` is a 12-character lowercase hex string.

### Resolve Runtime

`POST /api/v1/runtimes/resolve`

```json
{
  "token": "moo_xxx",
  "hash": "abc123abc123",
  "note": "fixed in commit xxx",
  "resolved_by": "developer"
}
```

`note` and `resolved_by` are optional.

## MCP Todo Tools

### List Todos

`POST /api/v1/todos/list`

```json
{ "token": "moo_xxx", "limit": 20, "status": "open" }
```

`status` is optional.

### Get Todo

`POST /api/v1/todos/get`

```json
{ "token": "moo_xxx", "id": "todo-id" }
```

### Update Todo Status

`POST /api/v1/todos/status`

```json
{
  "token": "moo_xxx",
  "id": "todo-id",
  "status": "in_progress",
  "note": "working on it",
  "by": "developer"
}
```

Valid statuses are controlled by the cloud service. The SDK only forwards the
value and normalizes transport errors.
