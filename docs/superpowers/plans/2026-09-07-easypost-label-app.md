# EasyPost USPS Label App Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Registered users buy USPS labels through EasyPost from a React form, the PDF is stored server-side, and each user sees only their own label history.

**Architecture:** Laravel 13 API (standard layout, Sanctum SPA session auth) with a thin `EasyPostClient` over Laravel's `Http` facade and one `CreateShippingLabel` action that orchestrates create-shipment → pick lowest USPS rate → buy → download PDF → persist. React 19 + TypeScript SPA lives in `resources/js/`, served by a Blade shell on the same origin.

**Tech Stack:** PHP 8.3, Laravel 13.30, Sanctum 4.3, MySQL 8.4 (Docker Compose), PHPUnit 12, Pint, Vite 8, React 19, TypeScript 5.9, React Router 7, axios 1, Tailwind v4.

**Spec:** `docs/superpowers/specs/2026-09-07-easypost-label-app-design.md`

## Global Constraints

- All EasyPost HTTP calls go through `App\Services\EasyPost\EasyPostClient`. Nothing else calls `Http` against EasyPost.
- Never return `label_url`, `easypost_response` or `label_file_path` in any API response.
- Never log the API key, full addresses or recipient names. Log `user_id`, `shipment_id`, HTTP status only.
- `buyShipment` is never retried. `createShipment` and `downloadLabel` retry twice (200 ms) on connection errors and 5xx only.
- Shipments are created with `options.label_format = "PDF"`.
- Addresses must have `country = US`; ZIP matches `^\d{5}(-\d{4})?$`; state is one of 50 states + DC.
- Every label query is scoped through `$user->labels()`; show/download also go through `ShippingLabelPolicy::view`.
- Frontend is TypeScript. `npm run typecheck` (`tsc --noEmit`) and `npm run build` must pass.
- Run `vendor/bin/pint --dirty` before every commit. Commit messages: English, imperative, no trailers.
- Tests need the MySQL container: `docker compose up -d` once per session. Run tests with `php artisan test`.
- Test requests to `/api/*` must carry a `Referer` header so Sanctum treats them as stateful (set once in `tests/TestCase.php`, Task 2).
- Laravel 13 model conventions already in the repo: `#[Fillable([...])]` / `#[Hidden([...])]` attributes and a `casts(): array` method (see `app/Models/User.php`). Follow them.

## File structure

Backend (create unless noted):

| File | Responsibility |
|---|---|
| `database/migrations/2026_09_07_220000_create_shipping_labels_table.php` | `shipping_labels` schema |
| `app/Models/ShippingLabel.php` | Eloquent model, hidden columns, casts, `user()` |
| `app/Models/User.php` (modify) | `labels()` relation |
| `database/factories/ShippingLabelFactory.php` | test data |
| `database/seeders/DatabaseSeeder.php` (modify) | demo user |
| `app/Http/Requests/Auth/RegisterRequest.php`, `LoginRequest.php` | auth validation |
| `app/Http/Controllers/Auth/RegisterController.php`, `LoginController.php`, `LogoutController.php` | invokable auth endpoints |
| `app/Services/EasyPost/EasyPostClient.php` | the only EasyPost HTTP gateway |
| `app/Services/EasyPost/Exceptions/EasyPostException.php` | provider failure with status/body |
| `app/Services/EasyPost/RateSelector.php` | pure lowest-USPS-rate selection |
| `app/Providers/AppServiceProvider.php` (modify) | bind `EasyPostClient` from config |
| `config/services.php` (modify), `config/filesystems.php` (modify) | EasyPost config, `serve => false` |
| `app/Support/UsStates.php` | state code list |
| `app/Http/Requests/StoreLabelRequest.php` | label input validation |
| `app/Exceptions/NoUspsRateException.php`, `LabelNotPersistedException.php` | domain failures |
| `app/Actions/CreateShippingLabel.php` | orchestration |
| `app/Http/Resources/LabelResource.php` | API shape whitelist |
| `app/Http/Controllers/LabelController.php` | index/store/show/download |
| `app/Policies/ShippingLabelPolicy.php` | ownership check |
| `bootstrap/app.php` (modify) | `statefulApi()`, exception rendering |
| `routes/api.php` (modify), `routes/web.php` (modify) | API routes, SPA catch-all |
| `tests/Concerns/FakesEasyPost.php`, `tests/Fixtures/easypost/*` | shared EasyPost fakes |

Frontend (create unless noted):

| File | Responsibility |
|---|---|
| `tsconfig.json`, `vite.config.js` (modify), `package.json` (modify) | toolchain |
| `resources/views/app.blade.php` | SPA shell (`welcome.blade.php` deleted) |
| `resources/js/app.tsx` | mount + routes (`app.js` deleted) |
| `resources/js/types.ts` | shared API types |
| `resources/js/api/client.ts`, `auth.ts`, `labels.ts` | HTTP layer |
| `resources/js/hooks/useAuth.tsx` | auth context |
| `resources/js/components/{Layout,RequireAuth,Field,Button,Alert,AddressFields}.tsx` | UI building blocks |
| `resources/js/pages/{LoginPage,RegisterPage,LabelsPage,NewLabelPage,LabelDetailPage}.tsx` | screens |

---

### Task 1: `shipping_labels` table, model, factory, demo seeder

**Files:**
- Create: `database/migrations/2026_09_07_220000_create_shipping_labels_table.php`
- Create: `app/Models/ShippingLabel.php`
- Create: `database/factories/ShippingLabelFactory.php`
- Modify: `app/Models/User.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/Models/ShippingLabelTest.php`

**Interfaces:**
- Produces: `App\Models\ShippingLabel` with columns listed in the migration; `$label->user`, `$user->labels()`; `ShippingLabel::factory()`; hidden `label_url`, `easypost_response`, `label_file_path`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Models/ShippingLabelTest.php

namespace Tests\Feature\Models;

use App\Models\ShippingLabel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShippingLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_label_owned_by_a_user(): void
    {
        $label = ShippingLabel::factory()->create();

        $this->assertDatabaseCount('shipping_labels', 1);
        $this->assertInstanceOf(User::class, $label->user);
        $this->assertTrue($label->user->labels()->whereKey($label->id)->exists());
    }

    public function test_sensitive_columns_are_hidden_from_serialization(): void
    {
        $label = ShippingLabel::factory()->create();

        $array = $label->toArray();

        $this->assertArrayNotHasKey('label_url', $array);
        $this->assertArrayNotHasKey('easypost_response', $array);
        $this->assertArrayNotHasKey('label_file_path', $array);
        $this->assertArrayHasKey('tracking_code', $array);
    }

    public function test_json_columns_are_cast_to_arrays(): void
    {
        $label = ShippingLabel::factory()->create([
            'parcel' => ['weight_oz' => 16, 'length_in' => 10, 'width_in' => 8, 'height_in' => 4],
        ]);

        $fresh = $label->fresh();

        $this->assertIsArray($fresh->from_address);
        $this->assertIsArray($fresh->to_address);
        $this->assertSame(16, $fresh->parcel['weight_oz']);
        $this->assertIsArray($fresh->easypost_response);
        $this->assertSame('7.33', $fresh->rate);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ShippingLabelTest`
Expected: FAIL with `Class "App\Models\ShippingLabel" not found`.

- [ ] **Step 3: Create the migration**

```php
<?php
// database/migrations/2026_09_07_220000_create_shipping_labels_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('easypost_shipment_id')->unique();
            $table->string('tracking_code')->nullable()->index();
            $table->string('carrier');
            $table->string('service');
            $table->decimal('rate', 8, 2);
            $table->char('currency', 3);
            $table->json('from_address');
            $table->json('to_address');
            $table->json('parcel');
            $table->string('label_file_path');
            $table->string('label_file_type');
            $table->string('label_url');
            $table->json('easypost_response');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_labels');
    }
};
```

- [ ] **Step 4: Create the model**

```php
<?php
// app/Models/ShippingLabel.php

namespace App\Models;

use Database\Factories\ShippingLabelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'easypost_shipment_id',
    'tracking_code',
    'carrier',
    'service',
    'rate',
    'currency',
    'from_address',
    'to_address',
    'parcel',
    'label_file_path',
    'label_file_type',
    'label_url',
    'easypost_response',
])]
#[Hidden(['label_url', 'easypost_response', 'label_file_path'])]
class ShippingLabel extends Model
{
    /** @use HasFactory<ShippingLabelFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'from_address' => 'array',
            'to_address' => 'array',
            'parcel' => 'array',
            'easypost_response' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 5: Add the inverse relation to `User`**

In `app/Models/User.php`, add the import `use Illuminate\Database\Eloquent\Relations\HasMany;` and this method after `casts()`:

```php
    public function labels(): HasMany
    {
        return $this->hasMany(ShippingLabel::class);
    }
```

- [ ] **Step 6: Create the factory**

```php
<?php
// database/factories/ShippingLabelFactory.php

namespace Database\Factories;

use App\Models\ShippingLabel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ShippingLabel>
 */
class ShippingLabelFactory extends Factory
{
    public function definition(): array
    {
        $shipmentId = 'shp_'.Str::lower(Str::random(32));

        return [
            'user_id' => User::factory(),
            'easypost_shipment_id' => $shipmentId,
            'tracking_code' => '9400100000000000000'.fake()->numerify('###'),
            'carrier' => 'USPS',
            'service' => 'GroundAdvantage',
            'rate' => '7.33',
            'currency' => 'USD',
            'from_address' => $this->address('CA', '94107'),
            'to_address' => $this->address('NY', '10001'),
            'parcel' => ['weight_oz' => 16, 'length_in' => 10, 'width_in' => 8, 'height_in' => 4],
            'label_file_path' => 'labels/1/'.Str::uuid().'.pdf',
            'label_file_type' => 'application/pdf',
            'label_url' => 'https://easypost-files.s3.us-west-2.amazonaws.com/files/postage_label/'.$shipmentId.'.pdf',
            'easypost_response' => ['id' => $shipmentId, 'object' => 'Shipment', 'mode' => 'test'],
        ];
    }

