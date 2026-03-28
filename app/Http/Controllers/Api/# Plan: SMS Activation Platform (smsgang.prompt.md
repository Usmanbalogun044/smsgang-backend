# Plan: SMS Activation Platform (smsgang)

## TL;DR
Build a full-stack SMS activation platform where users buy temporary phone numbers via 5SIM, pay per activation using Lendoverify, and receive SMS codes. Laravel 12 API backend + Next.js user frontend + separate Next.js admin frontend. MySQL database, Sanctum auth, database queue driver (cPanel-ready).

---

## Architecture Overview

- **Backend**: Laravel 12 REST API (existing fresh project at `backend/`)
- **User Frontend**: Next.js app (to be initialized in `frontend/`)
- **Admin Frontend**: Separate Next.js app (new directory `admin/`)
- **Database**: MySQL
- **Auth**: Laravel Sanctum (SPA token-based)
- **Payment**: Lendoverify (pay-per-activation, adapted from toplike project pattern)
- **SMS Provider**: 5SIM API (`https://5sim.net/v1`)
- **Queue**: Database driver

---

## Phase 1: Backend Foundation

### Step 1.1 — Environment & Dependencies
- Switch DB from SQLite to MySQL in `.env`
- Install Sanctum: `composer require laravel/sanctum`
- Add Sanctum middleware to API route group
- Add to `.env.example`: `FIVESIM_API_KEY`, `FIVESIM_BASE_URL=https://5sim.net/v1`, `LENDOVERIFY_BASE_URL`, `LENDOVERIFY_API_KEY`
- Add `config/services.php` entries for `fivesim` and `lendoverify`

### Step 1.2 — User Model Update
- Modify `app/Models/User.php`:
  - Add `role` field (enum: `user`, `admin`) with default `user`
  - Add `status` field (enum: `active`, `suspended`) with default `active`
  - Add Sanctum `HasApiTokens` trait
- Create migration to add `role` and `status` columns to `users` table

### Step 1.3 — Database Migrations (new tables)
Create migrations for:

**services** table:
- `id`, `name`, `slug`, `provider_service_code` (5SIM product code), `is_active` (default true), `timestamps`

**countries** table:
- `id`, `name`, `code` (ISO 2-letter), `flag` (emoji or URL), `is_active` (default true), `timestamps`

**service_prices** table:
- `id`, `service_id` (FK→services), `country_id` (FK→countries), `provider_price` (decimal 10,2), `markup_type` (enum: fixed, percent), `markup_value` (decimal 10,2), `final_price` (decimal 10,2), `is_active` (default true), `timestamps`
- Unique constraint on `[service_id, country_id]`

**orders** table:
- `id`, `user_id` (FK→users), `service_id` (FK→services), `country_id` (FK→countries), `price` (decimal 10,2), `payment_reference` (string, nullable), `lendoverify_checkout_url` (string, nullable), `status` (enum: pending, paid, processing, completed, failed, expired), `timestamps`

**activations** table:
- `id`, `order_id` (FK→orders), `service_id` (FK→services), `country_id` (FK→countries), `provider` (string, default '5sim'), `provider_activation_id` (string), `phone_number` (string), `sms_code` (text, nullable), `status` (enum: requested, number_received, waiting_sms, sms_received, completed, expired, cancelled), `expires_at` (timestamp), `timestamps`

**temp_emails** table (future — create migration but skip controller):
- `id`, `email_address`, `user_id` (FK, nullable), `provider`, `status` (enum: active, expired), `expires_at`, `timestamps`

### Step 1.4 — Models
Create Eloquent models with relationships:
- `Service` — hasMany ServicePrice, hasMany Order, hasMany Activation
- `Country` — hasMany ServicePrice, hasMany Order, hasMany Activation
- `ServicePrice` — belongsTo Service, belongsTo Country. Add `calculateFinalPrice()` accessor
- `Order` — belongsTo User, belongsTo Service, belongsTo Country, hasOne Activation
- `Activation` — belongsTo Order, belongsTo Service, belongsTo Country. Add `isExpired()` method
- `TempEmail` — belongsTo User (placeholder)

