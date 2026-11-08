<?php
/**
 * Sitemap for search engines: the public pages Google should list.
 * Submit <live address>/sitemap.php in Google Search Console → Sitemaps.
 */
require_once __DIR__ . '/../config/supabase.php';

header('Content-Type: application/xml; charset=UTF-8');

$pages = [
    ['index.php', 'daily', '1.0'],
    ['events.php', 'daily', '0.9'],
    ['organizations.php', 'weekly', '0.7'],
];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($pages as [$path, $freq, $priority]): ?>
  <url>
    <loc><?= htmlspecialchars(app_url($path), ENT_XML1) ?></loc>
    <changefreq><?= $freq ?></changefreq>
    <priority><?= $priority ?></priority>
  </url>
<?php endforeach; ?>
</urlset>