    private function address(string $state, string $zip): array
    {
        return [
            'name' => fake()->name(),
            'street1' => fake()->streetAddress(),
            'street2' => null,
            'city' => fake()->city(),
            'state' => $state,
            'zip' => $zip,
            'country' => 'US',
            'phone' => null,
        ];
    }
}
```

- [ ] **Step 7: Replace the seeder with the demo user**

```php
<?php
// database/seeders/DatabaseSeeder.php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::factory()->create([
            'name' => 'Demo User',
            'email' => 'demo@example.com',
        ]);
    }
}
```

`UserFactory` already hashes the password `password`, so no override is needed.

- [ ] **Step 8: Run the test to verify it passes**

Run: `php artisan test --filter=ShippingLabelTest`
Expected: PASS (3 tests).

- [ ] **Step 9: Migrate the dev database and seed**

Run: `php artisan migrate && php artisan db:seed`
Expected: `create_shipping_labels_table ... DONE`, seeder finishes without error.

- [ ] **Step 10: Commit**

```bash
vendor/bin/pint --dirty
git add database app/Models tests/Feature/Models
git commit -m "Add shipping_labels table, model and demo seeder"
```

---

### Task 2: Auth endpoints (register, login, logout) in Sanctum SPA mode

**Files:**
- Modify: `bootstrap/app.php`
- Modify: `tests/TestCase.php`
- Create: `app/Http/Requests/Auth/RegisterRequest.php`
- Create: `app/Http/Requests/Auth/LoginRequest.php`
- Create: `app/Http/Controllers/Auth/RegisterController.php`
- Create: `app/Http/Controllers/Auth/LoginController.php`
- Create: `app/Http/Controllers/Auth/LogoutController.php`
- Modify: `routes/api.php`
- Modify: `.env.example`, `.env`
- Test: `tests/Feature/Auth/RegisterTest.php`, `tests/Feature/Auth/LoginTest.php`, `tests/Feature/Auth/LogoutTest.php`

**Interfaces:**
- Produces: `POST /api/register` → 201 user JSON; `POST /api/login` → 200 user JSON, 422 on bad credentials, throttled 6/min; `POST /api/logout` → 204; existing `GET /api/user` → 200/401. All `/api/*` requests are stateful (session cookie) when they carry a `Referer`/`Origin` from a stateful domain.

- [ ] **Step 1: Make test requests stateful**

Replace `tests/TestCase.php`:

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum only starts a session for requests coming from a stateful frontend.
        $this->withHeader('Referer', config('app.url'));
    }
}
```

- [ ] **Step 2: Write the failing tests**

```php
<?php
// tests/Feature/Auth/RegisterTest.php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_can_register_and_is_logged_in(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ]);

        $response->assertCreated()
            ->assertJsonPath('email', 'ada@example.com')
            ->assertJsonMissingPath('password');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_password_confirmation_is_required(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
    }
}
```

```php
<?php
// tests/Feature/Auth/LoginTest.php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('id', $user->id);

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'wrong',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);

        $this->assertGuest();
    }

    public function test_guest_cannot_read_current_user(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_authenticated_user_can_read_current_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/user')->assertOk()->assertJsonPath('email', $user->email);
    }
}
```

```php
<?php
// tests/Feature/Auth/LogoutTest.php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_ends_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/logout')->assertNoContent();

        $this->assertGuest();
    }

    public function test_guest_cannot_logout(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --filter=Auth`
Expected: FAIL with 404s on `/api/register`, `/api/login`, `/api/logout`.

- [ ] **Step 4: Enable Sanctum stateful API middleware**

In `bootstrap/app.php`, replace the empty `withMiddleware` closure body:

```php
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
    })
```

- [ ] **Step 5: Create the form requests**

```php
<?php
// app/Http/Requests/Auth/RegisterRequest.php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
```

```php
<?php
// app/Http/Requests/Auth/LoginRequest.php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
```

- [ ] **Step 6: Create the controllers**

```php
<?php
// app/Http/Controllers/Auth/RegisterController.php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json($user, 201);
    }
}
```

```php
<?php
// app/Http/Controllers/Auth/LoginController.php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __invoke(LoginRequest $request): JsonResponse
    {
        if (! Auth::attempt($request->validated())) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        return response()->json($request->user());
    }
}
```

```php
<?php
// app/Http/Controllers/Auth/LogoutController.php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class LogoutController extends Controller
{
    public function __invoke(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
```

- [ ] **Step 7: Register the routes**

Replace `routes/api.php`:

```php
<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', RegisterController::class);
Route::post('/login', LoginController::class)->middleware('throttle:6,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', LogoutController::class);

    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});
```

- [ ] **Step 8: Document the stateful domains**

Append to both `.env.example` and `.env` (after `EASYPOST_API_KEY=`):

```
SANCTUM_STATEFUL_DOMAINS=localhost:8000,127.0.0.1:8000
```

- [ ] **Step 9: Run tests to verify they pass**

Run: `php artisan test --filter=Auth`
Expected: PASS (9 tests).

- [ ] **Step 10: Commit**

```bash
vendor/bin/pint --dirty
git add bootstrap/app.php tests/TestCase.php app/Http routes/api.php .env.example tests/Feature/Auth
git commit -m "Add Sanctum SPA auth endpoints"
```

---

### Task 3: `EasyPostClient` and `EasyPostException`

**Files:**
- Modify: `config/services.php`
- Modify: `config/filesystems.php`
- Create: `app/Services/EasyPost/Exceptions/EasyPostException.php`
- Create: `app/Services/EasyPost/EasyPostClient.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Unit/EasyPostClientTest.php`

**Interfaces:**
- Produces:
  - `EasyPostClient::createShipment(array $shipment): array` → decoded Shipment JSON.
  - `EasyPostClient::buyShipment(string $shipmentId, string $rateId): array` → decoded Shipment JSON (with `postage_label`).
  - `EasyPostClient::downloadLabel(string $url): string` → raw file bytes.
  - `EasyPostException` (extends `RuntimeException`) with `public readonly ?int $status`, `public readonly ?array $body`, `isClientError(): bool` (4xx except 401/403).
  - Container binding: `app(EasyPostClient::class)` built from `config('services.easypost.*')`; throws `RuntimeException` when the key is empty.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Unit/EasyPostClientTest.php

namespace Tests\Unit;

use App\Services\EasyPost\EasyPostClient;
use App\Services\EasyPost\Exceptions\EasyPostException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class EasyPostClientTest extends TestCase
{
    private function client(): EasyPostClient
    {
        return new EasyPostClient(apiKey: 'EZTK_test_key', baseUrl: 'https://api.easypost.com/v2', timeout: 15);
    }

    public function test_create_shipment_posts_to_shipments_with_basic_auth(): void
    {
        Http::fake(['api.easypost.com/v2/shipments' => Http::response(['id' => 'shp_1', 'rates' => []])]);

        $result = $this->client()->createShipment(['parcel' => ['weight' => 16]]);

        $this->assertSame('shp_1', $result['id']);
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.easypost.com/v2/shipments'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('EZTK_test_key:'))
                && $request->hasHeader('Accept', 'application/json')
                && $request['shipment']['parcel']['weight'] === 16;
        });
    }

    public function test_buy_shipment_posts_rate_id_to_buy_endpoint(): void
    {
        Http::fake(['api.easypost.com/v2/shipments/shp_1/buy' => Http::response(['id' => 'shp_1', 'tracking_code' => '9400'])]);

        $result = $this->client()->buyShipment('shp_1', 'rate_1');

        $this->assertSame('9400', $result['tracking_code']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.easypost.com/v2/shipments/shp_1/buy'
            && $request['rate']['id'] === 'rate_1');
    }

    public function test_download_label_returns_raw_body_without_easypost_credentials(): void
    {
        Http::fake(['files.example.com/*' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf'])]);

        $bytes = $this->client()->downloadLabel('https://files.example.com/label.pdf');

        $this->assertSame('%PDF-1.4', $bytes);
        Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
    }

    public function test_client_error_is_wrapped_with_easypost_message(): void
    {
        Http::fake(['api.easypost.com/v2/shipments' => Http::response([
            'error' => ['code' => 'ADDRESS.VERIFY.FAILURE', 'message' => 'Unable to verify address.', 'errors' => []],
        ], 422)]);

        try {
            $this->client()->createShipment([]);
            $this->fail('Expected EasyPostException');
        } catch (EasyPostException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('Unable to verify address.', $e->getMessage());
            $this->assertTrue($e->isClientError());
        }

        Http::assertSentCount(1);
    }

    public function test_unauthorized_is_not_treated_as_a_client_error(): void
    {
        Http::fake(['api.easypost.com/v2/shipments' => Http::response(['error' => ['message' => 'Invalid API key']], 401)]);

        try {
            $this->client()->createShipment([]);
            $this->fail('Expected EasyPostException');
        } catch (EasyPostException $e) {
            $this->assertSame(401, $e->status);
            $this->assertFalse($e->isClientError());
        }
    }

    public function test_server_error_on_create_is_retried_twice_then_wrapped(): void
    {
        Http::fake(['api.easypost.com/v2/shipments' => Http::response(['error' => ['message' => 'boom']], 500)]);

        try {
            $this->client()->createShipment([]);
            $this->fail('Expected EasyPostException');
        } catch (EasyPostException $e) {
            $this->assertSame(500, $e->status);
            $this->assertFalse($e->isClientError());
        }

        Http::assertSentCount(3);
    }

    public function test_server_error_on_buy_is_never_retried(): void
    {
        Http::fake(['api.easypost.com/v2/shipments/shp_1/buy' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $this->expectException(EasyPostException::class);

        try {
            $this->client()->buyShipment('shp_1', 'rate_1');
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_connection_failure_is_wrapped(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        try {
            $this->client()->createShipment([]);
            $this->fail('Expected EasyPostException');
        } catch (EasyPostException $e) {
            $this->assertNull($e->status);
            $this->assertFalse($e->isClientError());
        }
    }

    public function test_container_builds_client_from_config(): void
    {
        config(['services.easypost.key' => 'EZTK_from_config']);

        $this->assertInstanceOf(EasyPostClient::class, app(EasyPostClient::class));
    }

    public function test_empty_api_key_fails_fast(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EASYPOST_API_KEY');

        new EasyPostClient(apiKey: '', baseUrl: 'https://api.easypost.com/v2', timeout: 15);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=EasyPostClientTest`
Expected: FAIL with `Class "App\Services\EasyPost\EasyPostClient" not found`.

- [ ] **Step 3: Add config**

In `config/services.php`, the `easypost` block already exists with `key`. Replace it with:

```php
    'easypost' => [
        'key' => env('EASYPOST_API_KEY'),
        'base_url' => env('EASYPOST_BASE_URL', 'https://api.easypost.com/v2'),
        'timeout' => (int) env('EASYPOST_TIMEOUT', 15),
    ],
```

In `config/filesystems.php`, inside the `'local'` disk array, change `'serve' => true,` to `'serve' => false,` (removes the `/storage/{path}` routes; label files must only be reachable through the authenticated download route).

- [ ] **Step 4: Create the exception**

```php
<?php
// app/Services/EasyPost/Exceptions/EasyPostException.php

namespace App\Services\EasyPost\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

class EasyPostException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?array $body = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $body = $response->json();
        $message = is_array($body) ? data_get($body, 'error.message') : null;

        return new self(
            is_string($message) && $message !== '' ? $message : 'EasyPost request failed.',
            $response->status(),
            is_array($body) ? $body : null,
        );
    }

    public static function transport(Throwable $previous): self
    {
        return new self('Could not reach EasyPost.', null, null, $previous);
    }

    /**
     * 4xx caused by the request payload. 401/403 mean our key is wrong, not the user's input.
     */
    public function isClientError(): bool
    {
        return $this->status !== null
            && $this->status >= 400
            && $this->status < 500
            && ! in_array($this->status, [401, 403], true);
    }
}
```

- [ ] **Step 5: Create the client**

```php
<?php
// app/Services/EasyPost/EasyPostClient.php

namespace App\Services\EasyPost;

use App\Services\EasyPost\Exceptions\EasyPostException;
use Closure;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EasyPostClient
{
    private const RETRY_TIMES = 2;

    private const RETRY_SLEEP_MS = 200;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeout,
    ) {
        if ($this->apiKey === '') {
            throw new RuntimeException('EASYPOST_API_KEY is not set.');
        }
    }

    /**
     * @param  array<string, mixed>  $shipment  to_address, from_address, parcel, options
     * @return array<string, mixed> EasyPost Shipment object
     */
    public function createShipment(array $shipment): array
    {
        return $this->send(fn () => $this->api()
            ->retry(self::RETRY_TIMES, self::RETRY_SLEEP_MS, $this->retryWhen(), throw: false)
            ->post('/shipments', ['shipment' => $shipment])
        )->json();
    }

    /**
     * Never retried: a lost response followed by a retry would buy the label twice.
     *
     * @return array<string, mixed> EasyPost Shipment object with postage_label
     */
    public function buyShipment(string $shipmentId, string $rateId): array
    {
        return $this->send(fn () => $this->api()
            ->post("/shipments/{$shipmentId}/buy", ['rate' => ['id' => $rateId]])
        )->json();
    }

    /**
     * The label URL points at EasyPost's file storage, not the API, so no credentials are sent.
     */
    public function downloadLabel(string $url): string
    {
        return $this->send(fn () => Http::timeout($this->timeout)
            ->retry(self::RETRY_TIMES, self::RETRY_SLEEP_MS, $this->retryWhen(), throw: false)
            ->get($url)
        )->body();
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withBasicAuth($this->apiKey, '')
            ->acceptJson()
            ->timeout($this->timeout);
    }

    private function retryWhen(): Closure
    {
        return fn (Exception $exception): bool => $exception instanceof ConnectionException
            || ($exception instanceof RequestException && $exception->response->serverError());
    }

    /**
     * @param  Closure(): Response  $request
     */
    private function send(Closure $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw EasyPostException::transport($e);
        }

        if ($response->failed()) {
            throw EasyPostException::fromResponse($response);
        }

        return $response;
    }
}
```

- [ ] **Step 6: Bind the client in the container**

In `app/Providers/AppServiceProvider.php`, add `use App\Services\EasyPost\EasyPostClient;` and replace the `register()` body:

```php
    public function register(): void
    {
        $this->app->singleton(EasyPostClient::class, fn () => new EasyPostClient(
            apiKey: (string) config('services.easypost.key', ''),
            baseUrl: (string) config('services.easypost.base_url'),
            timeout: (int) config('services.easypost.timeout'),
        ));
    }
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=EasyPostClientTest`
Expected: PASS (10 tests). The retry tests sleep 200 ms twice; total runtime under 2 s.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add config app/Services app/Providers tests/Unit/EasyPostClientTest.php
git commit -m "Add EasyPost HTTP client"
```

---

### Task 4: `RateSelector` (lowest USPS rate)

**Files:**
- Create: `app/Services/EasyPost/RateSelector.php`
- Test: `tests/Unit/RateSelectorTest.php`

**Interfaces:**
- Produces: `RateSelector::lowestUsps(array $rates): ?array` — `$rates` is the EasyPost `rates[]` array (each with `carrier`, `service`, `rate` as string, `id`); returns the cheapest rate whose `carrier === 'USPS'`, first one on ties, `null` when none.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/RateSelectorTest.php

namespace Tests\Unit;

use App\Services\EasyPost\RateSelector;
use PHPUnit\Framework\TestCase;

class RateSelectorTest extends TestCase
{
    public function test_picks_the_cheapest_usps_rate_and_ignores_other_carriers(): void
    {
        $rates = [
            ['id' => 'rate_ups', 'carrier' => 'UPSDAP', 'service' => 'Ground', 'rate' => '6.10'],
            ['id' => 'rate_priority', 'carrier' => 'USPS', 'service' => 'Priority', 'rate' => '9.83'],
            ['id' => 'rate_ground', 'carrier' => 'USPS', 'service' => 'GroundAdvantage', 'rate' => '7.33'],
            ['id' => 'rate_express', 'carrier' => 'USPS', 'service' => 'Express', 'rate' => '31.40'],
        ];

        $this->assertSame('rate_ground', RateSelector::lowestUsps($rates)['id']);
    }

    public function test_compares_rates_numerically_not_as_strings(): void
    {
        $rates = [
            ['id' => 'rate_a', 'carrier' => 'USPS', 'service' => 'A', 'rate' => '10.00'],
            ['id' => 'rate_b', 'carrier' => 'USPS', 'service' => 'B', 'rate' => '9.50'],
        ];

        $this->assertSame('rate_b', RateSelector::lowestUsps($rates)['id']);
    }

    public function test_first_rate_wins_on_ties(): void
    {
        $rates = [
            ['id' => 'rate_first', 'carrier' => 'USPS', 'service' => 'A', 'rate' => '7.33'],
            ['id' => 'rate_second', 'carrier' => 'USPS', 'service' => 'B', 'rate' => '7.33'],
        ];

        $this->assertSame('rate_first', RateSelector::lowestUsps($rates)['id']);
    }

    public function test_returns_null_when_there_is_no_usps_rate(): void
    {
        $rates = [
            ['id' => 'rate_ups', 'carrier' => 'UPSDAP', 'service' => 'Ground', 'rate' => '6.10'],
        ];

        $this->assertNull(RateSelector::lowestUsps($rates));
    }

