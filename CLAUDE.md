# CLAUDE.md

Invoice Ninja v5 backend: a Laravel 12 / PHP 8.2+ app with three parts.

- The REST API (`/api/v1`). The React admin UI is a separate repo, checked out
  at `../../invoiceninja-ui/invoiceninja-ui`.
- The Blade + Livewire client and vendor portals.
- Background jobs: recurring invoices, reminders, auto-bill, e-invoicing, bank
  sync and more.

The default branch is `v5-develop`, and PRs target it. `v5-stable` is the
release branch.

## Commands

```sh
composer install
php artisan migrate && php artisan db:seed          # seeds static data (countries, currencies, gateways, ...)
php artisan ninja:post-update                        # post-deploy housekeeping, also run in CI
vendor/bin/phpunit --testsuite Feature               # suites: Unit, Integration, Feature, Pdf
vendor/bin/phpunit tests/Feature/ProductTest.php     # single file
vendor/bin/phpunit --filter testRequiredFields tests/Feature/ProductTest.php
vendor/bin/phpstan analyse                           # level 5, app/ + Modules/, with baseline
vendor/bin/php-cs-fixer fix                          # composer format
```

- Tests need a real MySQL/MariaDB database that is migrated and seeded. Most
  feature tests use `DatabaseTransactions`, not in-memory SQLite. CI copies
  `.env.ci`, runs the migrations and seeders, then `tests/ci`, which shards
  test classes over `CI_NODE_TOTAL` nodes.
- `phpunit.xml` uses `processIsolation` and `stopOnFailure`. It sets
  `PDF_GENERATOR=simulator`, so PDFs are not rendered. Tests that need real
  PDFs opt in through `UsesRealPdfGeneration` / `PDF_GENERATOR_REAL`.
- Test data can be generated with `php artisan ninja:create-test-data` /
  `ninja:create-single-account`. The UI's Playwright e2e tests also need
  `db:seed --class=RandomDataSeeder`.

## Request lifecycle (API)

`routes/api.php` puts the authenticated API under the `token_auth`,
`valid_json` and `locale` middleware with the prefix `api/v1`. Most entities
are registered with `Route::resource(...)`, plus `POST <entity>/bulk` and
`PUT <entity>/{id}/upload`.

Each entity (Product, Client, Invoice, ...) has the same set of files:

| Layer | Location | Notes |
|---|---|---|
| Controller | `app/Http/Controllers/XController.php` | Extends `BaseController` and sets `$entity_type` and `$entity_transformer`. Returns `$this->itemResponse($model)` or `$this->listResponse($query)`. |
| Form requests | `app/Http/Requests/X/{Store,Update,Show,Edit,Create,Destroy,Bulk,Upload}XRequest.php` | Extend `App\Http\Requests\Request`. `authorize()` calls policies (`$user->can('edit', $this->product)`). `prepareForValidation()` calls `decodePrimaryKeys()`. `rules()` should return `$this->globalRules($rules)`. |
| Policy | `app/Policies/XPolicy.php` | Permission checks (`edit_invoice`, owned or assigned entities, admin). |
| Factory | `app/Factory/XFactory.php` | `XFactory::create($company_id, $user_id)` builds a blank model. These are not Laravel model factories. |
| Repository | `app/Repositories/XRepository.php` | `save($data, $model)`. `BaseRepository` handles archive, restore and delete, and fires the `App\Events\X\XWas{Archived,Restored,Deleted}` events. |
| Filters | `app/Filters/XFilters.php` | Query-string filtering, used as `X::filter($filters)`. |
| Transformer | `app/Transformers/XTransformer.php` | Fractal. Encodes IDs and supports `?include=`. |
| Service | `app/Services/X/` | Business logic, chained from the model: `$invoice->service()->markSent()->save()`. |
| Events, listeners, jobs | `app/Events`, `app/Listeners`, `app/Jobs` | Activities, webhooks, emails, ledger updates. |

When adding a field to an entity, touch the migration, the model `$fillable`,
the request rules, the transformer and the repository if needed, and add a
feature test. Also update the OpenAPI spec in `openapi/` and the UI repo's
`src/common/interfaces`.

## Key concepts

