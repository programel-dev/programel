# Best Practices: SOLID + Clean Code

This rule is your internal code reviewer. Every time you write code, run the result through the principles below. Don't list them to the user — just write code that follows them.

---

## SOLID

Five principles of object-oriented design. They work together: violating one usually leads to violating others.

### S — Single Responsibility Principle

A class (module, function) should have one and only one reason to change. This means one actor, one area of responsibility.

**How to apply:**
- Before writing a class, ask: "Who would request a change to this code?" If there's more than one answer — split it.
- A function does one thing. If you need the word "and" to describe it — break it up.
- Never mix business logic with infrastructure (HTTP, database, filesystem) in the same class.

**Bad:**
```php
class Employee {
    public function calculatePay() { /* accounting */ }
    public function save() { /* database */ }
    public function generateReport() { /* reporting */ }
}
```

**Good:**
```php
class PayCalculator { public function calculate(Employee $e): Money { ... } }
class EmployeeRepository { public function save(Employee $e): void { ... } }
class EmployeeReportGenerator { public function generate(Employee $e): Report { ... } }
```

### O — Open/Closed Principle

Entities are open for extension, closed for modification. New behavior is added without editing existing code.

**How to apply:**
- Use interfaces and abstractions instead of `if/switch` on type.
- Strategies, decorators, factories — these are your tools.
- If adding a new case requires editing an existing class — that's an OCP violation.

### L — Liskov Substitution Principle

Subtypes must be fully substitutable for their base types without changing program correctness.

**How to apply:**
- A subclass must not strengthen preconditions or weaken postconditions.
- Never throw `NotImplementedException` in overridden methods — that's a direct LSP violation.
- If a subclass can't honestly fulfill the parent's contract — use composition over inheritance.

### I — Interface Segregation Principle

Clients should not depend on methods they don't use. Fat interfaces are split into thin ones.

**How to apply:**
- An interface with 7+ methods is a reason to consider splitting.
- Three small interfaces beat one "god interface".
- A class implementing multiple interfaces is normal and correct.

### D — Dependency Inversion Principle

High-level modules don't depend on low-level modules. Both depend on abstractions. Abstractions don't depend on details.

**How to apply:**
- Inject dependencies through the constructor (constructor injection).
- Type-hint dependencies with interfaces, not concrete classes.
- Never instantiate dependencies via `new` inside business logic.

---

## Clean Code (Uncle Bob)

### Naming

- A variable/function/class name must answer: "Why does it exist, what does it do, how is it used?"
- Avoid abbreviations, single-letter variables (except short loops), and misleading names.
- Class name — noun (`UserRepository`, `InvoiceCalculator`). Method name — verb (`calculateTotal`, `sendNotification`).
- Don't encode type in the name (`strName`, `arrItems`) — Hungarian notation is obsolete.
- Name length is proportional to scope: the wider the scope, the more descriptive the name.

### Functions

- Small. Even smaller. The ideal is 5–15 lines.
- One level of abstraction per function. Don't mix high-level logic with implementation details.
- Minimize arguments: 0 (niladic) — ideal, 1 (monadic) — good, 2 (dyadic) — acceptable, 3+ — refactor.
- Don't use flag arguments (`function render(bool $isSuite)`) — it means the function does two things.
- Side effects are forbidden unless obvious from the name. `checkPassword()` must not initialize a session.

### Comments

- Good code explains itself. A comment is an admission of failure in code expressiveness.
- Acceptable comments: legal, intent explanation, consequence warnings, TODO, PHPDoc/JSDoc for public APIs.
- Forbidden: commented-out code (delete it — VCS remembers), noise comments (`// constructor`), section markers (`// ===== SECTION =====`).

### Formatting

- Vertical openness: group related lines, separate concepts with blank lines.
- Vertical density: things related by meaning should be close together.
- Horizontal line length — up to 120 characters.
- Indentation reflects hierarchy; don't collapse blocks into one line for brevity.

### Error Handling

- Prefer exceptions over return codes.
- Write `try-catch-finally` first — it defines the behavioral contract.
- Don't return `null`. Don't pass `null`. Use Null Object, Optional, or throw an exception.
- Create domain exceptions (`InsufficientBalanceException`), don't rethrow bare `\RuntimeException`.

### Classes

- Small. Measured not by lines, but by number of responsibilities (see SRP).
- High cohesion: class methods work with its fields. If some methods use one subset of fields and others use a different subset — that's two classes.
- Low coupling: changing one class should not cascade-break others.

### Tests (F.I.R.S.T. Rule)

- **F**ast — tests are fast, otherwise people stop running them.
- **I**ndependent — no dependency on each other or on execution order.
- **R**epeatable — same result in any environment.
- **S**elf-Validating — result is pass or fail, no manual log reading.
- **T**imely — written before or alongside production code, not "later".

One assert per test. One concept per test.

---

## Checklist Before Delivering Code

Mentally run through each point before showing code to the user:

1. Does every class/function have a single responsibility?
2. Can new behavior be added without modifying existing code?
3. Do subclasses honestly fulfill the parent's contract?
4. Are interfaces thin and specific?
5. Are dependencies injected through abstractions?
6. Are names readable and self-documenting?
7. Are functions short with minimal arguments?
8. Are errors handled with exceptions, not `null`/return codes?
9. No commented-out code or noise comments?
10. Is formatting consistent throughout?

Don't inform the user about the checklist. Just write code that passes it.
