# Financial event ledger API

A small service that takes financial events over HTTP, stores each one once, and writes
the matching pair of ledger rows (a debit and a credit).

`POST /events` checks the payload, saves the event, puts a job in the queue and answers
`202 Accepted` with an empty body. A worker takes the job and writes the two ledger rows
in one database transaction. Two more endpoints read an account's balance and its
transactions.

| | |
|---|---|
| `POST /events` | record an event |
| `GET /accounts/{id}/balance` | debit total, credit total and balance, per currency |
| `GET /accounts/{id}/transactions` | the account's ledger rows, newest first |

The fields, the responses, every status code and an example per error are in
[`openapi.yaml`](openapi.yaml), browsable in Swagger UI at
<http://localhost:8080/docs> once the stack is up - it is served from the same host as
the API, so "Try it out" sends real requests. The docs container is part of the dev
stack only; nothing in the API needs it. Errors come back as RFC 7807 problem documents
(`application/problem+json`).

Stack: PHP 8.4, Symfony 7.4 LTS, PostgreSQL 16, Redis (caches the balance read), Doctrine
ORM + Migrations, Symfony Messenger (the queue lives in Postgres), Docker, PHPUnit,
PHPStan, php-cs-fixer.

## Quick start

Everything runs in Docker. You need Docker with the Compose plugin, and nothing else.

```bash
make up
```

That builds the images and starts six containers: nginx on **:8080**, php-fpm, Postgres,
Redis, a worker that runs the queue, and Swagger UI on **:8080/docs**. Migrations run when
the php container starts, so the API works as soon as it answers.

The last one comes from `compose.override.yaml`, which Compose picks up automatically and
which holds everything that is for development only.

```bash
curl -i -X POST localhost:8080/events \
  -H 'Content-Type: application/json' \
  -d '{"event_id":"evt_1001","type":"payment_received","amount":100.00,"currency":"EUR","timestamp":"2026-01-01T10:00:00Z"}'
# HTTP/1.1 202 Accepted

curl -s localhost:8080/accounts/user_account/balance
# {"account":"user_account","balances":[{"currency":"EUR","debit_total":"100.00","credit_total":"0.00","balance":"100.00"}]}
```

| Target | What it does |
|---|---|
| `make up` | build and start everything |
| `make down` | stop everything |
| `make build` | rebuild the images from scratch |
| `make test` | set up the test database and run PHPUnit |
| `make stan` | PHPStan |
| `make migrate` | run migrations against the dev database |
| `make consume` | run the queue in the foreground, verbose |
| `make shell` | a shell inside the php container |
| `make logs` | follow the php and worker logs |

## Database

Two tables:

- **`processed_events`** - one row per event that came in, with the caller's `event_id` as
  a unique column and a status that goes from `pending` to `posted`.
- **`transactions`** - the ledger, two rows per event (a debit and a credit), insert only.

The columns are in [`migrations/`](migrations). What each event type writes:

| Event type | Debit | Credit |
|---|---|---|
| `payment_received` | `user_account` | `system_cash_account` |
| `payment_sent` | `system_cash_account` | `user_account` |
| `fee_charged` | `user_account` | `fee_account` |

These are the directions the task gives, written exactly as given. They sit in one `match`
in `EventType::accounts()`, so a new event type is one enum case and one arm, and PHPStan
complains if the arm is missing.

## Tests

```bash
make test
```

165 tests, green in about 5 seconds. The target creates the test database, migrates it and
sets up the queue first, so it works on a fresh clone.

The unit tests cover the field checks one by one, the decimal-to-cents conversion, the
debit/credit pair per event type, the money formatting and the paging. The functional
tests go over real HTTP against Postgres (each test runs in a transaction that is rolled
back afterwards) and cover every status code, the same event twice leaving two ledger rows
and not four, the same queue job twice writing no second pair, the numbers the read
endpoints return, and the cache. In tests the queue runs inline and the cache is an array
in memory, so the suite needs no worker and no Redis.

Also there: `make stan` and `vendor/bin/php-cs-fixer fix` for PSR-12.

## Console commands

Tooling, not part of the API:

| Command | What it does |
|---|---|
| `app:post-events <count> --concurrency= --url=` | posts random events, for load testing |
| `app:purge-events <prefix> --force --with-queue` | deletes events whose `event_id` starts with a prefix, and their ledger rows; only counts them without `--force` |

## How it works

```
POST /events -> check the fields -> save the event + queue a job (one transaction) -> 202
                                                   |
                       worker -> write the debit and the credit + mark it posted -> clear the cache
```

The write side is split in two. The request only saves the event and queues a job, so it
stays fast and cannot lose anything: the event row and the job go into the same database
in one transaction, because the queue is a Postgres table too. The worker then writes the
two ledger rows and marks the event posted, again in one transaction. `event_id` is unique
in the database, so the same event sent twice is a `409`, and the worker locks the event
row before posting, so the same job run twice never writes a second pair.

The read side goes straight to the ledger, except for the balance, which is a sum over
every row for the account. That one is cached in Redis and the worker clears it after it
posts. If Redis is down the read simply goes to Postgres.

Amounts are stored as whole cents in a `BIGINT`, never as floats, and converted once when
the request comes in using the number of decimals the currency has. The ledger is insert
only, so nothing in it can be rewritten. Errors are exceptions, and one subscriber turns
them into status codes.
