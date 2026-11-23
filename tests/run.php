<?php

/**
 * Testy wtyczki bez sieci i bez Joomli: php tests/run.php
 * API Fakturowni jest udawane (tests/FakeFakturownia.php), baza to SQLite w pamięci.
 * Oczekiwania testów wynikają z instrukcji audytu i z dokumentacji API Fakturowni/KSeF.
 */

require __DIR__ . '/bootstrap.php';

use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Session\Session;
use Pablop76\Plugin\Hikashop\Fakturownia\Extension\Fakturownia;

final class AssertionFailed extends Exception
{
}

const TOKEN = 'TEST_TOKEN_abc123';

$GLOBALS['RESULTS']  = [];
$GLOBALS['WARNINGS'] = [];

set_error_handler(function ($no, $str, $file, $line) {
    if (str_contains($file, 'Fakturownia.php')) {
        $GLOBALS['WARNINGS'][] = "$str (Fakturownia.php:$line)";
    }

    return true;
});

function check(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new AssertionFailed($msg);
    }
}

function t(string $id, string $title, callable $fn): void
{
    $status = 'PASS';
    $detail = '';

    try {
        $detail = (string) ($fn() ?? '');
    } catch (AssertionFailed $e) {
        $status = 'FAIL';
        $detail = $e->getMessage();
    } catch (Throwable $e) {
        $status = 'FAIL';
        $detail = 'Wyjątek wyszedł z wtyczki (przerwałby zapis zamówienia): ' . get_class($e) . ': ' . $e->getMessage();
    }

    if ($GLOBALS['WARNINGS']) {
        $status = 'FAIL';
        $detail .= ($detail ? ' | ' : '') . 'Ostrzeżenia PHP: ' . implode('; ', array_unique($GLOBALS['WARNINGS']));
    }

    $GLOBALS['RESULTS'][] = [$id, $title, $status, $detail];
}

function makePlugin(array $params = []): Fakturownia
{
    return new Fakturownia($params + [
        'api_token' => TOKEN, 'subdomain' => 'sklep-testowy', 'seller_name' => 'Sklep Testowy Sp. z o.o.',
        'seller_tax_no' => '5252445767', 'trigger_status' => 'confirmed', 'paid_status' => 'auto',
        'invoice_mode' => 'vat', 'auto_send_email' => 0, 'coupon_note' => 1, 'correction_status' => 'auto',
        'auto_correction' => 0, 'payment_days' => 0, 'debug' => 0, 'debug_order' => 0,
    ]);
}

/** @return array{0: Fakturownia, 1: FakeFakturownia} */
function env(array $params = [], bool $ksef = false): array
{
    $GLOBALS['WARNINGS']  = [];
    $GLOBALS['HS_ORDERS'] = [];
    $GLOBALS['HS_LOG']    = [];
    $GLOBALS['HS_CONFIG'] = ['cancelled_order_status' => 'cancelled,refunded', 'order_confirmed_status' => 'confirmed'];
    Factory::$db          = new FakeDb();
    Factory::$app         = new FakeApp();
    Session::$tokenOk     = true;
    $srv                  = new FakeFakturownia();
    $srv->ksef            = $ksef;
    HttpFactory::$http    = $srv;

    return [makePlugin($params), $srv];
}

function product(string $name, float $qty, float $net, float $rate, array $extra = []): stdClass
{
    static $n = 0;
    $n++;

    return (object) ($extra + [
        'order_product_id' => $n, 'product_id' => $n, 'order_product_name' => $name, 'order_product_code' => '',
        'order_product_quantity' => $qty, 'order_product_price' => $net, 'order_product_tax' => round($net * $rate, 5),
        'order_product_tax_info' => [(object) ['tax_rate' => $rate]], 'order_product_option_parent_id' => 0,
    ]);
}

/** Zamówienie w kształcie zwracanym przez HikaShop loadFullOrder(); order_full_price podawany ręcznie */
function order(array $o): stdClass
{
    $billing = (object) (($o['billing'] ?? []) + [
        'address_firstname' => 'Jan', 'address_lastname' => 'Kowalski', 'address_company' => '', 'address_vat' => '',
        'address_street' => 'ul. Polna 1', 'address_post_code' => '00-950', 'address_city' => 'Warszawa',
        'address_country_name' => 'Polska', 'address_country_code_2' => 'PL', 'address_telephone' => '500600700',
    ]);
    $products = $o['products'] ?? [product('Sok jabłkowy 0,5 l', 2, 10.00, 0.23)];
    unset($o['billing'], $o['products']);
    $o += ['order_number' => 'A' . ($o['order_id'] ?? 101)];

    $order = (object) ($o + [
        'order_id' => 101, 'order_status' => 'confirmed', 'order_full_price' => 24.60,
        'order_created' => strtotime('2026-11-20 10:00:00'), 'order_invoice_created' => strtotime('2026-11-20 10:05:00'),
        'order_currency_info' => serialize((object) ['currency_code' => 'PLN']),
        'order_discount_code' => '', 'order_discount_price' => 0, 'order_shipping_price' => 0, 'order_shipping_tax' => 0,
        'order_payment_price' => 0, 'order_payment_tax' => 0,
        'customer' => (object) ['user_email' => 'jan.kowalski@example.invalid'],
        'payment' => (object) ['payment_name' => 'PayU'], 'shippings' => [],
    ]);
    $order->billing_address  = $billing;
    $order->shipping_address = clone $billing;
    $order->products         = $products;

    return $GLOBALS['HS_ORDERS'][$order->order_id] = $order;
}

function fire(Fakturownia $p, int $id = 101, ?string $status = null): void
{
    if ($status !== null) {
        $GLOBALS['HS_ORDERS'][$id]->order_status = $status;
    }

    $p->onAfterOrderUpdate((object) ['order_id' => $id]);
}

function invoices(FakeFakturownia $s, ?string $kind = null): array
{
    return array_values(array_filter($s->invoices, fn ($i) => $kind === null || $i['kind'] === $kind));
}

function sumInv(array $inv): float
{
    $t = 0.0;

    foreach ($inv['positions'] as $p) {
        $t += (float) $p['total_price_gross'] - (float) ($p['discount'] ?? 0);
    }

    return round($t, 2);
}

