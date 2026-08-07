# Scheduling — Frontend Integration Guide

**Audience:** the `b-dashboard-pizza` team.
**Backend:** OperationsPizza (`/api/v1/...`).
**Feature:** `app/[locale]/(dashboard)/dashboard/scheduling`.

Today the scheduling page is a fully client-side prototype: all data comes from
`lib/scheduling/data.ts`, state lives in `useState` inside
`components/scheduling/scheduling-manager-new.tsx`, and everything is lost on
refresh. This document is what it needs to talk to.

Nothing here has been implemented on the frontend — the backend is built and
tested (87 passing tests), the frontend is yours.

---

## 0. TL;DR — the eight things that will bite you

Read these before writing any code. Each one is a bug that looks like it works.

1. **`day_index` is computed server-side.** Don't derive it from a date on the
   client. The business week starts **Tuesday**, and that is a per-store setting
   (`week.week_start_dow`), not a constant.
2. **Use `duration_minutes`, never `calcHours(startTime, endTime)`.** Wall-clock
   arithmetic is wrong on the two DST days a year. `22:00→06:00` is **9 hours**
   on the November fall-back night, and that is the payroll number.
3. **A `Shift.id` is an *assignment* id, not a shift id.** One Humanity shift can
   hold several employees; the grid draws one card per person. To update or
   delete, use the separate `shift_id` field.
4. **Updates are `POST`, not `PUT`/`PATCH`.** House convention across all these
   services.
5. **`EMPLOYEE_NOT_SYNCED` (409) is resumable, not a failure.** Hold the
   manager's typed shift, poll, and replay it. Details in §4.
6. **Bulk operations return `202` + a batch id.** Copy-week is ~70 upstream calls
   against a rate limit. You must poll. There is **no rollback** — failures come
   back per item.
7. **Publish uploads `multipart/form-data` with a real `Blob`.** Use
   `canvas.toBlob()`, not `toDataURL()`. The current prototype builds a 1–3 MB
   base64 string; that must not go in a JSON body.
8. **Shifts legitimately cross midnight.** The store closes at `00:00`, so
   `end_time <= start_time` is the normal case, not an error. `crosses_midnight`
   tells you when.

---

## 1. Wiring

### Suggested file layout (house 4-layer pattern)

```
components/scheduling/scheduling-manager-new.tsx   (existing — swap mocks for the hook)
  → lib/hooks/use-schedule-week.ts                 (new)
  → lib/api/services/scheduling.service.ts         (new)
  → app/api/operations/[storeId]/[...path]/route.ts (new — one catch-all proxy)
  → OperationsPizza /api/v1/stores/{storeId}/...
```

A **catch-all proxy** works well here because every scheduling path maps 1:1 onto
the upstream surface — one route file instead of ~20, and adding a backend
endpoint needs no frontend route change. It must:

- check auth via `app/api/_lib/auth.ts` (same as every other proxy);
- forward `Authorization` and, if present, `X-Correlation-Id`;
- **pass the upstream status and body through untouched** — the UI branches on
  the error codes in §7, so flattening them into a generic error breaks the
  retry flows;
- stream `multipart/form-data` through as an `ArrayBuffer` without setting
  `Content-Type` yourself (that would drop the boundary);
- return `204` with a `null` body, not an empty string.

### Env vars

```
OPERATIONS_API_URL / NEXT_PUBLIC_OPERATIONS_API_URL   # e.g. http://localhost:8000/api
OPERATIONS_TIMEOUT_MS=20000
OPERATIONS_UPLOAD_TIMEOUT_MS=60000                    # publish uploads an image
```

### Auth & store scoping

Same bearer token as every other service (`localStorage["auth-token"]` →
`state.token`). Every route is store-scoped, and **`{storeId}` is the
`store_number` string** (e.g. `03759-00001`) — the value already in
`useSelectedStoreStore().selectedStore.storeId`, not a numeric PK.

`401` = bad/absent token. `403` = pizzasys says this user can't do this here;
show it as a permissions problem, not a bug.

---

## 2. The one call that boots the page

```http
GET /api/operations/{storeId}/schedule/week?week_start=2026-08-04&mode=planned
```

