# plnjbt-dashboard

A web application for managing and visualizing performance data for the
Planning Directorate at PLN UIT JBT - a replacement for Looker Studio where
both the data and the dashboard features can be managed by the staff
themselves, without depending on a developer.

## Features

- Upload Excel/CSV report files with automatic validation
- Dashboards that can be built, edited, and removed directly in the app
- Visualizations: KPI cards, bar charts, line charts, and tables
- Date and unit filters that apply across all widgets
- Audit trail for every data change

## Requirements

- PHP >= 8.2
- Composer
- Node.js and npm
- MySQL 8

## Installation

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Configure your MySQL credentials in the `.env` file, then run:

```bash
php artisan migrate
npm run dev
php artisan serve
```

The application is available at `http://localhost:8000`.