    public function test_returns_null_for_empty_list(): void
    {
        $this->assertNull(RateSelector::lowestUsps([]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RateSelectorTest`
Expected: FAIL with `Class "App\Services\EasyPost\RateSelector" not found`.

- [ ] **Step 3: Implement**

```php
<?php
// app/Services/EasyPost/RateSelector.php

namespace App\Services\EasyPost;

class RateSelector
{
    /**
     * @param  array<int, array<string, mixed>>  $rates  EasyPost Shipment rates[]
     * @return array<string, mixed>|null
     */
    public static function lowestUsps(array $rates): ?array
    {
        $usps = array_values(array_filter(
            $rates,
            fn (array $rate): bool => ($rate['carrier'] ?? null) === 'USPS' && isset($rate['rate']),
        ));

        if ($usps === []) {
            return null;
        }

        // usort is stable since PHP 8.0, so the first rate wins on ties.
        usort($usps, fn (array $a, array $b): int => (float) $a['rate'] <=> (float) $b['rate']);

        return $usps[0];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=RateSelectorTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/EasyPost/RateSelector.php tests/Unit/RateSelectorTest.php
git commit -m "Add lowest USPS rate selector"
```

---

### Task 5: Create label — validation, action, resource, `POST /api/labels` (happy path)

**Files:**
- Create: `tests/Fixtures/easypost/shipment_created.json`, `shipment_bought.json`, `label.pdf`
- Create: `tests/Concerns/FakesEasyPost.php`
- Create: `app/Support/UsStates.php`
- Create: `app/Http/Requests/StoreLabelRequest.php`
- Create: `app/Exceptions/NoUspsRateException.php`
- Create: `app/Exceptions/LabelNotPersistedException.php`
- Create: `app/Actions/CreateShippingLabel.php`
- Create: `app/Http/Resources/LabelResource.php`
- Create: `app/Http/Controllers/LabelController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Labels/CreateLabelTest.php`

**Interfaces:**
- Consumes: `EasyPostClient` (Task 3), `RateSelector::lowestUsps` (Task 4), `ShippingLabel`/`User::labels()` (Task 1).
- Produces:
  - `CreateShippingLabel::handle(User $user, array $data): ShippingLabel` where `$data` is `StoreLabelRequest::validated()` (`from_address`, `to_address`, `parcel`).
  - `LabelResource` JSON: `id, carrier, service, rate, currency, tracking_code, from_address, to_address, parcel, created_at`.
  - `POST /api/labels` → 201 `{data: LabelResource}`.
  - `NoUspsRateException`, `LabelNotPersistedException` (rendered in Task 6).
  - Test trait `Tests\Concerns\FakesEasyPost`: `fakeEasyPost(array $overrides = [])`, `easyPostFixture(string $file): array`, `fixturePath(string $file): string`, constants `EASYPOST_CREATE`, `EASYPOST_BUY`, `EASYPOST_LABEL` (fake URL patterns).

- [ ] **Step 1: Create the fixtures**

`tests/Fixtures/easypost/shipment_created.json`:

```json
{
  "id": "shp_test0001",
  "object": "Shipment",
  "mode": "test",
  "status": "unknown",
  "to_address": {
    "id": "adr_to0001", "object": "Address", "name": "Grace Hopper", "street1": "350 5th Ave", "street2": null,
    "city": "New York", "state": "NY", "zip": "10118", "country": "US", "phone": null
  },
  "from_address": {
    "id": "adr_from0001", "object": "Address", "name": "Ada Lovelace", "street1": "417 Montgomery St", "street2": "Floor 5",
    "city": "San Francisco", "state": "CA", "zip": "94104", "country": "US", "phone": null
  },
  "parcel": { "id": "prcl_test0001", "object": "Parcel", "length": 10.0, "width": 8.0, "height": 4.0, "weight": 16.0 },
  "options": { "label_format": "PDF", "currency": "USD" },
  "rates": [
    { "id": "rate_ups_ground", "object": "Rate", "carrier": "UPSDAP", "service": "Ground", "rate": "6.10", "currency": "USD", "delivery_days": 3, "shipment_id": "shp_test0001" },
    { "id": "rate_usps_priority", "object": "Rate", "carrier": "USPS", "service": "Priority", "rate": "9.83", "currency": "USD", "delivery_days": 2, "shipment_id": "shp_test0001" },
    { "id": "rate_usps_ground", "object": "Rate", "carrier": "USPS", "service": "GroundAdvantage", "rate": "7.33", "currency": "USD", "delivery_days": 4, "shipment_id": "shp_test0001" },
    { "id": "rate_usps_express", "object": "Rate", "carrier": "USPS", "service": "Express", "rate": "31.40", "currency": "USD", "delivery_days": 1, "shipment_id": "shp_test0001" }
  ],
  "selected_rate": null,
  "postage_label": null,
  "tracking_code": null,
  "created_at": "2026-09-07T20:00:00Z",
  "updated_at": "2026-09-07T20:00:00Z"
}
```

`tests/Fixtures/easypost/shipment_bought.json`:

```json
{
  "id": "shp_test0001",
  "object": "Shipment",
  "mode": "test",
  "status": "unknown",
  "to_address": {
    "id": "adr_to0001", "object": "Address", "name": "Grace Hopper", "street1": "350 5th Ave", "street2": null,
    "city": "New York", "state": "NY", "zip": "10118", "country": "US", "phone": null
  },
  "from_address": {
    "id": "adr_from0001", "object": "Address", "name": "Ada Lovelace", "street1": "417 Montgomery St", "street2": "Floor 5",
    "city": "San Francisco", "state": "CA", "zip": "94104", "country": "US", "phone": null
  },
  "parcel": { "id": "prcl_test0001", "object": "Parcel", "length": 10.0, "width": 8.0, "height": 4.0, "weight": 16.0 },
  "options": { "label_format": "PDF", "currency": "USD" },
  "rates": [
    { "id": "rate_ups_ground", "object": "Rate", "carrier": "UPSDAP", "service": "Ground", "rate": "6.10", "currency": "USD", "delivery_days": 3, "shipment_id": "shp_test0001" },
    { "id": "rate_usps_priority", "object": "Rate", "carrier": "USPS", "service": "Priority", "rate": "9.83", "currency": "USD", "delivery_days": 2, "shipment_id": "shp_test0001" },
    { "id": "rate_usps_ground", "object": "Rate", "carrier": "USPS", "service": "GroundAdvantage", "rate": "7.33", "currency": "USD", "delivery_days": 4, "shipment_id": "shp_test0001" },
    { "id": "rate_usps_express", "object": "Rate", "carrier": "USPS", "service": "Express", "rate": "31.40", "currency": "USD", "delivery_days": 1, "shipment_id": "shp_test0001" }
  ],
  "selected_rate": { "id": "rate_usps_ground", "object": "Rate", "carrier": "USPS", "service": "GroundAdvantage", "rate": "7.33", "currency": "USD", "delivery_days": 4, "shipment_id": "shp_test0001" },
  "postage_label": {
    "id": "pl_test0001",
    "object": "PostageLabel",
    "label_date": "2026-09-07T20:00:05Z",
    "label_resolution": 300,
    "label_size": "4x6",
    "label_type": "default",
    "label_file_type": "application/pdf",
    "label_url": "https://easypost-files.s3.us-west-2.amazonaws.com/files/postage_label/20260907/shp_test0001.pdf",
    "label_pdf_url": null,
    "label_zpl_url": null,
    "label_epl2_url": null
  },
  "tracking_code": "9400100000000000000012",
  "created_at": "2026-09-07T20:00:00Z",
  "updated_at": "2026-09-07T20:00:05Z"
}
```

`tests/Fixtures/easypost/label.pdf` — create with:

```bash
printf '%%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 288 432]>>endobj\ntrailer<</Root 1 0 R>>\n%%%%EOF\n' > tests/Fixtures/easypost/label.pdf
```

These fixtures follow the documented EasyPost Shipment/Rate/PostageLabel shapes. If a real test key is available, Task 12 replaces them with captured responses.

- [ ] **Step 2: Create the shared fake trait**

```php
<?php
// tests/Concerns/FakesEasyPost.php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;

trait FakesEasyPost
{
    protected const EASYPOST_CREATE = 'api.easypost.com/v2/shipments';

    protected const EASYPOST_BUY = 'api.easypost.com/v2/shipments/*/buy';

    protected const EASYPOST_LABEL = 'easypost-files.s3.us-west-2.amazonaws.com/*';

    protected function fixturePath(string $file): string
    {
        return base_path("tests/Fixtures/easypost/{$file}");
    }

    /**
     * @return array<string, mixed>
     */
    protected function easyPostFixture(string $file): array
    {
        return json_decode(file_get_contents($this->fixturePath($file)), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Happy-path fakes for create, buy and label download. Pass overrides keyed by the
     * EASYPOST_* constants to replace a step (e.g. a 500 on buy).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function fakeEasyPost(array $overrides = []): void
    {
        Http::preventStrayRequests();

        Http::fake($overrides + [
            self::EASYPOST_CREATE => Http::response($this->easyPostFixture('shipment_created.json')),
            self::EASYPOST_BUY => Http::response($this->easyPostFixture('shipment_bought.json')),
            self::EASYPOST_LABEL => Http::response(
                file_get_contents($this->fixturePath('label.pdf')),
                200,
                ['Content-Type' => 'application/pdf'],
            ),
        ]);
    }
}
```

- [ ] **Step 3: Write the failing tests**

```php
<?php
// tests/Feature/Labels/CreateLabelTest.php

namespace Tests\Feature\Labels;

use App\Models\ShippingLabel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesEasyPost;
use Tests\TestCase;

class CreateLabelTest extends TestCase
{
    use FakesEasyPost;
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'from_address' => [
                'name' => 'Ada Lovelace',
                'street1' => '417 Montgomery St',
                'street2' => 'Floor 5',
                'city' => 'San Francisco',
                'state' => 'CA',
                'zip' => '94104',
                'country' => 'US',
                'phone' => null,
            ],
            'to_address' => [
                'name' => 'Grace Hopper',
                'street1' => '350 5th Ave',
                'street2' => null,
                'city' => 'New York',
                'state' => 'NY',
                'zip' => '10118',
                'country' => 'US',
                'phone' => null,
            ],
            'parcel' => [
                'weight_oz' => 16,
                'length_in' => 10,
                'width_in' => 8,
                'height_in' => 4,
            ],
        ], $overrides);
    }

    public function test_user_buys_the_cheapest_usps_label_and_it_is_stored(): void
    {
        Storage::fake('local');
        $this->fakeEasyPost();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/labels', $this->validPayload());

        $response->assertCreated()
            ->assertJsonPath('data.carrier', 'USPS')
            ->assertJsonPath('data.service', 'GroundAdvantage')
            ->assertJsonPath('data.rate', '7.33')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.tracking_code', '9400100000000000000012')
            ->assertJsonPath('data.to_address.city', 'New York')
            ->assertJsonPath('data.parcel.weight_oz', 16)
            ->assertJsonMissingPath('data.label_url')
            ->assertJsonMissingPath('data.easypost_response')
            ->assertJsonMissingPath('data.label_file_path');

        $this->assertStringNotContainsString('amazonaws', $response->getContent());

        $label = ShippingLabel::sole();
        $this->assertSame($user->id, $label->user_id);
        $this->assertSame('shp_test0001', $label->easypost_shipment_id);
        $this->assertSame('application/pdf', $label->label_file_type);
        $this->assertStringStartsWith("labels/{$user->id}/", $label->label_file_path);
        $this->assertStringEndsWith('.pdf', $label->label_file_path);
        Storage::disk('local')->assertExists($label->label_file_path);
        $this->assertSame('shp_test0001', $label->easypost_response['id']);
    }

    public function test_shipment_is_created_as_pdf_and_the_cheapest_usps_rate_is_bought(): void
    {
        Storage::fake('local');
        $this->fakeEasyPost();

        $this->actingAs(User::factory()->create())->postJson('/api/labels', $this->validPayload())->assertCreated();

        Http::assertSent(function (Request $request) {
            return str_ends_with($request->url(), '/v2/shipments')
                && $request['shipment']['options']['label_format'] === 'PDF'
                && $request['shipment']['parcel'] === ['weight' => 16, 'length' => 10, 'width' => 8, 'height' => 4]
                && $request['shipment']['to_address']['zip'] === '10118'
                && $request['shipment']['from_address']['country'] === 'US';
        });

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/shipments/shp_test0001/buy')
            && $request['rate']['id'] === 'rate_usps_ground');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://easypost-files.s3.us-west-2.amazonaws.com/files/postage_label/20260907/shp_test0001.pdf');

        Http::assertSentCount(3);
    }

    public function test_guest_cannot_create_labels(): void
    {
        $this->fakeEasyPost();

        $this->postJson('/api/labels', $this->validPayload())->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_non_us_destination_is_rejected_before_calling_easypost(): void
    {
        $this->fakeEasyPost();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/labels', $this->validPayload(['to_address' => ['country' => 'BR']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to_address.country']);

        Http::assertNothingSent();
    }

    public function test_invalid_zip_and_state_are_rejected(): void
    {
        $this->fakeEasyPost();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/labels', $this->validPayload([
                'to_address' => ['zip' => 'ABCDE'],
                'from_address' => ['state' => 'XX'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to_address.zip', 'from_address.state']);

        Http::assertNothingSent();
    }

    public function test_zip_plus_four_is_accepted(): void
    {
        Storage::fake('local');
        $this->fakeEasyPost();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/labels', $this->validPayload(['to_address' => ['zip' => '10118-0110']]))
            ->assertCreated();
    }

    public function test_parcel_must_have_positive_dimensions_and_weight(): void
    {
        $this->fakeEasyPost();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/labels', $this->validPayload(['parcel' => ['weight_oz' => 0, 'length_in' => -1]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parcel.weight_oz', 'parcel.length_in']);

        Http::assertNothingSent();
    }

    public function test_required_fields_are_enforced(): void
    {
        $this->fakeEasyPost();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/labels', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'from_address.name', 'from_address.street1', 'from_address.city', 'from_address.state', 'from_address.zip', 'from_address.country',
                'to_address.name', 'to_address.street1', 'to_address.city', 'to_address.state', 'to_address.zip', 'to_address.country',
                'parcel.weight_oz', 'parcel.length_in', 'parcel.width_in', 'parcel.height_in',
            ]);

        Http::assertNothingSent();
    }
}
```

- [ ] **Step 4: Run tests to verify they fail**

Run: `php artisan test --filter=CreateLabelTest`
Expected: FAIL — 404 on `POST /api/labels` (or 405).

- [ ] **Step 5: Create the US state list**

```php
<?php
// app/Support/UsStates.php

