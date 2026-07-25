<?php
/**
 * Dynamic XML Sitemap
 * Tells search engines (Google, Bing, etc.) which pages to index.
 * URL: https://yourdomain.com/SBA/sitemap.php
 */
require_once __DIR__ . '/config/config.php';

$baseUrl = rtrim(APP_URL, '/');
$today   = date('Y-m-d');

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9
          http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">

  <!-- Main landing / login page -->
  <url>
    <loc><?php echo htmlspecialchars($baseUrl); ?>/login.php</loc>
    <lastmod><?php echo $today; ?></lastmod>
    <changefreq>monthly</changefreq>
    <priority>1.0</priority>
  </url>

  <!-- School registration page -->
  <url>
    <loc><?php echo htmlspecialchars($baseUrl); ?>/school_register.php</loc>
    <lastmod><?php echo $today; ?></lastmod>
    <changefreq>monthly</changefreq>
    <priority>0.9</priority>
  </url>

</urlset>
