<?php
declare(strict_types=1);
// ============================================================
//  GitHub-Actions-Scraper – erzeugt offers.json
//  Läuft auf GitHubs Servern (wechselnde IPs). KEINE Telegram/
//  Gemini-Aufrufe. ap86.de holt sich nur die fertige offers.json.
// ============================================================
date_default_timezone_set('Europe/Berlin');

// Nachtruhe 20–7 Uhr: nichts tun – AUSSER bei manuellem Check (FORCE_SCRAPE=true).
$force = (getenv('FORCE_SCRAPE') === 'true');
$h = (int)date('H');
if (!$force && ($h < 7 || $h >= 20)) {
    fwrite(STDERR, "Nachtruhe ($h Uhr Berlin) – kein Scrape.\n");
    exit(0);
}
if ($force) fwrite(STDERR, "Manueller Lauf (FORCE) – Nachtruhe übergangen.\n");

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/equipment.php';
require_once __DIR__ . '/scrapers/ohne_anzahlung.php';
require_once __DIR__ . '/scrapers/toprate24.php';
require_once __DIR__ . '/scrapers/goleasy.php';
require_once __DIR__ . '/scrapers/leasingmarkt.php';
require_once __DIR__ . '/scrapers/toprate.php';
require_once __DIR__ . '/scrapers/mister_leasing.php';

// toprate.de wird bewusst NICHT über GitHub gescrapt: toprate blockiert
// Cloud-/Rechenzentrums-IPs (Azure). ap86 holt toprate direkt (dessen IP
// wird von toprate akzeptiert) und mischt es zu diesen 5 Quellen.
$scrapers = [
    'ohne-anzahlung.de' => 'scrape_ohne_anzahlung',
    'toprate24.de'      => 'scrape_toprate24',
    'goleasy.de'        => 'scrape_goleasy',
    'leasingmarkt.de'   => 'scrape_leasingmarkt',
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

// Sicherheitsnetz gegen unvollständige Läufe (Drossel): altes offers.json NICHT
// mit Teildaten überschreiben. Blockiert, wenn (a) 0 Angebote, (b) eine zuvor gut
// gefüllte Quelle komplett wegbricht, oder (c) die Gesamtzahl < 50% des Vorstands.
if (count($all) === 0) {
    fwrite(STDERR, "0 Angebote – offers.json bleibt unverändert (Schutz).\n");
    exit(0);
}
$prevRaw = @file_get_contents(__DIR__ . '/offers.json');
$prev    = $prevRaw ? json_decode($prevRaw, true) : null;
if (is_array($prev) && !empty($prev['offers'])) {
    $pbys = []; foreach ($prev['offers'] as $o) { $s = $o['source'] ?? ''; $pbys[$s] = ($pbys[$s] ?? 0) + 1; }
    $nbys = []; foreach ($all as $o) { $s = $o['source'] ?? ''; $nbys[$s] = ($nbys[$s] ?? 0) + 1; }
    $collapsed = [];
    foreach ($pbys as $s => $pc) {
        if ($pc >= 5 && ($nbys[$s] ?? 0) === 0) $collapsed[] = "$s ($pc->0)";
    }
    $bigDrop = count($all) < 0.5 * count($prev['offers']);
    if ($collapsed || $bigDrop) {
        fwrite(STDERR, "UNVOLLSTÄNDIG – offers.json NICHT überschrieben. "
            . ($collapsed ? "Weggebrochen: " . implode(', ', $collapsed) . ". " : "")
            . "Neu " . count($all) . " vs. vorher " . count($prev['offers']) . ".\n");
        exit(0);
    }
}

$payload = [
    'generated' => gmdate('c'),
    'count'     => count($all),
    'offers'    => $all,
];
file_put_contents(__DIR__ . '/offers.json',
    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
fwrite(STDERR, "offers.json geschrieben: " . count($all) . " Angebote.\n");