### Step 1.5 — Enums
Create PHP backed enums at `app/Enums/`:
- `UserRole: string` (user, admin) — used in User model cast + migration + middleware checks
- `UserStatus: string` (active, suspended) — used in User model cast + EnsureIsActive middleware
- `OrderStatus: string` (pending, paid, processing, completed, failed, expired) — used in Order model cast + status transitions
- `ActivationStatus: string` (requested, number_received, waiting_sms, sms_received, completed, expired, cancelled) — used in Activation model cast + job logic
- `MarkupType: string` (fixed, percent) — used in ServicePrice model cast + pricing calculations

All enums are **string-backed** for DB storage and JSON serialization. Models cast columns to their respective enum types.

### Step 1.6 — Form Requests (Validation)
Create `app/Http/Requests/` for every write endpoint — all validation lives here, not in controllers:

**Auth:**
- `RegisterRequest` — name (required|string|max:255), email (required|email|unique:users), password (required|min:8|confirmed)
- `LoginRequest` — email (required|email), password (required)

**User-facing:**
- `BuyActivationRequest` — service_id (required|exists:services,id), country_id (required|exists:countries,id). Custom rule: service+country combo must have an active ServicePrice
- `VerifyPaymentRequest` — reference (required|string)

**Admin:**
- `StoreServiceRequest` — name (required|string|max:255), provider_service_code (required|string), is_active (boolean)
- `UpdateServiceRequest` — name (string|max:255), is_active (boolean)
- `StoreCountryRequest` — name (required|string|max:255), code (required|string|size:2|unique:countries), flag (nullable|string)
- `UpdateCountryRequest` — name (string|max:255), code (string|size:2), flag (nullable|string), is_active (boolean)
- `UpdatePricingRequest` — markup_type (required|in:fixed,percent, uses MarkupType enum values), markup_value (required|numeric|min:0)
- `UpdateUserRequest` — role (in:user,admin, uses UserRole enum values), status (in:active,suspended, uses UserStatus enum values)

All Form Requests extend `FormRequest` and use `authorize()` for ownership/role checks where applicable.

### Step 1.7 — API Resources (Response Formatting)
Create `app/Http/Resources/` — all API responses pass through Resources for consistent JSON structure:
- `UserResource` — id, name, email, role, status, created_at (never exposes password/token)
- `ServiceResource` — id, name, slug, provider_service_code, is_active
- `CountryResource` — id, name, code, flag, is_active
- `ServicePriceResource` — id, service (nested), country (nested), provider_price, markup_type, markup_value, final_price
- `OrderResource` — id, user_id, service (nested), country (nested), price, status, payment_reference, created_at
- `ActivationResource` — id, order_id, phone_number, sms_code, status, provider, expires_at, created_at. **Hides** provider_activation_id from non-admin users
- `AdminStatsResource` — total_orders, total_revenue, active_activations, registered_users, revenue_today, revenue_week, revenue_month

All list endpoints return paginated `ResourceCollection` with standard meta (current_page, last_page, per_page, total).

### Step 1.8 — Policies (Authorization)
Create `app/Policies/` for ownership-based authorization:
- `OrderPolicy` — `view(User $user, Order $order)`: user owns the order OR user is admin
- `ActivationPolicy` — `view()`, `cancel()`: user owns the activation's order OR user is admin. `cancel()` also checks activation is in a cancellable status
- Register policies in `AppServiceProvider::boot()` or auto-discovery

---

## Phase 2: Service Layer (5SIM + Lendoverify)

### Step 2.1 — 5SIM Provider Service
Create `app/Services/SmsProviders/ProviderInterface.php`:
```
buyNumber(string $product, string $country): array
checkSms(string $activationId): ?array
finishActivation(string $activationId): bool
cancelActivation(string $activationId): bool
getBalance(): float
```

Create `app/Services/SmsProviders/FiveSimProvider.php` implementing ProviderInterface:
- Uses `Http::withToken()` facade with `config('services.fivesim.api_key')`
- `buyNumber()` → `GET /user/buy/activation/{country}/any/{product}` — returns `{id, phone, operator, ...}`
- `checkSms()` → `GET /user/check/{id}` — returns SMS array from response
- `finishActivation()` → `GET /user/finish/{id}`
- `cancelActivation()` → `GET /user/cancel/{id}`
- `getBalance()` → `GET /user/profile` — returns balance float

