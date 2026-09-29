-- Icon der konkreten Artikelseite (apple-touch-icon / <link rel="icon"> / Tile-Image /
-- favicon.ico), beim Extrahieren aus dem HTML gelesen (siehe ContentExtractorService::
-- extractSiteIconUrl()). Wird NICHT in Artikellisten ausgeliefert, sondern nur als
-- supportBox.iconUrl im Einzelabruf (Service\SupportBoxService). NULL bei Artikeln, die vor
-- dieser Migration gespeichert wurden - dort fällt die Box auf /favicon.ico der Origin zurück.
ALTER TABLE articles ADD COLUMN site_icon_url TEXT;
