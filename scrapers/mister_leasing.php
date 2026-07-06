<?php
// ============================================================
//  SCRAPER: mister-leasing.de
//  Supabase REST-API mit eigenem API-Key
//  Nur BMW iX (kein iX1/2/3), Privat, Elektro
//
//  Strategie: API-Filter bewusst LOCKER halten (nur Marke + verfügbar),
//  die eigentliche Filterung (Modell, Preis, Laufzeit) passiert in PHP.
//  So verpassen wir keine Varianten wie M70 die anders kategorisiert sind.
// ============================================================

function scrape_mister_leasing(): array
{
    $price_max = price_cap('PRICE_MAX_MISTER', 900);

    // API-Filter nur grob: BMW + verfügbar + privat-fähig
    // Modell/Preis/Laufzeit filtern wir unten in PHP
    $api_url = 'https://rpmzovgeudcbljqjsjpb.supabase.co/rest/v1/vehicles?'
             . 'select=id,offer_id,brand,model,variant,image_url,monthly_rate_gross,'
             . 'monthly_rate,lease_duration,annual_mileage,power_hp,transmission,'
             . 'fuel_type,vehicle_type,leasing_type,available,exterior_color,gross_list_price'
             . '&available=eq.true'
             . '&brand=ilike.%25bmw%25'
             . '&order=monthly_rate.asc&limit=200';

    $key = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InJwbXpvdmdldWRjYmxqcWpzanBiIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NjE3MTY4OTQsImV4cCI6MjA3NzI5Mjg5NH0.VdQ3TkWcZcMUfZrBAEmyQ8k5xqJVCIiUlFJ0qiQr_pc';
    // Hinweis: Falls der Key abläuft, im Browser-Netzwerktab den aktuellen 'apikey' Header kopieren

    $json = http_get($api_url, [
        'Accept: application/json',
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
    ]);

    if (!$json) {
        echo "[WARNUNG] mister-leasing.de: API nicht erreichbar\n";
        return [];
    }

    $vehicles = json_decode($json, true);
    if (!is_array($vehicles)) {
        echo "[WARNUNG] mister-leasing.de: Unerwartetes Format\n";
        return [];
    }

    debug("mister-leasing.de: " . count($vehicles) . " Fahrzeuge von API erhalten");

    $offers = [];
    foreach ($vehicles as $v) {
        $model   = trim($v['model']   ?? '');
        $variant = trim($v['variant'] ?? '');
        $brand   = trim($v['brand']   ?? 'BMW');

        // ── Modell-Filter: echter iX, kein iX1/iX2/iX3 ──────────
        // model kann "iX", "ix", "iX M70" etc. sein – variant separat
        $full_model = trim($model . ' ' . $variant);

        // Muss "iX" enthalten (Wortgrenze), aber nicht iX1/iX2/iX3
        $is_ix = preg_match('/\biX\b/i', $full_model) || preg_match('/^ix\b/i', $model);
        if (!$is_ix) continue;
        if (preg_match('/\biX[123]\b/i', $full_model)) continue;

        // ── Elektro-Filter (locker) ─────────────────────────────
        $fuel = strtolower($v['fuel_type'] ?? '');
        if ($fuel && !str_contains($fuel, 'elektro') && !str_contains($fuel, 'electric')) continue;

        // ── Privat-fähig ────────────────────────────────────────
        $ltype = strtolower($v['leasing_type'] ?? '');
        if ($ltype && !str_contains($ltype, 'privat') && !str_contains($ltype, 'both')
                   && !str_contains($ltype, 'gewerbe_privat')) {
            continue;
        }

        // ── Laufzeit 24–36 Monate ───────────────────────────────
        $duration = (int)($v['lease_duration'] ?? 0);
        if ($duration > 0 && ($duration < 24 || $duration > 36)) continue;

        // ── Preis ───────────────────────────────────────────────
        $rate = (float)($v['monthly_rate_gross'] ?? $v['monthly_rate'] ?? 0);
        if ($rate < 100 || $rate > $price_max) continue;

        // ── Angebot bauen ───────────────────────────────────────
        $id      = (string)($v['offer_id'] ?? $v['id'] ?? uniqid());
        $hp      = $v['power_hp']      ?? '';
        $mileage = $v['annual_mileage'] ?? '';
        $img     = $v['image_url']      ?? '';

        $title = trim("$brand $model" . ($variant ? " $variant" : ''));
        $title = preg_replace('/\s+/', ' ', $title);
        if ($hp) $title .= " – {$hp} PS";

        $mileage_fmt = is_numeric($mileage) ? number_format((int)$mileage, 0, ',', '.') : $mileage;
        $price = number_format($rate, 2, ',', '.') . " €/mtl."
               . ($duration ? " ({$duration}M" . ($mileage_fmt ? "/{$mileage_fmt}km" : '') . ")" : '');

        $link = $id ? "https://mister-leasing.de/fahrzeug/" . urlencode($id) : 'https://mister-leasing.de/suche?brand=bmw';

        $offers[$id] = [
            'source' => 'mister-leasing.de',
            'id'     => $id,
            'title'  => $title,
            'price'  => $price,
            'url'    => $link,
            'image'  => $img,
            'color'  => trim((string)($v['exterior_color'] ?? '')),
            'list_price' => (int)round((float)($v['gross_list_price'] ?? 0)),
        ];
        debug("mister-leasing.de: [$id] $title – $price");
    }

    $offers = array_values($offers);

    if (empty($offers)) {
        echo "[INFO] mister-leasing.de: Keine BMW iX Angebote ≤{$price_max}€ gefunden.\n";
    } else {
        echo "[INFO] mister-leasing.de: " . count($offers) . " Angebote gefunden.\n";
    }

    return $offers;
}