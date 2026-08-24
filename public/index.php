<?php

declare(strict_types=1);

use Merlin\App;
use Merlin\Controller\AccountController;
use Merlin\Controller\AdminController;
use Merlin\Controller\ArticleController;
use Merlin\Controller\ContentFilterController;
use Merlin\Controller\HighlightController;
use Merlin\Controller\LoginFlowController;
use Merlin\Controller\PageController;
use Merlin\Controller\PublicShareController;
use Merlin\Controller\ShareController;
use Merlin\Controller\SiteCredentialController;
use Merlin\Controller\TagController;
use Merlin\Controller\TtsController;
use Merlin\Controller\UserContentFilterController;
use Merlin\Controller\UserSettingsController;
use Merlin\Controller\VideoStreamController;
use Merlin\Http\Middleware\AdminOnlyMiddleware;
use Merlin\Http\Middleware\AuthMiddleware;
use Merlin\Http\Request;
use Merlin\Http\Response;
use Merlin\Http\Router;

require_once __DIR__ . '/../vendor/autoload.php';

$app = new App();
$basePath = (string) $app->config('base_path', '/');

Response::setBasePath($basePath);

$auth = new AuthMiddleware($app->users(), $app->apiTokenRepository(), $app->apiTokenService(), $app->sessions());
$adminOnly = new AdminOnlyMiddleware();

$pages = new PageController(
    $app->users(),
    $app->settings(),
    $app->passwordHasher(),
    $app->passwordResetService(),
    $app->sessions(),
    $app->loginFlows(),
    $app->apiTokenService(),
    $app->userSettings(),
);
$account = new AccountController($app->apiTokenRepository(), $app->apiTokenService());
$admin = new AdminController($app->users(), $app->settings(), $app->passwordHasher(), $app->contentFilterRepository(), $app->siteCredentialRepository());
$articles = new ArticleController($app->articles(), $app->tags(), $app->contentExtractor(), $app->exportService(), $app->logger());
$contentFilters = new ContentFilterController(
    $app->contentFilterRepository(),
    $app->contentFilterValidator(),
    $app->contentFilterMerger(),
    $app->contentExtractor(),
    $app->logger(),
    $app->sessions(),
);
$userContentFilters = new UserContentFilterController(
    $app->contentFilterRepository(),
    $app->contentFilterValidator(),
    $app->contentFilterMerger(),
    $app->contentExtractor(),
    $app->logger(),
    $app->sessions(),
);
$siteCredentials = new SiteCredentialController($app->siteCredentialService(), $app->sessions());
$tags = new TagController($app->tags(), $app->articles());
$highlights = new HighlightController($app->highlights(), $app->articles());
$loginFlow = new LoginFlowController($app->loginFlows(), $app->users());
$userSettings = new UserSettingsController($app->userSettings());
$share = new ShareController($app->articles(), $app->articleShares());
$tts = new TtsController($app->articles(), $app->ttsStream());
$videoStream = new VideoStreamController($app->articles(), $app->videoStreamResolver());
$publicShare = new PublicShareController(
    $app->articleShares(),
    $app->articles(),
    $app->highlights(),
    $app->ttsStream(),
    $app->sessions(),
);

$router = new Router();

// HTML-Seiten
$router->add('GET', '/login', $pages->loginForm(...));
$router->add('POST', '/login', $pages->login(...));
$router->add('GET', '/logout', $pages->logout(...));
$router->add('GET', '/register', $pages->registerForm(...));
$router->add('POST', '/register', $pages->register(...));
$router->add('GET', '/password/forgot', $pages->passwordForgotForm(...));
$router->add('POST', '/password/forgot', $pages->passwordForgot(...));
$router->add('GET', '/password/reset', $pages->passwordResetForm(...));
$router->add('POST', '/password/reset', $pages->passwordReset(...));
$router->add('GET', '/account', $pages->account(...));
$router->add('GET', '/admin', $pages->admin(...));
$router->add('GET', '/admin/content-filters', $pages->adminContentFilters(...));
$router->add('GET', '/account/content-filters', $pages->personalContentFilters(...));
$router->add('GET', '/library', $pages->library(...));
$router->add('GET', '/articles/{id}', $pages->articleReader(...));
$router->add('GET', '/', fn() => Response::redirect('/library'));
$router->add('GET', '/lang/{code}', $pages->setLanguage(...));

