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
| The scheduling timezone comes from **Humanity**, not from `stores` | `StoreTimezoneResolver` reads it off the mapped Humanity location. Humanity has no per-request timezone and interprets every date and `HH:MM` we send in its location's local time, so a second local copy could only ever drift from the value that actually decides what a shift means. Falls back to `OPERATIONS_DEFAULT_TIMEZONE` for an unmapped store. |
| `users` and `stores` hold **identity only** | No `is_active`, no roles, no verification state. pizzasys owns all of that and is consulted on every request, so a mirrored copy would go stale and disagree. The local rows exist so `created_by_user_id` can name a human and so shifts can be store-scoped. |
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

   Create a *new* durable with `--deliver=all`. Stores, users and the employee
   roster reach this service only as replayed events — there is no store API and
   no importer — and the default `new` policy leaves the database empty without
   ever reporting an error. `event_inbox` makes the replay idempotent.
2. **Registration in pizzasys.** One seeder does the permissions, the
   `service_clients` row and the `auth_rules` in one idempotent pass:
   ```bash
   php artisan db:seed --class=OperationsServiceSeeder   # in pizzasys
   ```
   It prints the raw token ONCE — that value is `AUTH_SERVER_CALL_TOKEN`. Only
   its sha256 is stored, so re-run with `OPERATIONS_ROTATE_TOKEN=1` to reissue.

   The service identity is **`Operations`**, and that one string has to be
   identical in three places or every request 403s: `service_clients.name`,
   `auth_rules.service`, and our `AUTH_SERVER_SERVICE_NAME`. (Do not confuse it
   with `operations-system`, which is the unrelated `source` label on the events
   we publish and the `service` field of the health payload.)
3. **The employee roster.** Employees hired before HiringPizza's outbox existed
   have no `employee.created` event for the replay to find. Manufacture them
   from HiringPizza:
   ```bash
   php artisan hiring:republish-employees --dry-run   # in HiringPizza
   php artisan hiring:republish-employees
   ```
4. **A Humanity app** (Settings → API v2) and a **Manager/Supervisor service
   account**, in both sandbox and production.
5. **Store ↔ Humanity mappings.** Nothing is matched by name at runtime, so
   until these rows exist every shift write fails with a 422:
   ```bash
   php artisan humanity:sync-catalog                       # fetch the catalog
   php artisan humanity:map-location --list                # see both sides
   php artisan humanity:map-location  --store=03759-00001  # STORE_NOT_MAPPED
   php artisan humanity:map-position  --store=03759-00001 --default
   ```
   Both commands are interactive when run without flags, and refuse ids that
   Humanity doesn't actually have.

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
| `humanity:map-location` | Binds a store to a Humanity location. **Required** — store numbers never match Humanity's location names, so nothing is inferred at runtime. `--list` shows both sides. |
| `humanity:map-position` | Maps a position to a Humanity position for a store. **A `--default` row is mandatory** or every shift create fails `POSITION_NOT_MAPPED`. |
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