namespace App\Support;

class UsStates
{
    /** @var list<string> 50 states + District of Columbia */
    public const CODES = [
        'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL',
        'GA', 'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME',
        'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH',
        'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI',
        'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI',
        'WY',
    ];
}
```

- [ ] **Step 6: Create the form request**

```php
<?php
// app/Http/Requests/StoreLabelRequest.php

namespace App\Http\Requests;

use App\Support\UsStates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLabelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...$this->addressRules('from_address'),
            ...$this->addressRules('to_address'),
            'parcel' => ['required', 'array'],
            'parcel.weight_oz' => ['required', 'numeric', 'min:0.1', 'max:1120'], // USPS 70 lb limit
            'parcel.length_in' => ['required', 'numeric', 'min:0.1'],
            'parcel.width_in' => ['required', 'numeric', 'min:0.1'],
            'parcel.height_in' => ['required', 'numeric', 'min:0.1'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function addressRules(string $prefix): array
    {
        return [
            $prefix => ['required', 'array'],
            "{$prefix}.name" => ['required', 'string', 'max:255'],
            "{$prefix}.street1" => ['required', 'string', 'max:255'],
            "{$prefix}.street2" => ['nullable', 'string', 'max:255'],
            "{$prefix}.city" => ['required', 'string', 'max:100'],
            "{$prefix}.state" => ['required', 'string', Rule::in(UsStates::CODES)],
            "{$prefix}.zip" => ['required', 'string', 'regex:/^\d{5}(-\d{4})?$/'],
            "{$prefix}.country" => ['required', 'string', Rule::in(['US'])],
            "{$prefix}.phone" => ['nullable', 'string', 'max:20'],
        ];
    }
}
```

- [ ] **Step 7: Create the domain exceptions**

```php
<?php
// app/Exceptions/NoUspsRateException.php

namespace App\Exceptions;

use RuntimeException;

class NoUspsRateException extends RuntimeException
{
    public function __construct(public readonly string $shipmentId)
    {
        parent::__construct("EasyPost returned no USPS rate for shipment {$shipmentId}.");
    }
}
```

```php
<?php
// app/Exceptions/LabelNotPersistedException.php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The rate was bought at EasyPost but the label could not be downloaded or saved.
 * The shipment id and label URL are logged so the label can be recovered manually.
 */
class LabelNotPersistedException extends RuntimeException
{
    public function __construct(public readonly string $shipmentId, Throwable $previous)
    {
        parent::__construct("Label for shipment {$shipmentId} was bought but not persisted.", 0, $previous);
    }
}
```

- [ ] **Step 8: Create the action**

```php
<?php
// app/Actions/CreateShippingLabel.php

namespace App\Actions;

use App\Exceptions\LabelNotPersistedException;
use App\Exceptions\NoUspsRateException;
use App\Models\ShippingLabel;
use App\Models\User;
use App\Services\EasyPost\EasyPostClient;
use App\Services\EasyPost\RateSelector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class CreateShippingLabel
{
    public function __construct(private readonly EasyPostClient $easyPost) {}

    /**
     * @param  array{from_address: array<string, mixed>, to_address: array<string, mixed>, parcel: array<string, mixed>}  $data  StoreLabelRequest::validated()
     */
    public function handle(User $user, array $data): ShippingLabel
    {
        $shipment = $this->easyPost->createShipment([
            'from_address' => $data['from_address'],
            'to_address' => $data['to_address'],
            'parcel' => [
                'weight' => $data['parcel']['weight_oz'],
                'length' => $data['parcel']['length_in'],
                'width' => $data['parcel']['width_in'],
                'height' => $data['parcel']['height_in'],
            ],
            'options' => ['label_format' => 'PDF'],
        ]);

        $rate = RateSelector::lowestUsps($shipment['rates'] ?? [])
            ?? throw new NoUspsRateException($shipment['id']);

        $bought = $this->easyPost->buyShipment($shipment['id'], $rate['id']);

        try {
            $path = sprintf('labels/%d/%s.pdf', $user->id, Str::uuid());
            Storage::disk('local')->put($path, $this->easyPost->downloadLabel($bought['postage_label']['label_url']));

            return $user->labels()->create([
                'easypost_shipment_id' => $bought['id'],
                'tracking_code' => $bought['tracking_code'] ?? null,
                'carrier' => $bought['selected_rate']['carrier'] ?? $rate['carrier'],
                'service' => $bought['selected_rate']['service'] ?? $rate['service'],
                'rate' => $bought['selected_rate']['rate'] ?? $rate['rate'],
                'currency' => $bought['selected_rate']['currency'] ?? $rate['currency'] ?? 'USD',
                'from_address' => $data['from_address'],
                'to_address' => $data['to_address'],
                'parcel' => $data['parcel'],
                'label_file_path' => $path,
                'label_file_type' => $bought['postage_label']['label_file_type'] ?? 'application/pdf',
                'label_url' => $bought['postage_label']['label_url'],
                'easypost_response' => $bought,
            ]);
        } catch (Throwable $e) {
            Log::error('Label bought at EasyPost but not persisted', [
                'user_id' => $user->id,
                'shipment_id' => $bought['id'],
                'label_url' => $bought['postage_label']['label_url'] ?? null,
                'reason' => $e->getMessage(),
            ]);

            throw new LabelNotPersistedException($bought['id'], $e);
        }
    }
}
```

- [ ] **Step 9: Create the resource**

```php
<?php
// app/Http/Resources/LabelResource.php

namespace App\Http\Resources;

use App\Models\ShippingLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ShippingLabel
 */
