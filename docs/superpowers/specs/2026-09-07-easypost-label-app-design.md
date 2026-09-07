# EasyPost USPS Label App — Design Spec

Date: 2026-09-07
Status: approved for implementation planning
Scope: 4-hour take-home prototype ("Generate and store a USPS Shipping Label")

## 1. Goal

A web app where a registered user enters a US origin address, a US destination address
and package attributes, and receives a printable USPS shipping label bought through the
EasyPost API. Labels are stored on the server and listed per user; a user can only ever
see their own labels. All EasyPost calls happen in the backend.

Spec requirements and where they are satisfied:

| Requirement | Where |
|---|---|
| Laravel (latest stable) / React / MySQL | Laravel 13, React 19 + TypeScript, MySQL 8.4 via Docker Compose |
| User credentials | Sanctum SPA session auth, register/login/logout endpoints |
| Each user sees only their own labels | Query scoping on list, `ShippingLabelPolicy` on show/download |
| All EasyPost calls from the backend | `App\Services\EasyPost\EasyPostClient`, the only place that knows the API |
| US-only addresses | `StoreLabelRequest` rules (`country` fixed to `US`, state list, ZIP regex) |
| Printable USPS label | Shipment created with `label_format: PDF`, served inline by an authenticated route |
| Persistent storage of all labels | PDF stored on the private `local` disk + `shipping_labels` row |
| User-specific history | `GET /api/labels`, newest first, paginated |

## 2. Constraints and assumptions

- Time box is 4 hours. This is a prototype; polish is out of scope.
- EasyPost **test mode** API key. Test labels carry a "SAMPLE" watermark and cost nothing.
- Carrier is fixed to USPS. The service level is chosen automatically (lowest USPS rate).
- Units are what EasyPost and US users expect: weight in ounces, dimensions in inches.
- Addresses are validated locally for shape and US-only. EasyPost's own validation is
  the second line: its errors are surfaced to the user as 422.
- No password reset, no email verification, no address book, no rate selection UI.
  These are listed in "What I'd do next".
- MySQL runs in Docker (`compose.yaml`). Tests run against a second database in the same
  container because the dev machine's PHP lacks `pdo_sqlite`.

## 3. Architecture

### 3.1 Backend (Laravel 13, standard layout)

```
app/Http/Controllers/Auth/   RegisterController, LoginController, LogoutController (invokable)
app/Http/Controllers/        LabelController (index, store, show, download)
app/Http/Requests/Auth/      RegisterRequest, LoginRequest
app/Http/Requests/           StoreLabelRequest
app/Http/Resources/          LabelResource
app/Policies/                ShippingLabelPolicy
app/Actions/                 CreateShippingLabel
app/Services/EasyPost/       EasyPostClient, Exceptions/EasyPostException
app/Exceptions/              NoUspsRateException
app/Models/                  User, ShippingLabel
database/migrations/         create_shipping_labels_table
database/seeders/            DatabaseSeeder (demo user)
```

Conventional directories were chosen over a vertical-slice layout: two small areas
(auth, labels) do not justify feature folders, and the standard layout is instantly
legible to a Laravel reviewer.

### 3.2 Routes

