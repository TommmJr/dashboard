# PLN UIT JBT Performance Dashboard

A secure internal web dashboard for the Planning Division of PLN UIT JBT. It turns Excel and CSV performance reports into validated, auditable KPI visualizations for daily monitoring and management review.

## Background

Performance reporting was previously dependent on spreadsheets and external dashboard tools. This application centralizes the import, validation, storage, and visualization workflow in one internal Laravel application while preserving role-based access and an audit trail for every important data change.

## Project Vision

Provide a maintainable internal dashboard that reduces manual reporting work and external-tool dependency. Operational staff can submit data, management can monitor performance, and the IT dashboard editor can manage the presentation layer without receiving write access to source data.

## Current Scope

- Session authentication and granular RBAC for Admin, Management, Dashboard Editor, and Division Staff.
- Excel/CSV dataset import, FATAL/WARNING validation, archiving, and an audit log whose row data is append-only at the database level (see the grant limits below).
- One-click hierarchical KPI report import. Valid unit sheets are stored under the canonical `Perencanaan Pengusahaan` data owner (`perencanaan-pengusahaan`).
- KPI/NKO dashboard charts and rankings powered by Apache ECharts.
- Dashboard filters for date ranges and multiple units through `start_date`, `end_date`, and `unit[]`.
- User and division administration, security headers, and login rate limiting.

## Current Limitations

- The dashboard uses a Phase 1 role-based layout from `config/dashboard_layouts.php`.
- The metadata-driven dashboard builder and per-dashboard persistent layouts are planned for Phase 2, pending additional source-data samples.
- The Reports module is currently disabled; its `/reports/*` routes are intentionally commented out.

## Tech Stack

- Laravel 13 and PHP 8.3+
- Blade, Alpine.js 3, Tailwind CSS 4, and Vite
- MySQL 8.4
- Apache ECharts
- PhpSpreadsheet 5.9
- Docker Compose

## Prerequisites

- Git
- PHP 8.3+ and Composer
- Node.js and npm
- Docker Desktop (recommended for the local MySQL and Apache environment)

## Getting Started

1. Clone the repository and install dependencies.

   ```bash
   git clone https://github.com/TommmJr/dashboard.git
   cd dashboard
   composer install
   npm install
   ```

2. Create the environment file.

   ```bash
   cp .env.example .env
   ```

   Configure `.env` with the application and database values supplied by your local setup or deployment administrator. Do not commit this file.

3. Start the application and database containers, then initialize the application.

   On a **fresh** database volume, `docker/mysql/init/01-create-migrator.sh`
   runs automatically and creates the migration user. Migrations then run with
   that user, not the application user:

   ```bash
   docker compose up -d --build
   docker compose exec app php artisan key:generate
   docker compose exec -e DB_USERNAME="$DB_MIGRATOR_USERNAME" \
     -e DB_PASSWORD="$DB_MIGRATOR_PASSWORD" app php artisan migrate --seed --no-interaction
   npm run build
   ```

   If the database volume already existed before this script was added, create
   the migration user manually:

   ```bash
   docker compose exec -T db bash /docker-entrypoint-initdb.d/01-create-migrator.sh
   ```

4. Harden the database grants for the audit log.

   Docker grants the application user full privileges on the whole database,
   including DDL. That would allow `UPDATE`, `DELETE`, `TRUNCATE`, `ALTER`, and
   `DROP` on `audit_logs`, so the audit trail could be destroyed with runtime
   credentials. Run the grant script after `migrate`:

   ```bash
   docker cp docker/mysql/harden-audit-log.sql plnjbt_db:/tmp/harden.sql
   docker compose exec -T db bash -c \
     "mysql -uroot -pchange_this_in_production < /tmp/harden.sql"
   ```

   Replace the root password with your own `DB_ROOT_PASSWORD` if you set one
   in `.env`. Run the script again whenever a migration adds a new table,
   otherwise the new table has no privileges. The script is safe to run
   repeatedly.

   **Two users, two roles.** The script gives the application user DML only,
   with no DDL at all:

   | User | Rights | Used by |
   | --- | --- | --- |
   | `dashboard_migrator` | Full privileges on the database, including DDL | `php artisan migrate` only |
   | `dashboard_app` | DML per table; `audit_logs` limited to `SELECT` and `INSERT` | The running application |

   With no DDL, `TRUNCATE`, `ALTER TABLE`, and `DROP TABLE` on `audit_logs`
   are all rejected for the application user, so the audit trail can no longer
   be destroyed from runtime credentials. The CI job `db-grants` asserts this,
   and also asserts that `migrate:fresh` fails when run with the application
   user.