class LabelResource extends JsonResource
{
    /**
     * Whitelist only. Never expose label_url, easypost_response or label_file_path.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'carrier' => $this->carrier,
            'service' => $this->service,
            'rate' => $this->rate,
            'currency' => $this->currency,
            'tracking_code' => $this->tracking_code,
            'from_address' => $this->from_address,
            'to_address' => $this->to_address,
            'parcel' => $this->parcel,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
```

- [ ] **Step 10: Create the controller with `store` only**

```php
<?php
// app/Http/Controllers/LabelController.php

namespace App\Http\Controllers;

use App\Actions\CreateShippingLabel;
use App\Http\Requests\StoreLabelRequest;
use App\Http\Resources\LabelResource;
use Illuminate\Http\JsonResponse;

class LabelController extends Controller
{
    public function store(StoreLabelRequest $request, CreateShippingLabel $action): JsonResponse
    {
        $label = $action->handle($request->user(), $request->validated());

        return LabelResource::make($label)->response()->setStatusCode(201);
    }
}
```

- [ ] **Step 11: Register the route**

In `routes/api.php`, add `use App\Http\Controllers\LabelController;` and inside the `auth:sanctum` group, after the `/user` route:

```php
    Route::post('/labels', [LabelController::class, 'store']);
```

- [ ] **Step 12: Run tests to verify they pass**

Run: `php artisan test --filter=CreateLabelTest`
Expected: PASS (8 tests).

- [ ] **Step 13: Commit**

```bash
vendor/bin/pint --dirty
git add app tests/Concerns tests/Fixtures tests/Feature/Labels routes/api.php
git commit -m "Add label purchase endpoint"
```

---

### Task 6: Error branches — exception rendering and failure tests

**Files:**
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/Labels/CreateLabelFailureTest.php`

**Interfaces:**
- Consumes: `EasyPostException::isClientError()`, `NoUspsRateException`, `LabelNotPersistedException` (Tasks 3, 5).
- Produces: JSON error responses — 422 `{message}` for EasyPost client errors and no USPS rate; 502 `{message}` for provider 5xx/401/403/transport and post-buy persistence failures.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/Labels/CreateLabelFailureTest.php

namespace Tests\Feature\Labels;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesEasyPost;
use Tests\TestCase;

class CreateLabelFailureTest extends TestCase
{
    use FakesEasyPost;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->user = User::factory()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'from_address' => ['name' => 'Ada Lovelace', 'street1' => '417 Montgomery St', 'street2' => null, 'city' => 'San Francisco', 'state' => 'CA', 'zip' => '94104', 'country' => 'US', 'phone' => null],
            'to_address' => ['name' => 'Grace Hopper', 'street1' => '350 5th Ave', 'street2' => null, 'city' => 'New York', 'state' => 'NY', 'zip' => '10118', 'country' => 'US', 'phone' => null],
            'parcel' => ['weight_oz' => 16, 'length_in' => 10, 'width_in' => 8, 'height_in' => 4],
        ];
    }

    public function test_no_usps_rate_returns_422_and_buys_nothing(): void
    {
        $created = $this->easyPostFixture('shipment_created.json');
        $created['rates'] = array_values(array_filter($created['rates'], fn (array $rate) => $rate['carrier'] !== 'USPS'));
        $this->fakeEasyPost([self::EASYPOST_CREATE => Http::response($created)]);

        $this->actingAs($this->user)->postJson('/api/labels', $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No USPS rate available for this shipment.');

        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/buy'));
        $this->assertDatabaseCount('shipping_labels', 0);
    }

    public function test_easypost_client_error_is_returned_as_422_with_its_message(): void
    {
        $this->fakeEasyPost([self::EASYPOST_CREATE => Http::response([
            'error' => ['code' => 'ADDRESS.VERIFY.FAILURE', 'message' => 'Unable to verify address.', 'errors' => []],
        ], 422)]);

        $this->actingAs($this->user)->postJson('/api/labels', $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Unable to verify address.');

        Http::assertSentCount(1);
        $this->assertDatabaseCount('shipping_labels', 0);
    }

    public function test_easypost_server_error_is_retried_then_returned_as_502(): void
    {
        $this->fakeEasyPost([self::EASYPOST_CREATE => Http::response(['error' => ['message' => 'boom']], 500)]);

        $this->actingAs($this->user)->postJson('/api/labels', $this->payload())
            ->assertStatus(502)
            ->assertJsonPath('message', 'Shipping provider unavailable, please try again.');

        Http::assertSentCount(3);
        $this->assertDatabaseCount('shipping_labels', 0);
    }

    public function test_invalid_api_key_is_our_problem_not_the_users(): void
    {
        $this->fakeEasyPost([self::EASYPOST_CREATE => Http::response(['error' => ['message' => 'Invalid API key']], 401)]);

        $response = $this->actingAs($this->user)->postJson('/api/labels', $this->payload());

        $response->assertStatus(502);
        $this->assertStringNotContainsString('API key', $response->getContent());
    }

    public function test_buy_failure_is_never_retried_and_nothing_is_stored(): void
    {
        $this->fakeEasyPost([self::EASYPOST_BUY => Http::response(['error' => ['message' => 'boom']], 500)]);

        $this->actingAs($this->user)->postJson('/api/labels', $this->payload())->assertStatus(502);

        Http::assertSentCount(2); // 1 create + 1 buy, no retry on buy
        $this->assertDatabaseCount('shipping_labels', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_download_failure_after_buy_is_logged_and_returns_502(): void
    {
        Log::spy();
        $this->fakeEasyPost([self::EASYPOST_LABEL => Http::response('nope', 500)]);

        $this->actingAs($this->user)->postJson('/api/labels', $this->payload())
            ->assertStatus(502)
            ->assertJsonPath('message', 'The label was purchased but could not be saved. Please contact support.');

        $this->assertDatabaseCount('shipping_labels', 0);
        // The exception handler also logs the reported exception, so match our call by its context.
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []) => ($context['shipment_id'] ?? null) === 'shp_test0001'
                && ($context['user_id'] ?? null) === $this->user->id
                && str_contains($context['label_url'] ?? '', 'shp_test0001.pdf'))
            ->once();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=CreateLabelFailureTest`
Expected: FAIL — responses are 500 instead of 422/502.

- [ ] **Step 3: Register the renderers**

Replace the `withExceptions` block in `bootstrap/app.php` (keep the existing `shouldRenderJsonWhen`) and add the imports:

```php
use App\Exceptions\LabelNotPersistedException;
use App\Exceptions\NoUspsRateException;
use App\Services\EasyPost\Exceptions\EasyPostException;
```

```php
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Provider 5xx/401/403/transport failures are still reported (logged) by the handler.
        $exceptions->render(fn (EasyPostException $e, Request $request) => $e->isClientError()
            ? response()->json(['message' => $e->getMessage()], 422)
            : response()->json(['message' => 'Shipping provider unavailable, please try again.'], 502));

        $exceptions->render(fn (NoUspsRateException $e, Request $request) => response()->json(
            ['message' => 'No USPS rate available for this shipment.'],
            422,
        ));

        $exceptions->render(fn (LabelNotPersistedException $e, Request $request) => response()->json(
            ['message' => 'The label was purchased but could not be saved. Please contact support.'],
            502,
        ));
    })
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=CreateLabel`
Expected: PASS (14 tests across both files).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add bootstrap/app.php tests/Feature/Labels/CreateLabelFailureTest.php
git commit -m "Render EasyPost and label failures as JSON errors"
```

---

### Task 7: Label history, detail and authenticated PDF download

**Files:**
- Create: `app/Policies/ShippingLabelPolicy.php`
- Modify: `app/Http/Controllers/LabelController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Labels/ListLabelsTest.php`, `tests/Feature/Labels/ShowDownloadLabelTest.php`

**Interfaces:**
- Consumes: `ShippingLabel`, `LabelResource`.
- Produces: `GET /api/labels?page=N` → paginated `{data: LabelResource[], links, meta}` (15 per page, newest first, current user only); `GET /api/labels/{id}` → `{data: LabelResource}`; `GET /api/labels/{id}/download` → PDF inline. 403 for another user's label, 404 unknown, 401 guest.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/Labels/ListLabelsTest.php

namespace Tests\Feature\Labels;

use App\Models\ShippingLabel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_sees_only_their_own_labels_newest_first(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $older = ShippingLabel::factory()->for($user)->create(['created_at' => now()->subDay()]);
        $newer = ShippingLabel::factory()->for($user)->create(['created_at' => now()]);
        ShippingLabel::factory()->for($other)->create();

        $response = $this->actingAs($user)->getJson('/api/labels');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonMissingPath('data.0.label_url');
    }

    public function test_history_is_paginated_by_fifteen(): void
    {
        $user = User::factory()->create();
        ShippingLabel::factory()->for($user)->count(16)->create();

        $this->actingAs($user)->getJson('/api/labels')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.last_page', 2);

        $this->actingAs($user)->getJson('/api/labels?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_guest_cannot_list_labels(): void
    {
        $this->getJson('/api/labels')->assertUnauthorized();
    }
}
```

```php
<?php
// tests/Feature/Labels/ShowDownloadLabelTest.php

namespace Tests\Feature\Labels;

use App\Models\ShippingLabel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShowDownloadLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_label_details(): void
    {
        $user = User::factory()->create();
        $label = ShippingLabel::factory()->for($user)->create(['service' => 'Priority']);

        $this->actingAs($user)->getJson("/api/labels/{$label->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $label->id)
            ->assertJsonPath('data.service', 'Priority')
            ->assertJsonMissingPath('data.label_url')
            ->assertJsonMissingPath('data.label_file_path');
    }

    public function test_owner_can_download_the_pdf_inline(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $label = ShippingLabel::factory()->for($user)->create(['label_file_path' => "labels/{$user->id}/test.pdf"]);
        Storage::disk('local')->put($label->label_file_path, '%PDF-1.4 fake');

        $response = $this->actingAs($user)->get("/api/labels/{$label->id}/download");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString("label-{$label->id}.pdf", $response->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-1.4 fake', $response->streamedContent());
    }

    public function test_another_user_gets_403(): void
    {
        Storage::fake('local');
        $label = ShippingLabel::factory()->create();
        Storage::disk('local')->put($label->label_file_path, '%PDF');
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->getJson("/api/labels/{$label->id}")->assertForbidden();
        $this->actingAs($intruder)->get("/api/labels/{$label->id}/download")->assertForbidden();
    }

    public function test_unknown_label_is_404(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/labels/999')->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson('/api/labels/999/download')->assertNotFound();
    }

    public function test_guest_is_401(): void
    {
        $label = ShippingLabel::factory()->create();

        $this->getJson("/api/labels/{$label->id}")->assertUnauthorized();
        $this->getJson("/api/labels/{$label->id}/download")->assertUnauthorized();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="ListLabelsTest|ShowDownloadLabelTest"`
Expected: FAIL with 404/405 on the new routes.

- [ ] **Step 3: Create the policy**

Laravel auto-discovers `App\Policies\ShippingLabelPolicy` for `App\Models\ShippingLabel`; no registration needed.

```php
<?php
// app/Policies/ShippingLabelPolicy.php

namespace App\Policies;

use App\Models\ShippingLabel;
use App\Models\User;

class ShippingLabelPolicy
{
    public function view(User $user, ShippingLabel $label): bool
    {
        return $label->user_id === $user->id;
    }
}
```

- [ ] **Step 4: Add `index`, `show`, `download` to the controller**

Replace `app/Http/Controllers/LabelController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\CreateShippingLabel;
use App\Http\Requests\StoreLabelRequest;
use App\Http\Resources\LabelResource;
use App\Models\ShippingLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LabelController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return LabelResource::collection(
            $request->user()->labels()->latest()->paginate(15),
        );
    }

    public function store(StoreLabelRequest $request, CreateShippingLabel $action): JsonResponse
    {
        $label = $action->handle($request->user(), $request->validated());

        return LabelResource::make($label)->response()->setStatusCode(201);
    }

    public function show(ShippingLabel $label): LabelResource
    {
        Gate::authorize('view', $label);

        return LabelResource::make($label);
    }

    public function download(ShippingLabel $label): StreamedResponse
    {
        Gate::authorize('view', $label);

        return Storage::disk('local')->response(
            $label->label_file_path,
            "label-{$label->id}.pdf",
            ['Content-Type' => $label->label_file_type],
            'inline',
        );
    }
}
```

- [ ] **Step 5: Register the routes**

In `routes/api.php`, inside the `auth:sanctum` group, replace the single `/labels` line with:

```php
    Route::get('/labels', [LabelController::class, 'index']);
    Route::post('/labels', [LabelController::class, 'store']);
    Route::get('/labels/{label}', [LabelController::class, 'show'])->whereNumber('label');
    Route::get('/labels/{label}/download', [LabelController::class, 'download'])->whereNumber('label');
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=Labels`
Expected: PASS (all `tests/Feature/Labels` tests).

- [ ] **Step 7: Run the whole suite**

Run: `php artisan test`
Expected: PASS. Backend is complete at this point.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add app/Policies app/Http/Controllers/LabelController.php routes/api.php tests/Feature/Labels
git commit -m "Add label history, detail and PDF download endpoints"
```

---

### Task 8: Frontend toolchain — React + TypeScript under Vite, Blade shell, SPA catch-all

**Files:**
- Modify: `package.json`, `vite.config.js`
- Create: `tsconfig.json`
- Create: `resources/views/app.blade.php`
- Delete: `resources/views/welcome.blade.php`, `resources/js/app.js`
- Create: `resources/js/app.tsx`
- Modify: `routes/web.php`
- Modify: `tests/TestCase.php`
- Test: `tests/Feature/SpaTest.php` (replaces `tests/Feature/ExampleTest.php`)

**Interfaces:**
- Produces: any non-API GET renders the SPA shell (`<div id="app">`); `npm run typecheck` and `npm run build` commands; `resources/js/app.tsx` is the Vite entry.

- [ ] **Step 1: Write the failing test**

Delete `tests/Feature/ExampleTest.php` and create:

```php
<?php
// tests/Feature/SpaTest.php

namespace Tests\Feature;

use Tests\TestCase;

class SpaTest extends TestCase
{
    public function test_root_serves_the_spa_shell(): void
    {
        $this->get('/')->assertOk()->assertSee('id="app"', false);
    }

    public function test_deep_links_serve_the_spa_shell(): void
    {
        $this->get('/labels/new')->assertOk()->assertSee('id="app"', false);
        $this->get('/labels/42')->assertOk()->assertSee('id="app"', false);
    }

    public function test_unknown_api_routes_are_json_404_not_the_shell(): void
    {
        $this->getJson('/api/does-not-exist')->assertNotFound()->assertJsonStructure(['message']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SpaTest`
Expected: FAIL — `/labels/new` is 404 and `/` renders the welcome page without `id="app"`.

- [ ] **Step 3: Install frontend dependencies**

```bash
npm install react@^19 react-dom@^19 react-router@^7 axios@^1
npm install -D typescript@^5 @types/react@^19 @types/react-dom@^19 @vitejs/plugin-react@^6
```

Add the script to `package.json` `"scripts"`:

```json
        "typecheck": "tsc --noEmit"
```

- [ ] **Step 4: Create `tsconfig.json`**

```json
{
    "compilerOptions": {
        "target": "ES2022",
        "lib": ["ES2022", "DOM", "DOM.Iterable"],
        "module": "ESNext",
        "moduleResolution": "bundler",
        "jsx": "react-jsx",
        "strict": true,
        "noEmit": true,
        "isolatedModules": true,
        "esModuleInterop": true,
        "skipLibCheck": true,
        "resolveJsonModule": true,
        "noUnusedLocals": true,
        "types": ["vite/client"]
    },
    "include": ["resources/js/**/*.ts", "resources/js/**/*.tsx"]
}
```

- [ ] **Step 5: Update `vite.config.js`**

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        react(),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
```

- [ ] **Step 6: Create the Blade shell and remove the welcome page**

```bash
rm resources/views/welcome.blade.php resources/js/app.js
```

```blade
{{-- resources/views/app.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} — USPS Labels</title>
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
</head>
<body class="min-h-screen bg-gray-50 text-gray-900 antialiased">
    <div id="app"></div>
</body>
</html>
```

- [ ] **Step 7: Create a placeholder entry point**

```tsx
// resources/js/app.tsx
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

function App() {
    return <h1 className="p-8 text-2xl font-semibold">USPS Labels</h1>;
}

createRoot(document.getElementById('app')!).render(
    <StrictMode>
        <App />
    </StrictMode>,
);
```

- [ ] **Step 8: SPA catch-all route**

Replace `routes/web.php`:

```php
<?php

use Illuminate\Support\Facades\Route;

// Everything that is not the API, Sanctum or the health check is the React app.
Route::view('/{any?}', 'app')->where('any', '^(?!api|sanctum|up).*$');
```

- [ ] **Step 9: Skip Vite in tests**

Tests have no `public/build/manifest.json`, so `@vite` would throw. Add `$this->withoutVite();` to `tests/TestCase.php::setUp()` right after `parent::setUp();`:

```php
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // Sanctum only starts a session for requests coming from a stateful frontend.
        $this->withHeader('Referer', config('app.url'));
    }
```

- [ ] **Step 10: Verify**

Run: `php artisan test --filter=SpaTest`
Expected: PASS (3 tests).

Run: `npm run typecheck && npm run build`
Expected: `tsc` prints nothing; Vite prints `✓ built` with `public/build/assets/app-*.js`.

Run: `php artisan test`
Expected: PASS (whole suite; `ExampleTest` for `/` was replaced by `SpaTest`).

- [ ] **Step 11: Commit**

```bash
vendor/bin/pint --dirty
git add package.json package-lock.json tsconfig.json vite.config.js resources routes/web.php tests
git commit -m "Set up React TypeScript frontend shell"
```

---

### Task 9: API client, auth context, login and register pages

**Files:**
- Create: `resources/js/types.ts`
- Create: `resources/js/api/client.ts`, `resources/js/api/auth.ts`, `resources/js/api/labels.ts`
- Create: `resources/js/hooks/useAuth.tsx`
- Create: `resources/js/components/Field.tsx`, `Button.tsx`, `Alert.tsx`, `RequireAuth.tsx`, `Layout.tsx`
- Create: `resources/js/pages/LoginPage.tsx`, `RegisterPage.tsx`
- Modify: `resources/js/app.tsx`

**Interfaces:**
- Consumes: `POST /api/login|register|logout`, `GET /api/user` (Task 2); label endpoints (Tasks 5, 7).
- Produces (used by Tasks 10 and 11):
  - `types.ts`: `User`, `Address`, `Parcel`, `Label`, `Paginated<T>`, `ApiError`, `NewLabelPayload`.
  - `api/client.ts`: `http` (axios instance), `extractApiError(error: unknown): ApiError`.
  - `api/labels.ts`: `listLabels(page?: number): Promise<Paginated<Label>>`, `createLabel(payload: NewLabelPayload): Promise<Label>`, `showLabel(id: string | number): Promise<Label>`, `labelDownloadUrl(id: string | number): string`.
  - `hooks/useAuth.tsx`: `AuthProvider`, `useAuth(): { user, loading, login, register, logout }`.
  - Components: `Field`, `Button`, `Alert`, `RequireAuth` (layout route), `Layout` (layout route with `<Outlet />`).

- [ ] **Step 1: Types**

```ts
// resources/js/types.ts
export interface User {
    id: number;
    name: string;
    email: string;
}

export interface Address {
    name: string;
    street1: string;
    street2: string | null;
    city: string;
    state: string;
    zip: string;
    country: 'US';
    phone: string | null;
}

export interface Parcel {
    weight_oz: number;
    length_in: number;
    width_in: number;
    height_in: number;
}

export interface Label {
    id: number;
    carrier: string;
    service: string;
    rate: string;
    currency: string;
    tracking_code: string | null;
    from_address: Address;
    to_address: Address;
    parcel: Parcel;
    created_at: string;
}

export interface Paginated<T> {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    links: {
        prev: string | null;
        next: string | null;
    };
}

export interface ApiError {
    message: string;
    errors?: Record<string, string[]>;
}

export interface NewLabelPayload {
    from_address: Address;
    to_address: Address;
    parcel: Parcel;
}
```

- [ ] **Step 2: HTTP client**

```ts
// resources/js/api/client.ts
import axios from 'axios';
import type { ApiError } from '../types';

export const http = axios.create({
    baseURL: '/api',
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json' },
});

let onUnauthenticated: (() => void) | null = null;

/** AuthProvider registers a handler so a 401/419 anywhere logs the user out client-side. */
export function setUnauthenticatedHandler(handler: (() => void) | null): void {
    onUnauthenticated = handler;
}

http.interceptors.response.use(
    (response) => response,
    (error: unknown) => {
        if (axios.isAxiosError(error)) {
            const status = error.response?.status;
            if (status === 401 || status === 419) {
                onUnauthenticated?.();
            }
        }
        return Promise.reject(error);
    },
);

/** Sanctum SPA auth needs the XSRF-TOKEN cookie before the first POST. */
export async function ensureCsrfCookie(): Promise<void> {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}

export function extractApiError(error: unknown): ApiError {
    if (axios.isAxiosError(error) && error.response) {
        if (error.response.status === 429) {
            return { message: 'Too many attempts. Please wait a minute and try again.' };
        }
        const data = error.response.data as Partial<ApiError> | undefined;
        return {
            message: data?.message ?? `Request failed (${error.response.status}).`,
            errors: data?.errors,
        };
    }
    return { message: 'Network error. Please try again.' };
}
```

