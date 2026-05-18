# CLI User Management

## Purpose

Console commands for managing users in the system, including creation of regular and admin users.

## Requirements

### Requirement: Create user via console command
The system SHALL provide a Symfony Console command `app:create-user` that creates a new user in the database with a hashed password.

#### Scenario: Create a regular user
- **WHEN** `bin/console app:create-user user@example.com mypassword` is executed
- **THEN** a new user is created with email `user@example.com`, hashed password, and roles `["ROLE_USER"]`
- **AND** the command outputs a success message with the user's email
- **AND** the command exits with code 0

#### Scenario: Create an admin user
- **WHEN** `bin/console app:create-user admin@example.com mypassword --admin` is executed
- **THEN** a new user is created with email `admin@example.com`, hashed password, and roles `["ROLE_ADMIN", "ROLE_USER"]`
- **AND** the command outputs a success message indicating admin role was assigned
- **AND** the command exits with code 0

#### Scenario: Reject duplicate email
- **WHEN** `bin/console app:create-user existing@example.com mypassword` is executed
- **AND** a user with email `existing@example.com` already exists
- **THEN** the command outputs an error message stating the email is already taken
- **AND** no new user is created
- **AND** the command exits with code 1

#### Scenario: Reject invalid email format
- **WHEN** `bin/console app:create-user not-an-email mypassword` is executed
- **THEN** the command outputs an error message about invalid email format
- **AND** no user is created
- **AND** the command exits with code 1

#### Scenario: Password is hashed before storage
- **WHEN** a user is created via `app:create-user`
- **THEN** the stored password is hashed using Symfony's configured password hasher
- **AND** the plaintext password is never persisted to the database

#### Scenario: Command is available inside Docker container
- **WHEN** `docker compose -f docker-compose.prod.yml exec api bin/console app:create-user` is executed
- **THEN** the command is recognized and executes within the container environment