// Login-Flow-v2-Klon (siehe LoginFlowController-Docblock): unauthentifiziert,
// bildet Nextclouds Login-Flow-v2-Protokoll nach, damit native Clients ihre
// bestehende Login-Flow-Logik gegen merlin-server wiederverwenden können.
$router->add('POST', '/login/v2', $loginFlow->start(...));
$router->add('POST', '/login/v2/poll', $loginFlow->poll(...));
$router->add('GET', '/login/v2/flow/{token}', $pages->loginFlowForm(...));
$router->add('POST', '/login/v2/flow/{token}', $pages->loginFlowSubmit(...));

// Konto-API (Session- oder API-Token-Auth)
$router->add('GET', '/account/tokens', $account->listTokens(...), [$auth->handle(...)]);
$router->add('POST', '/account/tokens', $account->createToken(...), [$auth->handle(...)]);
$router->add('DELETE', '/account/tokens/{id}', $account->revokeToken(...), [$auth->handle(...)]);

// Admin-API
$router->add('GET', '/admin/users', $admin->listUsers(...), [$auth->handle(...), $adminOnly->handle(...)]);
$router->add('POST', '/admin/users', $admin->createUser(...), [$auth->handle(...), $adminOnly->handle(...)]);
$router->add('PUT', '/admin/users/{id}', $admin->updateUser(...), [$auth->handle(...), $adminOnly->handle(...)]);
$router->add('DELETE', '/admin/users/{id}', $admin->deleteUser(...), [$auth->handle(...), $adminOnly->handle(...)]);
$router->add('GET', '/admin/settings', $admin->getSettings(...), [$auth->handle(...), $adminOnly->handle(...)]);
$router->add('PUT', '/admin/settings', $admin->updateSettings(...), [$auth->handle(...), $adminOnly->handle(...)]);

// Content-Filter-Verwaltung (Admin-Custom-Ebene, instanzweit)
$router->add('GET', '/api/admin/content-filters', $contentFilters->index(...), [$auth->handle(...), $adminOnly->handle(...)]);
$router->add('GET', '/api/admin/content-filters/{domain}', $contentFilters->show(...), [$auth->handle(...), $adminOnly->handle(...)]);
$router->add('PUT', '/api/admin/content-filters/{domain}', $contentFilters->update(...), [$auth->handle(...), $adminOnly->handle(...)]);
$router->add('DELETE', '/api/admin/content-filters/{domain}', $contentFilters->destroy(...), [$auth->handle(...), $adminOnly->handle(...)]);
$router->add('GET', '/api/admin/content-filters/{domain}/export', $contentFilters->export(...), [$auth->handle(...), $adminOnly->handle(...)]);
$router->add('POST', '/api/admin/content-filters/{domain}/test', $contentFilters->test(...), [$auth->handle(...), $adminOnly->handle(...)]);

// Persönliche Content-Filter-Overrides (jeder eingeloggte Nutzer, eigener Routen-Präfix)
$router->add('GET', '/api/user/content-filters', $userContentFilters->index(...), [$auth->handle(...)]);
$router->add('GET', '/api/user/content-filters/{domain}', $userContentFilters->show(...), [$auth->handle(...)]);
$router->add('PUT', '/api/user/content-filters/{domain}', $userContentFilters->update(...), [$auth->handle(...)]);
$router->add('DELETE', '/api/user/content-filters/{domain}', $userContentFilters->destroy(...), [$auth->handle(...)]);
$router->add('POST', '/api/user/content-filters/{domain}/test', $userContentFilters->test(...), [$auth->handle(...)]);

// Paywall-Abo-Zugangsdaten (jeder eingeloggte Nutzer, eigene private Ebene)
$router->add('GET', '/api/user/site-credentials', $siteCredentials->index(...), [$auth->handle(...)]);
$router->add('PUT', '/api/user/site-credentials/{domain}', $siteCredentials->update(...), [$auth->handle(...)]);
$router->add('DELETE', '/api/user/site-credentials/{domain}', $siteCredentials->destroy(...), [$auth->handle(...)]);