- [ ] **Step 3: Auth and label API modules**

```ts
// resources/js/api/auth.ts
import { ensureCsrfCookie, http } from './client';
import type { User } from '../types';

export interface RegisterPayload {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
}

export async function me(): Promise<User> {
    return (await http.get<User>('/user')).data;
}

export async function login(email: string, password: string): Promise<User> {
    await ensureCsrfCookie();
    return (await http.post<User>('/login', { email, password })).data;
}

export async function register(payload: RegisterPayload): Promise<User> {
    await ensureCsrfCookie();
    return (await http.post<User>('/register', payload)).data;
}

export async function logout(): Promise<void> {
    await http.post('/logout');
}
```

```ts
// resources/js/api/labels.ts
import { http } from './client';
import type { Label, NewLabelPayload, Paginated } from '../types';

export async function listLabels(page = 1): Promise<Paginated<Label>> {
    return (await http.get<Paginated<Label>>('/labels', { params: { page } })).data;
}

export async function createLabel(payload: NewLabelPayload): Promise<Label> {
    return (await http.post<{ data: Label }>('/labels', payload)).data.data;
}

export async function showLabel(id: string | number): Promise<Label> {
    return (await http.get<{ data: Label }>(`/labels/${id}`)).data.data;
}

/** Authenticated PDF route; open in a new tab, the browser viewer handles printing. */
export function labelDownloadUrl(id: string | number): string {
    return `/api/labels/${id}/download`;
}
```

- [ ] **Step 4: Auth context**

```tsx
// resources/js/hooks/useAuth.tsx
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import * as authApi from '../api/auth';
import type { RegisterPayload } from '../api/auth';
import { setUnauthenticatedHandler } from '../api/client';
import type { User } from '../types';

interface AuthContextValue {
    user: User | null;
    loading: boolean;
    login: (email: string, password: string) => Promise<void>;
    register: (payload: RegisterPayload) => Promise<void>;
    logout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<User | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        setUnauthenticatedHandler(() => setUser(null));

        authApi
            .me()
            .then(setUser)
            .catch(() => setUser(null))
            .finally(() => setLoading(false));

        return () => setUnauthenticatedHandler(null);
    }, []);

    const login = useCallback(async (email: string, password: string) => {
        setUser(await authApi.login(email, password));
    }, []);

    const register = useCallback(async (payload: RegisterPayload) => {
        setUser(await authApi.register(payload));
    }, []);

    const logout = useCallback(async () => {
        await authApi.logout();
        setUser(null);
    }, []);

    const value = useMemo(
        () => ({ user, loading, login, register, logout }),
        [user, loading, login, register, logout],
    );

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
    const context = useContext(AuthContext);
    if (!context) {
        throw new Error('useAuth must be used inside <AuthProvider>');
    }
    return context;
}
```

- [ ] **Step 5: UI building blocks**

```tsx
// resources/js/components/Field.tsx
import type { InputHTMLAttributes } from 'react';

interface FieldProps extends InputHTMLAttributes<HTMLInputElement> {
    label: string;
    error?: string[];
}

export function Field({ label, error, id, name, className, ...props }: FieldProps) {
    const inputId = id ?? name;
    const border = error ? 'border-red-500 focus:ring-red-200' : 'border-gray-300 focus:ring-blue-200';

    return (
        <div className={className}>
            <label htmlFor={inputId} className="block text-sm font-medium text-gray-700">
                {label}
            </label>
            <input
                id={inputId}
                name={name}
                {...props}
                className={`mt-1 block w-full rounded-md border bg-white px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 ${border}`}
            />
            {error && <p className="mt-1 text-sm text-red-600">{error[0]}</p>}
        </div>
    );
}
```

```tsx
// resources/js/components/Button.tsx
import type { ButtonHTMLAttributes } from 'react';

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: 'primary' | 'secondary';
    loading?: boolean;
}

export function Button({ variant = 'primary', loading = false, disabled, children, className = '', ...props }: ButtonProps) {
    const styles =
        variant === 'primary'
            ? 'bg-blue-600 text-white hover:bg-blue-700'
            : 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50';

    return (
        <button
            {...props}
            disabled={disabled || loading}
            className={`inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium shadow-sm disabled:cursor-not-allowed disabled:opacity-60 ${styles} ${className}`}
        >
            {loading ? 'Please wait…' : children}
        </button>
    );
}
```

```tsx
// resources/js/components/Alert.tsx
interface AlertProps {
    message: string | null;
    tone?: 'error' | 'info';
}

export function Alert({ message, tone = 'error' }: AlertProps) {
    if (!message) {
        return null;
    }

    const styles = tone === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-blue-200 bg-blue-50 text-blue-800';

    return (
        <div role="alert" className={`rounded-md border px-4 py-3 text-sm ${styles}`}>
            {message}
        </div>
    );
}
```

```tsx
// resources/js/components/RequireAuth.tsx
import { Navigate, Outlet } from 'react-router';
import { useAuth } from '../hooks/useAuth';

export function RequireAuth() {
    const { user, loading } = useAuth();

    if (loading) {
        return <p className="p-8 text-center text-gray-500">Loading…</p>;
    }

    if (!user) {
        return <Navigate to="/login" replace />;
    }

    return <Outlet />;
}
```

```tsx
// resources/js/components/Layout.tsx
import { Link, Outlet, useNavigate } from 'react-router';
import { useAuth } from '../hooks/useAuth';
import { Button } from './Button';

export function Layout() {
    const { user, logout } = useAuth();
    const navigate = useNavigate();

    async function handleLogout() {
        await logout();
        navigate('/login', { replace: true });
    }

    return (
        <div className="min-h-screen">
            <header className="border-b border-gray-200 bg-white">
                <div className="mx-auto flex max-w-5xl items-center justify-between px-4 py-3">
                    <Link to="/labels" className="text-lg font-semibold">
                        USPS Labels
                    </Link>
                    <div className="flex items-center gap-4 text-sm text-gray-600">
                        <span>{user?.name}</span>
                        <Button variant="secondary" onClick={handleLogout}>
                            Log out
                        </Button>
                    </div>
                </div>
            </header>
            <main className="mx-auto max-w-5xl px-4 py-8">
                <Outlet />
            </main>
        </div>
    );
}
```

- [ ] **Step 6: Login and register pages**

```tsx
// resources/js/pages/LoginPage.tsx
import { useState, type FormEvent } from 'react';
import { Link, Navigate, useNavigate } from 'react-router';
import { extractApiError } from '../api/client';
import { Alert } from '../components/Alert';
import { Button } from '../components/Button';
import { Field } from '../components/Field';
import { useAuth } from '../hooks/useAuth';
import type { ApiError } from '../types';

export function LoginPage() {
    const { user, loading, login } = useAuth();
    const navigate = useNavigate();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState<ApiError | null>(null);
    const [submitting, setSubmitting] = useState(false);

    if (!loading && user) {
        return <Navigate to="/labels" replace />;
    }

    async function handleSubmit(event: FormEvent) {
        event.preventDefault();
        setSubmitting(true);
        setError(null);
        try {
            await login(email, password);
            navigate('/labels', { replace: true });
        } catch (e) {
            setError(extractApiError(e));
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="mx-auto mt-16 max-w-sm rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h1 className="mb-6 text-xl font-semibold">Log in</h1>
            <form onSubmit={handleSubmit} className="space-y-4">
                <Alert message={error?.errors ? null : error?.message ?? null} />
                <Field
                    label="Email"
                    name="email"
                    type="email"
                    autoComplete="email"
                    required
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    error={error?.errors?.email}
                />
                <Field
                    label="Password"
                    name="password"
                    type="password"
                    autoComplete="current-password"
                    required
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    error={error?.errors?.password}
                />
                <Button type="submit" loading={submitting} className="w-full">
                    Log in
                </Button>
            </form>
            <p className="mt-4 text-center text-sm text-gray-600">
                No account?{' '}
                <Link to="/register" className="text-blue-600 hover:underline">
                    Register
                </Link>
            </p>
        </div>
    );
}
```

```tsx
// resources/js/pages/RegisterPage.tsx
import { useState, type FormEvent } from 'react';
import { Link, Navigate, useNavigate } from 'react-router';
import { extractApiError } from '../api/client';
import { Alert } from '../components/Alert';
import { Button } from '../components/Button';
import { Field } from '../components/Field';
import { useAuth } from '../hooks/useAuth';
import type { ApiError } from '../types';

export function RegisterPage() {
    const { user, loading, register } = useAuth();
    const navigate = useNavigate();
    const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '' });
    const [error, setError] = useState<ApiError | null>(null);
    const [submitting, setSubmitting] = useState(false);

    if (!loading && user) {
        return <Navigate to="/labels" replace />;
    }

    function update(field: keyof typeof form) {
        return (e: { target: { value: string } }) => setForm((prev) => ({ ...prev, [field]: e.target.value }));
    }

    async function handleSubmit(event: FormEvent) {
        event.preventDefault();
        setSubmitting(true);
        setError(null);
        try {
            await register(form);
            navigate('/labels', { replace: true });
        } catch (e) {
            setError(extractApiError(e));
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="mx-auto mt-16 max-w-sm rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h1 className="mb-6 text-xl font-semibold">Create an account</h1>
            <form onSubmit={handleSubmit} className="space-y-4">
                <Alert message={error?.errors ? null : error?.message ?? null} />
                <Field label="Name" name="name" required value={form.name} onChange={update('name')} error={error?.errors?.name} />
                <Field label="Email" name="email" type="email" autoComplete="email" required value={form.email} onChange={update('email')} error={error?.errors?.email} />
                <Field
                    label="Password"
                    name="password"
                    type="password"
                    autoComplete="new-password"
                    required
                    minLength={8}
                    value={form.password}
                    onChange={update('password')}
                    error={error?.errors?.password}
                />
                <Field
                    label="Confirm password"
                    name="password_confirmation"
                    type="password"
                    autoComplete="new-password"
                    required
                    value={form.password_confirmation}
                    onChange={update('password_confirmation')}
                />
                <Button type="submit" loading={submitting} className="w-full">
                    Register
                </Button>
            </form>
            <p className="mt-4 text-center text-sm text-gray-600">
                Already registered?{' '}
                <Link to="/login" className="text-blue-600 hover:underline">
                    Log in
                </Link>
            </p>
        </div>
    );
}
```

- [ ] **Step 7: Wire the router**

Replace `resources/js/app.tsx`. `LabelsPage`, `NewLabelPage` and `LabelDetailPage` do not exist yet; use the temporary placeholder below and replace it in Tasks 10 and 11.

```tsx
// resources/js/app.tsx
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router';
import { Layout } from './components/Layout';
import { RequireAuth } from './components/RequireAuth';
import { AuthProvider } from './hooks/useAuth';
import { LoginPage } from './pages/LoginPage';
import { RegisterPage } from './pages/RegisterPage';

function LabelsPlaceholder() {
    return <p className="text-gray-600">Labels coming in the next task.</p>;
}

createRoot(document.getElementById('app')!).render(
    <StrictMode>
        <BrowserRouter>
            <AuthProvider>
                <Routes>
                    <Route path="/login" element={<LoginPage />} />
                    <Route path="/register" element={<RegisterPage />} />
                    <Route element={<RequireAuth />}>
                        <Route element={<Layout />}>
                            <Route path="/labels" element={<LabelsPlaceholder />} />
                        </Route>
                    </Route>
                    <Route path="*" element={<Navigate to="/labels" replace />} />
                </Routes>
            </AuthProvider>
        </BrowserRouter>
    </StrictMode>,
);
```

- [ ] **Step 8: Verify**

Run: `npm run typecheck && npm run build`
Expected: no type errors, build succeeds.

Manual check (needs `docker compose up -d` and a seeded DB): run `composer run dev`, open `http://localhost:8000`.
- Redirects to `/login`.
- Wrong password shows "These credentials do not match our records." under the email field.
- `demo@example.com` / `password` lands on `/labels` with the header showing "Demo User".
- Reload keeps the session. "Log out" returns to `/login`; visiting `/labels` redirects to `/login`.
- `/register` with a new email logs in and lands on `/labels`.

- [ ] **Step 9: Commit**

```bash
git add resources/js
git commit -m "Add API client, auth context and auth pages"
```

---

### Task 10: Label history and detail pages

**Files:**
- Create: `resources/js/pages/LabelsPage.tsx`, `resources/js/pages/LabelDetailPage.tsx`
- Modify: `resources/js/app.tsx`

**Interfaces:**
- Consumes: `listLabels`, `showLabel`, `labelDownloadUrl` (Task 9), `Label`/`Paginated` types.
- Produces: routes `/labels`, `/labels/:id`.

- [ ] **Step 1: History page**

