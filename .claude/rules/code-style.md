# PHP Code Style

## PHP 8.2+

- Use modern PHP features: constructor promotion, readonly properties, enums, union/intersection types, match expressions, named arguments, fibers
- Use typed properties — no `@var` PHPDoc for type declaration
- Use `?Type` for nullable parameters and return types
- Trailing commas in function calls and parameter lists are fine

## PHPDoc Policy

- **Do NOT add redundant PHPDoc** when parameters and return type are already type-hinted
- PHPDoc is only needed when: type hints are insufficient (e.g., `array<int, string>`), complex logic needs explanation, or generics are required
- Use `@var` only when the type system can't express it (e.g., template types in collections)

```php
// WRONG — redundant PHPDoc
/**
 * @param int $companyId
 * @return string
 */
public function getCountry(int $companyId): string

// CORRECT — type hints are enough
public function getCountry(int $companyId): string

// CORRECT — PHPDoc adds value (array contents)
/** @return array<int, string> */
public function getNames(): array
```

## Symfony 7.2 Conventions

- **Autowiring + autoconfigure enabled** — services registered automatically from `src/`
- **PHP attributes** for routing, Doctrine mapping, console commands, DI — no YAML service definitions needed
- `#[AsCommand]` for console commands, `#[ORM\Entity]` for entities, `#[Route]` for controllers
- Constructor injection via promoted parameters

## Code Quality Tools

| Tool           | Purpose        | Command                                                            |
|----------------|----------------|--------------------------------------------------------------------|
| PHP-CS-Fixer   | Code styling   | `docker compose -f docker-compose.dev.yml exec api vendor/bin/php-cs-fixer fix` |
| PHPStan        | Static analysis | `docker compose -f docker-compose.dev.yml exec api vendor/bin/phpstan analyse` |
| simple-phpunit | Unit tests     | `docker compose -f docker-compose.dev.yml exec api vendor/bin/simple-phpunit` |

php-cs-fixer uses `@Symfony` ruleset (configured in `api/.php-cs-fixer.dist.php`). Key rules:
- Yoda conditions: `'value' === $var` (not `$var === 'value'`)
- Blank line before return statements
- Space after `fn` in arrow functions: `fn () =>`

## Line Length

- Max 120 characters per line
- Multi-line function signatures: one param per line, closing `)` + return type + `{` on same line

```php
public function example(
    string $param1,
    int $param2
): bool {
```
