## Emergency Dispatch Management System

Laravel 12 REST API foundation for a multi-station fire brigade and ambulance dispatch system. This repository started empty; Phase 1 is implemented. Vehicle, team, incident, dispatch, GPS, dashboard, and reporting features remain scheduled work and are not represented as complete.

### Phase 1 implementation

- Sanctum bearer-token login, current-user, and logout endpoints.
- Seeded system roles and permissions with role/permission middleware.
- PostgreSQL migrations for users, stations, wards, station membership, RBAC, tokens, and audit events.
- Optional initial super-admin creation from private `.env` values. No demo user or default password is created.
- Login throttling (five attempts per minute per IP/email) and eight-hour token expiration by default.
- Automated auth, RBAC, and station-scope tests.

### Recommended project structure

```text
app/
  Http/Controllers/Api/V1/   Versioned REST endpoints
  Http/Middleware/           Authentication and permission checks
  Http/Requests/             Request validation
  Http/Resources/            Stable API response shapes
  Models/                    Eloquent entities and relationships
  Policies/                  Resource and station authorization
  Services/                  Transactions and business workflows
database/
  factories/
  migrations/
  seeders/
resources/js/                 React application (planned)
routes/api.php                API routes
tests/Feature/                API and workflow tests
tests/Unit/                   Isolated domain tests
```

### PostgreSQL ER design

Application identifiers use `BIGINT`; dispatch-domain date/time values use `TIMESTAMPTZ`. Foreign keys are explicit, operational data uses soft deletes where appropriate, and job status changes are designed as immutable history rows.

| Table | Core columns | Relationships / constraints |
|---|---|---|
| `users` | `id`, `name`, normalized unique `email`, `password`, `is_active`, `last_login_at`, timestamps, `deleted_at` | Login identities; roles and station access are many-to-many. |
| `roles` | `id`, unique `code`, `name`, `description`, `is_system`, timestamps, `deleted_at` | Seven seeded system roles; many-to-many permissions and users. |
| `permissions` | `id`, unique `code`, `name`, `description`, timestamps | Many-to-many roles through `role_permissions`. |
| `role_permissions` | `role_id`, `permission_id`, timestamps | Composite primary key; both FKs cascade on role/permission removal. |
| `user_roles` | `user_id`, `role_id`, nullable `assigned_by`, timestamps | Composite primary key; assignment actor references `users`. |
| `fire_stations` | `id`, unique `code`, `name`, `address`, `contact_number`, nullable `admin_user_id`, coordinates, `is_active`, timestamps, `deleted_at` | Admin references `users`; one-to-many vehicles/teams; many-to-many wards and station-scoped users. |
| `wards` | `id`, unique `code`, `name`, `description`, `is_active`, timestamps, `deleted_at` | Many-to-many fire stations; referenced by incidents. |
| `station_wards` | `fire_station_id`, `ward_id`, timestamps | Composite primary key maps station coverage. |
| `user_fire_stations` | `user_id`, `fire_station_id`, nullable `assigned_by`, timestamps | Composite primary key limits user access to assigned stations (or a station they administer). |
| `vehicles` | `id`, `fire_station_id`, unique `vehicle_number`, type, registration/GPS identifiers, coordinates, availability/maintenance status, timestamps, `deleted_at` | Belongs to a station; referenced by assignments and GPS logs. |
| `teams` | `id`, `fire_station_id`, `leader_user_id`, `name`, contact, availability, timestamps, `deleted_at` | Belongs to a station; members are users through `team_members`. |
| `team_members` | `team_id`, `user_id`, qualification/designation, membership dates, timestamps | Composite primary key records team membership. |
| `emergency_jobs` | `id`, unique public `job_id`, caller details, call source, incident type/priority/time, `ward_id`, address/coordinates, remarks, status, `created_by`, timestamps, `deleted_at` | One-to-many call logs, assignments, history, remarks, and external references. |
| `job_call_logs` | `id`, `job_id`, caller/source details, `received_at`, `created_by` | Each call/report belongs to one job. |
| `job_assignments` | `id`, `job_id`, `fire_station_id`, `vehicle_id`, nullable `team_id`/leader, `assigned_by`, `assigned_at`, status, remarks, cancellation reason, timestamps | Each deployment belongs to one job and vehicle; team/station FKs capture the responding unit. |
| `job_status_history` | `id`, `job_id`, nullable `assignment_id`, previous/new status, actor, `changed_at`, remarks, optional coordinates | Append-only status events; assignment FK is null for job-level transitions. |
| `job_remarks` | `id`, `job_id`, `user_id`, body, timestamps | Each remark belongs to a job and author. |
| `vehicle_gps_logs` | `id`, `vehicle_id`, nullable `assignment_id`, latitude/longitude, optional speed, `recorded_at` | Append-only GPS samples; index vehicle and recorded time. |
| `job_external_references` | `id`, `job_id`, reference type/number, transfer/confirmation details, actor, timestamps | Separate rows distinguish 112 transfer from duplicate closure. |
| `notifications` | Laravel notification ID, `notifiable_type/id`, type, JSON data, `read_at`, `created_at` | Polymorphic recipient; retain Laravel's UUID notification ID. |
| `audit_logs` | `id`, nullable actor, event, subject type/id, IP/user agent, JSON metadata, `created_at` | Append-only event log; actor FK becomes null when a user is removed. |