```tsx
// resources/js/pages/LabelsPage.tsx
import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { extractApiError } from '../api/client';
import { labelDownloadUrl, listLabels } from '../api/labels';
import { Alert } from '../components/Alert';
import { Button } from '../components/Button';
import type { Label, Paginated } from '../types';

function formatDate(iso: string): string {
    return new Date(iso).toLocaleString();
}

export function LabelsPage() {
    const [searchParams, setSearchParams] = useSearchParams();
    const page = Number(searchParams.get('page') ?? '1') || 1;
    const [result, setResult] = useState<Paginated<Label> | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let cancelled = false;
        setResult(null);
        listLabels(page)
            .then((data) => {
                if (!cancelled) setResult(data);
            })
            .catch((e) => {
                if (!cancelled) setError(extractApiError(e).message);
            });
        return () => {
            cancelled = true;
        };
    }, [page]);

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <h1 className="text-2xl font-semibold">Your labels</h1>
                <Link to="/labels/new">
                    <Button>New label</Button>
                </Link>
            </div>

            <Alert message={error} />

            {result && result.data.length === 0 && (
                <div className="rounded-lg border border-dashed border-gray-300 bg-white p-12 text-center">
                    <p className="text-gray-600">No labels yet.</p>
                    <Link to="/labels/new" className="mt-4 inline-block text-blue-600 hover:underline">
                        Create your first USPS label
                    </Link>
                </div>
            )}

            {result && result.data.length > 0 && (
                <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                    <table className="min-w-full divide-y divide-gray-200 text-sm">
                        <thead className="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                            <tr>
                                <th className="px-4 py-3">Created</th>
                                <th className="px-4 py-3">Recipient</th>
                                <th className="px-4 py-3">Service</th>
                                <th className="px-4 py-3">Rate</th>
                                <th className="px-4 py-3">Tracking</th>
                                <th className="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {result.data.map((label) => (
                                <tr key={label.id}>
                                    <td className="whitespace-nowrap px-4 py-3">{formatDate(label.created_at)}</td>
                                    <td className="px-4 py-3">
                                        <div className="font-medium">{label.to_address.name}</div>
                                        <div className="text-gray-500">
                                            {label.to_address.city}, {label.to_address.state}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        {label.carrier} {label.service}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        {label.currency} {label.rate}
                                    </td>
                                    <td className="px-4 py-3 font-mono text-xs">{label.tracking_code ?? '—'}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-right">
                                        <Link to={`/labels/${label.id}`} className="mr-3 text-blue-600 hover:underline">
                                            Details
                                        </Link>
                                        <a
                                            href={labelDownloadUrl(label.id)}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="text-blue-600 hover:underline"
                                        >
                                            View / Print
                                        </a>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {result && result.meta.last_page > 1 && (
                <div className="flex items-center justify-between text-sm text-gray-600">
                    <span>
                        Page {result.meta.current_page} of {result.meta.last_page} · {result.meta.total} labels
                    </span>
                    <div className="flex gap-2">
                        <Button
                            variant="secondary"
                            disabled={result.meta.current_page <= 1}
                            onClick={() => setSearchParams({ page: String(result.meta.current_page - 1) })}
                        >
                            Previous
                        </Button>
                        <Button
                            variant="secondary"
                            disabled={result.meta.current_page >= result.meta.last_page}
                            onClick={() => setSearchParams({ page: String(result.meta.current_page + 1) })}
                        >
                            Next
                        </Button>
                    </div>
                </div>
            )}

            {!result && !error && <p className="text-gray-500">Loading…</p>}
        </div>
    );
}
```

- [ ] **Step 2: Detail page**

```tsx
// resources/js/pages/LabelDetailPage.tsx
import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router';
import { extractApiError } from '../api/client';
import { labelDownloadUrl, showLabel } from '../api/labels';
import { Alert } from '../components/Alert';
import { Button } from '../components/Button';
import type { Address, Label } from '../types';

function AddressBlock({ title, address }: { title: string; address: Address }) {
    return (
        <div>
            <h2 className="text-sm font-medium uppercase tracking-wide text-gray-500">{title}</h2>
            <address className="mt-1 not-italic text-gray-900">
                <div>{address.name}</div>
                <div>{address.street1}</div>
                {address.street2 && <div>{address.street2}</div>}
                <div>
                    {address.city}, {address.state} {address.zip}
                </div>
                {address.phone && <div className="text-gray-500">{address.phone}</div>}
            </address>
        </div>
    );
}

export function LabelDetailPage() {
    const { id } = useParams<{ id: string }>();
    const [label, setLabel] = useState<Label | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!id) return;
        showLabel(id)
            .then(setLabel)
            .catch((e) => setError(extractApiError(e).message));
    }, [id]);

    if (error) {
        return (
            <div className="space-y-4">
                <Alert message={error} />
                <Link to="/labels" className="text-blue-600 hover:underline">
                    Back to labels
                </Link>
            </div>
        );
    }

    if (!label) {
        return <p className="text-gray-500">Loading…</p>;
    }

    return (
        <div className="space-y-6">
            <Link to="/labels" className="text-sm text-blue-600 hover:underline">
                ← Back to labels
            </Link>

            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold">
                        {label.carrier} {label.service}
                    </h1>
                    <p className="text-gray-600">
                        {label.currency} {label.rate} · created {new Date(label.created_at).toLocaleString()}
                    </p>
                </div>
                <a href={labelDownloadUrl(label.id)} target="_blank" rel="noopener noreferrer">
                    <Button>View / Print label</Button>
                </a>
            </div>

            <div className="grid gap-6 rounded-lg border border-gray-200 bg-white p-6 sm:grid-cols-2">
                <AddressBlock title="From" address={label.from_address} />
                <AddressBlock title="To" address={label.to_address} />
                <div>
                    <h2 className="text-sm font-medium uppercase tracking-wide text-gray-500">Parcel</h2>
                    <p className="mt-1">
                        {label.parcel.weight_oz} oz · {label.parcel.length_in} × {label.parcel.width_in} × {label.parcel.height_in} in
                    </p>
                </div>
                <div>
                    <h2 className="text-sm font-medium uppercase tracking-wide text-gray-500">Tracking</h2>
                    <p className="mt-1 font-mono">{label.tracking_code ?? '—'}</p>
                </div>
            </div>
        </div>
    );
}
```

- [ ] **Step 3: Register the routes**

In `resources/js/app.tsx`: delete `LabelsPlaceholder`, add the imports

```tsx
import { LabelDetailPage } from './pages/LabelDetailPage';
import { LabelsPage } from './pages/LabelsPage';
```

and replace the `/labels` route line with:

```tsx
                            <Route path="/labels" element={<LabelsPage />} />
                            <Route path="/labels/:id" element={<LabelDetailPage />} />
```

- [ ] **Step 4: Verify**

Run: `npm run typecheck && npm run build`
Expected: clean.

Manual check with `composer run dev`, logged in as the demo user:
- `/labels` shows the empty state with the "Create your first USPS label" link.
- Seed one row for the demo user to see the table (in `php artisan tinker`):

```php
\App\Models\ShippingLabel::factory()->for(\App\Models\User::where('email', 'demo@example.com')->first())->create();
```

- The row shows recipient, `USPS GroundAdvantage`, `USD 7.33`, tracking, "Details" and "View / Print".
- "Details" opens `/labels/{id}` with both addresses and parcel. "View / Print" opens the download route in a new tab (404 for this fake row because the factory path has no file; real labels are exercised in Task 11).
- Delete the fake row afterwards: `\App\Models\ShippingLabel::query()->delete();`

- [ ] **Step 5: Commit**

```bash
git add resources/js
git commit -m "Add label history and detail pages"
```

---

### Task 11: New label form

**Files:**
- Create: `resources/js/usStates.ts`
- Create: `resources/js/components/AddressFields.tsx`
- Create: `resources/js/pages/NewLabelPage.tsx`
- Modify: `resources/js/app.tsx`

**Interfaces:**
- Consumes: `createLabel` (Task 9), `Field`, `Button`, `Alert`, `Address`, `Parcel`, `NewLabelPayload`, `ApiError`.
- Produces: route `/labels/new`; on success navigates to `/labels/{id}`.

- [ ] **Step 1: State list (mirror of `App\Support\UsStates`)**

```ts
// resources/js/usStates.ts
export const US_STATES: ReadonlyArray<{ code: string; name: string }> = [
    { code: 'AL', name: 'Alabama' }, { code: 'AK', name: 'Alaska' }, { code: 'AZ', name: 'Arizona' },
    { code: 'AR', name: 'Arkansas' }, { code: 'CA', name: 'California' }, { code: 'CO', name: 'Colorado' },
    { code: 'CT', name: 'Connecticut' }, { code: 'DE', name: 'Delaware' }, { code: 'DC', name: 'District of Columbia' },
    { code: 'FL', name: 'Florida' }, { code: 'GA', name: 'Georgia' }, { code: 'HI', name: 'Hawaii' },
    { code: 'ID', name: 'Idaho' }, { code: 'IL', name: 'Illinois' }, { code: 'IN', name: 'Indiana' },
    { code: 'IA', name: 'Iowa' }, { code: 'KS', name: 'Kansas' }, { code: 'KY', name: 'Kentucky' },
    { code: 'LA', name: 'Louisiana' }, { code: 'ME', name: 'Maine' }, { code: 'MD', name: 'Maryland' },
    { code: 'MA', name: 'Massachusetts' }, { code: 'MI', name: 'Michigan' }, { code: 'MN', name: 'Minnesota' },
    { code: 'MS', name: 'Mississippi' }, { code: 'MO', name: 'Missouri' }, { code: 'MT', name: 'Montana' },
    { code: 'NE', name: 'Nebraska' }, { code: 'NV', name: 'Nevada' }, { code: 'NH', name: 'New Hampshire' },
    { code: 'NJ', name: 'New Jersey' }, { code: 'NM', name: 'New Mexico' }, { code: 'NY', name: 'New York' },
    { code: 'NC', name: 'North Carolina' }, { code: 'ND', name: 'North Dakota' }, { code: 'OH', name: 'Ohio' },
    { code: 'OK', name: 'Oklahoma' }, { code: 'OR', name: 'Oregon' }, { code: 'PA', name: 'Pennsylvania' },
    { code: 'RI', name: 'Rhode Island' }, { code: 'SC', name: 'South Carolina' }, { code: 'SD', name: 'South Dakota' },
    { code: 'TN', name: 'Tennessee' }, { code: 'TX', name: 'Texas' }, { code: 'UT', name: 'Utah' },
    { code: 'VT', name: 'Vermont' }, { code: 'VA', name: 'Virginia' }, { code: 'WA', name: 'Washington' },
    { code: 'WV', name: 'West Virginia' }, { code: 'WI', name: 'Wisconsin' }, { code: 'WY', name: 'Wyoming' },
];
```

- [ ] **Step 2: Reusable address block**

```tsx
// resources/js/components/AddressFields.tsx
import { Field } from './Field';
import type { Address } from '../types';
import { US_STATES } from '../usStates';

interface AddressFieldsProps {
    legend: string;
    prefix: 'from_address' | 'to_address';
    value: Address;
    errors: Record<string, string[]> | undefined;
    onChange: (next: Address) => void;
}

export function AddressFields({ legend, prefix, value, errors, onChange }: AddressFieldsProps) {
    const errorFor = (field: keyof Address) => errors?.[`${prefix}.${field}`];
    const set = (field: keyof Address) => (e: { target: { value: string } }) =>
        onChange({ ...value, [field]: e.target.value === '' && (field === 'street2' || field === 'phone') ? null : e.target.value });

    const stateError = errorFor('state');

    return (
        <fieldset className="space-y-4 rounded-lg border border-gray-200 bg-white p-6">
            <legend className="px-2 text-sm font-semibold uppercase tracking-wide text-gray-500">{legend}</legend>
            <Field label="Full name" name={`${prefix}.name`} required value={value.name} onChange={set('name')} error={errorFor('name')} />
            <Field label="Street address" name={`${prefix}.street1`} required value={value.street1} onChange={set('street1')} error={errorFor('street1')} />
            <Field label="Apt, suite, etc. (optional)" name={`${prefix}.street2`} value={value.street2 ?? ''} onChange={set('street2')} error={errorFor('street2')} />
            <div className="grid gap-4 sm:grid-cols-3">
                <Field label="City" name={`${prefix}.city`} required value={value.city} onChange={set('city')} error={errorFor('city')} />
                <div>
                    <label htmlFor={`${prefix}.state`} className="block text-sm font-medium text-gray-700">
                        State
                    </label>
                    <select
                        id={`${prefix}.state`}
                        name={`${prefix}.state`}
                        required
                        value={value.state}
                        onChange={set('state')}
                        className={`mt-1 block w-full rounded-md border bg-white px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 ${stateError ? 'border-red-500 focus:ring-red-200' : 'border-gray-300 focus:ring-blue-200'}`}
                    >
                        <option value="">Select…</option>
                        {US_STATES.map((s) => (
                            <option key={s.code} value={s.code}>
                                {s.code} — {s.name}
                            </option>
                        ))}
                    </select>
                    {stateError && <p className="mt-1 text-sm text-red-600">{stateError[0]}</p>}
                </div>
                <Field label="ZIP" name={`${prefix}.zip`} required inputMode="numeric" placeholder="94104" value={value.zip} onChange={set('zip')} error={errorFor('zip')} />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Country" name={`${prefix}.country`} value="US" readOnly className="opacity-70" error={errorFor('country')} />
                <Field label="Phone (optional)" name={`${prefix}.phone`} type="tel" value={value.phone ?? ''} onChange={set('phone')} error={errorFor('phone')} />
            </div>
        </fieldset>
    );
}
```

- [ ] **Step 3: The form page**

