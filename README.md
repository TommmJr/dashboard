# PLN UIT JBT Performance Dashboard

A secure internal web dashboard for the Planning Division of PLN UIT JBT. It turns Excel and CSV performance reports into validated, auditable KPI visualizations for daily monitoring and management review.

## Background

Performance reporting was previously dependent on spreadsheets and external dashboard tools. This application centralizes the import, validation, storage, and visualization workflow in one internal Laravel application while preserving role-based access and an audit trail for every important data change.

## Project Vision

Provide a maintainable internal dashboard that reduces manual reporting work and external-tool dependency. Operational staff can submit data, management can monitor performance, and the IT dashboard editor can manage the presentation layer without receiving write access to source data.

## Current Scope

- Session authentication and granular RBAC for Admin, Management, Dashboard Editor, and Division Staff.
- Excel/CSV dataset import, FATAL/WARNING validation, archiving, and append-only audit logs.
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

   ```bash
   docker compose up -d --build
   docker compose exec app php artisan key:generate
   docker compose exec app php artisan migrate --seed --no-interaction
   npm run build
   ```

4. Harden the database grants for the append-only audit log.

   Docker creates the application database user with full privileges on the
   whole database, which also allows `UPDATE` and `DELETE` on `audit_logs`.
   Run the grant script after `migrate` to restrict that table to
   `SELECT` and `INSERT`:

   ```bash
   docker cp docker/mysql/harden-audit-log.sql plnjbt_db:/tmp/harden.sql
   docker compose exec -T db bash -c \
     "mysql -uroot -pchange_this_in_production < /tmp/harden.sql"
   ```

   Replace the root password with your own `DB_ROOT_PASSWORD` if you set one
   in `.env`. Run the script again whenever a migration adds a new table,
   otherwise the new table has no privileges. The script is safe to run
   repeatedly.

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

# Must succeed:
docker compose exec -T db mysql -udashboard_app -ppassword dashboard \
  -e "SELECT COUNT(*) FROM audit_logs;"
```

## Security and Access

Authorization is enforced on the backend through granular permissions. Dashboard Editors are read-only for data, while Division Staff may change only their own division's data. Audit logs are append-only at both the application and database layers.

## Project Status

The core MVP and dashboard filtering phase are complete. `progress.md` is the source of truth for implementation status; the local `Obsidian/` vault contains supporting requirements and design notes.
