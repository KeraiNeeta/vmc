## Emergency Dispatch Management System

Laravel 12 application and REST API foundation for a multi-station fire brigade and ambulance dispatch system. Server-rendered Blade pages provide a live, permission-scoped dashboard and searchable station, ward, vehicle, team, incident, user, and role directories. Phase 2 master-data management and Phase 3 incident intake, caller lookup, duplicate closure, and 112 handoff are implemented. Resource assignment and GPS ingestion remain scheduled work.

### Phase 1 implementation

- Sanctum bearer-token login, current-user, and logout endpoints.
- Seeded system roles and permissions with role/permission middleware.
- PostgreSQL migrations for users, stations, wards, station membership, RBAC, tokens, and audit events.
- Optional initial super-admin creation from private `.env` values. No demo user or default password is created.
- Login throttling (five attempts per minute per IP/email) and eight-hour token expiration by default.
- Server-rendered dashboard and searchable master-data directories use Laravel session authentication and station-level access checks.
- Dashboard summaries and recent-incident listings use live, station-scoped PostgreSQL records and respect the viewer's role permissions.
- Hierarchical user administration assigns roles and stations only within the manager's scope, records account changes, and revokes tokens when an account is disabled.
- Automated auth, RBAC, and station-scope tests.

### Phase 3 implementation

- Register incidents with a generated public job ID, validated caller/location details, an initial call log, and an append-only received-status history entry.
- Search caller records from the last 30 days and surface same-phone incidents from the last 48 hours before new intake; results remain permission- and station-scoped.
- Close only unassigned, newly received incidents as duplicates after confirming the original job has the same caller number. The duplicate link and actor are retained as an external reference and audit event.
- Transfer only unassigned, newly received incidents to 112 with a required external reference number; duplicate and 112 statuses remain distinct.
- Server-rendered incident registration/detail pages and pending, duplicate, and 112 directories.
- Authenticated API endpoints: `GET /api/v1/jobs`, `POST /api/v1/jobs`, `GET /api/v1/callers/search`, `POST /api/v1/jobs/{id}/duplicate`, and `POST /api/v1/jobs/{id}/transfer-112`.

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
resources/views/               Server-rendered Blade pages
public/css/                    Static styles for the Blade pages
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

The migrations establish the identity and authorization tables as well as `vehicles`, `teams`, `team_members`, `emergency_jobs`, `job_call_logs`, `job_assignments`, `job_status_history`, `job_remarks`, `vehicle_gps_logs`, `job_external_references`, and Laravel `notifications`. Phase 2 provides searchable directories and create, update, and safe archive workflows for stations, wards, vehicles, teams, and team membership. All writes are permission-checked, station-scoped, transactional, validated, and audit logged. Records with operational dependencies cannot be archived.

### Setup

Requirements: PHP 8.2+ with `pdo_pgsql`, Composer, and PostgreSQL. The server-rendered pages do not require Node.js/npm.

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

### User hierarchy and local demo records

The user hierarchy is Super Admin → Control Room Admin → Station Admin → Dispatcher/Report Viewer → Vehicle Driver → Team Member. Administrators may create and update lower-level accounts only. Station administrators can manage users, wards, vehicles, and teams only within assigned stations; station records themselves are managed by global administrators. The user directory includes each user's roles, station assignments, sign-in time, effective permissions, and recent audit activity.

For local development, `php artisan migrate --seed` creates the RBAC records and initial administrator (when configured), then seeds sample stations, wards, accounts, vehicles, teams, and emergency jobs. To seed or refresh demo data separately:

```powershell
php artisan db:seed --class=RbacSeeder
php artisan db:seed --class=DemoDispatchSeeder
```

The demo seeder runs only in local/testing environments and is repeatable. It creates sample vehicles, response teams, a team membership, three incidents in received/en-route/transferred states, call logs, an assignment, status history, a remark, a GPS point, a 112 handoff reference, and a notification. All incident and location details are fictional. Demo accounts use `example.test` emails with randomly generated, undisclosed passwords. Sign in as a securely configured super administrator and set individual demo passwords from User management before testing those accounts. Demo account emails are `control.room@example.test`, `central.admin@example.test`, `north.admin@example.test`, `central.dispatch@example.test`, `north.dispatch@example.test`, `vehicle.driver@example.test`, `team.member@example.test`, and `report.viewer@example.test`.

### Authentication API

- `POST /api/v1/auth/login` — accepts `email`, `password`, and optional `device_name`; returns a Sanctum bearer token.
- `GET /api/v1/auth/me` — requires `Authorization: Bearer <token>`; returns the user, role/permission codes, and assigned station IDs.
- `POST /api/v1/auth/logout` — requires a bearer token and revokes that token.

Login input is normalized and validated. Inactive and unknown accounts receive the same authentication error. Add `permission:<code>` after `auth:sanctum` on protected routes; station-scoped queries must also enforce the user's station access.

### Master-data API

All endpoints below require a Sanctum bearer token. Catalogue endpoints support `search`, `page`, and `per_page` (1–50); results include pagination metadata.

- `GET|POST /api/v1/fire-stations`; `PUT|PATCH|DELETE /api/v1/fire-stations/{id}` — station directory and management.
- `GET|POST /api/v1/wards`; `PUT|PATCH|DELETE /api/v1/wards/{id}` — wards and their station assignments.
- `GET|POST /api/v1/vehicles`; `PUT|PATCH|DELETE /api/v1/vehicles/{id}` — vehicle details, station, status, GPS identity, and service date.
- `GET|POST /api/v1/teams`; `PUT|PATCH|DELETE /api/v1/teams/{id}` — team details, station, leader, members, and availability.

Read and write permissions are separate (`stations.view/manage`, `wards.view/manage`, `vehicles.view/manage`, `teams.view/manage`). Control Room Admins can manage master data across stations; Fire Station Admins can manage wards, vehicles, and teams within assigned stations. Only Super Admins and Control Room Admins can create stations. Vehicle/team leaders and members must be active users of that station. Deletes are soft archives and return validation errors when operational history references the record; change its status instead.

### Verification

```powershell
php artisan test
vendor\bin\pint.bat --test
```

Tests use SQLite in memory. Configure PostgreSQL credentials before running PostgreSQL migrations locally.

### Development phases

1. **Foundation (implemented):** inspect/scaffold, PostgreSQL configuration, API auth, RBAC, station/ward schema.
2. **Master data (implemented):** searchable station, ward, vehicle, and team directories; station-scoped create/update/archive forms and APIs; team membership; role-based permissions; audit logging.
3. **Incident intake (implemented):** incident registration, caller search, duplicate detection, and 112 handoff workflows.
4. Multi-vehicle assignment, status transitions, timeline, and history.
5. Authenticated GPS ingestion, tracking map, and route history.
6. Expanded operational reporting and analytics beyond the current live station/ward dashboard.
7. Archive, reports, and CSV/Excel/PDF exports.
8. Security, API, performance, and deployment verification.
