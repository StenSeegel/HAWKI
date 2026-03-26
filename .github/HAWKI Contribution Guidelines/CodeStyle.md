# Code Style & Standards

This document defines the coding standards and best practices for HAWKI. Following these guidelines ensures consistency, maintainability, and code quality across the project.

---

## Table of Contents

1. [Modern PHP & Laravel Practices](#modern-php--laravel-practices)
2. [Type Declarations](#type-declarations)
3. [Configuration Best Practices](#configuration-best-practices)
4. [Documentation Standards](#documentation-standards)
5. [Code Quality Checklist](#code-quality-checklist)

---

## Modern PHP & Laravel Practices

### Strict Types Declaration

**MANDATORY:** Every PHP file must include `declare(strict_types=1);` at the top, immediately after the opening `<?php` tag.

```php
<?php

declare(strict_types=1);

namespace App\Services;

class UserService
{
    // ...
}
```

**Why?**
- Enforces type safety at runtime
- Catches type-related bugs early
- Makes code more predictable and reliable

### Dependency Injection Over Facades

**Prefer Constructor Injection over Facades** in services, repositories, and business logic classes.

```php
// Bad - Using Facades (hides dependencies)
class UserService
{
    public function createUser(array $data): User
    {
        $user = User::create($data);
        Mail::to($user)->send(new WelcomeEmail($user));

        return $user;
    }
}

// Good - Constructor Injection (explicit dependencies)
class UserService
{
    public function __construct(
        private UserRepository $userRepository,
        private MailService $mailService
    ) {}

    public function createUser(array $data): User
    {
        $user = $this->userRepository->create($data);
        $this->mailService->sendWelcomeEmail($user);

        return $user;
    }
}
```

**Why?**
- Makes dependencies explicit and visible
- Improves testability (easy to mock dependencies)
- Better IDE support and type checking

**When Facades Are Acceptable:**
- In controllers (for brevity)
- In blade templates
- In simple utility scripts

### Avoid Config Helper in Classes

**Bad:**
```php
class PaymentService
{
    public function process(): void
    {
        $apiKey = config('payment.api_key'); // Hidden dependency
        // ...
    }
}
```

**Good:**
```php
class PaymentService
{
    public function __construct(
        private string $apiKey
    ) {}

    // In ServiceProvider:
    // $this->app->singleton(PaymentService::class, fn() =>
    //     new PaymentService(config('payment.api_key'))
    // );
}
```

**Why?**
- Dependencies are explicit
- Easier to test (inject test values)
- Better encapsulation

---

## Type Declarations

**ALWAYS use type declarations** for parameters and return types in all functions and methods.

### Basic Types

```php
// Bad - No type declarations
public function calculateTotal($quantity, $price)
{
    return $quantity * $price;
}

// Good - Full type declarations
public function calculateTotal(int $quantity, float $price): float
{
    return $quantity * $price;
}
```

### Class Types

```php
public function saveUser(User $user): void
{
    $this->repository->save($user);
}
```

### Nullable Types

```php
public function findUser(int $id): ?User
{
    return $this->repository->find($id);
}

public function updateEmail(?string $email = null): void
{
    // ...
}
```

### Union Types (PHP 8.0+)

```php
public function getId(): int|string
{
    return $this->id;
}
```

### Mixed Type (Avoid When Possible)

```php
// Only use when truly necessary
public function process(mixed $data): mixed
{
    // ...
}
```

**Best Practice:** Be as specific as possible with types. Use `mixed` only when no other type fits.

---

[//]: # (TODO: What did martin mean with Attributes over Config Helper: Instead of using the config'key' helper inside classes which hides dependencies, encourage dependency injection.)
## Configuration Best Practices

### Use Config Files, Not ENV Directly

**Never** use `env()` directly in application code. Always access configuration through config files.

**Bad:**
```php
$apiKey = env('API_KEY');
$timeout = env('API_TIMEOUT', 30);
```

**Good:**
```php
// In config/api.php
return [
    'key' => env('API_KEY'),
    'timeout' => env('API_TIMEOUT', 30),
];

// In your code
$apiKey = config('api.key');
$timeout = config('api.timeout');
```

**Why?**
- Config values are cached in production (`php artisan config:cache`)
- `env()` returns `null` when config is cached
- Better organization and documentation
- Single source of truth for configuration

---

## Documentation Standards

### The Modern Approach: Don't Duplicate Type Information

In PHP 8+, with full type declarations, **redundant DocBlocks are considered noise** that drifts out of sync.

#### When NOT to Write DocBlocks

If a method is **fully typed** with clear parameter and return types, DocBlocks are **optional**:

```php
// ✅ No DocBlock needed - types are clear
public function verify(User $user): bool
{
    return $user->isVerified();
}

public function createPost(string $title, string $content, User $author): Post
{
    return Post::create([
        'title' => $title,
        'content' => $content,
        'author_id' => $author->id,
    ]);
}
```

Adding `@param User $user` or `@return bool` here is redundant and adds maintenance burden.

#### When DocBlocks ARE Required

**1. Complicated Types (Arrays with Specific Shapes)**

```php
/**
 * Calculate order totals including taxes and discounts.
 *
 * @param array{items: array, discount: float, tax_rate: float} $orderData
 * @return array{subtotal: float, tax: float, total: float}
 */
public function calculateOrderTotal(array $orderData): array
{
    // ...
}
```

**2. Business Logic Explanation**

When the **intent** or **context** isn't obvious from the code:

```php
/**
 * Processes refunds only for orders older than 30 days.
 * Newer orders are handled by the instant refund service.
 */
public function processDelayedRefund(Order $order): void
{
    // ...
}
```

**3. Non-Obvious Behavior or Side Effects**

```php
/**
 * Sends notification and logs the event.
 * Note: This method commits the transaction automatically.
 */
public function completeCheckout(Order $order): void
{
    // ...
}
```

**4. Complex Return Values**

```php
/**
 * @return Collection<int, User> Collection of active users sorted by registration date
 */
public function getActiveUsers(): Collection
{
    // ...
}
```

### Inline Comments: Use Standard Syntax

**Inside function bodies**, use standard comments (`//` or `/* */`), **NOT** DocBlocks (`/** */`).

```php
// Bad - DocBlock style inside function
public function process(): void
{
    /** Validate the input */
    if (!$this->isValid()) {
        return;
    }
}

// Good - Standard comment
public function process(): void
{
    // Validate the input
    if (!$this->isValid()) {
        return;
    }
}
```

### What NOT to Comment

Avoid comments that:
- Repeat what the code already says
- Explain trivial operations
- Serve as temporary debugging notes

```php
// Bad - Obvious comment
// Increment counter by one
$counter++;

// Bad - Repeating the code
// Get the user by ID
$user = $this->userRepository->find($id);

// Good - Explains WHY, not WHAT
// Counter is used for retry limit enforcement across distributed workers
$this->incrementRetryCounter();
```

### High-Level Documentation

Prefer:
- High-level summaries over inline comments
- Intent-focused documentation over implementation details
- Extracting complex logic into well-named methods

```php
// Instead of this:
public function process(): void
{
    // First, validate the user's email
    if (!filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidEmailException();
    }

    // Then, check if they're verified
    if (!$user->isVerified()) {
        throw new UnverifiedUserException();
    }

    // Finally, send the notification
    Mail::to($user)->send(new Notification());
}

// Do this:
public function process(): void
{
    $this->validateUserEmail($user);
    $this->ensureUserIsVerified($user);
    $this->sendNotification($user);
}
```

### Summary: Documentation Rules

| Scenario | Documentation Required? |
|----------|------------------------|
| Fully typed method with obvious intent | ❌ No |
| Array parameters/returns with specific shapes | ✅ Yes |
| Business logic that needs context | ✅ Yes |
| Non-obvious side effects | ✅ Yes |
| Public API methods | ✅ Yes (optional if fully typed and obvious) |
| Private helper methods | ❌ Usually no |

**Rule of Thumb:** If a developer needs to read the function body to understand **what** it does, add documentation. If the types and name make it clear, skip the DocBlock.

---

## Code Quality Checklist

Before submitting your PR, ensure:

- [ ] **`declare(strict_types=1);`** is at the top of every PHP file
- [ ] All functions have **type declarations** for parameters and return types
- [ ] Services use **constructor injection**, not Facades
- [ ] Configuration uses `config()` helper via **dependency injection**, not directly in classes
- [ ] Code follows **PSR-12** coding standards
- [ ] DocBlocks are included **only where needed** (complex types, business logic)
- [ ] Inline comments use `//` or `/* */`, **not** `/** */`
- [ ] Business logic is in **Services**, not Controllers
- [ ] No hardcoded values (use config or constants)
- [ ] Error handling is appropriate and consistent
- [ ] No debug statements (`dd()`, `dump()`, `var_dump()`, `console.log()`)
- [ ] Code is readable and follows existing patterns

---

## Philosophy

- **Clarity over cleverness** — Simple, readable code wins
- **Type safety** — Leverage PHP's type system fully
- **Explicit dependencies** — Make dependencies visible and testable
- **Meaningful documentation** — Document intent and context, not obvious code
- **Consistency** — Follow established patterns in the codebase