// Artikel-API
$router->add('GET', '/api/articles/counts', $articles->counts(...), [$auth->handle(...)]);
$router->add('GET', '/api/articles/search', $articles->search(...), [$auth->handle(...)]);
$router->add('GET', '/api/articles', $articles->index(...), [$auth->handle(...)]);
$router->add('POST', '/api/articles', $articles->create(...), [$auth->handle(...)]);
$router->add('GET', '/api/articles/{id}', $articles->show(...), [$auth->handle(...)]);
$router->add('PUT', '/api/articles/{id}', $articles->update(...), [$auth->handle(...)]);
$router->add('DELETE', '/api/articles/{id}', $articles->destroy(...), [$auth->handle(...)]);
$router->add('PUT', '/api/articles/{id}/read', $articles->toggleRead(...), [$auth->handle(...)]);
$router->add('PUT', '/api/articles/{id}/favorite', $articles->toggleFavorite(...), [$auth->handle(...)]);
$router->add('PUT', '/api/articles/{id}/archive', $articles->toggleArchive(...), [$auth->handle(...)]);
$router->add('PUT', '/api/articles/{id}/progress', $articles->updateProgress(...), [$auth->handle(...)]);
$router->add('GET', '/api/articles/{id}/export/html', $articles->exportHtml(...), [$auth->handle(...)]);

// Tag-API
$router->add('GET', '/api/tags', $tags->index(...), [$auth->handle(...)]);
$router->add('POST', '/api/tags', $tags->create(...), [$auth->handle(...)]);
$router->add('PUT', '/api/tags/{id}', $tags->update(...), [$auth->handle(...)]);
$router->add('DELETE', '/api/tags/{id}', $tags->destroy(...), [$auth->handle(...)]);
$router->add('POST', '/api/articles/{articleId}/tags/{tagId}', $tags->addToArticle(...), [$auth->handle(...)]);
$router->add('DELETE', '/api/articles/{articleId}/tags/{tagId}', $tags->removeFromArticle(...), [$auth->handle(...)]);

// Highlight-API
$router->add('GET', '/api/articles/{articleId}/highlights', $highlights->index(...), [$auth->handle(...)]);
$router->add('POST', '/api/articles/{articleId}/highlights', $highlights->create(...), [$auth->handle(...)]);
$router->add('DELETE', '/api/highlights/{id}', $highlights->destroy(...), [$auth->handle(...)]);

// Settings-API (Settings-Sync)
$router->add('GET', '/api/settings', $userSettings->get(...), [$auth->handle(...)]);
$router->add('PUT', '/api/settings', $userSettings->update(...), [$auth->handle(...)]);

// Share-API (authentifiziert - Verwaltung eigener Share-Links)
$router->add('GET', '/api/articles/{articleId}/share', $share->show(...), [$auth->handle(...)]);
$router->add('POST', '/api/articles/{articleId}/share', $share->create(...), [$auth->handle(...)]);
$router->add('PUT', '/api/articles/{articleId}/share', $share->update(...), [$auth->handle(...)]);
$router->add('POST', '/api/articles/{articleId}/share/regenerate', $share->regenerate(...), [$auth->handle(...)]);
$router->add('DELETE', '/api/articles/{articleId}/share', $share->destroy(...), [$auth->handle(...)]);

// TTS-API (authentifiziert)
$router->add('GET', '/api/articles/{id}/tts', $tts->synthesize(...), [$auth->handle(...)]);

// Native ARD/ZDF/Arte-Stream-Auflösung (siehe VideoStreamResolverService-Docblock)
$router->add('GET', '/api/articles/{id}/video-stream', $videoStream->resolve(...), [$auth->handle(...)]);

// Public Share (unauthentifiziert - öffentliche Ansicht eines geteilten Artikels)
$router->add('GET', '/s/{token}', $publicShare->show(...));
$router->add('POST', '/s/{token}/unlock', $publicShare->unlock(...));
$router->add('GET', '/s/{token}/data', $publicShare->data(...));
$router->add('GET', '/s/{token}/tts', $publicShare->tts(...));

$request = Request::fromGlobals($basePath);
$router->dispatch($request)->send();
