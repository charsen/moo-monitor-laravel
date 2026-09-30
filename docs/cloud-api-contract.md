---
title: 云端 API 契约
group: 后端监控
order: 10
---
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
{
  "ok": true,
  "saved": 1,
  "filtered": 1,
  "skipped": 1,
  "results": [
    {
      "index": 0,
      "hash": "aaaaaaaaaaaa",
      "status": "saved",
      "retryable": false,
      "reason": null
    },
    {
      "index": 1,
      "hash": "bbbbbbbbbbbb",
      "status": "filtered",
      "retryable": false,
      "reason": "ingest_filter"
    },
    {
      "index": 2,
      "hash": "cccccccccccc",
      "status": "skipped",
      "retryable": true,
      "reason": "upsert_failed"
    }
  ]
}
```

`results` contains one item for every request record. `index` and `hash` must
match that record; `status` is `saved`, `filtered`, or `skipped`; and the item
counts must match the aggregate `saved`, `filtered`, and `skipped` fields.

`saved` and `filtered` are final acknowledgements and are not sent again. A
retryable `skipped` item remains pending by itself. A non-retryable `skipped`
item is moved to the local `cloud-rejected` quarantine for investigation. A
`skipped` item must include a non-empty `reason`.

For rolling upgrades, the client accepts a legacy response without `results`
only when `skipped` is zero and `saved + filtered` equals `records.length`.
Missing, malformed, duplicated, or inconsistent acknowledgement fields fail
the request without advancing the cursor.

Local cursor and partial acknowledgement updates require a successful lock,
complete write, and atomic replacement. A persistence failure makes sync fail
and prevents post-sync bulk pruning. Persisted acknowledgements remain usable
on retry; an acknowledgement that could not be persisted may be sent again.

`moo:cloud:test` requires its single record to be `saved`. A `filtered` or
`skipped` result produces a failing exit code with the outcome and does not
trigger runtime resolution. This does not change sync's final acknowledgement
semantics for filtered records.

### Slow Queries

`POST /api/v1/slow-queries/intake`

Body and response match the runtime intake endpoint.

### Cumulative Snapshots and Optional Context

Runtime and slow-query records are cumulative snapshots. Cloud merges them
under a per-record database lock: `count` and slow-query `max_ms` take the
maximum, `first_seen` takes the earliest time, and `last_seen` the latest.
Counts from multiple hosts are not summed.

Cloud compares `meta.updated_at`, falling back to `last_seen`, and retains
microsecond precision. A strictly older snapshot may advance cumulative
maxima but cannot replace status, resolution metadata, exception, SQL, or
request context. Equal timestamps or missing comparable timestamps retain
arrival-order behavior; different contents with the same timestamp are not
distinguished.

Optional runtime `exception.previous` carries up to three exception entries
with `class`, `message`, `file`, and `line`. Cloud sanitizes these fields on
write and read, and exposes the chain in runtime get, AI Markdown, and the
detail page. Optional slow-query `at.connection` is retained in Cloud and
shown in AI Markdown and the detail summary. Omitting either optional field
does not clear its stored value.

The shared `tests/Fixtures/cloud-monitor-snapshot-contract.json` fixture
verifies these additive fields across SDK sync, Cloud intake, and runtime
reads.

## Local Development Noise

`POST /api/v1/runtimes/discard-local`（ability：`runtimes`）和
`POST /api/v1/slow-queries/discard-local`（ability：`slow_queries`）只接受：

```json
{ "token": "moo_xxx", "env": "local" }
```

成功返回 `{ "ok": true, "deleted": 12 }`。Cloud 只把当前项目 `env=local`
且状态为 `open / in_progress` 的对应记录移入「已删除」；`resolved`、其它环境和其它项目不动。
同 hash 后续再次真实发生并经 intake 上报时，沿用现有机制重新打开。

Monitor 在 Cloud 回执成功后，仅丢弃当前 scope 中按 cursor / partial ack 判定的 pending YAML；
已同步 open 聚合锚点与 cursor 保持不动。

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

The returned `runtime.exception` includes `previous`, an empty array when no
exception chain was captured.

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

Each todo row carries `category`: `bug` = unclassified defect,
`frontend_bug` = frontend defect, `backend_bug` = backend defect, and `task` =
ordinary task. The column defaults to `bug`, so pre-existing rows read back as
`bug`. The list endpoint filters by `status` only — there is no server-side
`category` filter.

### Get Todo

`POST /api/v1/todos/get`

```json
{ "token": "moo_xxx", "id": "todo-id" }
```

The returned `todo` object includes the four-value `category`, sanitized
`page_url`, `context_requests`, `context_errors`, timeline fields, AI-ready
`markdown`, and an `attachments` metadata array. Attachment metadata never
contains a storage path, tokenized URL, or binary data. Image rows carry
`ai_readable=true`; videos remain metadata-only.

### Get Todo Image

`POST /api/v1/todos/image`

```json
{
  "token": "moo_xxx",
  "id": "01J...",
  "attachment_id": "8675309"
}
```

The endpoint requires the `mcp` ability and verifies that both the Todo and
image attachment belong to the token's project. It returns one bounded image
preview as base64 plus `mime_type`, dimensions, and size. It never creates a
public or signed attachment URL.

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
