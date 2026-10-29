<?php

declare(strict_types=1);

namespace App;

use App\Contracts\AuthProvider;
use App\Contracts\EvidenceStorage;
use App\Contracts\Mailer;
use App\Engine\ClearanceEvaluator;
use App\Engine\InherentRiskCalculator;
use App\Engine\ResidualRiskCalculator;
use App\Engine\RiskTriggerEvaluator;
use App\Engine\TemplateMatcher;
use App\Http\View;
use App\Infrastructure\AuditLogger;
use App\Infrastructure\DemoAuthProvider;
use App\Infrastructure\DevAuthProvider;
use App\Infrastructure\LocalEvidenceStorage;
use App\Infrastructure\LogMailer;
use App\Infrastructure\PdoConnection;
use App\Infrastructure\PortalSessionAuthProvider;
use App\Repositories;
use App\Services;
use App\Support\Clock;
use App\Support\Config;

/**
 * Hand-wired service container. Swap CU's real AuthProvider / Mailer / EvidenceStorage
 * by changing the three factory methods marked "ADAPTER" — no business logic changes needed.
 */
final class App
{
    /** @var array<string,object> */
    private array $instances = [];

    public function __construct(public readonly Config $config, public readonly string $root, private ?Clock $clock = null)
    {
    }

    /**
     * @template T of object
     * @param class-string<T>|string $key
     * @param callable():T $make
     * @return T
     */
    private function once(string $key, callable $make): object
    {
        return $this->instances[$key] ??= $make();
    }

    public function clock(): Clock
    {
        return $this->clock ??= new Clock();
    }

    public function db(): PdoConnection
    {
        return $this->once(PdoConnection::class, fn () => PdoConnection::fromConfig($this->config));
    }

    // --- ADAPTER: identity ------------------------------------------------------------
    public function auth(): AuthProvider
    {
        return $this->once(AuthProvider::class, fn () => match ($this->config->get('AUTH_ADAPTER', 'portal_session')) {
            'dev' => new DevAuthProvider($this->config->isDev()),
            'demo' => new DemoAuthProvider((string) $this->config->get('DEMO_PASSWORD', '')),
            default => new PortalSessionAuthProvider((string) $this->config->get('PORTAL_SESSION_USER_KEY', 'portal_user'), (string) $this->config->get('PORTAL_LOGIN_URL', '/login')),
        });
    }

    // --- ADAPTER: mail ------------------------------------------------------------------
    public function mailer(): Mailer
    {
        return $this->once(Mailer::class, fn () => new LogMailer(
            rtrim((string) $this->config->get('LOG_PATH', $this->root . '/storage/logs'), '/') . '/mail.log',
            (string) $this->config->get('MAIL_FROM', 'privacy-risk@localhost'),
        ));
    }

    // --- ADAPTER: evidence storage -------------------------------------------------------
    public function storage(): EvidenceStorage
    {
        return $this->once(EvidenceStorage::class, fn () => new LocalEvidenceStorage(
            rtrim((string) $this->config->get('STORAGE_PATH', $this->root . '/storage/evidence'), '/'),
            $this->config->int('EVIDENCE_MAX_BYTES', 10 * 1024 * 1024),
        ));
    }

    public function view(): View
    {
        return $this->once(View::class, fn () => new View($this->root . '/templates'));
    }

    public function audit(): AuditLogger
    {
        return $this->once(AuditLogger::class, fn () => new AuditLogger($this->db()));
    }

    // --- repositories --------------------------------------------------------------------
    public function refs(): Repositories\ReferenceRepository
    {
        return $this->once(Repositories\ReferenceRepository::class, fn () => new Repositories\ReferenceRepository($this->db()));
    }

    public function configs(): Repositories\ConfigRepository
    {
        return $this->once(Repositories\ConfigRepository::class, fn () => new Repositories\ConfigRepository($this->db()));
    }

    public function entities(): Repositories\RiskEntityRepository
    {
        return $this->once(Repositories\RiskEntityRepository::class, fn () => new Repositories\RiskEntityRepository($this->db()));
    }

    public function controls(): Repositories\ControlRepository
    {
        return $this->once(Repositories\ControlRepository::class, fn () => new Repositories\ControlRepository($this->db()));
    }

    public function remediationRepo(): Repositories\RemediationRepository
    {
        return $this->once(Repositories\RemediationRepository::class, fn () => new Repositories\RemediationRepository($this->db()));
    }

    public function approvalsRepo(): Repositories\ApprovalRepository
    {
        return $this->once(Repositories\ApprovalRepository::class, fn () => new Repositories\ApprovalRepository($this->db()));
    }

    public function templatesRepo(): Repositories\TemplateRepository
    {
        return $this->once(Repositories\TemplateRepository::class, fn () => new Repositories\TemplateRepository($this->db()));
    }

