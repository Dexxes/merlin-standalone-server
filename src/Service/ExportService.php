<?php

declare(strict_types=1);

namespace Merlin\Service;

/**
 * Port von merlin-nextcloud/lib/Service/ExportService.php - identisches
 * HTML/CSS-Template, nur auf das Array-basierte Article-Model von
 * merlin-server umgestellt ($article['title'] statt $article->getTitle()).
 */
final class ExportService {
    /**
     * @param array $article Roh-Zeile aus ArticleRepository (snake_case Spalten)
     */
    public function exportHtml(array $article): string {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>{$this->escape($article['title'])}</title>
	<style>
		body {
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
			line-height: 1.6;
			max-width: 800px;
			margin: 0 auto;
			padding: 20px;
			color: #333;
		}
		h1 {
			font-size: 2em;
			margin-bottom: 0.5em;
			color: #000;
		}
		.metadata {
			color: #666;
			font-style: italic;
			margin-bottom: 2em;
			padding-bottom: 1em;
			border-bottom: 1px solid #eee;
		}
		.content {
			font-size: 1.1em;
		}
		.content img {
			max-width: 100%;
			height: auto;
		}
		.content a {
			color: #0082c9;
			text-decoration: none;
		}
		.content a:hover {
			text-decoration: underline;
		}
		@media (prefers-color-scheme: dark) {
			body {
				background-color: #1e1e1e;
				color: #e0e0e0;
			}
			h1 {
				color: #fff;
			}
			.metadata {
				color: #999;
				border-bottom-color: #333;
			}
		}
	</style>
</head>
<body>
	<article>
		<h1>{$this->escape($article['title'])}</h1>
		<div class="metadata">
HTML;

        $metadata = [];
        if (!empty($article['author'])) {
            $metadata[] = 'By ' . $this->escape($article['author']);
        }
        if (!empty($article['site_name'])) {
            $metadata[] = $this->escape($article['site_name']);
        }
        $metadata[] = $this->formatDate($article['created_at']);

        $html .= implode(' &bull; ', $metadata);

        $html .= <<<HTML
		</div>
		<div class="content">
			{$article['content']}
		</div>
	</article>
	<footer style="margin-top: 3em; padding-top: 1em; border-top: 1px solid #eee; color: #999; font-size: 0.9em;">
		<p>Saved from: <a href="{$this->escape($article['url'])}">{$this->escape($article['url'])}</a></p>
		<p>Exported from Merlin on {$this->escape($this->formatDate(gmdate('c')))}</p>
	</footer>
</body>
</html>
HTML;

        return $html;
    }

    private function formatDate(string $isoDate): string {
        $date = date_create($isoDate) ?: null;
        return $date === null ? $isoDate : $date->format('F j, Y');
    }

    private function escape(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