### Step 2.2 — Lendoverify Payment Service
Create `app/Services/LendoverifyService.php` (adapted from toplike):
- `initializeTransaction(float $amountNaira, string $email, string $name, string $description): array` — returns checkout URL + reference
- `verifyTransaction(string $reference): array` — returns payment status + amount
- Uses `Http::withHeaders(['Authorization' => 'Bearer ' . config('services.lendoverify.api_key')])`
- Amounts sent in Kobo (× 100)

### Step 2.3 — Activation Service
Create `app/Services/ActivationService.php`:
- `initiatePurchase(User $user, Service $service, Country $country): Order` — creates order with calculated price, initializes Lendoverify payment, returns order with checkout URL
- `processAfterPayment(Order $order): Activation` — called after payment verified; calls FiveSimProvider::buyNumber(), creates Activation record, dispatches CheckSmsJob
- `checkForSms(Activation $activation): ?string` — calls FiveSimProvider::checkSms(), updates activation if SMS found
- `completeActivation(Activation $activation): void` — calls FiveSimProvider::finishActivation(), marks completed
- `cancelActivation(Activation $activation): void` — calls FiveSimProvider::cancelActivation() if within window, marks cancelled
- `expireActivation(Activation $activation): void` — marks expired

### Step 2.4 — Pricing Service
Create `app/Services/PricingService.php`:
- `calculateFinalPrice(float $providerPrice, string $markupType, float $markupValue): float`
- `syncPricesFromProvider(string $country, string $product): void` — fetches 5SIM prices, creates/updates ServicePrice records with provider_price, keeps existing markup settings
- `getAvailableServices(int $countryId): Collection` — returns services with final prices for a country

### Step 2.5 — Register Service Bindings
In `AppServiceProvider::register()`:
- Bind `ProviderInterface` → `FiveSimProvider` (singleton)

---

## Phase 3: API Routes & Controllers

### Step 3.1 — Create `routes/api.php`
Register in `bootstrap/app.php` withRouting.

### Step 3.2 — Auth Controllers
Create `app/Http/Controllers/Api/AuthController.php`:
- `POST /api/register` — name, email, password → creates user + Sanctum token
- `POST /api/login` — email, password → returns Sanctum token
- `POST /api/logout` — revoke current token
- `GET /api/user` — return authenticated user

### Step 3.3 — Activation Controllers (User-facing)
Create `app/Http/Controllers/Api/ServiceController.php`:
- `GET /api/services` — list active services (with optional country filter)
- `GET /api/countries` — list active countries
- `GET /api/services/{service}/countries` — list countries with prices for a service
- `GET /api/countries/{country}/services` — list services with prices for a country

Create `app/Http/Controllers/Api/ActivationController.php`:
- `POST /api/activations/buy` — body: `{service_id, country_id}` → creates order, returns checkout URL
- `POST /api/activations/{order}/verify-payment` — verifies Lendoverify payment, triggers number purchase
- `GET /api/activations/{activation}` — get activation details + SMS code
- `GET /api/activations/{activation}/check-sms` — manually trigger SMS check
- `POST /api/activations/{activation}/cancel` — cancel activation
- `GET /api/activations` — list user's activations (paginated)

Create `app/Http/Controllers/Api/OrderController.php`:
- `GET /api/orders` — list user's orders (paginated)
- `GET /api/orders/{order}` — order details

### Step 3.4 — Lendoverify Webhook
Create `app/Http/Controllers/Api/LendoverifyWebhookController.php`:
- `POST /api/webhooks/lendoverify` — handles `collection.successful` / `payment.successful`
- Finds order by `payment_reference`, marks as paid, triggers `processAfterPayment()`
- Route excluded from CSRF and Sanctum middleware

### Step 3.5 — Admin Controllers
Create `app/Http/Controllers/Api/Admin/` with admin middleware (check `role === admin`):

`AdminServiceController.php`:
- `GET /api/admin/services` — list all services
- `POST /api/admin/services` — create service
- `PUT /api/admin/services/{id}` — update service (name, is_active)
- `POST /api/admin/services/sync` — sync services from 5SIM

