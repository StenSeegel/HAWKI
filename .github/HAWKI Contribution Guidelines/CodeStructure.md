# Code Structure & Architecture

This project prioritizes **long-term maintainability, readability, and scalability**. Contributions must follow clean architecture principles and Laravel best practices.

The goal is that any developer can understand, extend, or refactor a feature without touching unrelated parts of the system.

---

## Table of Contents

1. [Architectural Patterns](#architectural-patterns)
2. [Directory Structure & Organization](#directory-structure--organization)
3. [MVC & Service Layer Responsibilities](#mvc--service-layer-responsibilities)
4. [Contracts Are the Boundary](#contracts-are-the-boundary)
5. [Singletons & Application-Wide Services](#singletons--application-wide-services)

---

## Architectural Patterns

We support two patterns depending on the nature of the feature.

### 1. Core-Integrated Features (Layer-First Structure)

**Use this when the feature:**
- Is tightly coupled to the core domain
- Depends directly on core models (e.g., User, Room, Auth)
- Is required for the system to function
- Is not intended to be extracted or reused independently

**Structure:**

Code is organized by Laravel's standard layers, with feature-based subfolders:

```
app/
├── Models/
│   └── Chat/
│       ├── Message.php
│       └── Room.php
├── Services/
│   └── Chat/
│       ├── MessageService.php
│       └── RoomService.php
├── Http/
│   ├── Controllers/
│   │   └── Chat/
│   │       └── ChatController.php
│   ├── Requests/
│   │   └── Chat/
│   │       ├── CreateMessageRequest.php
│   │       └── UpdateRoomRequest.php
│   └── Resources/
│       └── Chat/
│           ├── MessageResource.php
│           └── RoomResource.php
├── Events/
│   └── Chat/
│       ├── MessageSent.php
│       └── RoomCreated.php
└── Listeners/
    └── Chat/
        ├── NotifyRoomMembers.php
        └── LogMessageActivity.php
```

**Rules:**
- Each feature must have consistent folder names across layers
- Controllers must remain thin (see [Controllers section](#controllers-entry-points))
- Business logic belongs in Services
- Models focus on data, relationships, and simple domain rules
- Cross-feature access must happen via contracts, not concrete implementations
- Shared logic must be moved to a dedicated `shared` or `core` layer

### 2. Modular / Parallel Features (Feature-First Structure)

**Use this when the feature:**
- Is optional or replaceable
- Integrates external systems or providers
- Is experimental or fast-evolving
- Has clear boundaries
- Should be developed and tested independently

**Structure:**

Each feature encapsulates its own layers and configuration:

```
app/Modules/YourFeature/
├── Controllers/
├── Requests/
├── Resources/
├── Services/
├── Models/
├── Events/
├── Listeners/
├── Contracts/
├── Providers/
├── Config/
└── routes.php
```

**Rules:**
- Features must be self-contained
- Communication with core must happen via interfaces
- Feature code must not directly depend on internal core implementations
- Service bindings should be registered via a feature-specific service provider
- Feature boundaries must be respected at all times

---

## Directory Structure & Organization

### Events & Listeners

Events and Listeners follow the architectural pattern of the feature:

**Core-Integrated Features:**
```
app/
├── Events/
│   └── FeatureName/
│       └── EventName.php
└── Listeners/
    └── FeatureName/
        └── ListenerName.php
```

**Modular Features:**
```
app/Modules/FeatureName/
├── Events/
│   └── EventName.php
└── Listeners/
    └── ListenerName.php
```

**Best Practices:**
- Events should be named in **past tense** (e.g., `MessageSent`, `UserRegistered`)
- Listeners should be named as **actions** (e.g., `SendWelcomeEmail`, `NotifyAdmins`)
- Register event-listener mappings in `EventServiceProvider`
- Keep events simple — they're data carriers, not logic containers



[//]: # (TODO: Do we need resources? in the project data object is defined in Value etc.)
### API Resources (Eloquent Resources)

**To avoid putting presentation logic in Models or Controllers, use Eloquent API Resources.**

Resources transform models into JSON responses consistently and reusably.

**Structure:**
```
app/Http/Resources/
└── FeatureName/
    ├── ResourceNameResource.php
    └── ResourceNameCollection.php
```

**Example:**

```php
// ❌ Bad - Presentation logic in controller
public function show(User $user): JsonResponse
{
    return response()->json([
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'created_at' => $user->created_at->toIso8601String(),
    ]);
}

// ✅ Good - Using API Resource
public function show(User $user): UserResource
{
    return new UserResource($user);
}
```

**Resource Example:**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'created_at' => $this->created_at->toIso8601String(),
            'posts' => PostResource::collection($this->whenLoaded('posts')),
        ];
    }
}
```

**Collection Example:**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\User;

use Illuminate\Http\Resources\Json\ResourceCollection;

class UserCollection extends ResourceCollection
{
    public function toArray($request): array
    {
        return [
            'data' => $this->collection,
            'meta' => [
                'total' => $this->collection->count(),
            ],
        ];
    }
}
```

---

## MVC & Service Layer Responsibilities

We follow a strict separation of concerns. Each layer has a clearly defined responsibility.

### Controllers (Entry Points)

Controllers handle HTTP requests and responses, nothing more.

**Responsibilities:**
- Receive HTTP requests
- Delegate validation to **FormRequest** classes
- Handle authorization and access control
- Convert request data into DTOs or method parameters
- Delegate execution to Services
- Return responses using **API Resources** (for JSON) or views

**Controllers should be thin:**
- One public method per action
- One service call
- Minimal conditional logic

**Forbidden in Controllers:**
- Validation logic (use FormRequest)
- Business rules or workflows
- Database queries
- Complex data transformations
- Calling multiple unrelated services
- JSON formatting logic (use API Resources)

**Example:**

```php
// ✅ Good - Thin controller
public function store(
    CreateUserRequest $request,
    UserService $userService
): UserResource {
    $user = $userService->createUser($request->validated());

    return new UserResource($user);
}

// ❌ Bad - Business logic in controller
public function store(Request $request): JsonResponse
{
    $validated = $request->validate([
        'name' => 'required|string',
        'email' => 'required|email|unique:users',
    ]);

    $user = new User($validated);

    if ($user->email_verified_at === null) {
        Mail::to($user)->send(new VerificationEmail($user));
    }

    $user->save();

    return response()->json([
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
    ], 201);
}
```

### Services (Business Logic)

Services contain all business rules and workflows.

**Responsibilities:**
- Contain all business rules and workflows
- Coordinate domain operations
- Interact with models, repositories, and external services
- Be reusable across controllers, jobs, and commands
- Be testable in isolation

**Design Rules:**
- Services must have a single responsibility
- Services must not depend on HTTP, sessions, or request objects
- Dependencies must be injected via constructor
- Similar services must share contracts (interfaces)

**Example:**

```php
<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use App\Contracts\EmailVerificationServiceInterface;

class UserService
{
    public function __construct(
        private UserRepository $repository,
        private EmailVerificationServiceInterface $verification
    ) {}

    public function createUser(array $data): User
    {
        $user = $this->repository->create($data);
        $this->verification->sendVerificationEmail($user);

        return $user;
    }
}
```

### Models (Data & Domain)

Models represent data and domain structure.

**Responsibilities:**
- Define database structure and relationships
- Attribute casting and accessors
- Simple, data-related helper logic
- **Query scopes** for reusable query logic

**Allowed in Models:**
- Relationships (`hasMany`, `belongsTo`, etc.)
- Attribute mutators and accessors
- Lightweight domain rules directly tied to data
- **Query scopes** (preferred over query methods)

**Forbidden in Models:**
- Complex workflows
- Business processes
- Cross-domain coordination
- External service calls

### Avoid Fat Controllers: Use Query Scopes

Move frequent queries to **local scopes** in models. Scopes return a query builder, allowing the controller or service to decide whether to `get()`, `paginate()`, or add further `where()` clauses.

**❌ Bad - Query logic in controller (breaks chain):**
```php
public function index(): View
{
    $clients = Client::verified()
        ->with(['orders' => function ($q) {
            $q->where('created_at', '>', Carbon::today()->subWeek());
        }])
        ->get();

    return view('index', ['clients' => $clients]);
}
```

**❌ Also Bad - Method returns Collection (stops query chain):**
```php
// In Controller
public function index(): View
{
    return view('index', ['clients' => $this->client->getWithNewOrders()]);
}

// In Model
class Client extends Model
{
    public function getWithNewOrders(): Collection // ❌ Returns Collection, can't chain
    {
        return $this->verified()
            ->with(['orders' => function ($q) {
                $q->where('created_at', '>', Carbon::today()->subWeek());
            }])
            ->get();
    }
}
```

**✅ Good - Using Query Scope (returns Builder):**
```php
// In Controller or Service
public function index(): View
{
    $clients = Client::withNewOrders()->paginate(20); // Can chain ->paginate(), ->get(), etc.

    return view('index', ['clients' => $clients]);
}

// In Model
class Client extends Model
{
    /**
     * Scope to include verified clients with orders from the past week.
     */
    public function scopeWithNewOrders(Builder $query): Builder
    {
        return $query->verified()
            ->with(['orders' => function ($q) {
                $q->where('created_at', '>', Carbon::today()->subWeek());
            }]);
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->whereNotNull('verified_at');
    }
}
```

**Why Scopes?**
- Returns a `Builder`, not a `Collection` — keeps the query chain open
- Controllers/Services can decide whether to `get()`, `paginate()`, `first()`, etc.
- Allows further `where()` clauses or ordering
- Reusable and composable

---

## Contracts Are the Boundary

To ensure modularity:

- Core ↔ Feature communication **must go through interfaces**
- Feature modules must not depend on concrete core services
- Bindings happen in service providers

**Example:**

```php
// Define a contract
interface EmailVerificationServiceInterface
{
    public function sendVerificationEmail(User $user): void;
}

// Implement the contract
class EmailVerificationService implements EmailVerificationServiceInterface
{
    public function sendVerificationEmail(User $user): void
    {
        Mail::to($user)->send(new VerificationEmail($user));
    }
}

// Bind in ServiceProvider
public function register(): void
{
    $this->app->singleton(
        EmailVerificationServiceInterface::class,
        EmailVerificationService::class
    );
}

// Use via dependency injection
class UserService
{
    public function __construct(
        private EmailVerificationServiceInterface $verification
    ) {}

    public function createUser(array $data): User
    {
        $user = User::create($data);
        $this->verification->sendVerificationEmail($user);

        return $user;
    }
}
```

This guarantees:
- **Replaceability** — Swap implementations without changing dependent code
- **Testability** — Mock interfaces easily in tests
- **Extraction safety** — Features can be moved to packages

---

## Singletons & Application-Wide Services

If a service is:
- Stateless or internally cached
- Used across many parts of the system
- Has a single valid configuration

**Register it as a singleton** in a service provider:

```php
public function register(): void
{
    $this->app->singleton(StorageService::class, function ($app) {
        return new StorageService(
            config('storage.driver'),
            config('storage.options')
        );
    });
}
```

This avoids:
- Multiple unnecessary instantiations
- Inconsistent behavior across the app
- Performance overhead

---

## Summary

| Component | Responsibility | Key Rule |
|-----------|----------------|----------|
| **Controllers** | HTTP handling | Thin, delegate to Services, use FormRequests & Resources |
| **FormRequests** | Validation | All validation logic |
| **API Resources** | JSON formatting | Transform models to API responses |
| **Services** | Business logic | Coordinate workflows, testable |
| **Models** | Data & relationships | Use scopes for queries, avoid workflows |
| **Events** | Notifications | Data carriers (past tense) |
| **Listeners** | Event handlers | Actions in response to events |
| **Contracts** | Boundaries | Define interfaces for modularity |

**Rule of Thumb:**
- Does it handle HTTP? → Controller
- Does it validate input? → FormRequest
- Does it format JSON? → API Resource
- Does it decide or coordinate behavior? → Service
- Does it describe or manage data? → Model
- Does it announce something happened? → Event
- Does it react to an announcement? → Listener

When in doubt, **prefer Services** over Controllers and Models.