`week_start` may be **any day inside the week** — the backend snaps it to the
store's configured week start. Send the date you have; don't compute Tuesday
yourself.

One request returns everything the grid needs, because eight round trips would
show the user a week assembling itself piece by piece.

```jsonc
{
  "data": {
    "week": {
      "start": "2026-08-04",          // always the true week start
      "end": "2026-08-10",
      "label": "Aug 4 – Aug 10, 2026",
      "week_start_dow": 2,            // 0=Sun..6=Sat. 2 = Tuesday
      "day_dates": ["4","5","6","7","8","9","10"],
      "full_dates": ["2026-08-04", "…", "2026-08-10"]
    },
    "store": {
      "store_number": "03759-00001",
      "timezone": "America/Chicago",
      "open_time": "09:00",
      "close_time": "00:00",          // midnight = END of day, not zero-length
      "slot_minutes": 30,
      "overtime_threshold_hours": 40,
      "default_labor_rate": 15.0
    },
    "employees": [ /* §3 */ ],
    "departments": [ { "id": "POS1", "name": "Kitchen", "humanity_position_id": "POS1" } ],
    "shifts": [ /* §3 */ ],
    "actual_shifts": [ /* §6 */ ],
    "availability": [ /* §5 */ ],
    "time_off": [ /* §5 */ ],
    "published": null,                // or the published record for this week
    "stats": {
      "total_hours": 128.5,
      "total_shifts": 22,
      "active_employees": 9,
      "labor_cost": 2113.75           // real, from replicated pay history
    },
    "overtime_employee_ids": ["501"],
    "conflicts": [
      { "employee_id": "501", "shift_a_id": "12", "shift_b_id": "18", "shift_date": "2026-08-06" }
    ]
  }
}
```

**Query params:** `week_start`, `mode` (`planned|actual|both`), `department`,
`search`. Filtering server-side keeps the roster and the shifts consistent — a
filtered grid never shows a card with no row to sit on.

### What this replaces

| Prototype constant | Replace with |
|---|---|
| `DUMMY_EMPLOYEES` | `data.employees` |
| `INITIAL_SHIFTS` / `PREVIOUS_WEEK_SHIFTS` | `data.shifts` (fetch the week you need) |
| `INITIAL_AVAILABILITY` | `data.availability` |
| `INITIAL_TIME_OFF` | `data.time_off` |
| `INITIAL_ACTUAL_SHIFTS` | `data.actual_shifts` |
| `DEPARTMENTS` | `data.departments` |
| `DEFAULT_OVERTIME_THRESHOLD` | `data.store.overtime_threshold_hours` |
| hardcoded `$15` labor rate | `data.stats.labor_cost` / `employee.hourly_rate` |
| `detectConflicts()` client-side | `data.conflicts` (see §5 for why) |

---

## 3. Core shapes

### Employee

```jsonc
{
  "id": "501",
  "name": "Marco Rossi",
  "first_name": "Marco", "last_name": "Rossi",
  "role": "Pizzaiolo",
  "department": "Kitchen",
  "avatar": "MR",              // server-assigned initials
  "color": "blue",             // server-assigned, stable per employee
  "is_active": true,
  "status": "hired",
  "humanity_employee_id": "88213",
  "synced": true,              // ← false means they CANNOT be scheduled yet
  "hourly_rate": 16.5,
  "email": "marco@example.com",
  "phone": "555-0100"
}
```

`avatar` and `color` come from the server on purpose, so a person is the same
colour across sessions, devices and users. The palette matches the existing
`EMPLOYEE_COLORS` keys (`blue`, `emerald`, `violet`, `amber`, `rose`, `cyan`,
`orange`, `pink`, `indigo`, `teal`).

**`synced: false`** → show a "Syncing…" state and don't let the manager start a
shift for them, or handle the 409 in §4.

### Shift