- **Hashed IDs**: primary keys never leave the API as integers. The
  `MakesHash` trait does the conversion
  (`encodePrimaryKey` / `decodePrimaryKey`, Hashids with `ninja.hash_salt`).
  Models expose `$model->hashed_id`. `BaseModel::resolveRouteBinding` rejects
  numeric IDs and includes trashed records. Incoming `*_id` fields are decoded
  in each request's `prepareForValidation`.
- **Multi-tenancy**: the hierarchy is Account → Company → CompanyUser → User.
  Each `CompanyToken` (`X-API-TOKEN`) is bound to one company. Scope queries
  with `->company()` (the `BaseModel::scopeCompany` scope uses
  `auth()->user()->companyId()`). There is no global scope, so a query without
  the company scope can leak data from other tenants.
- **Multi-DB (hosted)**: hosted runs several databases (`db-ninja-01`, ...).
  `App\Libraries\MultiDB` and the `SetDb*` middleware choose the connection
  per request. Queued jobs must re-set it (`MultiDB::setDb($this->db)`).
  Self-hosted runs with `MULTI_DB_ENABLED=false`.
- **Hosted vs self-hosted**: `Ninja::isHosted()` / `Ninja::isSelfHost()`
  (`config('ninja.environment')`). Plans and limits apply only on hosted.
- **Settings cascade**: settings resolve in the order Client → GroupSetting →
  Company. The schemas live in `app/DataMapper/CompanySettings.php` /
  `ClientSettings.php`. Read them with `$client->getSetting('key')`, not the
  company settings directly.
- **Money**: use the BcMath helpers (see `tests/Unit/BcMath*`) and the invoice
  calculators (`app/Helpers/Invoice`) rather than float arithmetic.
- **PDFs**: rendering lives in `app/Services/Pdf` / `PdfMaker` and uses
  Twig/HTML designs. The renderer is chosen by `PDF_GENERATOR` (snappdf,
  hosted_ninja, phantom, or simulator in tests).
- **E-invoicing**: ZUGFeRD/Factur-X, UBL, Peppol, FACe and Verifactu live in
  `app/Services/EDocument`.
- **Payment gateways**: there is one driver per gateway in
  `app/PaymentDrivers/*PaymentDriver.php`, extending `BaseDriver`.
- **Portals**: the client portal (`routes/client.php`), vendor portal
  (`routes/vendor.php`) and billing portal use Blade views in
  `resources/views` and Livewire components in `app/Livewire`.
- **Translations**: `lang/<locale>/texts.php` is the source of truth. The
  React UI's `en.json` mirrors its keys (`php artisan ninja:translations`). Add
  new keys to `lang/en/texts.php` and use `ctrans('texts.key')`.
- **Modules**: `nwidart/laravel-modules`. `modules_statuses.json` enables
  `Admin`, which comes from the sibling `../admin-api` path repository and is
  not present in a plain checkout.
- **React bundle**: the release workflow builds the UI repo into `public/`
  and `resources/views/react/index.blade.php`. Don't edit those built files by
  hand.

## Tests

- `tests/TestCase.php` is the base class. Most feature tests combine
  `use MockAccountData; use DatabaseTransactions;`, call
  `$this->makeTestData()` in `setUp()`, and send requests with the headers
  `X-API-SECRET => config('ninja.api_secret')` and
  `X-API-TOKEN => $this->token`. `MockAccountData` provides `$this->company`,
  `$this->user`, `$this->client`, `$this->invoice`, `$this->vendor`, and so on.
- Decode returned IDs with `$this->decodePrimaryKey($arr['data']['id'])`.
- Suites: `tests/Unit` (pure logic), `tests/Feature` (HTTP and API),
  `tests/Integration`, `tests/Pdf`. Shared traits live in `tests/Concerns`,
  fixtures in `tests/Fixtures`.

## Code style

- PSR-12 via php-cs-fixer. Most PHP files start with the Invoice Ninja license
  docblock (`@copyright ... @license https://www.elastic.co/licensing/elastic-license`).
- New code should pass phpstan level 5. Don't grow `phpstan-baseline.neon`.
- `auth()->user()` is used directly in controllers and requests, often with a
  `/** @var \App\Models\User $user */` hint.