`AdminCountryController.php`:
- `GET /api/admin/countries` — list all countries
- `POST /api/admin/countries` — create country
- `PUT /api/admin/countries/{id}` — update country (is_active, flag)
- `POST /api/admin/countries/sync` — sync countries from 5SIM

`AdminPricingController.php`:
- `GET /api/admin/prices` — list service prices (filterable by service/country)
- `PUT /api/admin/prices/{id}` — update markup_type, markup_value → recalculates final_price
- `POST /api/admin/prices/sync` — sync provider prices from 5SIM for all active service+country combinations

`AdminActivationController.php`:
- `GET /api/admin/activations` — list all activations (filterable, paginated)
- `POST /api/admin/activations/{id}/expire` — force expire an activation
- `GET /api/admin/stats` — dashboard stats (total orders, revenue, active activations)

`AdminUserController.php`:
- `GET /api/admin/users` — list users (paginated)
- `PUT /api/admin/users/{id}` — update role/status

### Step 3.6 — Middleware
- Create `app/Http/Middleware/EnsureIsAdmin.php` — checks `auth()->user()->role === UserRole::Admin`
- Create `app/Http/Middleware/EnsureIsActive.php` — checks `auth()->user()->status === UserStatus::Active`
- Register in `bootstrap/app.php` as named middleware aliases: `admin`, `active`

### Step 3.7 — Route Middleware Stack
Apply middleware groups to route groups:
- **Public routes** (no auth): `POST /register`, `POST /login`, `GET /services`, `GET /countries`, `POST /webhooks/lendoverify`
- **Authenticated user routes**: `sanctum + active` middleware — all activation, order, user profile routes
- **Admin routes**: `sanctum + active + admin` middleware — all `/api/admin/*` routes
- **Webhook routes**: no auth, no CSRF — only `/api/webhooks/*`

### Step 3.8 — Rate Limiting
Define named rate limiters in `AppServiceProvider::boot()` using `RateLimiter::for()`:
- `api` — 60 requests/minute per user (authenticated) or per IP (guest)
- `auth` — 5 requests/minute per IP on login/register (brute-force protection)
- `buy` — 10 requests/minute per authenticated user on `/activations/buy`
- `sms-check` — 20 requests/minute per user on `/check-sms` (prevent hammering)
- `webhook` — 30 requests/minute per IP on webhook endpoint

Apply via `throttle:limiterName` middleware on respective route groups.

---

## Phase 4: Background Jobs

### Step 4.1 — CheckSmsJob
Create `app/Jobs/CheckSmsJob.php`:
- Dispatched after number is purchased
- Polls `FiveSimProvider::checkSms()` for the activation
- If SMS found → update activation status + sms_code, call `finishActivation()`
- If not found and not expired → re-dispatch self with 5-second delay (max 60 retries = ~5 minutes)
- If expired → mark activation as expired

### Step 4.2 — ExpireActivationsJob
Create `app/Jobs/ExpireActivationsJob.php`:
- Scheduled via `routes/console.php` to run every minute
- Queries activations where `expires_at < now()` and status is not terminal
- Marks them as expired

### Step 4.3 — Schedule Registration
In `routes/console.php`:
- `Schedule::job(new ExpireActivationsJob)->everyMinute()`

---

## Phase 5: User Frontend (Next.js)

### Step 5.1 — Initialize Project
- `npx create-next-app@latest frontend` with TypeScript, Tailwind CSS, App Router
- Install: `axios`, `zustand` (state management), `react-hot-toast`

### Step 5.2 — Auth Pages
- `/login` — email + password form → POST to `/api/login`, store token in httpOnly cookie or localStorage
- `/register` — name + email + password form → POST to `/api/register`
- Auth context/provider wrapping the app
- Protected route wrapper (redirect to login if not authed)

