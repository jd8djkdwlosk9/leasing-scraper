<?php
// ============================================================
//  SCRAPER: ohne-anzahlung.de
//  ID:    <span class="oa-red">Angebot 7411649</span>
//         ACHTUNG: auch "oa-red oa-extra-headline" existiert → exakt matchen
//  Preis: <span class="oa-price-brutto">789,01 €</span>
//  Titel: alt="BMW iX xDrive45 - Leasing-Angebot: 7411649"
//  Bild:  src="https://assets.ohne-anzahlung.de/...@1x.jpg"
// ============================================================

function scrape_ohne_anzahlung(): array
{
    $urls = [
        'https://www.ohne-anzahlung.de/bmw-leasing/bmw-ix/',
        'https://www.ohne-anzahlung.de/bmw-leasing/bmw-ix-m70/',
    ];
    $offers = [];

    foreach ($urls as $page_url) {
        $html = http_get($page_url);
        if ($html === false) {
            echo "[WARNUNG] ohne-anzahlung.de: $page_url nicht erreichbar\n";
            continue;
        }

        // ID: exakt class="oa-red" (nicht "oa-red oa-extra-headline")
        preg_match_all('/class="oa-red">Angebot\s+(\d{6,})<\/span>/i', $html, $id_m);

        // Preis: erstes oa-price-brutto mit Zahl (zweites enthält <sup>*</sup>)
        preg_match_all('/<span[^>]+class="oa-price-brutto"[^>]*>(\d{3,4},\d{2})\s*€<\/span>/i', $html, $price_m);

        // Titel aus alt-Attribut des Bildes
        preg_match_all('/alt="([^"]+?)\s*-\s*Leasing-Angebot:\s*\d+"/i', $html, $title_m);

        // Bild @1x
        preg_match_all('/src="(https:\/\/assets\.ohne-anzahlung\.de\/[^"]+@1x\.[a-z]+)"/i', $html, $img_m);

        $ids    = $id_m[1]    ?? [];
        $prices = $price_m[1] ?? [];
        $titles = $title_m[1] ?? [];
        $images = $img_m[1]   ?? [];

        $count = min(count($ids), count($prices));
        for ($i = 0; $i < $count; $i++) {
            $offers[] = [
                'source' => 'ohne-anzahlung.de',
                'id'     => $ids[$i],
                'title'  => isset($titles[$i]) ? trim($titles[$i]) : "BMW iX – Angebot {$ids[$i]}",
                'price'  => $prices[$i] . ' €/mtl.',
                'url'    => rtrim($page_url, '/') . "/{$ids[$i]}/",
                'image'  => $images[$i] ?? '',
            ];
            debug("ohne-anzahlung.de: [{$ids[$i]}] – {$prices[$i]} €");
        }
    }
    return $offers;
}