    public function auditRepo(): Repositories\AuditRepository
    {
        return $this->once(Repositories\AuditRepository::class, fn () => new Repositories\AuditRepository($this->db()));
    }

    public function dashboardRepo(): Repositories\DashboardRepository
    {
        return $this->once(Repositories\DashboardRepository::class, fn () => new Repositories\DashboardRepository($this->db()));
    }

    // --- services -------------------------------------------------------------------------
    public function authz(): Services\Authorizer
    {
        return $this->once(Services\Authorizer::class, fn () => new Services\Authorizer());
    }

    public function catalog(): Services\FactorCatalog
    {
        return $this->once(Services\FactorCatalog::class, fn () => new Services\FactorCatalog($this->refs(), $this->entities()));
    }

    public function triggers(): RiskTriggerEvaluator
    {
        return $this->once(RiskTriggerEvaluator::class, fn () => new RiskTriggerEvaluator());
    }

    public function statusTransitioner(): Services\StatusTransitioner
    {
        return $this->once(Services\StatusTransitioner::class, fn () => new Services\StatusTransitioner($this->entities(), $this->audit()));
    }

    public function recommendations(): Services\RecommendationService
    {
        return $this->once(Services\RecommendationService::class, fn () => new Services\RecommendationService($this->controls(), $this->entities(), $this->catalog(), $this->triggers()));
    }

    public function residual(): Services\ResidualService
    {
        return $this->once(Services\ResidualService::class, fn () => new Services\ResidualService(
            $this->entities(), $this->controls(), $this->recommendations(), new ClearanceEvaluator(), new ResidualRiskCalculator(), $this->audit(), $this->clock(),
        ));
    }

    public function people(): Services\PeopleDirectory
    {
        return $this->once(Services\PeopleDirectory::class, fn () => new Services\PeopleDirectory($this->refs(), $this->auth()));
    }

    public function notifier(): Services\Notifier
    {
        return $this->once(Services\Notifier::class, fn () => new Services\Notifier($this->mailer(), $this->auth(), $this->refs(), (string) $this->config->get('APP_BASE_URL', '')));
    }

    public function assessments(): Services\AssessmentService
    {
        return $this->once(Services\AssessmentService::class, fn () => new Services\AssessmentService(
            $this->db(), $this->entities(), $this->refs(), $this->configs(), $this->controls(), $this->catalog(),
            new InherentRiskCalculator(), $this->residual(), $this->statusTransitioner(), $this->authz(), $this->audit(), $this->clock(),
        ));
    }

    public function controlService(): Services\ControlService
    {
        return $this->once(Services\ControlService::class, fn () => new Services\ControlService(
            $this->db(), $this->assessments(), $this->controls(), $this->configs(), $this->recommendations(), $this->residual(),
            new ClearanceEvaluator(), $this->statusTransitioner(), $this->storage(), $this->authz(), $this->audit(), $this->clock(),
        ));
    }

    public function approvals(): Services\ApprovalService
    {
        return $this->once(Services\ApprovalService::class, fn () => new Services\ApprovalService(
            $this->db(), $this->assessments(), $this->entities(), $this->approvalsRepo(), $this->configs(),
            $this->statusTransitioner(), $this->authz(), $this->audit(), $this->notifier(), $this->clock(),
        ));
    }

    public function remediation(): Services\RemediationService
    {
        return $this->once(Services\RemediationService::class, fn () => new Services\RemediationService(
            $this->db(), $this->remediationRepo(), $this->assessments(), $this->authz(), $this->audit(), $this->clock(),
        ));
    }

    public function templates(): Services\TemplateService
    {
        return $this->once(Services\TemplateService::class, fn () => new Services\TemplateService(
            $this->db(), $this->templatesRepo(), $this->controls(), $this->entities(), $this->configs(), $this->assessments(),
            $this->controlService(), $this->catalog(), new InherentRiskCalculator(), new TemplateMatcher(),
            $this->statusTransitioner(), $this->authz(), $this->audit(), $this->clock(),
        ));
    }

    public function dashboard(): Services\DashboardService
    {
        return $this->once(Services\DashboardService::class, fn () => new Services\DashboardService(
            $this->dashboardRepo(), $this->remediation(), $this->configs(),
            Services\StoryGenerator::fromFile($this->root . '/config/stories.php'), $this->authz(), $this->clock(),
        ));
    }

    public function admin(): Services\AdminService
    {
        return $this->once(Services\AdminService::class, fn () => new Services\AdminService(
            $this->db(), $this->configs(), $this->refs(), $this->controls(), $this->triggers(), $this->authz(), $this->audit(),
        ));
    }

    public function jobs(): Services\JobService
    {
        return $this->once(Services\JobService::class, fn () => new Services\JobService(
            $this->db(), $this->entities(), $this->dashboardRepo(), $this->statusTransitioner(), $this->notifier(), $this->audit(), $this->clock(),
        ));
    }
}