Application primary keys and foreign keys use `BIGINT`; the Laravel notification table retains its framework UUID ID. Dispatch-domain date/time values use `TIMESTAMPTZ`, and status values use constrained domain codes. `emergency_jobs` has one-to-many call logs, assignments, status history, remarks, and external references. `job_assignments` is the only job-to-vehicle/team association; vehicle availability must be locked and checked transactionally when dispatching so a vehicle cannot be double-booked. Duplicate closure and transfer to 112 remain distinct job statuses. Station membership and authorization are separate checks: a permission does not grant access to another station's records.

The Phase 1 migrations establish `users`, `roles`, `permissions`, `role_permissions`, `user_roles`, `fire_stations`, `wards`, `station_wards`, `user_fire_stations`, `personal_access_tokens`, and `audit_logs`. Vehicle/job tables are intentionally deferred to their implementation phases.

### Setup

Requirements: PHP 8.2+ with `pdo_pgsql`, Composer, Node.js/npm for the planned React client, and PostgreSQL.

1. Copy `.env.example` to `.env` and set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` for a PostgreSQL database you created.
2. Set `INITIAL_SUPER_ADMIN_EMAIL` and a private `INITIAL_SUPER_ADMIN_PASSWORD` of at least 14 characters before seeding if an initial administrator is needed.
3. Run:

   ```powershell
   composer install
   php artisan key:generate
   php artisan migrate --seed
   ```

4. Start the API with `php artisan serve`. Routes are listed by `php artisan route:list --path=api`.

Do not commit `.env` or use a default/shared administrator password. If no initial admin credentials are configured, seeding creates the system roles and prints a warning without creating a login.

### Authentication API

- `POST /api/v1/auth/login` — accepts `email`, `password`, and optional `device_name`; returns a Sanctum bearer token.
- `GET /api/v1/auth/me` — requires `Authorization: Bearer <token>`; returns the user, role/permission codes, and assigned station IDs.
- `POST /api/v1/auth/logout` — requires a bearer token and revokes that token.

Login input is normalized and validated. Inactive and unknown accounts receive the same authentication error. Add `permission:<code>` after `auth:sanctum` on protected routes; station-scoped queries must also enforce the user's station access.

### Verification

```powershell
php artisan test
vendor\bin\pint.bat --test
```

Tests use SQLite in memory. Configure PostgreSQL credentials before running PostgreSQL migrations locally.

### Development phases

1. **Foundation (implemented):** inspect/scaffold, PostgreSQL configuration, API auth, RBAC, station/ward schema.
2. Station, ward, vehicle, and team management APIs and screens.
3. Incident registration, caller search, duplicate detection, and 112 workflows.
4. Multi-vehicle assignment, status transitions, timeline, and history.
5. Authenticated GPS ingestion, tracking map, and route history.
6. Live dashboard, station/ward metrics, and analytics.
7. Archive, reports, and CSV/Excel/PDF exports.
8. Security, API, performance, and deployment verification.
