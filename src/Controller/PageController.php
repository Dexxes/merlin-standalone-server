<?php

declare(strict_types=1);

namespace Merlin\Controller;

use Merlin\Auth\ApiTokenService;
use Merlin\Auth\PasswordHasher;
use Merlin\Auth\PasswordResetService;
use Merlin\Auth\SessionService;
use Merlin\Db\LoginFlowRepository;
use Merlin\Db\SettingsRepository;
use Merlin\Db\UserRepository;
use Merlin\Http\Request;
use Merlin\Http\Response;

/**
 * HTML-Seiten für Login/Registrierung/Passwort-Reset/Konto/Admin/Login-Flow -
 * die "nötigsten HTML-Seiten" aus dem Plan, kein Vue-Build. Formulare senden
 * klassische POSTs; die Konto-/Admin-Seiten laden ihre Daten per Fetch von
 * der JSON-API (AccountController/AdminController) nach.
 */
final class PageController {
    public function __construct(
        private readonly UserRepository $users,
        private readonly SettingsRepository $settings,
        private readonly PasswordHasher $hasher,
        private readonly PasswordResetService $passwordReset,
        private readonly SessionService $sessions,
        private readonly LoginFlowRepository $loginFlows,
        private readonly ApiTokenService $apiTokenService,
    ) {
    }

    public function loginForm(Request $request): Response {
        if ($this->sessions->currentUserId() !== null) {
            return Response::redirect('/library');
        }
        return $this->render('login', [
            'allowSelfRegistration' => $this->settings->getBool(SettingsRepository::ALLOW_SELF_REGISTRATION),
        ]);
    }

    public function login(Request $request): Response {
        $username = (string) $request->input('username', '');
        $password = (string) $request->input('password', '');

        $user = $this->users->findByUsername($username);
        if ($user === null || !$this->hasher->verify($password, $user['password_hash'])) {
            return $this->render('login', ['error' => 'Benutzername oder Passwort ist falsch.']);
        }
        if (!(bool) $user['is_active']) {
            return $this->render('login', ['error' => 'Dieses Konto ist deaktiviert.']);
        }

        $this->sessions->login((int) $user['id']);
        return Response::redirect('/library');
    }

    public function logout(Request $request): Response {
        $this->sessions->logout();
        return Response::redirect('/login');
    }

    public function registerForm(Request $request): Response {
        if (!$this->settings->getBool(SettingsRepository::ALLOW_SELF_REGISTRATION)) {
            return Response::redirect('/login');
        }
        return $this->render('register');
    }

    public function register(Request $request): Response {
        if (!$this->settings->getBool(SettingsRepository::ALLOW_SELF_REGISTRATION)) {
            return Response::redirect('/login');
        }

        $username = trim((string) $request->input('username', ''));
        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');

        $error = $this->validateNewAccount($username, $email, $password);
        if ($error !== null) {
            return $this->render('register', ['error' => $error]);
        }

        $user = $this->users->create($username, $email, $this->hasher->hash($password), 'user');
        $this->sessions->login((int) $user['id']);
        return Response::redirect('/library');
    }

    public function passwordForgotForm(Request $request): Response {
        return $this->render('password_forgot');
    }

    public function passwordForgot(Request $request): Response {
        $email = trim((string) $request->input('email', ''));
        $this->passwordReset->requestReset($email);
        // Immer dieselbe Meldung, unabhängig davon ob die E-Mail existiert -
        // sonst liesse sich die Existenz von Accounts erraten.
        return $this->render('password_forgot', [
            'message' => 'Falls diese E-Mail-Adresse bekannt ist, wurde ein Link zum Zurücksetzen verschickt.',
        ]);
    }

    public function passwordResetForm(Request $request): Response {
        return $this->render('password_reset', ['token' => $request->query('token', '')]);
    }

    public function passwordReset(Request $request): Response {
        $token = (string) $request->input('token', '');
        $password = (string) $request->input('password', '');

        if (strlen($password) < 8) {
            return $this->render('password_reset', ['token' => $token, 'error' => 'Das Passwort muss mindestens 8 Zeichen lang sein.']);
        }

        try {
            $this->passwordReset->resetPassword($token, $password);
        } catch (\RuntimeException) {
            return $this->render('password_reset', ['token' => $token, 'error' => 'Der Link ist ungültig oder abgelaufen.']);
        }

        return Response::redirect('/login');
    }

    public function account(Request $request): Response {
        $userId = $this->sessions->currentUserId();
        if ($userId === null) {
            return Response::redirect('/login');
        }
        $user = $this->users->findById($userId);
        if ($user === null) {
            return Response::redirect('/login');
        }

        return $this->render('account', [
            'username' => $user['username'],
            'isAdmin' => $user['role'] === 'admin',
        ]);
    }