### Step 5.3 — Main Pages
- `/` — Landing page with hero, features, how-it-works
- `/services` — Browse services grid → select a service → shows countries with prices
- `/services/[slug]` — Service detail: list countries + prices, "Buy Number" button per country
- `/buy` flow:
  1. Click "Buy Number" → POST `/api/activations/buy` → redirect to Lendoverify checkout
  2. After payment redirect back → `/activation/[orderId]/verify` → calls verify-payment API
  3. Shows activation page with phone number + countdown timer + SMS code when received
- `/activations` — User's activation history (paginated list)
- `/orders` — User's order history

### Step 5.4 — Activation Live Page
- `/activation/[id]` — Shows:
  - Phone number (copy to clipboard)
  - Countdown timer (based on `expires_at`)
  - SMS code (polls `/api/activations/{id}` every 3 seconds or uses SSE)
  - Status badge
  - Cancel button (if eligible)

### Step 5.5 — Layout & Components
- Navbar with logo, services link, user menu
- Footer
- Service card component
- Country selector component
- Activation status card
- Timer component
- Toast notifications

---

## Phase 6: Admin Frontend (Next.js)

### Step 6.1 — Initialize Project
- `npx create-next-app@latest admin` with TypeScript, Tailwind CSS, App Router
- Install: `axios`, `zustand`, `react-hot-toast`
- Separate deployment from user frontend

### Step 6.2 — Admin Auth
- `/login` — admin login (same API, but verify role on frontend)
- Redirect non-admin users

### Step 6.3 — Admin Pages
- `/dashboard` — Stats overview (total orders, revenue today/week/month, active activations, registered users)
- `/services` — CRUD table for services with enable/disable toggle, "Sync from 5SIM" button
- `/countries` — CRUD table for countries with enable/disable toggle, "Sync from 5SIM" button
- `/pricing` — Table showing all service+country combos with provider_price, markup_type, markup_value, final_price. Inline edit for markup fields. "Sync Prices" button
- `/activations` — List all activations with filters (status, date range, user). "Force Expire" action
- `/users` — List users with role/status management
- `/orders` — List all orders with filters

---

## Phase 7: Seeding & Testing

### Step 7.1 — Seeders
- `AdminUserSeeder` — create default admin user
- `ServiceSeeder` — seed common services (Telegram, WhatsApp, Google, TikTok, Instagram, Facebook, Twitter, etc.) with their 5SIM product codes
- `CountrySeeder` — seed countries with codes and flags

### Step 7.2 — Feature Tests
- Auth: register, login, logout, get user
- Activation flow: buy → verify payment → check SMS → complete
- Admin: CRUD services, countries, prices
- Webhook: Lendoverify payment webhook handler
- Mock FiveSimProvider in tests

---

## Relevant Files

### Backend — Existing (to modify)
- `backend/app/Models/User.php` — add role, status, HasApiTokens
- `backend/app/Providers/AppServiceProvider.php` — register service bindings
- `backend/config/services.php` — add fivesim + lendoverify config
- `backend/.env.example` — add new env vars
- `backend/bootstrap/app.php` — register API routes + middleware
- `backend/routes/console.php` — add scheduled jobs

### Backend — New files
- `backend/app/Enums/` — UserRole, UserStatus, OrderStatus, ActivationStatus, MarkupType (all string-backed enums)
- `backend/app/Models/` — Service, Country, ServicePrice, Order, Activation, TempEmail
- `backend/app/Services/SmsProviders/ProviderInterface.php`
- `backend/app/Services/SmsProviders/FiveSimProvider.php`
- `backend/app/Services/LendoverifyService.php`
- `backend/app/Services/ActivationService.php`
- `backend/app/Services/PricingService.php`
- `backend/app/Http/Controllers/Api/AuthController.php`
- `backend/app/Http/Controllers/Api/ServiceController.php`
- `backend/app/Http/Controllers/Api/ActivationController.php`
- `backend/app/Http/Controllers/Api/OrderController.php`
- `backend/app/Http/Controllers/Api/LendoverifyWebhookController.php`
- `backend/app/Http/Controllers/Api/Admin/AdminServiceController.php`
- `backend/app/Http/Controllers/Api/Admin/AdminCountryController.php`
- `backend/app/Http/Controllers/Api/Admin/AdminPricingController.php`
- `backend/app/Http/Controllers/Api/Admin/AdminActivationController.php`
- `backend/app/Http/Controllers/Api/Admin/AdminUserController.php`
- `backend/app/Http/Requests/` — RegisterRequest, LoginRequest, BuyActivationRequest, VerifyPaymentRequest, StoreServiceRequest, UpdateServiceRequest, StoreCountryRequest, UpdateCountryRequest, UpdatePricingRequest, UpdateUserRequest
- `backend/app/Http/Resources/` — UserResource, ServiceResource, CountryResource, ServicePriceResource, OrderResource, ActivationResource, AdminStatsResource
- `backend/app/Policies/` — OrderPolicy, ActivationPolicy
- `backend/app/Http/Middleware/EnsureIsAdmin.php`
- `backend/app/Http/Middleware/EnsureIsActive.php`
- `backend/app/Jobs/CheckSmsJob.php`
- `backend/app/Jobs/ExpireActivationsJob.php`
- `backend/routes/api.php`
- `backend/database/migrations/` — 7 new migration files
- `backend/database/seeders/` — AdminUserSeeder, ServiceSeeder, CountrySeeder

