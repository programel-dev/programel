## ADDED Requirements

### Requirement: Symfony API-only skeleton
The system SHALL provide a Symfony project configured exclusively for API use — no Twig, no form components, no session-based auth.

#### Scenario: API health check endpoint
- **WHEN** a GET request is sent to `/api/health`
- **THEN** the system returns HTTP 200 with JSON:
  ```json
  {
    "status": "ok|degraded|error",
    "timestamp": "2026-04-11T12:00:00+00:00",
    "services": {
      "database": "connected|error",
      "redis": "connected|error"
    }
  }
  ```
- **AND** HTTP status is 200 when all services "connected", 503 when any service is "error"

#### Scenario: JSON-only responses
- **WHEN** any API request is made
- **THEN** the response Content-Type is `application/json` (or `application/ld+json` for API Platform endpoints)

### Requirement: URL-prefix API versioning
The system SHALL version all API endpoints using URL prefix format `/api/v{N}/`.

#### Scenario: Versioned endpoint routing
- **WHEN** a request is sent to `/api/v1/users`
- **THEN** the system routes to API Platform resources configured for version 1

#### Scenario: Unversioned API returns 404
- **WHEN** a request is sent to `/api/users` (without version prefix)
- **THEN** the system returns HTTP 404 (only `/api/health` and `/api/docs` are accessible without version)

#### Scenario: Adding a new API version
- **WHEN** a new version v2 is introduced
- **THEN** v1 endpoints continue to function unchanged alongside v2

### Requirement: Database configuration via Doctrine
The system SHALL use Doctrine ORM with PostgreSQL, configured entirely through environment variables.

#### Scenario: Database connection via environment
- **WHEN** the `DATABASE_URL` env var is set to a PostgreSQL connection string
- **THEN** Doctrine connects to that database without any hardcoded credentials in config files

#### Scenario: Migrations executable from container
- **WHEN** `docker compose exec api bin/console doctrine:migrations:migrate` is run
- **THEN** pending migrations are applied to the database

### Requirement: API Platform integration
The system SHALL include API Platform for automatic REST/OpenAPI endpoint generation.

#### Scenario: OpenAPI documentation
- **WHEN** a GET request is sent to `/api/docs`
- **THEN** the system returns Swagger UI with auto-generated OpenAPI specification

### Requirement: Behat integration tests
The system SHALL include Behat with Mink and API extensions for BDD-style integration testing of API endpoints.

#### Scenario: Run Behat tests from container
- **WHEN** `docker compose exec api vendor/bin/behat` is run
- **THEN** Behat executes all `.feature` files against the API with a test database

#### Scenario: Feature file tests API endpoint
- **WHEN** a `.feature` file contains a scenario with HTTP request steps (e.g., `When I send a GET request to "/api/v1/users"`)
- **THEN** Behat sends the request to the Symfony kernel and asserts the response status and JSON body

#### Scenario: Test database isolation
- **WHEN** a Behat scenario runs
- **THEN** the database `programel_test` is used (not the main database) and reset between scenarios via transaction rollback or fixtures reload

### Requirement: JWT authentication
The system SHALL use JWT (JSON Web Tokens) for stateless API authentication via lexik/jwt-authentication-bundle.

#### Scenario: Login returns JWT
- **WHEN** a POST request is sent to `/api/v1/auth/login` with valid email and password
- **THEN** the system returns HTTP 200 with a JSON body containing `token` (access JWT) and `refresh_token`

#### Scenario: Invalid credentials rejected
- **WHEN** a POST request is sent to `/api/v1/auth/login` with invalid credentials
- **THEN** the system returns HTTP 401 with a JSON error message

#### Scenario: Protected endpoint requires JWT
- **WHEN** a request is sent to a protected endpoint without a valid `Authorization: Bearer <token>` header
- **THEN** the system returns HTTP 401

#### Scenario: Public API endpoints accessible without auth
- **WHEN** a request is sent to `/api/health`, `/api/docs`, `/api/v1/auth/login`, or `/api/v1/auth/refresh`
- **THEN** the system returns a response without requiring JWT
- **AND** olcha/lebenslauf subdomains are served as static HTML by Nginx and do not reach the Symfony API

#### Scenario: JWT refresh
- **WHEN** a POST request is sent to `/api/v1/auth/refresh` with a valid refresh token
- **THEN** the system returns a new access JWT without requiring re-login

### Requirement: API rate limiting
The system SHALL enforce rate limiting on API endpoints to prevent abuse.

#### Scenario: Rate limit on authentication
- **WHEN** more than 5 login requests are sent from the same IP within 1 minute
- **THEN** the system returns HTTP 429 Too Many Requests

#### Scenario: Rate limit on API endpoints
- **WHEN** more than 60 requests are sent to `/api/v1/*` from the same authenticated user within 1 minute
- **THEN** the system returns HTTP 429 with `Retry-After` header

#### Scenario: Rate limit headers
- **WHEN** any API request is made
- **THEN** the response includes `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` headers

### Requirement: Database fixtures for development
The system SHALL provide Doctrine fixtures for seeding the development database with sample data.

#### Scenario: Load fixtures in dev
- **WHEN** `docker compose exec api bin/console doctrine:fixtures:load` is run
- **THEN** the database is populated with sample users, and test data sufficient for development

#### Scenario: Fixtures idempotent reload
- **WHEN** fixtures are loaded with `--purge-with-truncate`
- **THEN** existing data is cleared and fixtures are reloaded cleanly

### Requirement: CORS configuration
The system SHALL allow cross-origin requests from known frontend origins, configured per environment.

#### Scenario: CORS preflight for production
- **WHEN** the frontend at `https://programel.com` or `https://test.programel.com` sends an OPTIONS request to the API
- **THEN** the response includes `Access-Control-Allow-Origin` matching the requesting origin

#### Scenario: CORS preflight for development
- **WHEN** the frontend at `https://programel.local` or `http://localhost:3000` sends an OPTIONS request to the API
- **THEN** the response includes `Access-Control-Allow-Origin` matching the requesting origin

#### Scenario: Unknown origin rejected
- **WHEN** an OPTIONS request is sent from an origin not in the whitelist
- **THEN** the response does NOT include `Access-Control-Allow-Origin` header