```jsonc
{
  "id": "42",                  // ASSIGNMENT id — the card's identity
  "shift_id": 17,              // ← use THIS for update/delete
  "humanity_shift_id": "9001",
  "employee_id": "501",
  "shift_date": "2026-08-06",  // absolute; day_index is week-relative
  "day_index": 2,              // server-computed
  "start_time": "09:00",
  "end_time": "17:00",
  "starts_at": "2026-08-06T14:00:00+00:00",
  "ends_at": "2026-08-06T22:00:00+00:00",
  "duration_minutes": 480,     // ← use THIS for hours, never calcHours()
  "crosses_midnight": false,
  "label": "Morning",
  "type": "morning",
  "note": null,
  "is_recurring": false,
  "recurring_group_id": null,
  "is_published": false,
  "department": "Kitchen",
  "origin": "operations",      // operations | humanity | reconciler
  "updated_at": "2026-08-05T10:12:00+00:00"
}
```

> **`origin: "humanity"` or `"reconciler"`** means the shift was created or last
> changed in Humanity's own app, not here. Worth a subtle badge — it explains to
> a manager why something they didn't create appeared, or why their edit was
> overwritten. Humanity always wins.

---

## 4. Writing shifts

### Create

```http
POST /api/operations/{storeId}/shifts
```
```jsonc
{
  "employee_id": 501,
  "shift_date": "2026-08-06",   // absolute date — convert day_index yourself
  "start_time": "09:00",
  "end_time": "17:00",
  "label": "Morning",
  "shift_type": "morning",
  "note": null,
  "force": false                // see below
}
```
→ `201` with the created shift in the §3 shape.

### Update / delete

```http
POST   /api/operations/{storeId}/shifts/{shift_id}     // NOT PUT
DELETE /api/operations/{storeId}/shifts/{shift_id}?confirm=true
```

`DELETE` on a **published** shift returns `409 SHIFT_PUBLISHED` unless
`confirm=true`. Employees may already have been notified by Humanity, so
confirm this with the user rather than passing the flag automatically.

> **Every write goes to TCP Humanity first and only lands locally if that
> succeeded.** So a failed write means *nothing changed anywhere* — safe to
> retry, and safe to leave your optimistic state rolled back. Conversely, if a
> delete fails, the shift is **still live for the employee**: refetch rather
> than removing it from the UI.

### The conflict flow

`409 SHIFT_CONFLICT` / `EMPLOYEE_UNAVAILABLE` / `EMPLOYEE_ON_TIME_OFF` are all
**overridable**. Re-send the identical payload with `"force": true` after the
manager confirms.

Suggested UX: a toast with a "Schedule anyway" action.

### The unsynced-employee flow ⚠️ the interesting one

HiringPizza is the only system allowed to create employees in Humanity. When
scheduling meets an employee with no Humanity link, **nothing is written
anywhere** — the backend asks HiringPizza over NATS and returns:

```jsonc
// 409
{
  "message": "Marco Rossi isn't set up in the scheduling system yet.",
  "error": {
    "code": "EMPLOYEE_NOT_SYNCED",
    "employee_id": "501",
    "employee_name": "Marco Rossi",
    "sync_status": "requested",
    "retry_after_seconds": 5,
    "poll_url": "/api/v1/stores/03759-00001/employees/501/sync-status"
  }
}
```

**This must not lose the manager's work.** The intended flow:

1. Keep the shift they typed in memory.
2. Show a non-blocking banner: *"Setting Marco Rossi up in the scheduling
   system…"*.
3. Poll every ~5s:
   ```http
   GET /api/operations/{storeId}/employees/{employeeId}/sync-status
   ```
   ```jsonc
   { "data": { "synced": false, "humanity_employee_id": null,
                "sync_request": { "status": "requested", "attempts": 1, "last_error": null } } }
   ```
4. When `synced: true` → **replay the held create automatically** and confirm.
5. Cap it (~24 attempts / 2 min). On timeout or `sync_request.status:"failed"`,
   offer a **Retry sync** button:
   ```http
   POST /api/operations/{storeId}/employees/{employeeId}/humanity-sync   → 202
   ```

This normally resolves in a few seconds. It exists because the alternative —
letting two services create staff in Humanity — produces duplicate people, and
Humanity has no bulk delete.

---

## 5. Availability, time off, conflicts

### Availability — inverted server-side

The UI wants *blocked* windows; hiring stores the opposite (when someone **is**
available). The backend inverts it against store hours and layers manager
overrides on top, so you get exactly the existing `AvailabilityRule` shape plus
a `source` field:

```jsonc
{ "id": "profile-501-4", "employee_id": "501", "day_index": 4, "date": "2026-08-08",
  "all_day": true, "start_time": null, "end_time": null,
  "reason": "Not available on Saturdays", "source": "employee_profile" }
```

`source` is `employee_profile` (derived from their hiring record — read-only
here, changed in HiringPizza) or `override` (entered by a manager, deletable).

An employee with **no availability on file is treated as fully available** —
blocking everyone with an incomplete profile would make the roster unusable.

```http
GET    /api/operations/{storeId}/availability?week_start=YYYY-MM-DD
POST   /api/operations/{storeId}/availability-overrides
DELETE /api/operations/{storeId}/availability-overrides/{id}
```

> **Override `day_of_week` is canonical `0=Sun..6=Sat`**, *not* the grid's
> Tuesday-based `day_index`. Convert at the API edge.

### Time off

Mirrored from Humanity leave and **already expanded to one entry per day**, so
the grid never does calendar maths:

```jsonc
{ "id": "7-3", "time_off_id": "7", "employee_id": "501",
  "day_index": 3, "date": "2026-08-07", "type": "pto", "label": "PTO", "status": "approved" }
```

Locally-created entries can be deleted; Humanity-sourced ones return
`409 TIME_OFF_READ_ONLY` (they'd just reappear on the next sync — withdraw them
in Humanity).

```http
GET    /api/operations/{storeId}/time-off?week_start=YYYY-MM-DD
POST   /api/operations/{storeId}/time-off
DELETE /api/operations/{storeId}/time-off/{id}
```

### Conflicts — prefer the server's list

`data.conflicts` is authoritative. The existing client-side `detectConflicts()`
compares `"HH:mm"` strings, so it **cannot see** a `22:00–02:00` shift colliding
with the next morning's `01:00–09:00` one. The server compares UTC instants.

Keep the client check for instant feedback while dragging if you like, but the
server's list is the one to display.

---

## 6. Actual shifts (the Planned/Actual/Compare tabs)

Owned entirely by OperationsPizza and **never pushed to Humanity** — it's a
manager's review record, not a schedule.

```jsonc
{ "id": "9", "employee_id": "501",
  "planned_shift_id": "42",     // the ASSIGNMENT id, or null for ad-hoc coverage
  "shift_date": "2026-08-04", "day_index": 0,
  "start_time": "09:00", "end_time": "17:00", "duration_minutes": 480,
  "label": "Morning", "type": "morning",
  "status": "confirmed",        // confirmed | modified | absent | added
  "note": null, "source": "manual" }
```

**`status` is derived by the server, not sent by you.** Same times as the plan →
`confirmed`; different → `modified`; no planned counterpart → `added`. (`absent`
is set explicitly via its own endpoint.) This is deliberate: letting the client
assert the status lets it drift from the times it describes.

```http
POST   /api/operations/{storeId}/shift-assignments/{assignmentId}/confirm-actual  // one-click "worked as planned"
POST   /api/operations/{storeId}/actual-shifts                                    // create/amend
POST   /api/operations/{storeId}/actual-shifts/{id}                               // edit times
POST   /api/operations/{storeId}/actual-shifts/{id}/absent                        // { note }
DELETE /api/operations/{storeId}/actual-shifts/{id}
```

Reviewing the same assignment twice **amends** rather than duplicating.

---

## 7. Bulk operations — copy week, templates, clear week

All of these are `202 + batch id`. A copy-week fans out into ~70 upstream calls
against an undocumented rate limit; it cannot be a synchronous request.

```http
POST /api/operations/{storeId}/schedule/bulk/copy-week
     { "source_week_start": "2026-08-04", "target_week_start": "2026-08-11", "mode": "replace" }

POST /api/operations/{storeId}/schedule/bulk/apply-template
     { "template_id": 3, "week_start": "2026-08-11", "mode": "replace" }

POST /api/operations/{storeId}/schedule/bulk/clear-week
     { "week_start": "2026-08-11", "confirm": true }
```

`mode`: `replace` wipes the target week first; `merge` adds alongside.

### Poll it