### Frontend (user) — All new
- `frontend/` — Full Next.js app (App Router, TypeScript, Tailwind)

### Admin — All new
- `admin/` — Full Next.js app (App Router, TypeScript, Tailwind)

---

## Verification

1. **Backend unit**: Run `php artisan test` — auth flow, activation flow (mocked 5SIM), webhook handler
2. **Migration check**: `php artisan migrate:fresh --seed` succeeds without errors
3. **Route listing**: `php artisan route:list` shows all expected API routes
4. **Manual API tests**: Use Postman/Insomnia to test full activation flow:
   - Register → Login → Get services → Buy number → Verify payment (mock webhook) → Check SMS → Complete
5. **Admin API tests**: Login as admin → CRUD services/countries/prices → View activations
6. **Frontend smoke test**: `npm run dev` in frontend/ — pages render, auth works, activation flow works with backend running
7. **Admin smoke test**: `npm run dev` in admin/ — login, dashboard loads, CRUD operations work
8. **Queue test**: `php artisan queue:work` — CheckSmsJob dispatches and polls correctly
9. **Scheduler test**: `php artisan schedule:run` — ExpireActivationsJob runs and expires stale activations

---

## Decisions

- **Pay per activation** (no wallet) — each purchase triggers a Lendoverify checkout session. User is redirected to pay, then redirected back. Order stays `pending` until webhook/verification confirms payment.
- **Lendoverify** as payment gateway (same integration pattern as toplike project)
- **Sanctum token auth** — tokens stored client-side (localStorage or cookie), sent as `Authorization: Bearer` header
- **Separate admin frontend** — independent Next.js app at `admin/`, deployed separately, uses same API with admin routes
- **Database queue driver** — cPanel compatible, no Redis needed
- **5SIM operator = "any"** — when buying numbers, use `any` operator for maximum availability
- **Temp email** — migration created but controllers/frontend deferred to future phase
- **SMS polling via queued job** — CheckSmsJob re-dispatches itself every 5s until SMS arrives or expires (max ~5 min)

---

## Security

### Authentication & Authorization
- **Sanctum token auth** — all API tokens are hashed in DB (`personal_access_tokens` table). Tokens never logged or exposed in responses after creation
- **Password hashing** — Laravel's built-in bcrypt via `Hash::make()`. Passwords cast as `hashed` in User model (auto-hashed on set)
- **Role-based access** — `EnsureIsAdmin` middleware uses `UserRole` enum comparison, not string comparison. Admin routes are a separate route group, not mixed with user routes
- **Ownership checks** — Policies enforce that users can only view/cancel their own orders and activations. No IDOR (Insecure Direct Object Reference) vulnerabilities
- **Token scoping** — Sanctum tokens issued with abilities (e.g., `['user:read', 'activation:buy']` for regular users). Admin tokens get `['*']`. Check abilities in middleware or policies