`routes/api.php` (JSON, stateful through Sanctum's `statefulApi()` middleware):

| Method | Path | Handler | Middleware |
|---|---|---|---|
| POST | `/api/register` | `Auth\RegisterController` | — |
| POST | `/api/login` | `Auth\LoginController` | `throttle:6,1` |
| POST | `/api/logout` | `Auth\LogoutController` | `auth:sanctum` |
| GET | `/api/user` | closure (from `install:api`) | `auth:sanctum` |
| GET | `/api/labels` | `LabelController@index` | `auth:sanctum` |
| POST | `/api/labels` | `LabelController@store` | `auth:sanctum` |
| GET | `/api/labels/{label}` | `LabelController@show` | `auth:sanctum`, policy `view` |
| GET | `/api/labels/{label}/download` | `LabelController@download` | `auth:sanctum`, policy `view` |

`routes/web.php`: a catch-all `GET /{any}` (excluding `api/*`, `sanctum/*`, `up`) returns
`resources/views/app.blade.php`, a shell with `@vite` that mounts React. React Router
owns everything from there. The default `welcome.blade.php` is removed.

### 3.3 Configuration

- `config/services.php` → `easypost.key` (`EASYPOST_API_KEY`), `easypost.base_url`
  (`EASYPOST_BASE_URL`, default `https://api.easypost.com/v2`), `easypost.timeout` (15s).
- `config/filesystems.php` → `local` disk keeps its private root (`storage/app/private`)
  and gets `serve => false` so no `/storage/{path}` route exists.
- `bootstrap/app.php` → `$middleware->statefulApi()`; exception rendering (section 7).
- `.env.example` → `SANCTUM_STATEFUL_DOMAINS=localhost:8000,127.0.0.1:8000`,
  `EASYPOST_API_KEY=`.

### 3.4 Frontend (React 19, TypeScript, React Router 7, Tailwind v4, axios)

```
resources/js/
  app.tsx              mounts <App/>: BrowserRouter + AuthProvider + routes
  types.ts             User, Address, Parcel, Label, Paginated<T>, ApiError
  api/client.ts        axios instance: baseURL /api, withCredentials, withXSRFToken
  api/auth.ts          csrf(), register(), login(), logout(), me()
  api/labels.ts        list(page), create(payload), show(id), downloadUrl(id)
  hooks/useAuth.tsx    AuthProvider + useAuth(): user, loading, login, register, logout
  components/          Layout, RequireAuth, Field, Button, Alert, AddressFields
  pages/               LoginPage, RegisterPage, LabelsPage, NewLabelPage, LabelDetailPage
```

Local state only (`useState`/`useEffect`) plus one auth context. No query cache, no
global store: five screens do not need them.

## 4. Data model

`users`: Laravel default. `User` gains `labels(): HasMany` and the Sanctum `HasApiTokens`
trait (already applied).

`shipping_labels`:

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK `users`, indexed, `cascadeOnDelete` | owner |
| `easypost_shipment_id` | string, unique | `shp_...` |
| `tracking_code` | string, nullable, indexed | from the buy response |
| `carrier` | string | always `USPS` today |
| `service` | string | e.g. `GroundAdvantage`, `Priority` |
| `rate` | decimal(8,2) | EasyPost returns a string, cast to decimal |
| `currency` | char(3) | `USD` |
| `from_address` | json | snapshot as submitted |
| `to_address` | json | snapshot as submitted |
| `parcel` | json | `{weight_oz, length_in, width_in, height_in}` |
| `label_file_path` | string | relative to the `local` disk, `labels/{user_id}/{uuid}.pdf` |
| `label_file_type` | string | `application/pdf` (EasyPost `label_file_type`) |
| `label_url` | string | EasyPost's public S3 URL, **hidden** |
| `easypost_response` | json | raw shipment after buy, **hidden** |
| timestamps | | |

`ShippingLabel` model: explicit `$fillable`; casts `from_address`, `to_address`, `parcel`,
`easypost_response` → `array`, `rate` → `decimal:2`; `$hidden = ['label_url',
'easypost_response', 'label_file_path']`; `user(): BelongsTo`; factory for tests.

Addresses and parcel are JSON snapshots, not normalized tables: a purchased label is
immutable, so the snapshot is the correct semantics.

`LabelResource` whitelist: `id`, `carrier`, `service`, `rate`, `currency`,
`tracking_code`, `from_address`, `to_address`, `parcel`, `created_at`. Never any URL or
file path.

Seeder: `DatabaseSeeder` creates `demo@example.com` / `password` so a reviewer can log
in without registering.

## 5. Data flow

### 5.1 Authentication (Sanctum SPA, session cookie)

1. App boot: `GET /api/user`. 200 → user stored in `AuthProvider`; 401 → `null`.
2. Login: `GET /sanctum/csrf-cookie` (sets `XSRF-TOKEN`), then `POST /api/login
   {email, password}`. Success → session created, 200 with the user. axios sends
   `X-XSRF-TOKEN` automatically.
3. Register: same, `POST /api/register {name, email, password, password_confirmation}`
   → user created and logged in → 201 with the user.
4. Logout: `POST /api/logout` → session invalidated, CSRF token regenerated → 204.
   Frontend clears the user and navigates to `/login`.
5. `RequireAuth` redirects to `/login` when there is no user. An axios response
   interceptor treats 401 and 419 as "logged out".

### 5.2 Create label

Request `POST /api/labels`:

```json
{
  "from_address": {"name": "", "street1": "", "street2": null, "city": "", "state": "CA", "zip": "94107", "country": "US", "phone": null},
  "to_address":   {"name": "", "street1": "", "street2": null, "city": "", "state": "NY", "zip": "10001", "country": "US", "phone": null},
  "parcel":       {"weight_oz": 16, "length_in": 10, "width_in": 8, "height_in": 4}
}
```

`StoreLabelRequest` rules (both addresses):
`name` required string max 255; `street1` required string max 255; `street2` nullable
string max 255; `city` required string max 100; `state` required, in the list of 50
states + DC; `zip` required, regex `^\d{5}(-\d{4})?$`; `country` required, `in:US`;
`phone` nullable string max 20. Parcel: `weight_oz` numeric, min 0.1, max 1120 (USPS 70 lb
limit); `length_in`, `width_in`, `height_in` numeric, min 0.1.

Then `LabelController@store` → `CreateShippingLabel::handle(User $user, array $data)`:

1. `EasyPostClient::createShipment(array $shipment)` → `POST /v2/shipments` with
   `{"shipment": {"to_address": {...}, "from_address": {...}, "parcel": {"weight", "length",
   "width", "height"}, "options": {"label_format": "PDF"}}}`. Response includes `rates[]`.
2. Select the lowest USPS rate: filter `carrier === "USPS"`, minimum numeric `rate`,
   first wins on ties. None → `NoUspsRateException`.
3. `EasyPostClient::buyShipment(string $shipmentId, string $rateId)` →
   `POST /v2/shipments/{id}/buy` with `{"rate": {"id": "..."}}`. Response includes
   `postage_label.label_url`, `postage_label.label_file_type`, `tracking_code`,
   `selected_rate`.
4. `EasyPostClient::downloadLabel(string $url)` → GET, returns the PDF bytes.
5. `Storage::disk('local')->put("labels/{$user->id}/{$uuid}.pdf", $bytes)`.
6. `ShippingLabel::create([...])` with the columns from section 4.
7. Controller returns 201 with `LabelResource`.

Frontend navigates to `/labels/{id}`. "View / Print" opens `/api/labels/{id}/download`
in a new tab; the browser's PDF viewer handles printing.

### 5.3 List, show, download

- `index`: `$request->user()->labels()->latest()->paginate(15)` →
  `LabelResource::collection(...)`. Isolation comes from scoping the query through the
  authenticated user; `ShippingLabel::query()` is never used unscoped.
- `show`: route-model binding + `$this->authorize('view', $label)` → `LabelResource`.
- `download`: same authorization, then
  `Storage::disk('local')->response($label->label_file_path, "label-{$label->id}.pdf",
  ['Content-Type' => $label->label_file_type], 'inline')`.
- `ShippingLabelPolicy::view(User $user, ShippingLabel $label): bool` →
  `$label->user_id === $user->id`. Another user's label → 403; unknown id → 404;
  guest → 401.

## 6. EasyPost integration

- Transport: Laravel `Http` facade. No SDK. Three REST calls are not worth a dependency,
  and the reviewer sees the integration code directly.
- Auth: HTTP Basic with the API key as username and an empty password
  (`Http::withBasicAuth($key, '')`).
- `EasyPostClient` constructor reads `services.easypost.*` and throws a `RuntimeException`
  if the key is empty (fail fast, clear log line).
- Every call uses `timeout(15)`; the two JSON calls also use `acceptJson()`.
- Retry: up to 3 attempts (`retry(3, 200)`, i.e. two retries — Laravel's `retry($times)` counts total attempts) on
  `createShipment` and `downloadLabel` only. **No retry on `buyShipment`**: a lost response followed by a retry would
  buy the same label twice.
- Any non-2xx or transport failure becomes `EasyPostException` carrying the HTTP status,
  EasyPost's `error.message`, and the raw body.
- Label format: `options.label_format = "PDF"` at creation (4x6 label embedded in an
  8.5x11 page, print-ready). No post-purchase conversion.

## 7. Error handling

Rendering is centralized in `bootstrap/app.php` → `withExceptions(...)`:

| Source | Response |
|---|---|
| Validation failure | 422, Laravel's `{message, errors}` with dotted keys (`to_address.zip`) |
| `EasyPostException` with 4xx from EasyPost (except 401/403) | 422, `message` = EasyPost's message |
| `EasyPostException` with 401/403 from EasyPost (bad or missing key) | 502, generic message; `Log::error` (our misconfiguration, not the user's input) |
| `EasyPostException` with 5xx, timeout, connection error | 502, "Shipping provider unavailable, please try again" |
| `NoUspsRateException` | 422, "No USPS rate available for this shipment" |
| Missing API key | 500 (RuntimeException at client construction), logged |
| Download or DB insert fails after a successful buy | 502; `Log::error` with `shipment_id` and `label_url` for manual recovery; no partial row |
| Unauthenticated | 401 |
| Another user's label | 403 |
| Login throttled | 429 |

Logging: `user_id`, `shipment_id`, HTTP status. Never the API key, never full addresses
or recipient names. The raw EasyPost body lives only in the `easypost_response` column.

Frontend: `Alert` for non-field errors at the top of the form; per-field messages under
inputs; submit button disabled while a request is in flight (prevents double purchase
from double clicks).

## 8. Security and isolation

- Sanctum SPA mode: session cookie + CSRF cookie, no tokens stored in JavaScript.
- `throttle:6,1` on login.
- Every label query is scoped to the authenticated user; show/download additionally go
  through `ShippingLabelPolicy`.
- Label files live on the private `local` disk with `serve => false`; the only way to
  read one is the authenticated download route.
- EasyPost's public `label_url` is stored for debugging but never returned by the API
  (`$hidden` on the model and whitelisted `LabelResource`).
- `.env` is gitignored; `.env.example` ships `EASYPOST_API_KEY=` empty.

## 9. Testing

PHPUnit 12 (scaffold default). `RefreshDatabase` against `take_home_project_test` in the
Docker MySQL. EasyPost is always faked with `Http::fake()`; files with
`Storage::fake('local')`.

Fixtures in `tests/Fixtures/easypost/`: `shipment_created.json` (rates from USPS and a
second carrier), `shipment_bought.json` (`postage_label`, `tracking_code`,
`selected_rate`), `label.pdf` (minimal valid PDF). Hand-authored from the documented
Shipment/Rate/PostageLabel shapes with stable ids and prices the tests assert on; when a
test key is available, one real test-mode call is used to confirm the shape (keys), not
to replace the values.

Feature tests:

- `Auth/RegisterTest`: creates user and authenticates; duplicate email → 422.
- `Auth/LoginTest`: success; wrong password → 422; `GET /api/user` as guest → 401.
- `Auth/LogoutTest`: 204 and session no longer valid.
- `Labels/CreateLabelTest`: happy path (201, row fields, file exists under
  `labels/{user_id}/`, response has no `label_url`/`easypost_response`);
  `Http::assertSent` checks `options.label_format = PDF`, Basic auth, and that `buy`
  used the cheapest USPS `rate.id`; validation failures (country `BR`, bad ZIP, zero
  weight); no USPS rate → 422; EasyPost 4xx → 422 with its message; 5xx → 502; buy 500 →
  exactly one call to `/buy`; download failure → 502 and no row.
- `Labels/ListLabelsTest`: only the current user's labels, newest first, paginated.
- `Labels/ShowDownloadLabelTest`: owner 200; download is `application/pdf` inline; other
  user 403; unknown id 404; guest 401.

Unit tests: lowest-USPS-rate selection (mixed carriers, tie, empty) and `EasyPostClient`
(base URL, headers, exception on non-2xx).

Frontend: no unit tests inside the time box. Gate is `tsc --noEmit` and `npm run build`.
Code style: `vendor/bin/pint` before every commit.

## 10. Screens

| Route | Content |
|---|---|
| `/login` | email, password, link to register |
| `/register` | name, email, password, confirmation |
| `/labels` | history table: date, recipient (name, city/state), service, rate, tracking, "View / Print"; empty state with CTA; prev/next pagination; "New label" button; header with user name and logout |
| `/labels/new` | three blocks: From, To, Parcel. Address: name, street1, street2, city, state (select), zip, phone (optional), country fixed `US` (read-only). Parcel: weight (oz), length/width/height (in). Submit "Buy USPS label" with loading state; inline errors |
| `/labels/:id` | full details + "View / Print label" (opens the download route in a new tab) + back link |

Tailwind utilities only, no component library. Functional and clean; no time spent on
visual polish.

## 11. Developer workflow and README

Quick start (goes in the README):

1. `cp .env.example .env` and set `EASYPOST_API_KEY` (test key).
2. `composer install && npm install`
3. `docker compose up -d`
4. `php artisan key:generate && php artisan migrate --seed`
5. `composer run dev` → `http://localhost:8000`, log in as `demo@example.com` / `password`.

Tests: `docker compose up -d && php artisan test`.

README sections: Quick start · Architecture in ~10 lines · Assumptions · What I'd do
next · Short note on how AI tooling was used.

## 12. Out of scope / What I'd do next

- Two-step flow with rate selection (`POST /api/shipments` returns USPS rates, user
  picks the service, `POST /api/labels` buys the chosen `rate_id`).
- EasyPost Address Verification (`verify_strict: ["delivery"]`) with a confirmation step
  showing the normalized address.
- Address book (normalized `addresses` table, reuse of saved addresses).
- Reconciliation job for labels bought at EasyPost but not persisted.
- Password reset and email verification.
- Frontend tests (Vitest + React Testing Library).
- Vertical-slice organization if the domain grows (multiple carriers, billing).
