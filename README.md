# CU Unified Privacy Risk Module (Vendor Risk Assessment + DPIA)

One shared risk-and-controls engine with two entry points (vendor assessment and DPIA), embedded in the Covenant University portal. Built to `prd.md` v1.0. Design decisions: [docs/DECISIONS.md](docs/DECISIONS.md). Schema handover: [docs/SCHEMA.md](docs/SCHEMA.md). API: [docs/API.md](docs/API.md).

## Run it locally (Docker)

```bash
cp .env.example .env
docker compose up -d                       # PostgreSQL 17 + PHP 8.3 built-in server on http://localhost:8090
docker compose exec app composer install
docker compose exec app php bin/console migrate
docker compose exec app php bin/console seed --demo   # reference data + demo users, vendors, DPIAs, templates
```

Open http://localhost:8090. In development you choose a seeded user, one per role (`dev.requestor`, `dev.privacy_dpo`, `dev.admin`, …). There are no passwords: in production, identity comes from the portal.

Without demo data, use `php bin/console seed`, which loads departments, factor options, scoring config v1 and the control library only.

### Tests

```bash
docker compose exec db psql -U privacy_risk -c "CREATE DATABASE privacy_risk_test"   # once
docker compose exec app vendor/bin/phpunit                 # all (125 tests)
docker compose exec app vendor/bin/phpunit --testsuite unit # engine + RBAC only, no database
```

The integration suite rebuilds `privacy_risk_test` from scratch (override with `TEST_DB_DSN`). It is skipped if the database is unreachable.

### Scheduled job

Run daily from cron:

```
php bin/console jobs:daily
```

It moves decided assessments into monitoring, flags reassessments that are due, sends reminders through `Mailer`, and writes the dashboard snapshot.

## Integrating with the CU portal

Everything CU owns sits behind three interfaces in `src/Contracts`. Swap them in `src/App.php` (the methods marked `ADAPTER`); no business logic changes are needed.

| Interface | Dev implementation | CU replaces with |
|---|---|---|
| `AuthProvider` | `DevAuthProvider` (user switcher, dev only); `PortalSessionAuthProvider` reads `$_SESSION[PORTAL_SESSION_USER_KEY]` | The portal's SSO/session (PHP session, JWT or SAML/OIDC; PRD 17.9) |
| `Mailer` | `LogMailer` → `storage/logs/mail.log` | CU SMTP |
| `EvidenceStorage` | `LocalEvidenceStorage` → `STORAGE_PATH` (outside the web root) | CU file store |

Module roles are mapped to portal user IDs in `user_roles` (Admin → User roles). Set `APP_BASE_PATH` when mounting under a sub-path of the portal. For production: `APP_ENV=prod`, `AUTH_ADAPTER=portal_session`, and serve `public/` only.

## Code map

```
public/index.php          front controller (session, Kernel)
src/Engine/               PURE scoring: InherentRiskCalculator, ClearanceEvaluator, ResidualRiskCalculator,
                          TemplateMatcher, RiskTriggerEvaluator, ScoringConfig, LargestRemainder
src/Domain/               enums: Rating, Factor, ControlCategory, EntityType, Role, Permission, AssessmentStatus, …
src/Services/             workflow: Assessment, Control, Residual, Approval, Remediation, Template (fast path),
                          Dashboard + StoryGenerator, Admin, Job; Authorizer (the single RBAC check)
src/Repositories/         SQL (PDO prepared statements only)
src/Controllers/Web|Api/  HTML screens and REST API (/api/v1)
src/Infrastructure/       PdoConnection, AuditLogger (append-only), Migrator, dev adapters
templates/                server-rendered PHP views (escaped with e())
config/stories.php        dashboard story wording (edit freely)
database/migrations|seeds SQL migrations, reference seed, DemoSeeder
```

## PRD acceptance criteria → where they are proven

| PRD 15 | Test |
|---|---|
| 8.5 → 3.85 High; 8.6 → 3.83 High; vendor rescale; band boundaries; weights from config, ≠100% rejected | `tests/Unit/Engine/InherentRiskCalculatorTest`, `ScoringConfigTest` |
| All seven 9.5 residual cases | `tests/Unit/Engine/ResidualRiskCalculatorTest` |
| Vendor-risk rule: validation + DB CHECK; excluded everywhere without sharing; live vendor score with sharing | `tests/Integration/VendorRiskRuleTest` |
| Fast path: JAMB template prefills, shows 3.85/High + standard controls; customise rescoring; stale warning | `tests/Integration/FastPathTest` |
| Dashboard: % sum to 100; counts match lists; department totals; overdue; ≥3 stories with links; hidden without data | `tests/Integration/DashboardTest` |
| RBAC per role against the matrix; endpoints enforce it; CSRF; XSS escaping; audit append-only; evidence allow-list | `tests/Unit/Domain/AuthorizerTest`, `tests/Integration/HttpTest`, `WorkflowTest` |

## Not built (Phase 2 per PRD 16)

Vendor self-service portal, automatic propagation and notification when a vendor's score changes (DPIAs pick it up on their next calculation), dynamic approval routing beyond the configurable tier table, escalations, contract-renewal triggers, and AI features. Open business questions remain in PRD section 17. Our additional choices are in `docs/DECISIONS.md`.