### Input Validation & Injection Prevention
- **All input validated via Form Requests** — no raw `$request->input()` used in controllers without validation
- **Eloquent ORM** — parameterized queries by default, no raw SQL. Prevents SQL injection
- **Mass assignment protection** — all models define explicit `$fillable` arrays. No `$guarded = []`
- **XSS prevention** — API returns JSON only (no HTML rendering). All string fields validated with `string|max:N` rules
- **Enum validation** — status fields validated against enum values using Laravel's `Rule::in()` with enum cases, preventing invalid state transitions

### Payment Security
- **Webhook signature verification** — Lendoverify webhook handler verifies the request origin (IP whitelist or signature header if supported). Webhook route excluded from CSRF but still validates payload structure
- **Idempotent payment processing** — `payment_reference` is unique. Processing checks if order is already paid before crediting. Prevents double-processing from duplicate webhooks
- **Amount verification** — after Lendoverify payment verification, compare the `amountPaid` from Lendoverify response against `order.price` to ensure the correct amount was paid. Reject mismatched amounts
- **No price manipulation** — `final_price` calculated server-side from `ServicePrice` at time of order creation. Frontend never sends the price; it sends `service_id + country_id` and backend looks up the current price
- **Payment reference generation** — use `Str::uuid()` for payment references. Never use sequential/guessable IDs

### API Security
- **Rate limiting** — per-route rate limiters (see Step 3.8) to prevent brute-force login, purchase spam, and API abuse
- **CORS** — `config/cors.php` configured with explicit `allowed_origins` for user frontend and admin frontend domains only. No wildcard `*` in production
- **HTTPS enforcement** — `APP_URL` set to `https://`. Sanctum cookies set with `secure: true` in production. Add `ForceScheme` middleware or `URL::forceScheme('https')` in production
- **API versioning** — all routes prefixed under `/api/` (implicit v1). If breaking changes needed later, add `/api/v2/` prefix without breaking existing clients
- **Request size limits** — enforce `post_max_size` and validate file uploads if any (currently none, but TempEmail may need it later)

### Secret Management
- **Environment variables** — `FIVESIM_API_KEY`, `LENDOVERIFY_API_KEY` stored in `.env` only. Never committed to git. `.env` is in `.gitignore`
- **Config caching** — `config('services.fivesim.api_key')` accesses secrets via config layer, not `env()` directly (required for `config:cache` to work)
- **No secrets in logs** — Laravel's logger configured to sanitize sensitive fields. API keys never logged in HTTP client exceptions. Use `Http::withToken()->throw()` with try/catch that logs generic error messages

### Data Protection
- **Sensitive field hiding** — `ActivationResource` hides `provider_activation_id` and `sms_code` from users who don't own the activation. Admin sees full data
- **User model `$hidden`** — `password`, `remember_token` never serialized
- **Soft-deletes consideration** — Orders and Activations use `SoftDeletes` for audit trail. Users are suspended (not deleted) to preserve order history
- **Logging** — all payment events (order created, payment verified, webhook received) logged to `storage/logs/payments.log` via a dedicated Laravel log channel. Includes order_id, user_id, amount, status — but never full card details or API keys

### Infrastructure Security
- **Database** — MySQL user has limited privileges (SELECT, INSERT, UPDATE, DELETE on app database only). No `DROP`, `CREATE`, or `GRANT` privileges in production
- **Queue security** — Jobs are dispatched with typed parameters (model IDs, not full objects). Jobs validate the activation/order still exists and is in expected state before processing
- **Timeout protection** — HTTP client calls to 5SIM and Lendoverify APIs use explicit timeouts (`Http::timeout(15)->...`) to prevent hanging connections from blocking queue workers
- **Error handling** — custom exception handler returns sanitized JSON error responses (no stack traces in production). `APP_DEBUG=false` in production

---

## Further Considerations

1. **Refund policy**: Currently no refunds if activation expires. If `buyNumber()` fails after payment, mark order as `failed`. Admin handles manual refund (or implement Lendoverify refund API if available).
2. **Soft-delete strategy**: Consider `SoftDeletes` on Order and Activation models for audit trail and dispute resolution.
3. **Admin audit log**: Log admin actions (price changes, force-expire, user role changes) to a separate `admin_audit_log` table for accountability.
4. **IP-based fraud detection**: Track IP addresses on orders. Flag users placing many orders from different IPs or same IP with multiple accounts.