```http
GET /api/operations/{storeId}/schedule/bulk/{batchId}
```
```jsonc
{ "data": {
  "id": "01J...", "type": "copy_week",
  "status": "completed_with_errors",   // queued|processing|completed|completed_with_errors|failed
  "total": 22, "succeeded": 21, "failed": 1, "progress_percent": 100,
  "items": [                            // ONLY the failures are returned
    { "sequence": 14, "action": "create", "status": "failed",
      "employee_id": "507", "employee_name": "Aisha Noor",
      "shift_date": "2026-08-13", "start_time": "16:00", "end_time": "22:00",
      "error_code": "EMPLOYEE_NOT_SYNCED",
      "error_message": "Aisha Noor isn't set up in the scheduling system yet." }
  ]
}}
```

**There is no rollback, by design.** Deleting shifts we already created in order
to "undo" is more destructive than a partial week — especially if it was
published and employees have seen it. So `completed_with_errors` is a normal
outcome: show which slots failed and offer

```http
POST /api/operations/{storeId}/schedule/bulk/{batchId}/retry-failed   → 202
```

Poll ~2s while `queued`/`processing`, then refetch the week.

### Templates

```http
GET    /api/operations/{storeId}/schedule-templates            // Laravel paginator: rows in data.data
GET    /api/operations/{storeId}/schedule-templates/{id}       // includes shifts[]
POST   /api/operations/{storeId}/schedule-templates            // { name, description, week_start }
POST   /api/operations/{storeId}/schedule-templates/{id}       // rename
DELETE /api/operations/{storeId}/schedule-templates/{id}
```

Creating a template **snapshots the given week**; you don't send shifts.
Template rows are week-relative (`day_index`), which is what makes them
re-appliable.

---

## 8. Publishing a week

```http
POST /api/operations/{storeId}/published-schedules
Content-Type: multipart/form-data

week_start=2026-08-04
screenshot=<Blob>          // optional, max 8 MB, image/*
```

**Use `canvas.toBlob()`, not `toDataURL()`.** The prototype's
`screenshotDataUrl` approach produces a 1–3 MB base64 string; the backend stores
a file and returns a URL.

```jsonc
{ "data": { "id": "5", "week_start_date": "2026-08-04", "week_label": "Aug 4 – Aug 10, 2026",
            "published_at": "2026-08-05T18:00:00+00:00",
            "screenshot_url": "https://…/storage/schedules/03759-00001/abc.png",
            "shift_count": 22, "total_hours": 128.5 } }
```

So `PublishedSchedule.screenshotDataUrl` in `types/scheduling.types.ts` becomes
`screenshot_url`. Re-publishing the same week supersedes the previous record, so
"the published week" is never ambiguous.

```http
GET    /api/operations/{storeId}/published-schedules            // paginated
GET    /api/operations/{storeId}/published-schedules/{id}       // + frozen shift snapshot
DELETE /api/operations/{storeId}/published-schedules/{id}
```

---

## 9. Error contract

Every domain error has this shape. **Branch on `error.code`, not the message.**

```jsonc
{ "message": "Human-readable sentence, safe to show.",
  "error": { "code": "SHIFT_CONFLICT", "conflicts": [ … ] } }
```

| Code | HTTP | What to do |
|---|---|---|
| `EMPLOYEE_NOT_SYNCED` | 409 | Hold the draft, poll, replay. §4 |
| `SHIFT_CONFLICT` | 409 | Offer "Schedule anyway" → retry with `force:true` |
| `EMPLOYEE_UNAVAILABLE` | 409 | Same |
| `EMPLOYEE_ON_TIME_OFF` | 409 | Same |
| `SHIFT_PUBLISHED` | 409 | Confirm, then retry with `?confirm=true` |
| `TIME_OFF_READ_ONLY` | 409 | Explain it must be withdrawn in Humanity |
| `INVALID_LOCAL_TIME` | 422 | The time doesn't exist (DST spring-forward). Ask for another. |
| `STORE_NOT_MAPPED` | 422 | Setup problem — the store has no Humanity location. Not a user error. |
| `POSITION_NOT_MAPPED` | 422 | Setup problem — no default position for the store. |
| `EMPLOYEE_NOT_IN_STORE` | 404 | Stale roster; refetch |
| `HUMANITY_RATE_LIMITED` | 503 | Honour `Retry-After`; back off |
| `HUMANITY_WRITE_FAILED` | 502 | Upstream rejected it. **Nothing was saved** — safe to retry. |

