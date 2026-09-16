# PLN UIT JBT Performance Dashboard

A secure internal web dashboard for the Planning Division of PLN UIT JBT. It turns Excel and CSV performance reports into validated, auditable KPI visualizations for daily monitoring and management review.

## Background

Performance reporting was previously dependent on spreadsheets and external dashboard tools. This application centralizes the import, validation, storage, and visualization workflow in one internal Laravel application while preserving role-based access and an audit trail for every important data change.

## Project Vision

Provide a maintainable internal dashboard that reduces manual reporting work and external-tool dependency. Operational staff can submit data, management can monitor performance, and the IT dashboard editor can manage the presentation layer without receiving write access to source data.

## Current Scope

- Session authentication and granular RBAC for Admin, Management, Dashboard Editor, and Division Staff.
- Excel/CSV dataset import, FATAL/WARNING validation, archiving, and append-only audit logs.
- One-click hierarchical KPI report import. Valid unit sheets are stored under the canonical `TJBT` data owner.
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

2. Create the environment file and configure the Docker database connection.

   ```bash
   cp .env.example .env
   ```

   Set these values in `.env`:

   ```dotenv
   DB_CONNECTION=mysql
   DB_HOST=db
   DB_PORT=3306
   DB_DATABASE=dashboard
   DB_USERNAME=dashboard_app
   DB_PASSWORD=password
   ```

3. Start the application and database containers, then initialize the application.

   ```bash
   docker compose up -d --build
   docker compose exec app php artisan key:generate
   docker compose exec app php artisan migrate --seed --no-interaction
   npm run build
   ```

4. Open [http://localhost:8000](http://localhost:8000).

## Testing

```bash
docker compose exec app php artisan test --compact
```

## Security and Access

Authorization is enforced on the backend through granular permissions. Dashboard Editors are read-only for data, while Division Staff may change only their own division's data. Audit logs are append-only at both the application and database layers.

## Project Status

The core MVP and dashboard filtering phase are complete. `progress.md` is the source of truth for implementation status; the local `Obsidian/` vault contains supporting requirements and design notes.
