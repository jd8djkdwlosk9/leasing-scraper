<?php
// ============================================================
//  SCRAPER: toprate24.de
//  Preis: <span class="nobr ">700,&ndash;&nbsp;€</span>
//         oder 1.035,&ndash;&nbsp;€  (Tausenderpunkt!)
//  PROBLEM: "NP 144055.-" enthält ebenfalls Zahlen mit Punkt
//  LÖSUNG: Preis-Span wird strikt gematcht, Zahl max 4-stellig
//          vor Komma ODER mit genau einem Tausenderpunkt (X.XXX)
// ============================================================

function scrape_toprate24(): array
{
    $url = 'https://www.toprate24.de/processsearch.php?master=1&profileID=40'
         . '&searchdata=%7B%22fields%22%3A%7B%223%22%3A%5B%22BMW%22%5D%2C%224%22%3A%5B%22iX%22%5D'
         . '%2C%225%22%3A%7B%22min%22%3A%22263%22%7D%2C%2210008%22%3A%220%22%2C%2210014%22%3A%221%22%7D%7D';

    $html = http_get($url);
    if ($html === false) {
        echo "[WARNUNG] toprate24.de: Seite nicht erreichbar\n";
        return [];
    }

    $offers = [];

    preg_match_all(
        '/<article[^>]+data-vehicle-id="(\d+)"[^>]*>([\s\S]+?)<\/article>/i',
        $html, $articles, PREG_SET_ORDER
    );

    foreach ($articles as $art) {
        $id    = $art[1];
        $block = $art[2];

        // Preis NUR aus dem nobr-Span
        // Format: "700" oder "1.035" (Tausenderpunkt) vor ,&ndash;
        // Erlaubte Muster: 3-4 Ziffern ODER X.XXX (genau ein Punkt + 3 Ziffern)
        if (preg_match(
            '/<span[^>]+class="nobr[^"]*"[^>]*>(\d{1,3}(?:\.\d{3})?),(?:&ndash;|–|-)(?:&nbsp;|\xc2\xa0|\s)*€<\/span>/u',
            $block, $pm
        )) {
            $price = $pm[1] . ',– €/mtl.';
        } else {
            // Fallback: suche nach Preis-Div direkt
            if (preg_match('/<div[^>]+class="[^"]*leasing-price[^"]*"[^>]*>[\s\S]*?(\d{1,3}(?:\.\d{3})?),(?:&ndash;|–|-)/u', $block, $pm2)) {
                $price = $pm2[1] . ',– €/mtl.';
            } else {
                $price = '? €';
                debug("toprate24.de: Kein Preis für ID $id");
            }
        }

        // Titel normalisieren + HTML-Entities dekodieren
        preg_match('/<h6[^>]*>[\s\S]*?<a[^>]*>([\s\S]+?)<\/a>/i', $block, $tm);
        $title = isset($tm[1])
            ? html_entity_decode(preg_replace('/\s+/', ' ', trim(strip_tags($tm[1]))), ENT_HTML5, 'UTF-8')
            : "BMW iX – ID $id";

        // Bild
        preg_match('/src="(https:\/\/img\.autrado\.de\/[^"]+_640\.[a-z]+)"/i', $block, $im);

        $offers[] = [
            'source' => 'toprate24.de',
            'id'     => $id,
            'title'  => $title,
            'price'  => $price,
            'url'    => "https://www.toprate24.de/bmw-ix-leasing-x__{$id}.php",
            'image'  => $im[1] ?? '',
        ];
        debug("toprate24.de: [$id] $title – $price");
    }

    return $offers;
}
