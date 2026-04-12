## ADDED Requirements

### Requirement: Next.js application with SSR and TypeScript
The system SHALL provide a Next.js application with server-side rendering, App Router, and TypeScript strict mode.

#### Scenario: Development server startup
- **WHEN** the frontend container starts in dev mode
- **THEN** Next.js dev server runs on port 3000 with Fast Refresh (HMR) enabled

#### Scenario: Production build
- **WHEN** `npm run build` is executed
- **THEN** Next.js produces an optimized standalone server build in `.next/standalone/`

#### Scenario: SSR page rendering
- **WHEN** a user requests a page for the first time
- **THEN** the Next.js server renders the full HTML on the server and sends it to the client with hydration scripts

### Requirement: API communication via server-side and client-side
The system SHALL support calling the Symfony API from both server components (SSR) and client components (browser).

#### Scenario: Server-side API call
- **WHEN** a Next.js Server Component fetches data from the API
- **THEN** the request goes directly to the Symfony container via Docker network (internal URL, e.g., `http://api:9000`)

#### Scenario: Client-side API call
- **WHEN** a Next.js Client Component fetches data from the API in the browser
- **THEN** the request goes to `/api/v1/*` which Nginx proxies to Symfony

### Requirement: Authentication flow
The system SHALL protect all pages behind login, redirecting unauthenticated users.

#### Scenario: Unauthenticated user redirected to login
- **WHEN** an unauthenticated user accesses any page on programel.com
- **THEN** the user is redirected to the login page

#### Scenario: Authenticated user accesses protected pages
- **WHEN** a user has a valid JWT stored in an httpOnly Secure cookie
- **THEN** the user can access all pages normally

#### Scenario: JWT refresh on expiry
- **WHEN** the access token expires during a session
- **THEN** the frontend automatically refreshes the token using the refresh token without interrupting the user

### Requirement: API error handling
The system SHALL handle API errors gracefully without crashing the application.

#### Scenario: API returns 4xx error
- **WHEN** the API returns a 4xx error (e.g., 400 Bad Request, 404 Not Found)
- **THEN** the frontend displays a user-friendly error message relevant to the context

#### Scenario: API returns 5xx error
- **WHEN** the API returns a 5xx error (e.g., 500 Internal Server Error)
- **THEN** the frontend displays a generic "something went wrong" message and reports the error to Sentry

#### Scenario: Network timeout
- **WHEN** an API request times out or the network is unavailable
- **THEN** the frontend displays a "connection error" message with a retry option

#### Scenario: Refresh token expired
- **WHEN** both the access token and refresh token are expired
- **THEN** the frontend clears auth cookies and redirects to the login page

### Requirement: Environment-based configuration
The system SHALL resolve API URLs and other settings from environment variables at runtime (not build time).

#### Scenario: Runtime API URL for SSR
- **WHEN** `API_INTERNAL_URL=http://api:9000` is set
- **THEN** server components use this URL for direct container-to-container API calls

#### Scenario: Public API URL for client
- **WHEN** `NEXT_PUBLIC_API_URL=/api/v1` is set
- **THEN** client components use this URL for browser-side API calls
