-- Signalisiert ein an einer Paywall gescheitertes Speichern (siehe
-- Service\Login\PaywallLoginRequiredException, ArticleController::
-- scheduleExtraction()): requires_login_domain ist NULL im Normalfall, sonst
-- die Domain, für die der Nutzer Zugangsdaten hinterlegen muss - Grundlage
-- für den Login-Dialog in den Clients (siehe PLATFORMS.md). Port von
-- merlin-nextclouds Migration Version1000Date20240101000022.
ALTER TABLE articles ADD COLUMN requires_login_domain TEXT;
ALTER TABLE articles ADD COLUMN requires_login_page TEXT;
