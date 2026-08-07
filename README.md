# OperationsPizza

The operations backend. Owns **scheduling**, and is the third Laravel service in
the NATS mesh alongside `pizzasys` (auth) and `HiringPizza` (employees).

---

## How it fits together

```
pizzasys ──auth.v1.{user,store}.*──┐
                                   ├──► OperationsPizza ──► TCP Humanity (source of truth for shifts)
HiringPizza ─hiring.v1.employee.*──┘         │
       ▲                                     │
       └────operations.v1.employee.humanity_sync_requested
```

- **pizzasys** is the auth source of truth. Every request's bearer token is
  verified against it by `AuthTokenStoreScopeMiddleware`; nothing authenticates
  locally, which is why `users` has no password column.
- **HiringPizza** is the employee source of truth *and the only writer of staff
  into Humanity*. When scheduling meets an employee with no Humanity link, this
  service asks over NATS and waits — two writers to one external system is how
  duplicate people get created.
- **TCP Humanity** is the source of truth for shifts. Every write goes there
  first; a local row exists only because that write succeeded.

## Key decisions worth knowing before you change anything

| Thing | Why it is the way it is |
|---|---|
| Humanity is called **before** the DB transaction opens (shifts) | Never hold a transaction across a network round trip. If persistence fails afterwards, the reconciler creates the mirror row — which is why it must be able to create, not only update. |
| Humanity is called **inside** the transaction (employees, in HiringPizza) | The opposite trade-off, chosen deliberately: a failed push must roll the employee back, and the payload can only be built after the child rows are written. |
| Shifts store **both** wall-clock and UTC | Wall clock is what we display and what Humanity speaks; UTC is what every query, sort and overlap check uses. Comparing `TIME` columns breaks the moment a shift crosses midnight — and the store closes at 00:00, so that is routine. |
| `duration_minutes` is the true UTC delta | On the two DST days a year it differs from the clock-face duration. It is the payroll-facing number, so nothing should recompute hours from the wall-clock strings. |
| The week starts **Tuesday** | `store_schedule_settings.week_start_dow`, not a constant. Shifts store an absolute date; `day_index` is computed per request and never persisted (the sole exception is `schedule_template_shifts`, which is week-relative by nature). |
| Bulk work is always async | A copy-week is ~70 Humanity calls against an undocumented rate limit. |
| Bulk work never rolls back | Deleting shifts we already created to "undo" is worse than a partial week, especially once employees have seen it. Failures surface per item with Retry Failed. |

## The Humanity API, in one screen

Three different Humanity API surfaces exist. **Use v2 REST**
(`https://www.humanity.com/api/v2`). Do not mix in the legacy v1 RPC API or
`api.humanity.com/v1` (which the official PHP SDK targets). `docs.humanity.org`
is a different company entirely.

- **Auth is the `password` grant** — there is no `client_credentials`. It
  authenticates *as a real user*, and that user's **role is the entire
  permission model** (v2 returns `scope: null`). `POST`/`DELETE /shifts` need
  Manager (2) or Supervisor (3), so the service account must be one of those.
- **Errors live in the body, not the HTTP status.** v2 documents only 200 and
  400, and the SDK sends `suppress_response_codes=1`. `status: 1` is success,
  `91` is throttled, `7`/`20` are permission failures. Anything trusting
  `$response->successful()` treats a throttle as success.
- **`schedule` means Position ID.** The container a shift belongs to is the
  Position; there is no separate schedule object. It is what our UI calls a
  "department".
- **No webhooks.** Polling is the only option, which is why the reconciler is
  the primary sync mechanism rather than a backstop.
- **Assignment is `PUT /shifts/{id}`** with `add`/`remove` CSV lists — there is
  no `/shifts/{id}/employees` sub-resource — and a `PUT` **silently does nothing**
  without the matching `update_*` flag.
- **Rate limits are real but unpublished.** Status 91 fires reliably on bulk
  shift creation.

## Setup

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
```

### Prerequisites that are not code

These must exist or the service does nothing useful:

1. **NATS streams and durable consumers.** The app never calls `create()` — see
   the comment in `JetStreamConsumer::getOrInitConsumer`. Someone must run:
   ```bash
   nats stream add OPERATIONS_EVENTS --subjects 'operations.v1.>'
   nats consumer add AUTH_EVENTS   OPERATIONS_AUTH_CONSUMER   --filter 'auth.v1.>'   --pull
   nats consumer add HIRING_EVENTS OPERATIONS_HIRING_CONSUMER --filter 'hiring.v1.>' --pull
   ```
   Plus the `*_TESTING_*` variants when `DEV_MODE=1`.
2. **A `service_clients` row in pizzasys** named `operations-system`; its raw
   token becomes `AUTH_SERVER_CALL_TOKEN`.
3. **`auth_rules` rows in pizzasys** for `service = 'operations-system'`, with
   `store_scope_mode` set so `{storeId}` is enforced. Without these every
   request returns 403.
4. **A Humanity app** (Settings → API v2) and a **Manager/Supervisor service
   account**, in both sandbox and production.

### Running

```bash
php artisan serve
php artisan queue:work --queue=humanity,default   # bulk ops live on the humanity queue
php artisan nats:consume                          # long-running
php artisan schedule:work
```

## Commands

| Command | What it does |
|---|---|
| `nats:consume` | Long-running JetStream consumer (auth + hiring events). |
| `outbox:publish-pending` | Sweeper for the transactional outbox. Scheduled every 5 min. |
| `humanity:sync-catalog` | Pulls Humanity locations + positions into the mapping tables. `--auto-map` matches stores by name. |
| `humanity:reconcile` | Reconciles our shift mirror against Humanity. **Always run `--dry-run` first against production.** |
| `humanity:sync-leave` | Mirrors Humanity leave into `time_off`. |

## ⚠️ Before enabling writes against production

Humanity is **already live** with real managers and employees. The order matters:

1. `HUMANITY_ENV` has no default — the client refuses to start without it.
2. `HUMANITY_WRITES_ENABLED=false` until **HiringPizza's**
   `humanity:backfill-employee-ids` has matched the existing roster. Every
   current employee was created by hand in Humanity with no `eid`, so an
   unguarded push would create a **duplicate staff record for the entire
   roster** — and Humanity has no bulk delete.
3. The reconciler is scheduled only when `HUMANITY_ENV=sandbox`. Its first
   production pass **imports the live schedule** and can soft-delete local rows,
   so run it by hand with `--dry-run` first and read the diff.

## Testing

```bash
php artisan test
```

Runs on in-memory SQLite against `FakeHumanityClient`, so no credentials and no
network are needed. `QUEUE_CONNECTION=null` deliberately: `PublishOutboxEventJob`
does real NATS I/O, and a `sync` queue would make every write test dial a broker.

Arm failures on the fake to prove the rollback paths:

```php
app(FakeHumanityClient::class)->failNext('createShift');
app(FakeHumanityClient::class)->throttleNext('createShift');  // status 91
```
