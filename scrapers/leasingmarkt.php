<?php
// ============================================================
//  SCRAPER: leasingmarkt.de  (Next.js RSC / __next_f.push)
//
//  Daten sind doppelt escaped: \\\" im rohen HTML
//  Zwei Suchen: nr=0 (Gebraucht) + nr=1 (Neuwagen)
// ============================================================

function scrape_leasingmarkt(): array
{
    $price_max = price_cap('PRICE_MAX_LEASINGMARKT', 900);
    // mglpt = Preisgrenze in der Such-URL; bei globalem Limit mitwachsen lassen (+Puffer)
    $lm_cap = (defined('PRICE_MAX_GLOBAL') && PRICE_MAX_GLOBAL > 0) ? (int)(PRICE_MAX_GLOBAL + 100) : 800;

    $base = 'https://www.leasingmarkt.de/listing?v=2&yd=1&ins=1&tg=PRIVATE&sort=rate'
          . '&nc=1&mn=13&mag=%5B%222105%22%5D&mglpt=' . $lm_cap . '&df=24&dt=36'
          . '&excludedSpecialConditions=%5B%22ti%22%2C%22db%22%5D&epf=340';

    $offers = [];

    foreach (['0' => 'Gebraucht', '1' => 'Neuwagen'] as $nr => $label) {
        $url  = $base . '&nr=' . $nr;
        $html = http_get($url, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: de-DE,de;q=0.9',
            'Cache-Control: no-cache',
            'Upgrade-Insecure-Requests: 1',
        ]);

        if (!$html) {
            echo "[WARNUNG] leasingmarkt.de ($label): Seite nicht erreichbar\n";
            continue;
        }

        $found = leasingmarkt_parse($html, $price_max, $label);
        foreach ($found as $o) {
            $offers[$o['id']] = $o;
        }
    }

    $offers = array_values($offers);

    if (empty($offers)) {
        echo "[INFO] leasingmarkt.de: Keine BMW iX Angebote ≤{$price_max}€ gefunden.\n";
    } else {
        echo "[INFO] leasingmarkt.de: " . count($offers) . " Angebote gefunden.\n";
    }

    return $offers;
}

function leasingmarkt_parse(string $html, float $price_max, string $label): array
{
    // Daten sind einfach escaped: \" im rohen HTML
    // Erst dekodieren: \" → "
    $decoded = str_replace('\\"', '"', $html);

    // Jetzt sind die Angebote als normales JSON lesbar
    // Split am Anfang jedes Listing-Objekts
    $parts = preg_split('/"id":\d{6,9},"targetGroup"/', $decoded);

    if (count($parts) < 2) {
        echo "[INFO] leasingmarkt.de ($label): Keine Angebote gefunden\n";
        return [];
    }

    $offers = [];

    foreach (array_slice($parts, 1) as $block) {
        // URL mit listing-ID
        if (!preg_match('/"url":"(\/leasing\/pkw\/bmw-ix\/(\d+)[^"]+)"/', $block, $url_m)) continue;
        $url = 'https://www.leasingmarkt.de' . $url_m[1];
        $lid = $url_m[2];

        // Bild
        $img = '';
        if (preg_match('/"imageUrl":"(https:\/\/www\.leasingmarkt\.de\/data\/resized\/[^"]+)"/', $block, $img_m)) {
            $img = $img_m[1];
        }

        // Titel – matcht "BMW ix iX xDrive45" UND "BMW ix xDrive45" (mit/ohne zweites iX)
        if (!preg_match('/"headline":"(BMW ix [^"]{1,150})"/', $block, $head_m)) continue;
        $title = $head_m[1];
        $title = str_replace('\\', '', $title);                      // \" Zoll-Reste entfernen
        $title = trim(preg_replace('/[\x{1F300}-\x{1F9FF}⚡]/u', '', $title)); // Emojis raus

        // iX1/iX2/iX3 ausschließen
        if (preg_match('/\biX[123]\b/i', $title)) continue;

        // leasingOffer
        if (!preg_match(
            '/"leasingOffer":\{"id":\d+,"leaseTotalAmount":[^,]+,"duration":(\d+),"includedMileage":(\d+),"monthlyRate":(\d+(?:\.\d+)?)/',
            $block, $rate_m
        )) continue;

        $dur  = (int)$rate_m[1];
        $km   = (int)$rate_m[2];
        $rate = (float)$rate_m[3];

        if ($rate < 50 || $rate > $price_max) continue;

        $km_fmt = number_format($km, 0, ',', '.') . ' km/Jahr';
        $id     = 'lm_' . $lid;

        debug("leasingmarkt.de [$label]: [$id] $title – {$rate}€ ({$dur}M/{$km_fmt})");

        $offers[$id] = [
            'source' => 'leasingmarkt.de',
            'id'     => $id,
            'title'  => $title . " ({$dur}M / {$km_fmt})",
            'price'  => number_format($rate, 2, ',', '.') . ' €/mtl.',
            'url'    => $url,
            'image'  => $img,
        ];
    }

    return $offers;
}