5. Open [http://localhost:8000](http://localhost:8000).

## Testing

```bash
docker compose exec app php artisan test --compact
```

Note: the test suite runs against an in-memory SQLite database, so it does
not exercise MySQL grants. Verify the audit log hardening manually with the
checks below.

```bash
# Must fail with ERROR 1142 (command denied):
docker compose exec -T db mysql -udashboard_app -ppassword dashboard \
  -e "UPDATE audit_logs SET action='x' WHERE id=1;"

# Must also fail (no DDL for the application user):
docker compose exec -T db mysql -udashboard_app -ppassword dashboard \
  -e "TRUNCATE TABLE audit_logs;"

# Must succeed:
docker compose exec -T db mysql -udashboard_app -ppassword dashboard \
  -e "SELECT COUNT(*) FROM audit_logs;"
```

## Deployment

The steps below follow the container setup from Getting Started, run with the
environment values of the deployed instance. Run them in this order.

1. Build and start the containers.

   ```bash
   docker compose up -d --build
   ```

2. Generate the application key once per environment.

   ```bash
   docker compose exec app php artisan key:generate
   ```

3. Run migrations with the migration user, not the application user.

   ```bash
   docker compose exec \
     -e DB_USERNAME="$DB_MIGRATOR_USERNAME" \
     -e DB_PASSWORD="$DB_MIGRATOR_PASSWORD" \
     app php artisan migrate --no-interaction
   ```

4. Harden the audit log grants. Run this after `migrate`, and again after
   every migration that adds a table: a new table holds no privileges until
   the script runs again.

   ```bash
   docker cp docker/mysql/harden-audit-log.sql plnjbt_db:/tmp/harden.sql
   docker compose exec -T db bash -c \
     "mysql -uroot -pchange_this_in_production < /tmp/harden.sql"
   ```

   Replace `change_this_in_production` with the value of `DB_ROOT_PASSWORD`.

5. Build the frontend assets.

   ```bash
   npm run build
   ```

Required `.env` values for a deployed environment:

| Variable | Value | Purpose |
| --- | --- | --- |
| `APP_DEBUG` | `false` | Do not expose stack traces or environment details in responses. |
| `LOG_LEVEL` | `error` | Keep request-level log noise out of the deployed log. |
| `DB_MIGRATOR_USERNAME` | migration user name | Used only for `php artisan migrate`, so the application user keeps no DDL rights. |
| `DB_MIGRATOR_PASSWORD` | migration user password | Same reason as above. |

Note on existing database volumes: `docker/mysql/init/01-create-migrator.sh`
runs only when the MySQL data directory is empty, that is, on a fresh volume.
On an existing volume it does not run again, so create the migration user
manually before step 3:

```bash
docker compose exec -T db bash /docker-entrypoint-initdb.d/01-create-migrator.sh
```

Scope note: this section covers application and database setup only.
Production data validation and permanent widget mounting remain pending
mentor/PIC confirmation.

## Security and Access

Authorization is enforced on the backend through granular permissions. Dashboard Editors are read-only for data, while Division Staff may change only their own division's data.

Audit log protection, layer by layer:
- Row changes through the model: blocked. Single records throw `LogicException` on `update()`/`delete()`, and every bulk path (`update`, `upsert`, `updateFrom`, `touch`, `increment`, `decrement`, `incrementEach`, `decrementEach`, `delete`, `forceDelete`) is rejected by `App\Models\Builders\AuditLogBuilder`. Adding rows is still allowed: append-only means rows may be appended, not changed.
- Row data at the database level: blocked. The hardened grants limit the application user to `SELECT` and `INSERT` on `audit_logs`.
- Table structure: blocked. The application user has no DDL rights, so `TRUNCATE`, `ALTER TABLE`, and `DROP TABLE` on `audit_logs` are rejected. Migration runs as `dashboard_migrator`, a separate user that holds the DDL rights.
- Deleting a user is safe: `audit_logs.user_id` becomes `NULL` and the audit rows remain.

The CI job `db-grants` asserts all of the above, plus that `migrate:fresh` fails when attempted with the application user. Note that the PHPUnit suite runs on in-memory SQLite and therefore cannot cover any of these MySQL grant rules.