    public function admin(Request $request): Response {
        $userId = $this->sessions->currentUserId();
        $user = $userId === null ? null : $this->users->findById($userId);
        if ($user === null || $user['role'] !== 'admin') {
            return Response::redirect('/login');
        }

        return $this->render('admin_users');
    }

    public function adminContentFilters(Request $request): Response {
        $userId = $this->sessions->currentUserId();
        $user = $userId === null ? null : $this->users->findById($userId);
        if ($user === null || $user['role'] !== 'admin') {
            return Response::redirect('/login');
        }

        return $this->render('admin_content_filters');
    }

    public function personalContentFilters(Request $request): Response {
        if ($this->sessions->currentUserId() === null) {
            return Response::redirect('/login');
        }

        return $this->render('personal_content_filters');
    }

    public function library(Request $request): Response {
        $userId = $this->sessions->currentUserId();
        if ($userId === null) {
            return Response::redirect('/login');
        }
        $user = $this->users->findById($userId);

        return $this->render('library', ['isAdmin' => $user !== null && $user['role'] === 'admin']);
    }

    /**
     * Reines Template-Shell wie library() - Ownership-Prüfung der Artikel-ID
     * passiert bereits in ArticleController::show() (404 JSON), die Seite
     * selbst zeigt bei fehlendem/fremdem Artikel nur eine Meldung an.
     */
    public function articleReader(Request $request): Response {
        if ($this->sessions->currentUserId() === null) {
            return Response::redirect('/login');
        }

        return $this->render('article_reader', ['articleId' => (int) $request->routeParam('id')]);
    }

    /**
     * HTML-Teil des Login-Flow-v2-Klons (siehe LoginFlowController für den
     * JSON-Teil): Browser-Login-Formular, das der Client-seitige Login-Flow
     * in einem neuen Tab öffnet. Wiederverwendet dasselbe Template wie /login,
     * nur mit dem Flow-Token durchgereicht statt direkt einzuloggen.
     */
    public function loginFlowForm(Request $request): Response {
        $flowToken = (string) $request->routeParam('token');
        $flow = $this->loginFlows->findByFlowToken($flowToken);

        if ($flow === null || $flow['expires_at'] < gmdate('c')) {
            return $this->render('login_flow', ['state' => 'invalid']);
        }
        if ($flow['user_id'] !== null) {
            // Bereits abgeschlossen (z.B. Reload nach Erfolg) - kein erneutes Token ausstellen.
            return $this->render('login_flow', ['state' => 'done']);
        }

        return $this->render('login_flow', ['state' => 'form', 'flowToken' => $flowToken]);
    }

    public function loginFlowSubmit(Request $request): Response {
        $flowToken = (string) $request->routeParam('token');
        $flow = $this->loginFlows->findByFlowToken($flowToken);

        if ($flow === null || $flow['expires_at'] < gmdate('c') || $flow['user_id'] !== null) {
            return $this->render('login_flow', ['state' => 'invalid']);
        }

        $username = (string) $request->input('username', '');
        $password = (string) $request->input('password', '');

        $user = $this->users->findByUsername($username);
        if ($user === null || !$this->hasher->verify($password, $user['password_hash'])) {
            return $this->render('login_flow', [
                'state' => 'form',
                'flowToken' => $flowToken,
                'error' => 'Benutzername oder Passwort ist falsch.',
            ]);
        }
        if (!(bool) $user['is_active']) {
            return $this->render('login_flow', [
                'state' => 'form',
                'flowToken' => $flowToken,
                'error' => 'Dieses Konto ist deaktiviert.',
            ]);
        }

        $result = $this->apiTokenService->create((int) $user['id'], 'Login Flow');
        $this->loginFlows->complete((int) $flow['id'], (int) $user['id'], $result['plainText']);

        // Gleich auch die Browser-Session einloggen - ein Klick auf /account
        // direkt nach dem Verbinden fragt sonst erneut nach Zugangsdaten.
        $this->sessions->login((int) $user['id']);

        return $this->render('login_flow', ['state' => 'done']);
    }

    private function validateNewAccount(string $username, string $email, string $password): ?string {
        if ($username === '' || $email === '' || strlen($password) < 8) {
            return 'Bitte alle Felder ausfüllen (Passwort mindestens 8 Zeichen).';
        }
        if ($this->users->findByUsername($username) !== null) {
            return 'Dieser Benutzername ist bereits vergeben.';
        }
        if ($this->users->findByEmail($email) !== null) {
            return 'Diese E-Mail-Adresse wird bereits verwendet.';
        }
        return null;
    }

    private function render(string $template, array $vars = []): Response {
        extract($vars);
        ob_start();
        include __DIR__ . "/../../templates/{$template}.php";
        return Response::html((string) ob_get_clean());
    }
}