`422` from Laravel validation uses the standard `{ message, errors: {field: [...] } }`
shape instead.

---

## 10. Full endpoint list

All under `/api/operations/{storeId}` (→ `/api/v1/stores/{storeId}` upstream).

| Method | Path | Purpose |
|---|---|---|
| GET | `/schedule/week` | **Boot the page** (§2) |
| GET | `/schedule/employees` | Roster only, for pickers |
| GET | `/schedule/departments` | Department filter list |
| POST | `/shifts` | Create |
| GET | `/shifts/{shiftId}` | Read one |
| POST | `/shifts/{shiftId}` | Update |
| DELETE | `/shifts/{shiftId}` | Delete (`?confirm=true` if published) |
| POST | `/actual-shifts` | Create/amend an actual |
| POST | `/actual-shifts/{id}` | Edit times |
| POST | `/actual-shifts/{id}/absent` | Mark absent |
| DELETE | `/actual-shifts/{id}` | Remove |
| POST | `/shift-assignments/{id}/confirm-actual` | One-click confirm |
| GET | `/availability` | Blocked windows for a week |
| POST | `/availability-overrides` | Add a manager block |
| DELETE | `/availability-overrides/{id}` | Remove |
| GET | `/time-off` | Per-day entries |
| POST | `/time-off` | Add local time off |
| DELETE | `/time-off/{id}` | Remove (local only) |
| GET | `/schedule-templates` | List (paginated) |
| POST | `/schedule-templates` | Snapshot a week |
| GET | `/schedule-templates/{id}` | With shifts |
| POST | `/schedule-templates/{id}` | Rename |
| DELETE | `/schedule-templates/{id}` | Remove |
| GET | `/published-schedules` | History (paginated) |
| POST | `/published-schedules` | Publish (multipart) |
| GET | `/published-schedules/{id}` | With frozen snapshot |
| DELETE | `/published-schedules/{id}` | Remove |
| POST | `/schedule/bulk/copy-week` | 202 + batch id |
| POST | `/schedule/bulk/apply-template` | 202 + batch id |
| POST | `/schedule/bulk/clear-week` | 202 + batch id |
| GET | `/schedule/bulk/{batchId}` | Poll progress |
| POST | `/schedule/bulk/{batchId}/retry-failed` | 202 |
| GET | `/employees/{employeeId}/sync-status` | Poll the Humanity link |
| POST | `/employees/{employeeId}/humanity-sync` | Request a sync (202) |

Plus `GET /api/v1/health` (no store scope) to smoke-test the auth chain.

---

## 11. Suggested order of work

1. Proxy route + service + `useScheduleWeek`. Render the grid from
   `GET /schedule/week` with the mocks as a fallback when no store is selected.
2. Create / update / delete a shift, including the `force` retry.
3. The `EMPLOYEE_NOT_SYNCED` banner + poll + replay (§4). Worth doing early —
   it's the flow most likely to be hit in real use.
4. Availability + time off from the payload; switch the conflict badges to
   `data.conflicts`.
5. Templates + copy-week with the 202/poll/partial-failure UI.
6. Publish (blob upload) and the Actual/Compare tabs.
7. Un-comment the sidebar nav entry (`components/layout/sidebar.tsx`, currently
   commented out — the page is only reachable by URL today).

## 12. Open questions for us

- **Recurring shifts.** The prototype has an `isRecurring` toggle; the backend
  accepts `recurring: { enabled, weeks_ahead }` in the create payload but does
  not yet expand it. Tell us the intended UX (how many weeks? edit-one vs
  edit-all?) and we'll build it.
- **Drag-and-drop** isn't in the prototype. If you want it, it's just
  `POST /shifts/{id}` with a new date/time — no new endpoint needed.
- **Month view** currently aggregates client-side. If that gets slow we can add
  a month-range endpoint.
- **Excel export** is client-side today and can stay that way; say the word if
  you'd rather have a server-generated file.
