<?php
declare(strict_types=1);
// ============================================================
//  GitHub-Actions-Scraper – erzeugt offers.json
//  Läuft auf GitHubs Servern (wechselnde IPs). KEINE Telegram/
//  Gemini-Aufrufe. ap86.de holt sich nur die fertige offers.json.
// ============================================================
date_default_timezone_set('Europe/Berlin');

// Nachtruhe 20–7 Uhr: nichts tun (altes offers.json unangetastet lassen)
$h = (int)date('H');
if ($h < 7 || $h >= 20) {
    fwrite(STDERR, "Nachtruhe ($h Uhr Berlin) – kein Scrape.\n");
    exit(0);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/equipment.php';
require_once __DIR__ . '/scrapers/ohne_anzahlung.php';
require_once __DIR__ . '/scrapers/toprate24.php';
require_once __DIR__ . '/scrapers/goleasy.php';
require_once __DIR__ . '/scrapers/leasingmarkt.php';
require_once __DIR__ . '/scrapers/toprate.php';
require_once __DIR__ . '/scrapers/mister_leasing.php';

$scrapers = [
    'ohne-anzahlung.de' => 'scrape_ohne_anzahlung',
    'toprate24.de'      => 'scrape_toprate24',
    'goleasy.de'        => 'scrape_goleasy',
    'leasingmarkt.de'   => 'scrape_leasingmarkt',
    'toprate.de'        => 'scrape_toprate',
    'mister-leasing.de' => 'scrape_mister_leasing',
];

$all = [];
foreach ($scrapers as $label => $fn) {
    fwrite(STDERR, "Scrape $label ...\n");
    try {
        $all = array_merge($all, $fn());
    } catch (\Throwable $e) {
        fwrite(STDERR, "  FEHLER $label: " . $e->getMessage() . "\n");
    }
}
fwrite(STDERR, count($all) . " Rohangebote.\n");

// Globales Preislimit (falls gesetzt)
if (defined('PRICE_MAX_GLOBAL') && PRICE_MAX_GLOBAL > 0) {
    $g = (int)PRICE_MAX_GLOBAL;
    $all = array_values(array_filter($all, fn($o) => parse_price($o['price']) <= $g));
}

// Farbe + Ausstattung anreichern (Budget pro Lauf; Cache in data/equipment_cache.json)
if (defined('WISHLIST_FILTER_ENABLED') && WISHLIST_FILTER_ENABLED) {
    $budget = defined('WISHLIST_MAX_FETCHES_PER_RUN') ? WISHLIST_MAX_FETCHES_PER_RUN : 40;
    $all = apply_wishlist_filter($all, $budget);
}

// Sicherheitsnetz: bei 0 Angeboten (Totalausfall) altes offers.json NICHT überschreiben
if (count($all) === 0) {
    fwrite(STDERR, "0 Angebote – offers.json bleibt unverändert (Schutz).\n");
    exit(0);
}

$payload = [
    'generated' => gmdate('c'),
    'count'     => count($all),
    'offers'    => $all,
];
file_put_contents(__DIR__ . '/offers.json',
    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
fwrite(STDERR, "offers.json geschrieben: " . count($all) . " Angebote.\n");