function lockState(int $id = 101): ?string
{
    try {
        $r = Factory::$db->pdo->query('SELECT state FROM jos_hikashop_fakturownia_invoices WHERE order_id = ' . $id)->fetchColumn();

        return $r === false ? null : $r;
    } catch (Throwable $e) {
        return null;
    }
}

function historyText(int $id = 101): string
{
    $rows = Factory::$db->pdo->query('SELECT history_type, history_data FROM jos_hikashop_history WHERE history_order_id = ' . $id)->fetchAll(PDO::FETCH_ASSOC);

    return implode(' || ', array_map(fn ($h) => $h['history_type'] . ': ' . $h['history_data'], $rows));
}

/** Wpisy wtyczki w dzienniku HikaShopa (z danymi kontekstu, np. treścią żądania przy debug) */
function logText(): string
{
    return implode("
", array_map(fn ($e) => $e['message'] . (isset($e['context']['data']) ? ' ' . json_encode($e['context']['data'], JSON_UNESCAPED_UNICODE) : ''), $GLOBALS['HS_LOG']));
}

function lastPayload(FakeFakturownia $s, string $route = '/invoices.json'): array
{
    foreach (array_reverse($s->requests) as $r) {
        if ($r['method'] === 'POST' && $r['route'] === $route) {
            return $r['body']['invoice'] ?? $r['body']['client'] ?? [];
        }
    }

    return [];
}

function money(float $v): string
{
    return number_format($v, 2, ',', ' ');
}

// =========================================================================================
// A. Wystawianie dokumentów
// =========================================================================================

t('A1', 'Nowe zamówienie opłacone (PayU, confirmed)', function () {
    [$p, $s] = env();
    order(['products' => [product('Sok 0,5 l', 2, 10.00, 0.23), product('Susz 100 g', 1, 20.00, 0.08)], 'order_full_price' => 46.20]);
    fire($p);
    $inv = invoices($s);
    check(count($inv) === 1, 'liczba faktur: ' . count($inv));
    check($inv[0]['kind'] === 'vat', 'rodzaj: ' . $inv[0]['kind']);
    check(abs(sumInv($inv[0]) - 46.20) < 0.005, 'suma pozycji ' . money(sumInv($inv[0])) . ' ≠ 46,20');
    $pay = array_values($s->payments);
    check(count($pay) === 1 && abs((float) $pay[0]['price'] - 46.20) < 0.005, 'płatności: ' . json_encode($pay));
    check(lockState() === 'done', 'stan blokady: ' . lockState());

    return 'faktura i płatność 46,20 zł';
});

t('A2', 'Płatność za pobraniem: faktura przy „pending”, płatność dopiero po „confirmed”', function () {
    [$p, $s] = env(['trigger_status' => 'confirmed,pending']);
    order(['order_status' => 'pending', 'payment' => (object) ['payment_name' => 'Płatność przy odbiorze']]);
    fire($p);
    check(count(invoices($s)) === 1, 'brak faktury przy pending');
    check(count($s->payments) === 0, 'płatność zarejestrowana przed zapłatą');
    check(lockState() === 'done_unpaid', 'stan: ' . lockState());
    fire($p, 101, 'confirmed');
    check(count($s->payments) === 1, 'płatności po confirmed: ' . count($s->payments));
    fire($p, 101, 'confirmed');
    check(count($s->payments) === 1, 'płatność zdublowana: ' . count($s->payments));
    check(count(invoices($s)) === 1, 'faktur: ' . count(invoices($s)));
});

t('A3', 'Odroczona płatność: termin 14 dni liczony od daty wystawienia', function () {
    [$p, $s] = env(['trigger_status' => 'confirmed,pending', 'payment_days' => 14]);
    order(['order_status' => 'pending', 'order_invoice_created' => 0]);
    fire($p);
    $inv = lastPayload($s);
    check(($inv['issue_date'] ?? '') === '2026-11-20', 'issue_date: ' . ($inv['issue_date'] ?? '—'));
    check(($inv['payment_to'] ?? '') === '2026-12-04', 'payment_to: ' . ($inv['payment_to'] ?? '—'));
});

t('A4', 'Tryb auto: pole „Chcę fakturę” → faktura VAT, bez pola i bez NIP → paragon', function () {
    [$p, $s] = env(['invoice_mode' => 'auto']);
    order(['billing' => ['invoice_request' => '1']]);
    fire($p);
    order(['order_id' => 102]);
    fire($p, 102);
    $kinds = array_column(invoices($s), 'kind');
    check($kinds === ['vat', 'receipt'], 'rodzaje: ' . implode(', ', $kinds));
});

t('A5', 'Faktura na osobę fizyczną (pole „Chcę fakturę”, bez NIP): nabywca jako osoba prywatna', function () {
    [$p, $s] = env(['invoice_mode' => 'auto']);
    order(['billing' => ['invoice_request' => '1']]);
    fire($p);
    $inv = invoices($s);
    check(count($inv) === 1, 'faktura nie powstała: ' . $s->lastError());
    check($inv[0]['buyer_company'] === false, 'Jan Kowalski zapisany w Fakturowni jako FIRMA (karta klienta tworzona bez "company": false, a client_id nadpisuje dane nabywcy)');
});

t('A6', 'Firma z NIP w zapisie „PL 525-244-57-67”: faktura VAT, NIP oczyszczony', function () {
    [$p, $s] = env(['invoice_mode' => 'auto']);
    order(['billing' => ['address_company' => 'Zakład Ślusarski Łódź Sp. z o.o.', 'address_vat' => 'PL 525-244-57-67']]);
    fire($p);
    $inv = invoices($s);
    check(count($inv) === 1 && $inv[0]['kind'] === 'vat', 'brak faktury VAT');
    check($inv[0]['buyer_tax_no'] === '5252445767', 'NIP: ' . $inv[0]['buyer_tax_no']);
    check($inv[0]['buyer_company'] === true, 'nabywca nie jest firmą');
    check($inv[0]['buyer_name'] === 'Zakład Ślusarski Łódź Sp. z o.o.', 'nazwa: ' . $inv[0]['buyer_name']);
});

t('A7', 'Kilka produktów, w tym ten sam w dwóch liniach (scalanie pozycji)', function () {
    [$p, $s] = env();
    order(['products' => [product('Sok 0,5 l', 1, 10.00, 0.23), product('Sok 0,5 l', 2, 10.00, 0.23), product('Susz 100 g', 1, 20.00, 0.08)], 'order_full_price' => 58.50]);
    fire($p);
    $pos = invoices($s)[0]['positions'];
    check(count($pos) === 2, 'pozycji: ' . count($pos));
    check((float) $pos[0]['quantity'] === 3.0 && abs($pos[0]['total_price_gross'] - 36.90) < 0.005, 'scalona pozycja: ' . json_encode($pos[0], JSON_UNESCAPED_UNICODE));
    check(abs(sumInv(invoices($s)[0]) - 58.50) < 0.005, 'suma ' . money(sumInv(invoices($s)[0])));
});

t('A8', 'Różne stawki VAT (23%, 8%, 5%)', function () {
    [$p, $s] = env();
    order(['products' => [product('Sok', 1, 10.00, 0.23), product('Susz', 1, 20.00, 0.08), product('Dżem', 1, 10.00, 0.05)], 'order_full_price' => 44.40]);
    fire($p);
    $inv = invoices($s)[0];
    check(array_map('floatval', array_column($inv['positions'], 'tax')) === [23.0, 8.0, 5.0], 'stawki: ' . json_encode(array_column($inv['positions'], 'tax')));
    check(abs(sumInv($inv) - 44.40) < 0.005, 'suma ' . money(sumInv($inv)));
});

t('A8r', 'Zaokrąglenia przy włączonym w HikaShopie „round_calculations”: netto 4,07 zł × 7 szt., VAT 23%', function () {
    [$p, $s] = env();
    // HikaShop (classes/currency.php quantityPrice + order.php createFromCart): brutto jednostkowe zaokrąglone
    // do 5,01 zł, order_product_tax = 5,01 − 4,07 = 0,94; 7 szt. = 35,07 zł zapłacone przez klienta
    order(['products' => [product('Konfitura 200 g', 7, 4.07, 0.23, ['order_product_tax' => 0.94])], 'order_full_price' => 35.07]);
    fire($p);
    $sum = sumInv(invoices($s)[0]);
    check(abs($sum - 35.07) < 0.005, 'faktura ' . money($sum) . ' zł, zamówienie 35,07 zł — dokument będzie „częściowo opłacony” (wtyczka liczy brutto z netto×stawka zamiast z order_product_tax)');
});

t('A9', 'Rabat na produkcie (cena po rabacie w order_product_price)', function () {
    [$p, $s] = env();
    order(['products' => [product('Sok', 3, 10.00, 0.23, ['order_product_price_before_discount' => 12.00, 'order_product_discount_info' => 'Rabat1'])], 'order_full_price' => 36.90]);
    fire($p);
    $inv = invoices($s)[0];
    check(abs(sumInv($inv) - 36.90) < 0.005, 'suma ' . money(sumInv($inv)));
    check(!isset($inv['positions'][0]['discount']), 'rabat odjęty podwójnie');
});

t('A10', 'Kupon 10 zł przy dwóch stawkach VAT: rozkład na pozycje, bez ujemnych linii', function () {
    [$p, $s] = env();
    order(['products' => [product('Sok', 2, 10.00, 0.23), product('Susz', 1, 20.00, 0.08)],
        'order_discount_code' => 'KUPON10', 'order_discount_price' => 10.00, 'order_full_price' => 36.20]);
    fire($p);
    $inv = invoices($s)[0];
    check(abs(sumInv($inv) - 36.20) < 0.005, 'suma ' . money(sumInv($inv)) . ' ≠ 36,20');
    check(min(array_column($inv['positions'], 'total_price_gross')) > 0, 'ujemna pozycja');
    check(!empty($inv['raw']['show_discount']), 'show_discount wyłączone');
    check(str_contains((string) ($inv['raw']['description'] ?? ''), 'KUPON10'), 'brak kodu kuponu w uwagach');
});

t('A11', 'Darmowa dostawa: brak pozycji wysyłki', function () {
    [$p, $s] = env();
    order(['shippings' => [(object) ['shipping_name' => 'Odbiór osobisty', 'shipping_price' => 0]]]);
    fire($p);
    $names = array_column(invoices($s)[0]['positions'], 'name');
    check(!array_filter($names, fn ($n) => str_starts_with($n, 'Wysyłka')), 'pozycje: ' . implode(', ', $names));
});

t('A12', 'Koszt dostawy liczony dynamicznie (InPost: 30,75 zł zamiast ceny bazowej 25 zł)', function () {
    [$p, $s] = env();
    order(['shippings' => [(object) ['shipping_name' => 'InPost Paczkomaty 24/7', 'shipping_price' => 25.00]],
        'order_shipping_price' => 30.75, 'order_shipping_tax' => 5.75, 'order_full_price' => 55.35]);
    fire($p);
    $inv  = invoices($s)[0];
    $ship = array_values(array_filter($inv['positions'], fn ($x) => str_starts_with($x['name'], 'Wysyłka')))[0] ?? null;
    check($ship && abs($ship['total_price_gross'] - 30.75) < 0.005 && (float) $ship['tax'] === 23.0, 'wysyłka: ' . json_encode($ship, JSON_UNESCAPED_UNICODE));
    check(abs(sumInv($inv) - 55.35) < 0.005, 'suma ' . money(sumInv($inv)));
});

t('A13', 'Niestandardowa metoda płatności „Płatność przy odbiorze” → rodzaj płatności w Fakturowni', function () {
    [$p, $s] = env(['trigger_status' => 'confirmed,pending']);
    order(['order_status' => 'pending', 'payment' => (object) ['payment_name' => 'Płatność przy odbiorze']]);
    fire($p);
    $type = lastPayload($s)['payment_type'] ?? '';
    check($type === 'cash_on_delivery', "payment_type = \"$type\"; API ma wartość cash_on_delivery („opłata za pobraniem”)");
});

t('A14', 'Koszt metody płatności (2,46 zł z VAT 23%) jako osobna pozycja', function () {
    [$p, $s] = env();
    order(['order_payment_price' => 2.46, 'order_payment_tax' => 0.46, 'order_full_price' => 27.06]);
    fire($p);
    $inv = invoices($s)[0];
    $fee = array_values(array_filter($inv['positions'], fn ($x) => str_starts_with($x['name'], 'Koszt płatności')))[0] ?? null;
    check($fee && (float) $fee['tax'] === 23.0, 'pozycja: ' . json_encode($fee, JSON_UNESCAPED_UNICODE));
    check(abs(sumInv($inv) - 27.06) < 0.005, 'suma ' . money(sumInv($inv)));
});

// =========================================================================================
// B. Duplikaty i ponawianie
// =========================================================================================

t('B1', 'Dwukrotne wywołanie tego samego zdarzenia', function () {
    [$p, $s] = env();
    order([]);
    fire($p);
    fire($p);
    check(count(invoices($s)) === 1 && $s->countRequests('POST', '/invoices.json') === 1, 'faktur: ' . count(invoices($s)));
});

t('B2', 'Wielokrotna zmiana statusu (confirmed → shipped → confirmed)', function () {
    [$p, $s] = env();
    order([]);
    fire($p);
    fire($p, 101, 'shipped');
    fire($p, 101, 'confirmed');
    check(count(invoices($s)) === 1, 'faktur: ' . count(invoices($s)));
});

t('B3', 'Ponowne uruchomienie dla tego samego zamówienia (przycisk „Wystaw ponownie”)', function () {
    [$p, $s] = env();
    order([]);
    fire($p);
    Factory::$app->input = ['order_id' => 101];

    try {
        $p->onAjaxFakturownia();
    } catch (RedirectException $e) {
    }

    check(count(invoices($s)) === 1, 'w Fakturowni są ' . count(invoices($s)) . ' faktury dla jednego zamówienia — przycisk kasuje znacznik i wystawia od nowa; Fakturownia nie blokuje, bo wtyczka nie wysyła oid/oid_unique');
});

t('B4', 'Równoczesne dwa procesy (drugi startuje w trakcie wysyłania faktury przez pierwszy)', function () {
    [$p, $s] = env();
    order([]);
    $p2                              = makePlugin();
    $s->faults['POST /invoices.json'] = function () use ($p2) {
        static $done = false;

        if (!$done) {
            $done = true;
            $p2->onAfterOrderUpdate((object) ['order_id' => 101]);
        }

        return null;
    };
    fire($p);
    check(count(invoices($s)) === 1, 'faktur: ' . count(invoices($s)));
});

t('B5', 'Zerwane połączenie PO utworzeniu faktury w Fakturowni, potem kolejna aktualizacja', function () {
    [$p, $s] = env();
    order([]);
    $s->faults['POST /invoices.json'] = function ($srv, $path, $body, $query) {
        static $n = 0;

        if ($n++ === 0) {
            $srv->route('POST', '/invoices.json', $path, $body, $query);
            throw new RuntimeException('Connection reset by peer');
        }

        return null;
    };
    fire($p);
    fire($p, 101, 'confirmed'); // np. powtórzone powiadomienie bramki płatności
    check(count(invoices($s)) === 1, 'w Fakturowni są ' . count(invoices($s)) . ' faktury: po błędzie stan „error” i ponowny POST bez sprawdzenia, czy dokument już powstał');
});

t('B6', 'Odpowiedź API opóźniona o ponad 5 minut (inny proces przejmuje „zawieszoną” blokadę)', function () {
    [$p, $s] = env();
    order([]);
    $p2                              = makePlugin();
    $s->faults['POST /invoices.json'] = function () use ($p2) {
        static $n = 0;

        if ($n++ === 0) {
            Factory::$db->pdo->exec("UPDATE jos_hikashop_fakturownia_invoices SET modified = '" . gmdate('Y-m-d H:i:s', time() - 600) . "'");
            $p2->onAfterOrderUpdate((object) ['order_id' => 101]);
        }

        return null;
    };
    fire($p);
    check(count(invoices($s)) === 1, 'faktur: ' . count(invoices($s)) . ' (wtyczka nie ustawia limitu czasu żądania, więc taki stan jest możliwy)');
});

t('B7', 'Ponowienie po błędzie (500, potem sukces)', function () {
    [$p, $s] = env();
    order([]);
    $s->faults['POST /invoices.json'] = function ($srv) {
        static $n = 0;

        return $n++ === 0 ? $srv->json(500, ['code' => 'error', 'message' => 'Internal error']) : null;
    };
    fire($p);
    check(lockState() === 'error', 'stan po błędzie: ' . lockState());
    fire($p);
    check(count(invoices($s)) === 1 && lockState() === 'done', 'faktur: ' . count(invoices($s)) . ', stan ' . lockState());
});

t('B8', 'Faktura utworzona, ale odpowiedź 201 bez poprawnego JSON (brak ID), potem kolejna aktualizacja', function () {
    [$p, $s] = env();
    order([]);
    $s->faults['POST /invoices.json'] = function ($srv, $path, $body, $query) {
        static $n = 0;

        if ($n++ === 0) {
            $srv->route('POST', '/invoices.json', $path, $body, $query);

            return new FakeResponse(201, '<html>Bad gateway</html>');
        }

        return null;
    };
    fire($p);
    fire($p);
    check(count(invoices($s)) === 1, 'w Fakturowni są ' . count(invoices($s)) . ' faktury dla jednego zamówienia');
});

// =========================================================================================
// C. Błędy API
// =========================================================================================

foreach ([400, 401, 403, 404, 422, 429, 500] as $code) {
    t("C$code", "HTTP $code przy wystawianiu faktury", function () use ($code) {
        [$p, $s] = env();
        order([]);
        $s->faults['POST /invoices.json'] = fn ($srv) => $srv->json($code, ['code' => 'error', 'message' => "Komunikat testowy $code"]);
        fire($p);
        check(count(invoices($s)) === 0, 'faktura powstała');
        check(str_contains(logText(), "($code)") && str_contains(logText(), "Komunikat testowy $code"), 'brak kodu lub treści błędu w logu');
        check(str_contains(historyText(), "Komunikat testowy $code"), 'historia zamówienia: ' . historyText());
        check(lockState() === 'error', 'stan: ' . lockState() . ' (ponowienie niemożliwe)');
    });
}

t('C-token', 'Nieprawidłowy token API: zrozumiały komunikat dla administratora', function () {
    [$p, $s] = env(['api_token' => 'ZLY_TOKEN']);
    order([]);
    fire($p);
    $h = historyText();
    check(str_contains($h, '401') || stripos($h, 'token') !== false, "historia zamówienia: „{$h}” — bez kodu 401 i bez słowa o tokenie");
});

t('C-conn', 'Brak połączenia z serwerem (DNS)', function () {
    [$p, $s] = env();
    order([]);
    $s->faults['POST /invoices.json'] = function () {
        throw new RuntimeException('Could not resolve host: sklep-testowy.fakturownia.pl');
    };
    fire($p);
    check(str_contains(historyText(), 'Could not resolve host'), 'historia: ' . historyText());
    check(lockState() === 'error', 'stan: ' . lockState());
});

t('C-timeout', 'Limit czasu żądań do API', function () {
    [$p, $s] = env();
    order([]);
    fire($p);
    $without = count(array_filter($s->requests, fn ($r) => $r['timeout'] === null));
    check($without === 0, "$without z " . count($s->requests) . ' żądań bez limitu czasu (Joomla nie ustawia wtedy CURLOPT_TIMEOUT — zawieszone API wstrzymuje zapis zamówienia)');
});

t('C-json', 'Nieprawidłowy JSON w odpowiedzi 200 (faktura nie powstała)', function () {
    [$p, $s] = env();
    order([]);
    $s->faults['POST /invoices.json'] = fn () => new FakeResponse(200, '<html>Service Unavailable</html>');
    fire($p);
    check(historyText() !== '', 'administrator nie dostaje informacji w historii zamówienia (tylko wpis w logu)');
});

t('C-empty', 'Pusta odpowiedź API (200, brak treści)', function () {
    [$p, $s] = env();
    order([]);
    $s->faults['POST /invoices.json'] = fn () => new FakeResponse(200, '');
    fire($p);
    check(historyText() !== '', 'administrator nie dostaje informacji w historii zamówienia');
});

t('C-payment', 'Błąd 500 przy rejestracji płatności', function () {
    [$p, $s] = env();
    order([]);
    $s->faults['POST /banking/payments.json'] = fn ($srv) => $srv->json(500, ['code' => 'error', 'message' => 'x']);
    fire($p);
    check(str_contains(historyText(), 'nie udało się zarejestrować płatności'), 'historia: ' . historyText());
});

t('C-email', 'Wysyłka e-mailem przy aktywnym KSeF (API: HTTP 200 + "status":"error")', function () {
    [$p, $s] = env(['auto_send_email' => 1], true);
    order(['billing' => ['address_company' => 'Zakład Ślusarski Sp. z o.o.', 'address_vat' => '5252445767']]);
    fire($p);
    check(count(invoices($s)) === 1, 'faktura nie powstała: ' . $s->lastError());
    check(stripos(logText() . historyText(), 'KSeF') !== false, 'odmowa wysyłki („brak numeru KSeF”) nie jest nigdzie odnotowana');
});

// =========================================================================================
// D. Dane klientów
// =========================================================================================

t('D1', 'Klient prywatny przy domyślnej konfiguracji (zawsze faktura VAT)', function () {
    [$p, $s] = env();
    order([]);
    fire($p);
    $inv = invoices($s);
    check(count($inv) === 1 && $inv[0]['buyer_name'] === 'Jan Kowalski', 'faktura: ' . json_encode($inv[0] ?? null, JSON_UNESCAPED_UNICODE));
    check($inv[0]['buyer_company'] === false, 'osoba prywatna zapisana jako firma');
});

t('D2', 'Firma z prawidłowym NIP', function () {
    [$p, $s] = env();
    order(['billing' => ['address_company' => 'Sad Testowy Sp. z o.o.', 'address_vat' => '525-244-57-67']]);
    fire($p);
    check((invoices($s)[0]['buyer_tax_no'] ?? '') === '5252445767', 'NIP: ' . (invoices($s)[0]['buyer_tax_no'] ?? '—'));
});

t('D3', 'Firma bez NIP', function () {
    [$p, $s] = env();
    order(['billing' => ['address_company' => 'Stowarzyszenie Pszczelarzy']]);
    fire($p);
    check(count(invoices($s)) === 1 && invoices($s)[0]['buyer_name'] === 'Stowarzyszenie Pszczelarzy', 'faktura: ' . $s->lastError());
});

t('D4', 'Nieprawidłowy NIP: faktura bez NIP i ostrzeżenie w historii', function () {
    [$p, $s] = env();
    order(['billing' => ['address_company' => 'Firma X', 'address_vat' => '1234567890']]);
    fire($p);
    check(count(invoices($s)) === 1 && invoices($s)[0]['buyer_tax_no'] === '', 'NIP na fakturze: ' . (invoices($s)[0]['buyer_tax_no'] ?? '—'));
    check(str_contains(historyText(), 'nieprawidłowy NIP'), 'historia: ' . historyText());
});

t('D5', 'Brak adresu rozliczeniowego i danych klienta (HikaShop zwraca false)', function () {
    [$p, $s] = env();
    order([]);
    $GLOBALS['HS_ORDERS'][101]->billing_address = false;
    $GLOBALS['HS_ORDERS'][101]->customer        = false;
    fire($p);
    check(count(invoices($s)) === 1, 'faktura nie powstała: ' . $s->lastError());
});

t('D6', 'Polskie znaki w nazwie firmy i adresie', function () {
    [$p, $s] = env();
    $name = 'Gospodarstwo „Żurawinowe Źródło” – Łęczna';
    order(['billing' => ['address_company' => $name, 'address_vat' => '5252445767', 'address_street' => 'ul. Świętokrzyska 15/7', 'address_city' => 'Łęczna']]);
    fire($p);
    $inv = lastPayload($s);
    check((invoices($s)[0]['buyer_name'] ?? '') === $name && ($inv['buyer_street'] ?? '') === 'ul. Świętokrzyska 15/7', 'dane: ' . json_encode($inv, JSON_UNESCAPED_UNICODE));
});

t('D7', 'Długi adres (300 znaków, limit Fakturowni 255)', function () {
    [$p, $s] = env();
    order(['billing' => ['address_street' => str_repeat('ul. Bardzo Długa ', 18)]]);
    fire($p);
    check(count(invoices($s)) === 1, 'faktura nie powstała: ' . $s->lastError());
});

t('D8', 'Klient zagraniczny (Niemcy, numer VAT DE123456789)', function () {
    [$p, $s] = env();
    order(['billing' => ['address_company' => 'Muster GmbH', 'address_vat' => 'DE123456789', 'address_city' => 'Berlin',
        'address_post_code' => '10115', 'address_country_name' => 'Deutschland', 'address_country_code_2' => 'DE']]);
    fire($p);
    $inv = invoices($s)[0] ?? [];
    check(($inv['buyer_country'] ?? '') === 'DE', 'kraj nabywcy: „' . ($inv['buyer_country'] ?? '') . '” (API: kod ISO 3166)');
    check(($inv['buyer_tax_no'] ?? '') === 'DE123456789', 'numer VAT: „' . ($inv['buyer_tax_no'] ?? '') . '” (walidacja polskiego NIP usuwa numer UE)');
});

t('D9', 'Różne formaty kodów pocztowych przechodzą bez zmian', function () {
    [$p, $s] = env();
    $got = [];

    foreach (['00-950', '00950', '10115'] as $i => $code) {
        order(['order_id' => 200 + $i, 'customer' => (object) ['user_email' => "k$i@example.invalid"], 'billing' => ['address_post_code' => $code]]);
        fire($p, 200 + $i);
        $got[] = lastPayload($s)['buyer_post_code'] ?? '—';
    }

    check($got === ['00-950', '00950', '10115'], 'kody: ' . implode(', ', $got));
});

// =========================================================================================
// E. Zmiany zamówienia
// =========================================================================================

t('E1', 'Zmiana statusu przed wystawieniem (created → pending przy wyzwalaczu confirmed)', function () {
    [$p, $s] = env();
    order(['order_status' => 'created']);
    fire($p);
    fire($p, 101, 'pending');
    check(count($s->requests) === 0, 'żądań do API: ' . count($s->requests));
});

t('E2', 'Zmiana statusu po wystawieniu (shipped, delivered)', function () {
    [$p, $s] = env();
    order([]);
    fire($p);
    $before = count($s->requests);
    fire($p, 101, 'shipped');
    fire($p, 101, 'delivered');
    check(count($s->requests) === $before, 'dodatkowe żądania: ' . (count($s->requests) - $before));
});

t('E3', 'Zmiana danych klienta po wystawieniu: dokument nie jest modyfikowany', function () {
    [$p, $s] = env();
    order([]);
    fire($p);
    $before                                                    = count($s->requests);
    $GLOBALS['HS_ORDERS'][101]->billing_address->address_street = 'ul. Nowa 2';
    fire($p, 101, 'shipped');
    check(count($s->requests) === $before, 'dodatkowe żądania: ' . (count($s->requests) - $before));
});

t('E4', 'Zmiana pozycji po wystawieniu: brak automatycznej zmiany dokumentu', function () {
    [$p, $s] = env();
    order([]);
    fire($p);
    $before                              = count($s->requests);
    $GLOBALS['HS_ORDERS'][101]->products[] = product('Susz', 1, 20.00, 0.08);
    fire($p, 101, 'shipped');
    check(count($s->requests) === $before, 'dodatkowe żądania: ' . (count($s->requests) - $before));

    return 'bez zmian w Fakturowni; uwaga: administrator nie dostaje ostrzeżenia, że zamówienie różni się od faktury';
});

t('E5', 'Zmiana kosztu dostawy po wystawieniu: brak automatycznej zmiany dokumentu', function () {
    [$p, $s] = env();
    order([]);
    fire($p);
    $before                                         = count($s->requests);
    $GLOBALS['HS_ORDERS'][101]->order_shipping_price = 15.00;
    fire($p, 101, 'shipped');
    check(count($s->requests) === $before, 'dodatkowe żądania');
});

t('E6', 'Anulowanie przy wyłączonej auto-korekcie: tylko powiadomienie', function () {
    [$p, $s] = env();
    order([]);
    fire($p);
    fire($p, 101, 'cancelled');
    check(count(invoices($s, 'correction')) === 0, 'powstała korekta');
    check(lockState() === 'notified' && str_contains(historyText(), 'Wystaw korektę ręcznie'), 'stan ' . lockState() . '; historia: ' . historyText());
});

t('E8', 'Zwrot całkowity przy włączonej auto-korekcie: korekta do zera', function () {
    [$p, $s] = env(['auto_correction' => 1]);
    order(['products' => [product('Sok', 2, 10.00, 0.23), product('Susz', 1, 20.00, 0.08)], 'order_full_price' => 46.20]);
    fire($p);
    fire($p, 101, 'refunded');
    $corr = invoices($s, 'correction');
    check(count($corr) === 1, 'korekt: ' . count($corr) . ' ' . $s->lastError());
    check(abs(sumInv($corr[0]) + 46.20) < 0.005, 'suma korekty ' . money(sumInv($corr[0])));
    check((int) $corr[0]['from_invoice_id'] === invoices($s, 'vat')[0]['id'], 'from_invoice_id');
    check(lockState() === 'corrected', 'stan ' . lockState());
});

t('E9', 'Anulowanie po edycji zamówienia: korekta odpowiada fakturze, a nie bieżącemu zamówieniu', function () {
    [$p, $s] = env(['auto_correction' => 1]);
    order(['products' => [product('Sok', 2, 10.00, 0.23), product('Susz', 1, 20.00, 0.08)], 'order_full_price' => 46.20]);
    fire($p);
    array_pop($GLOBALS['HS_ORDERS'][101]->products);
    $GLOBALS['HS_ORDERS'][101]->order_full_price = 24.60;
    fire($p, 101, 'cancelled');
    $corr = invoices($s, 'correction')[0] ?? null;
    check($corr && abs(sumInv($corr) + 46.20) < 0.005, 'faktura 46,20 zł, korekta ' . ($corr ? money(sumInv($corr)) : '—') . ' zł — korekta budowana z bieżącego zamówienia, nie z pozycji faktury w Fakturowni');
});

// =========================================================================================
// K. Zgodność z aktualną dokumentacją API (KSeF.md, token w nagłówku)
// =========================================================================================

t('K1', 'KSeF aktywny: klient prywatny, domyślna konfiguracja', function () {
    [$p, $s] = env([], true);
    order([]);
    fire($p);
    check(count(invoices($s)) === 1, 'faktura odrzucona: ' . $s->lastError());
});

t('K2', 'KSeF aktywny: nazwa produktu 300 znaków (limit KSeF 256)', function () {
    [$p, $s] = env([], true);
    order(['billing' => ['address_company' => 'Sad Testowy Sp. z o.o.', 'address_vat' => '5252445767'], 'products' => [product(str_repeat('Sok jabłkowy ', 12), 1, 10.00, 0.23)], 'order_full_price' => 12.30]);
    fire($p);
    check(count(invoices($s)) === 1, 'faktura odrzucona: ' . $s->lastError());
});

t('K3', 'KSeF aktywny: nazwa sprzedawcy w ustawieniach wtyczki inna niż w danych firmy w Fakturowni', function () {
    [$p, $s] = env(['seller_name' => 'Sklep Testowy'], true);
    order(['billing' => ['address_company' => 'Sad Testowy Sp. z o.o.', 'address_vat' => '5252445767']]);
    fire($p);
    check(count(invoices($s)) === 1, 'faktura odrzucona: ' . $s->lastError());
});

t('K4', 'KSeF aktywny: korekta wskazuje fakturę polem invoice_id', function () {
    [$p, $s] = env(['auto_correction' => 1], true);
    order(['billing' => ['address_company' => 'Sad Testowy Sp. z o.o.', 'address_vat' => '5252445767']]);
    fire($p);
    fire($p, 101, 'cancelled');
    check(count(invoices($s, 'correction')) === 1, 'korekta odrzucona: ' . $s->lastError());
});

t('K5', 'Kraj nabywcy wysyłany jako kod ISO (PL)', function () {
    [$p, $s] = env();
    order([]);
    fire($p);
    check((invoices($s)[0]['buyer_country'] ?? '') === 'PL', 'kraj: „' . (invoices($s)[0]['buyer_country'] ?? '') . '”');
});

t('K6', 'Pole buyer_company wysyłane w każdym żądaniu faktury', function () {
    [$p, $s] = env();
    order([]);
    fire($p);
    check(array_key_exists('buyer_company', lastPayload($s)), 'brak buyer_company w treści żądania');
});

// =========================================================================================
// S. Bezpieczeństwo i odporność
// =========================================================================================

t('S1', 'Token API w nagłówku Authorization (zalecenie API od 11.2026)', function () {
    [$p, $s] = env(['auto_send_email' => 1]);
    order([]);
    fire($p);
    $by = array_count_values(array_map(fn ($r) => $r['auth'] ?? 'brak', $s->requests));
    check(($by['header'] ?? 0) === count($s->requests), 'sposób przekazania tokenu: ' . json_encode($by) . ' (adres URL z tokenem trafia do logów serwerów/proxy)');
});

t('S2', 'Token nie trafia do logu (tryb debug, zamówienie w logu, e-mail, korekta)', function () {
    [$p, $s] = env(['debug' => 1, 'debug_order' => 1, 'auto_send_email' => 1, 'auto_correction' => 1]);
    order([]);
    fire($p);
    fire($p, 101, 'cancelled');
    check(logText() !== '' && !str_contains(logText(), TOKEN), 'token w logu');
});

t('S3', 'Wyłącznie HTTPS i host *.fakturownia.pl, także przy złośliwej subdomenie w ustawieniach', function () {
    [$p, $s] = env(['subdomain' => 'https://evil.example.com/x?y=']);
    order([]);
    fire($p);
    $bad = array_filter($s->requests, fn ($r) => $r['scheme'] !== 'https' || !str_ends_with($r['host'], '.fakturownia.pl'));
    check(!$bad, 'żądania poza fakturownia.pl: ' . json_encode(array_column($bad, 'url')));
});

t('S4', '„Wystaw ponownie” wywołane z frontu sklepu (com_ajax w witrynie)', function () {
    [$p, $s] = env();
    order([]);
    Factory::$app->client = 'site';
    Factory::$app->input  = ['order_id' => 101];
    $p->onAjaxFakturownia();
    check(count($s->requests) === 0, 'żądań: ' . count($s->requests));
});

t('S5', '„Wystaw ponownie” bez tokenu CSRF', function () {
    [$p, $s] = env();
    order([]);
    Session::$tokenOk    = false;
    Factory::$app->input = ['order_id' => 101];

    try {
        $p->onAjaxFakturownia();
    } catch (RedirectException $e) {
    }

    check(count($s->requests) === 0, 'żądań: ' . count($s->requests));
});

t('S6', '„Wystaw ponownie” przez użytkownika bez uprawnień do HikaShopa', function () {
    [$p, $s] = env();
    order([]);
    Factory::$app->user  = new FakeUser(false, 'redaktor', false);
    Factory::$app->input = ['order_id' => 101];

    try {
        $p->onAjaxFakturownia();
    } catch (RedirectException $e) {
    }

    check(count($s->requests) === 0, 'żądań: ' . count($s->requests));
});

t('S7', 'Bez synchronizacji katalogu liczba żądań HTTP przy zapisie zamówienia nie rośnie z liczbą produktów', function () {
    $count = [];

    foreach ([1, 10] as $n) {
        [$p, $s] = env(['sync_products' => 0]);
        $prods   = [];

        for ($i = 1; $i <= $n; $i++) {
            $prods[] = product("Produkt $i", 1, 10.00, 0.23);
        }

        order(['products' => $prods, 'order_full_price' => 12.30 * $n]);
        fire($p);
        $count[$n] = count($s->requests);
    }

    check($count[1] === $count[10], "1 produkt: {$count[1]} żądań, 10 produktów: {$count[10]} żądań — wszystkie synchronicznie w żądaniu zapisu zamówienia (kasa klienta czeka)");
});

t('S7b', 'Synchronizacja katalogu: niezmieniony produkt nie jest wysyłany ponownie (bez PUT)', function () {
    [$p, $s] = env(['sync_products' => 1]);
    order(['products' => [product('Sok', 1, 10.00, 0.23, ['product_id' => 77])], 'order_full_price' => 12.30]);
    fire($p);
    order(['order_id' => 102, 'order_number' => 'A0102', 'products' => [product('Sok', 1, 10.00, 0.23, ['product_id' => 77])], 'order_full_price' => 12.30]);
    fire($p, 102);
    check($s->countRequests('POST', '/products.json') === 1, 'dodań produktu: ' . $s->countRequests('POST', '/products.json'));
    check($s->countRequests('PUT', '/products/{id}.json') === 0, 'zbędnych aktualizacji: ' . $s->countRequests('PUT', '/products/{id}.json'));
});

t('S7c', 'Katalog prowadzi wtyczka: wpis bez kodu (dodany przez Fakturownię) znaleziony po nazwie, kod dopisany, pozycja z product_id, katalog przed fakturą', function () {
    [$p, $s] = env(['sync_products' => 1]);
    $name    = 'Zestaw L<span class="hikashop_product_variant_subname">: Półsłodkie, Czarne pudełko</span>';
    $s->products[500] = ['id' => 500, 'name' => 'Zestaw L: Półsłodkie, Czarne pudełko', 'code' => null, 'price_net' => 1, 'tax' => 23];
    order(['products' => [product($name, 1, 100.00, 0.23, ['order_product_code' => 'Zestaw L_25_303'])], 'order_full_price' => 123.00]);
    fire($p);
    $routes = array_map(fn ($r) => $r['method'] . ' ' . $r['route'], $s->requests);
    check($s->countRequests('POST', '/products.json') === 0, 'nowych wpisów w katalogu: ' . $s->countRequests('POST', '/products.json'));
    check(($s->products[500]['code'] ?? '') === 'Zestaw L_25_303', 'kod wpisu: ' . json_encode($s->products[500]['code'] ?? null));
    check((invoices($s)[0]['positions'][0]['product_id'] ?? null) === 500, 'product_id pozycji: ' . json_encode(invoices($s)[0]['positions'][0]['product_id'] ?? null));
    check(array_search('PUT /products/{id}.json', $routes) < array_search('POST /invoices.json', $routes), 'kolejność: ' . implode(', ', $routes));
});

t('S7d', 'Katalog prowadzi Fakturownia: zero żądań do katalogu, pozycje bez product_id', function () {
    [$p, $s] = env(['sync_products' => 0]);
    order([]);
    fire($p);
    check(count(array_filter($s->requests, fn ($r) => str_starts_with($r['route'], '/products'))) === 0, 'żądań do katalogu: ' . count(array_filter($s->requests, fn ($r) => str_starts_with($r['route'], '/products'))));
    check(!isset(invoices($s)[0]['positions'][0]['product_id']), 'product_id w pozycji');
});

// =========================================================================================
// Nowe w 2.3.0
// =========================================================================================

t('B9', 'Fakturownia odrzuca duplikat (oid): stan „duplikat”, informacja w historii, brak dalszych prób', function () {
    [$p, $s] = env();
    order([]);
    $s->faults['POST /invoices.json'] = function ($srv, $path, $body, $query) {
        static $n = 0;

        if ($n++ === 0) {
            $srv->route('POST', '/invoices.json', $path, $body, $query);
            throw new RuntimeException('Operation timed out');
        }

        return null;
    };
    fire($p);
    fire($p, 101, 'confirmed');
    check(lockState() === 'duplicate', 'stan: ' . lockState());
    check(str_contains(historyText(), 'już istnieje w Fakturowni'), 'historia: ' . historyText());
    $posts = $s->countRequests('POST', '/invoices.json');
    fire($p, 101, 'confirmed');
    check($s->countRequests('POST', '/invoices.json') === $posts, 'kolejna próba po wykryciu duplikatu');
    check(count(invoices($s)) === 1, 'faktur: ' . count(invoices($s)));
});

t('K7', 'KSeF aktywny: korekta po zmianie danych klienta w sklepie zachowuje nabywcę z faktury', function () {
    [$p, $s] = env(['auto_correction' => 1], true);
    order(['billing' => ['address_company' => 'Sad Testowy Sp. z o.o.', 'address_vat' => '5252445767']]);
    fire($p);
    $GLOBALS['HS_ORDERS'][101]->billing_address->address_vat = '7740001454';
    $GLOBALS['HS_ORDERS'][101]->customer->user_email       = 'nowy@example.invalid';
    fire($p, 101, 'cancelled');
    $corr = invoices($s, 'correction')[0] ?? null;
    check($corr !== null, 'korekta odrzucona: ' . $s->lastError());
    check($corr['buyer_tax_no'] === '5252445767' && (int) $corr['invoice_id'] === invoices($s, 'vat')[0]['id'], 'korekta: ' . json_encode(array_intersect_key($corr, array_flip(['buyer_tax_no', 'invoice_id']))));
});

t('P1', 'Panel zamówienia pokazuje status KSeF dokumentu z Fakturowni', function () {
    [$p, $s] = env([], true);
    order(['billing' => ['address_company' => 'Sad Testowy Sp. z o.o.', 'address_vat' => '5252445767']]);
    fire($p);
    ob_start();
    $p->onAfterOrderProductsListingDisplay((object) ['order_id' => 101], 'order_back_show');
    $html = ob_get_clean();
    check(str_contains($html, 'PLG_HIKASHOP_FAKTUROWNIA_KSEF_STATUS|processing'), 'HTML: ' . strip_tags($html));
    $get = array_values(array_filter($s->requests, fn ($r) => $r['method'] === 'GET' && $r['route'] === '/invoices/{id}.json'))[0] ?? null;
    check($get && $get['timeout'] !== null && $get['timeout'] <= 5, 'limit czasu odczytu statusu: ' . json_encode($get['timeout'] ?? null));
});

t('U1', 'Aktualizacja: stary niechroniony plik logu przeniesiony do chronionego .log.php i usunięty', function () {
    $legacy = JPATH_ADMINISTRATOR . '/logs/hikashop_fakturownia.log';
    @unlink($legacy . '.php');
    file_put_contents($legacy, "2026-01-01 dane klienta\n");
    $script = require dirname(__DIR__) . '/script.php';
    (new ReflectionMethod($script, 'protectLegacyLog'))->invoke($script);
    $protected = (string) @file_get_contents($legacy . '.php');
    check(!is_file($legacy), 'stary plik nadal istnieje');
    check(str_starts_with($protected, "#<?php die('Forbidden.'); ?>") && str_contains($protected, 'dane klienta'), 'chroniony plik: ' . $protected);
    @unlink($legacy . '.php');
});

// =========================================================================================

$counts = array_count_values(array_column($GLOBALS['RESULTS'], 2));

if (in_array('--json', $argv, true)) {
    echo json_encode($GLOBALS['RESULTS'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
} else {
    foreach ($GLOBALS['RESULTS'] as [$id, $title, $status, $detail]) {
        printf("%-4s %-9s %s%s\n", $status, $id, $title, $detail !== '' ? "\n               → $detail" : '');
    }

    printf("\nPASS: %d, FAIL: %d\n", $counts['PASS'] ?? 0, $counts['FAIL'] ?? 0);
}

exit(empty($counts['FAIL']) ? 0 : 1);
