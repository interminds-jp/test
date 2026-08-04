# PostgreSQL 18 setup (Ubuntu / Debian)

Reproducible setup for a PostgreSQL 18 cluster intended for team-shared and
production use. The scripts are idempotent, so re-running them is safe and is
the intended way to apply configuration changes.

```bash
git clone <this repo> && cd test
sudo PG_DRY_RUN=yes ./setup-postgres.sh          # review what would change
sudo PG_ALLOW_CIDR="10.0.0.0/24" ./setup-postgres.sh
sudo ./scripts/create-app-db.sh myapp
sudo ./scripts/verify.sh
```

## What the scripts do

| File | Purpose |
| --- | --- |
| `setup-postgres.sh` | Adds the PGDG apt repository, installs PostgreSQL 18, creates the cluster with the right encoding/locale, writes tuning and `pg_hba.conf`. |
| `templates/pg_hba.conf.tmpl` | Host-based auth template. `__ALLOWED_HOSTS__` is replaced with rules built from `PG_ALLOW_CIDR`. |
| `scripts/create-app-db.sh` | Creates an application role, its database, and a matching read-only role. |
| `scripts/verify.sh` | Checks the result and flags the misconfigurations that cause incidents. Exits non-zero on failure, so it works in CI. |

Tuning is written to `/etc/postgresql/18/main/conf.d/10-local.conf` rather than
edited into `postgresql.conf`. That directory is already on the distro's
`include_dir`, so package upgrades leave it alone. Hand-written overrides
belong in a file that sorts after it, such as `20-manual.conf` — the setup
script overwrites `10-local.conf` on every run.

## Configuration

All variables are set in the environment.

| Variable | Default | Notes |
| --- | --- | --- |
| `PG_VERSION` | `18` | Major version. |
| `PG_CLUSTER` | `main` | Debian cluster name. |
| `PG_LISTEN` | `localhost` | Set to an interface address only once the firewall is in place. |
| `PG_PORT` | `5432` | |
| `PG_ALLOW_CIDR` | *(empty)* | Comma-separated networks allowed in over TLS. Empty means local-only. |
| `PG_MAX_CONNECTIONS` | `100` | Beyond ~100, use PgBouncer instead of raising this. |
| `PG_SSL` | `on` | |
| `PG_LOCALE_PROVIDER` | `builtin` | `builtin`, `icu` or `libc`. Requires PostgreSQL 17+. |
| `PG_LOCALE` | `C.UTF-8` | |
| `PG_ENCODING` | `UTF8` | |
| `PG_RECREATE_CLUSTER` | `auto` | `no` keeps an existing cluster even if its locale differs. |
| `PG_FORCE_RECREATE` | `no` | Required to drop a cluster that holds user databases. **Destroys data.** |
| `PG_DRY_RUN` | `no` | Print intended changes and exit without touching anything. |

Memory settings are derived from the host's RAM at run time
(`shared_buffers` 25%, `effective_cache_size` 60%), so the same script gives
sensible values on a laptop and on a database server.

## Decisions worth knowing about

### Why PGDG rather than the distro packages

Ubuntu 24.04 ships PostgreSQL 16. Using PGDG decouples the database's
lifecycle from the distro's: you pick the major version, and you follow
PostgreSQL's own five-year support window rather than the distro's.

### Encoding and locale cannot be changed later

`server_encoding`, `lc_collate` and `lc_ctype` are fixed when the cluster is
created. Changing them means dumping every database, recreating the cluster,
and restoring. **Confirm these before the cluster holds any data.**

The default here is the `builtin` locale provider with `C.UTF-8`, available
from PostgreSQL 17. It matters for a shared setup: with the traditional `libc`
provider, sort order comes from the host's glibc, and a glibc major upgrade
can silently change collation. Text indexes built under the old ordering are
then wrong — lookups miss rows that are present — and the fix is a full
`REINDEX`. Machines in a team drift out of sync at different times, which is
exactly when this is hardest to spot. The builtin provider removes the
dependency entirely.

The tradeoff: `C.UTF-8` sorts by code point, so Japanese text will not sort in
dictionary order. Where that matters, specify it per query or per column:

```sql
SELECT * FROM items ORDER BY name COLLATE "ja-JP-x-icu";
```

This keeps linguistic sorting where it is actually needed, without making
every index in the database depend on the host's locale.

### PostgreSQL 18 specifics

Two changes to watch when moving schemas from 17 or earlier:

- **`GENERATED ... AS` now defaults to virtual columns.** Values are computed
  on read instead of stored. If you need the old behaviour — in particular if
  you index the column — write `STORED` explicitly.
- **New clusters have data checksums enabled by default.** `verify.sh` checks
  this. Enabling it afterwards needs `pg_checksums` with the cluster stopped.

PostgreSQL 18 also introduces asynchronous I/O (`io_method`). The default is
left alone here. If sequential and bitmap scans dominate your workload, try
`io_uring` on Linux — but benchmark it against your own queries rather than
enabling it on trust.

### Authentication

`scram-sha-256` throughout; `md5` is broken and is never written by these
scripts. Note that switching `password_encryption` does **not** re-hash
existing passwords — a role keeps its md5 hash, and keeps working, until its
password is set again. `verify.sh` reports any role still holding one.

Remote rules are `hostssl`, so a client that will not negotiate TLS is
rejected rather than quietly downgraded to plaintext.

### Do not put port 5432 on the internet

`PG_ALLOW_CIDR` is for private networks. For access from outside, use a VPN or
an SSH tunnel:

```bash
ssh -L 5432:localhost:5432 user@db-host
```

If the server must listen on a public interface, firewall it:

```bash
sudo ufw allow from 10.0.0.0/24 to any port 5432 proto tcp
sudo ufw deny 5432
```

## Backups

**`setup-postgres.sh` leaves `archive_command` as a placeholder (`/bin/true`).
Point-in-time recovery does not work until you replace it.** `verify.sh`
reports this as a failure by design — it should stay red until backups are
real.

A logical dump alone is not enough for a shared database: it only restores to
the moment the dump ran, so an incident at 16:00 with a 02:00 dump loses
fourteen hours. Use both layers.

**Daily logical dump** — for migrations and single-table recovery:

```bash
sudo -u postgres pg_dump -Fc myapp -f /var/backups/pg/myapp-$(date +%F).dump
```

**Physical backup with PITR** — for actual disaster recovery. Use pgBackRest
rather than hand-rolling WAL archiving; it handles incremental backups,
retention, and parallel restore:

```bash
sudo apt install -y pgbackrest
```

Then set `archive_command` in `conf.d/20-manual.conf` (not `10-local.conf`,
which is regenerated):

```conf
archive_command = 'pgbackrest --stanza=main archive-push %p'
```

A backup that has never been restored is not a backup. Schedule a restore
into a scratch host monthly and confirm the row counts.

## Verification is not the same as testing

`verify.sh` inspects a running cluster. The setup script has been checked for
shell syntax and exercised in dry-run mode, but the full install path —
reaching `apt.postgresql.org`, installing packages, creating the cluster — has
not been run end to end here, because the environment it was written in blocks
outbound access to `apt.postgresql.org`. Run it on a throwaway host first:

```bash
sudo PG_DRY_RUN=yes ./setup-postgres.sh   # then without the flag
sudo ./scripts/verify.sh
```
