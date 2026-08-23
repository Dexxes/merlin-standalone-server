<?php

declare(strict_types=1);

namespace Merlin;

use Merlin\Auth\ApiTokenService;
use Merlin\Auth\CredentialCipher;
use Merlin\Auth\PasswordHasher;
use Merlin\Auth\PasswordResetService;
use Merlin\Auth\SessionService;
use Merlin\Db\ApiTokenRepository;
use Merlin\Db\ArticleRepository;
use Merlin\Db\ArticleShareRepository;
use Merlin\Db\ContentFilterRepository;
use Merlin\Db\Database;
use Merlin\Db\HighlightRepository;
use Merlin\Db\LoginFlowRepository;
use Merlin\Db\PasswordResetRepository;
use Merlin\Db\SettingsRepository;
use Merlin\Db\SiteCredentialRepository;
use Merlin\Db\TagRepository;
use Merlin\Db\UserRepository;
use Merlin\Db\UserSettingsRepository;
use Merlin\Logging\FileLogger;
use Merlin\Mail\MailerInterface;
use Merlin\Mail\PhpMailMailer;
use Merlin\Service\ContentExtractorService;
use Merlin\Service\ContentFilterMerger;
use Merlin\Service\ContentFilterValidator;
use Merlin\Service\ExportService;
use Merlin\Service\SiteCredentialService;
use Merlin\Service\TtsStreamService;
use Merlin\Service\VideoStreamResolverService;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Schlanker manueller Dependency-Container (kein Framework, siehe Plan) -
 * einmal pro Request (index.php) bzw. pro CLI-Aufruf (tools/*.php) gebaut,
 * lazy Instanziierung über die Getter.
 */
final class App {
    private readonly array $config;
    private ?PDO $db = null;
    private ?LoggerInterface $logger = null;
    private ?UserRepository $users = null;
    private ?ApiTokenRepository $apiTokenRepository = null;
    private ?PasswordResetRepository $passwordResetRepository = null;
    private ?SettingsRepository $settings = null;
    private ?ArticleRepository $articles = null;
    private ?TagRepository $tags = null;
    private ?HighlightRepository $highlights = null;
    private ?LoginFlowRepository $loginFlows = null;
    private ?UserSettingsRepository $userSettings = null;
    private ?ArticleShareRepository $articleShares = null;
    private ?TtsStreamService $ttsStream = null;
    private ?PasswordHasher $passwordHasher = null;
    private ?ApiTokenService $apiTokenService = null;
    private ?MailerInterface $mailer = null;
    private ?PasswordResetService $passwordResetService = null;
    private ?SessionService $sessions = null;
    private ?ContentExtractorService $contentExtractor = null;
    private ?VideoStreamResolverService $videoStreamResolver = null;
    private ?ExportService $exportService = null;
    private ?ContentFilterMerger $contentFilterMerger = null;
    private ?ContentFilterRepository $contentFilterRepository = null;
    private ?ContentFilterValidator $contentFilterValidator = null;
    private ?CredentialCipher $credentialCipher = null;
    private ?SiteCredentialRepository $siteCredentialRepository = null;
    private ?SiteCredentialService $siteCredentialService = null;

    public function __construct() {
        $configFile = __DIR__ . '/../config/config.php';
        if (!is_file($configFile)) {
            throw new \RuntimeException(
                'config/config.php fehlt - config/config.sample.php kopieren und anpassen.'
            );
        }
        $this->config = require $configFile;
    }

    public function config(string $key, mixed $default = null): mixed {
        return $this->config[$key] ?? $default;
    }

    public function db(): PDO {
        return $this->db ??= Database::connection();
    }

    public function logger(): LoggerInterface {
        return $this->logger ??= new FileLogger((string) $this->config('log_path'));
    }

    public function users(): UserRepository {
        return $this->users ??= new UserRepository($this->db());
    }

    public function apiTokenRepository(): ApiTokenRepository {
        return $this->apiTokenRepository ??= new ApiTokenRepository($this->db());
    }

    public function passwordResetRepository(): PasswordResetRepository {
        return $this->passwordResetRepository ??= new PasswordResetRepository($this->db());
    }

    public function settings(): SettingsRepository {
        return $this->settings ??= new SettingsRepository($this->db());
    }

    public function articles(): ArticleRepository {
        return $this->articles ??= new ArticleRepository($this->db());
    }

    public function tags(): TagRepository {
        return $this->tags ??= new TagRepository($this->db());
    }

    public function highlights(): HighlightRepository {
        return $this->highlights ??= new HighlightRepository($this->db());
    }

    public function loginFlows(): LoginFlowRepository {
        return $this->loginFlows ??= new LoginFlowRepository($this->db());
    }

    public function userSettings(): UserSettingsRepository {
        return $this->userSettings ??= new UserSettingsRepository($this->db());
    }

    public function articleShares(): ArticleShareRepository {
        return $this->articleShares ??= new ArticleShareRepository($this->db());
    }

    public function ttsStream(): TtsStreamService {
        $ttsConfig = (array) $this->config('tts', []);
        return $this->ttsStream ??= new TtsStreamService((string) ($ttsConfig['daemon_url'] ?? 'http://127.0.0.1:5051'));
    }

    public function passwordHasher(): PasswordHasher {
        return $this->passwordHasher ??= new PasswordHasher();
    }

    public function apiTokenService(): ApiTokenService {
        return $this->apiTokenService ??= new ApiTokenService($this->apiTokenRepository());
    }

    public function mailer(): MailerInterface {
        $mailConfig = (array) $this->config('mail', []);
        return $this->mailer ??= new PhpMailMailer(
            (string) ($mailConfig['from_address'] ?? 'merlin@localhost'),
            (string) ($mailConfig['from_name'] ?? 'Merlin'),
        );
    }

    public function passwordResetService(): PasswordResetService {
        return $this->passwordResetService ??= new PasswordResetService(
            $this->users(),
            $this->passwordResetRepository(),
            $this->apiTokenRepository(),
            $this->passwordHasher(),
            $this->mailer(),
            (string) $this->config('base_url', ''),
            (int) $this->config('password_reset_ttl', 3600),
        );
    }

    public function sessions(): SessionService {
        return $this->sessions ??= new SessionService();
    }

    public function exportService(): ExportService {
        return $this->exportService ??= new ExportService();
    }

    public function contentFilterMerger(): ContentFilterMerger {
        return $this->contentFilterMerger ??= new ContentFilterMerger($this->logger());
    }

    public function contentFilterRepository(): ContentFilterRepository {
        return $this->contentFilterRepository ??= new ContentFilterRepository(
            $this->db(),
            $this->logger(),
            $this->contentFilterMerger(),
        );
    }

    public function contentFilterValidator(): ContentFilterValidator {
        return $this->contentFilterValidator ??= new ContentFilterValidator();
    }

    public function contentExtractor(): ContentExtractorService {
        return $this->contentExtractor ??= new ContentExtractorService(
            $this->logger(),
            $this->contentFilterRepository(),
            $this->siteCredentialService(),
        );
    }

    public function videoStreamResolver(): VideoStreamResolverService {
        return $this->videoStreamResolver ??= new VideoStreamResolverService($this->logger());
    }

    public function credentialCipher(): CredentialCipher {
        return $this->credentialCipher ??= new CredentialCipher((string) $this->config('credential_cipher_key', ''));
    }

    public function siteCredentialRepository(): SiteCredentialRepository {
        return $this->siteCredentialRepository ??= new SiteCredentialRepository($this->db());
    }

    public function siteCredentialService(): SiteCredentialService {
        return $this->siteCredentialService ??= new SiteCredentialService(
            $this->siteCredentialRepository(),
            $this->contentFilterRepository(),
            $this->credentialCipher(),
            $this->logger(),
        );
    }
}