```tsx
// resources/js/pages/NewLabelPage.tsx
import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router';
import { extractApiError } from '../api/client';
import { createLabel } from '../api/labels';
import { AddressFields } from '../components/AddressFields';
import { Alert } from '../components/Alert';
import { Button } from '../components/Button';
import { Field } from '../components/Field';
import type { Address, ApiError } from '../types';

const emptyAddress = (): Address => ({
    name: '',
    street1: '',
    street2: null,
    city: '',
    state: '',
    zip: '',
    country: 'US',
    phone: null,
});

type ParcelForm = { weight_oz: string; length_in: string; width_in: string; height_in: string };

export function NewLabelPage() {
    const navigate = useNavigate();
    const [fromAddress, setFromAddress] = useState<Address>(emptyAddress);
    const [toAddress, setToAddress] = useState<Address>(emptyAddress);
    const [parcel, setParcel] = useState<ParcelForm>({ weight_oz: '', length_in: '', width_in: '', height_in: '' });
    const [error, setError] = useState<ApiError | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const parcelError = (field: keyof ParcelForm) => error?.errors?.[`parcel.${field}`];
    const setParcelField = (field: keyof ParcelForm) => (e: { target: { value: string } }) =>
        setParcel((prev) => ({ ...prev, [field]: e.target.value }));

    async function handleSubmit(event: FormEvent) {
        event.preventDefault();
        setSubmitting(true);
        setError(null);
        try {
            const label = await createLabel({
                from_address: fromAddress,
                to_address: toAddress,
                parcel: {
                    weight_oz: Number(parcel.weight_oz),
                    length_in: Number(parcel.length_in),
                    width_in: Number(parcel.width_in),
                    height_in: Number(parcel.height_in),
                },
            });
            navigate(`/labels/${label.id}`);
        } catch (e) {
            setError(extractApiError(e));
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="space-y-6">
            <Link to="/labels" className="text-sm text-blue-600 hover:underline">
                ← Back to labels
            </Link>
            <h1 className="text-2xl font-semibold">New USPS label</h1>

            <form onSubmit={handleSubmit} className="space-y-6">
                <Alert message={error?.message ?? null} />

                <div className="grid gap-6 lg:grid-cols-2">
                    <AddressFields legend="From" prefix="from_address" value={fromAddress} errors={error?.errors} onChange={setFromAddress} />
                    <AddressFields legend="To" prefix="to_address" value={toAddress} errors={error?.errors} onChange={setToAddress} />
                </div>

                <fieldset className="rounded-lg border border-gray-200 bg-white p-6">
                    <legend className="px-2 text-sm font-semibold uppercase tracking-wide text-gray-500">Parcel</legend>
                    <div className="grid gap-4 sm:grid-cols-4">
                        <Field label="Weight (oz)" name="parcel.weight_oz" type="number" min={0.1} max={1120} step="0.1" required value={parcel.weight_oz} onChange={setParcelField('weight_oz')} error={parcelError('weight_oz')} />
                        <Field label="Length (in)" name="parcel.length_in" type="number" min={0.1} step="0.1" required value={parcel.length_in} onChange={setParcelField('length_in')} error={parcelError('length_in')} />
                        <Field label="Width (in)" name="parcel.width_in" type="number" min={0.1} step="0.1" required value={parcel.width_in} onChange={setParcelField('width_in')} error={parcelError('width_in')} />
                        <Field label="Height (in)" name="parcel.height_in" type="number" min={0.1} step="0.1" required value={parcel.height_in} onChange={setParcelField('height_in')} error={parcelError('height_in')} />
                    </div>
                    <p className="mt-3 text-xs text-gray-500">USPS accepts up to 70 lb (1120 oz). The cheapest USPS service is selected automatically.</p>
                </fieldset>

                <div className="flex items-center gap-3">
                    <Button type="submit" loading={submitting}>
                        Buy USPS label
                    </Button>
                    <span className="text-sm text-gray-500">Test mode: labels are watermarked "SAMPLE" and free.</span>
                </div>
            </form>
        </div>
    );
}
```

- [ ] **Step 4: Register the route**

In `resources/js/app.tsx`, add `import { NewLabelPage } from './pages/NewLabelPage';` and insert **before** the `/labels/:id` route (so `new` is not captured as an id):

```tsx
                            <Route path="/labels/new" element={<NewLabelPage />} />
```

- [ ] **Step 5: Verify**

Run: `npm run typecheck && npm run build`
Expected: clean.

Manual end-to-end with the real EasyPost test key in `.env` (`composer run dev`, logged in as demo):
1. `/labels/new`, submit empty → per-field "The … field is required." messages under inputs, no EasyPost call.
2. Fill From: `Ada Lovelace`, `417 Montgomery St`, `San Francisco`, `CA`, `94104`. To: `Grace Hopper`, `350 5th Ave`, `New York`, `NY`, `10118`. Parcel `16 / 10 / 8 / 4`. Submit.
3. Button shows "Please wait…", then navigates to `/labels/{id}` showing `USPS <service>` and a rate.
4. "View / Print label" opens a new tab with a PDF watermarked SAMPLE; the browser's print dialog works.
5. `/labels` lists the new label first. A second account (register) sees an empty list; pasting the first account's download URL while logged in as the second account returns 403.

If the real key is not available yet, skip 2-5 and note it in Task 12.

- [ ] **Step 6: Commit**

```bash
git add resources/js
git commit -m "Add new label form"
```

---

### Task 12: README, environment template, final verification

**Files:**
- Modify: `README.md` (replace the Laravel boilerplate)
- Modify: `.env.example`
- Verify: whole suite, typecheck, build, Pint

**Interfaces:**
- Consumes: everything above.
- Produces: the deliverable README required by the take-home (quick start incl. database setup, assumptions, what I'd do next).

- [ ] **Step 1: Confirm `.env.example` has every variable the app reads**

`.env.example` must contain these lines (values as shown; add any that are missing):

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=take_home_project
DB_USERNAME=root
DB_PASSWORD=password

EASYPOST_API_KEY=
SANCTUM_STATEFUL_DOMAINS=localhost:8000,127.0.0.1:8000
```

`EASYPOST_BASE_URL` and `EASYPOST_TIMEOUT` have defaults in `config/services.php` and stay out of the template.

- [ ] **Step 2: Write the README**

Replace `README.md` with:

````markdown
# USPS Shipping Labels (EasyPost take-home)

A small web app where a registered user enters a US origin address, a US destination address and package
dimensions, and gets a printable USPS shipping label bought through the [EasyPost](https://www.easypost.com/) API.
Labels are stored on the server and listed per user; users only ever see their own labels.

**Stack:** Laravel 13 (PHP 8.3) · Laravel Sanctum (SPA session auth) · MySQL 8.4 · React 19 + TypeScript · Vite · Tailwind v4

## Quick start

Prerequisites: PHP 8.3+, Composer, Node 20+, Docker (for MySQL) and an EasyPost **test** API key.

```bash
cp .env.example .env            # then set EASYPOST_API_KEY=<your EasyPost test key>
composer install && npm install
docker compose up -d            # MySQL 8.4 on 127.0.0.1:3306 (creates the app and test databases)
php artisan key:generate
php artisan migrate --seed      # creates the demo user
composer run dev                # Laravel on http://localhost:8000 + Vite dev server
```

Open `http://localhost:8000` and log in with **demo@example.com / password**, or register a new account.

Using your own MySQL instead of Docker: point the `DB_*` variables in `.env` at it, create the `take_home_project`
database (and `take_home_project_test` if you want to run the tests), then run the migrate step.

### Running the tests

```bash
docker compose up -d
php artisan test            # PHPUnit: auth, label purchase (EasyPost faked), history, isolation
npm run typecheck           # tsc --noEmit
npm run build
```

Tests never call EasyPost: every request is faked with `Http::fake()` and JSON fixtures in `tests/Fixtures/easypost/`.

## How it works

1. React posts `from_address`, `to_address` and `parcel` (oz / inches) to `POST /api/labels`.
2. `StoreLabelRequest` validates shape and enforces US-only addresses (state list, ZIP regex, `country = US`).
3. `CreateShippingLabel` calls EasyPost through `EasyPostClient`: create shipment (`label_format: PDF`) → pick the
   cheapest **USPS** rate → buy it → download the PDF → store it on the private `local` disk → save a `shipping_labels` row.
4. `GET /api/labels` lists the current user's labels; `GET /api/labels/{id}/download` streams the PDF inline for
   viewing/printing. Both are behind `auth:sanctum`; show/download also go through `ShippingLabelPolicy`.

All EasyPost calls happen in the backend. EasyPost's public label URL is stored for debugging but never returned by the API.

```
app/Actions/CreateShippingLabel.php      orchestration
app/Services/EasyPost/EasyPostClient.php the only place that talks to EasyPost
app/Services/EasyPost/RateSelector.php   lowest USPS rate
app/Http/Controllers/LabelController.php index / store / show / download
app/Http/Controllers/Auth/*              register / login / logout (Sanctum SPA mode)
resources/js/                            React + TypeScript SPA (pages, api, hooks, components)
```

## Assumptions and decisions

- **Prototype in a 4-hour box.** Functional and tested, not polished.
- **Test mode only.** Labels come back watermarked "SAMPLE" and cost nothing.
- **Carrier fixed to USPS, service picked automatically** (lowest rate). Rate selection UI was deliberately left out.
- **Imperial units** (oz, in) because shipping is US-only and that is what EasyPost expects.
- **Addresses are validated locally, not verified by EasyPost.** Strict verification in test mode tends to reject the
  fictional addresses a reviewer types; EasyPost's own errors are still surfaced as 422.
- **Labels are downloaded and stored locally** (`storage/app/private/labels/{user_id}/…`) instead of linking to
  EasyPost's public URL, so our auth layer gates every byte.
- **No retry on the buy call.** A lost response followed by a retry would purchase the same label twice. Create and
  download are retried twice on connection errors / 5xx.
- **If the buy succeeds but download/persistence fails** the request returns 502 and the shipment id + label URL are
  logged for manual recovery. No partial row is written.
- **Standard Laravel layout** rather than feature folders: two small areas (auth, labels) did not justify more structure.
- **No SDK.** Three REST calls are not worth a dependency, and the integration code stays visible.
- **Tests run against MySQL**, not sqlite, so the dialect under test is the one in production.

## What I'd do next

- Two-step flow: show the USPS rates and let the user pick the service before buying.
- EasyPost Address Verification (`verify_strict`) with a "confirm normalized address" step.
- Address book (reuse saved from/to addresses).
- Reconciliation job for labels bought at EasyPost but not persisted.
- Password reset and email verification.
- Frontend tests (Vitest + React Testing Library).
- Rate limiting and audit log on label purchases; CI running Pint, PHPUnit, typecheck and build.

## Notes on AI usage

Claude Code was used as a pair programmer: brainstorming the design, drafting the spec and implementation plan, and
generating code and tests task by task. Every decision above was made and reviewed by me, and I can walk through any
part of the codebase.
````

- [ ] **Step 3: Optional — compare fixtures against a real test-mode response**

Only if `EASYPOST_API_KEY` is set in `.env`. This checks the *shape* the tests assume; it does not replace the fixtures (their ids and prices are hard-coded in the tests).

```bash
php artisan tinker
```

```php
$client = app(\App\Services\EasyPost\EasyPostClient::class);
$shipment = $client->createShipment([
    'from_address' => ['name' => 'Ada Lovelace', 'street1' => '417 Montgomery St', 'city' => 'San Francisco', 'state' => 'CA', 'zip' => '94104', 'country' => 'US'],
    'to_address' => ['name' => 'Grace Hopper', 'street1' => '350 5th Ave', 'city' => 'New York', 'state' => 'NY', 'zip' => '10118', 'country' => 'US'],
    'parcel' => ['weight' => 16, 'length' => 10, 'width' => 8, 'height' => 4],
    'options' => ['label_format' => 'PDF'],
]);
array_keys($shipment);                       // expect: id, object, mode, rates, postage_label, tracking_code, ...
array_keys($shipment['rates'][0]);           // expect: id, carrier, service, rate, currency, ...
$rate = \App\Services\EasyPost\RateSelector::lowestUsps($shipment['rates']);
$bought = $client->buyShipment($shipment['id'], $rate['id']);
array_keys($bought['postage_label']);        // expect: label_url, label_file_type, label_size, ...
$bought['postage_label']['label_file_type']; // expect: "application/pdf"
parse_url($bought['postage_label']['label_url'], PHP_URL_HOST);
```

If the label host differs from `easypost-files.s3.us-west-2.amazonaws.com`, update `EASYPOST_LABEL` in
`tests/Concerns/FakesEasyPost.php`, the `label_url` in `tests/Fixtures/easypost/shipment_bought.json`, and the URL
assertion in `CreateLabelTest::test_shipment_is_created_as_pdf_and_the_cheapest_usps_rate_is_bought`. If any key the
action reads is missing (`rates[].carrier`, `rates[].rate`, `postage_label.label_url`, `tracking_code`,
`selected_rate`), fix the fixtures and the action before continuing.

- [ ] **Step 4: Full verification**

```bash
vendor/bin/pint
php artisan test
npm run typecheck
npm run build
git status --short   # only intended files; .env must NOT appear
```

Expected: Pint reports no changes (or fix and re-run), all PHPUnit tests pass, `tsc` is silent, Vite builds.

Then the manual end-to-end from Task 11 Step 5 with the real key, if not done yet. Record the outcome (done / skipped and why) in the final handoff message.

- [ ] **Step 5: Commit**

```bash
git add README.md .env.example
git commit -m "Write README with quick start and design notes"
```

---

## Execution notes

- **Order:** Tasks 1 → 7 are backend and each leaves the suite green. Tasks 8 → 11 are frontend and each leaves `typecheck` + `build` green. Task 12 closes.
- **Environment before starting:** `docker compose up -d`, `.env` present with `DB_*` pointing at the container. `EASYPOST_API_KEY` is only needed for the manual checks (Tasks 11 and 12); every automated test fakes EasyPost.
- **Commits:** each task ends with a commit step. The repository owner may prefer to make commits themselves; if so, stop at the commit step and report the files to stage.
- **Retry sleeps:** the client's `retry(2, 200)` adds ~400 ms to each test that exercises a 5xx path. Expected; do not "fix" by removing the retry.
