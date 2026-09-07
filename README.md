# IBS - Intelligent Business Intelligence System (PLN UIT JBT)

An internal BI platform designed for the Planning Division of PLN UIT JBT, focusing on transforming raw performance data from spreadsheets into dynamic, configurable, and secure dashboards.

## Project Vision
The goal of IBS is to eliminate dependency on external tools  and manual data cleaning, providing a sustainable, self-service dashboard system that can be managed by internal IT staff without requiring deep coding knowledge.

### Core Pillars:
- **Data Management**: Automated import, validation, and cleaning of Excel/CSV datasets.
- **Business Intelligence**: Metadata-driven dashboard builder with configurable widgets.
- **AI Assistance (Phase 2)**: AI-powered chart recommendations and NL (Natural Language) queries.
- **Cybersecurity**: Granular RBAC, secure API access, and append-only audit logs.

---

## Tech Stack
- **Backend**: Laravel 13
- **Frontend**: Tailwind CSS 4 + Alpine.js
- **Database**: MySQL 8.4
- **Infrastructure**: Docker (Containerized Environment)

---

## Getting Started (Tutorial)

This project is containerized using Docker. You don't need to install PHP or MySQL on your local machine.

### Prerequisites
- [Docker Desktop](https://www.docker.com/products/docker-desktop/) installed and running.

### Installation Steps

1. **Clone the repository**
   ```bash
   git clone https://github.com/TommmJr/dashboard.git
   cd dashboard
   ```

2. **Environment Setup**
   Copy the environment example file:
   ```bash
   cp .env.example .env
   ```
   Update `.env` with your local settings (the Docker defaults are already provided in `docker-compose.yml`).

3. **Spin up the Infrastructure**
   Run the containers in detached mode:
   ```bash
   docker-compose up -d --build
   ```

4. **Initialize the Application**
   Run migrations and seed the initial data (including RBAC roles and Admin user):
   ```bash
   docker exec -it plnjbt_app php artisan migrate --seed
   ```

5. **Access the App**
   Open your browser and go to: [http://localhost:8000](http://localhost:8000)


## Security Note
Authorization is strictly enforced at the backend level using Laravel Gates and Middleware. Frontend visibility is used for UX convenience, but the API and routes are protected by granular permissions.
