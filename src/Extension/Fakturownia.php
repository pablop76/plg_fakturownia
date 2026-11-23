<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Hikashop.Fakturownia
 *
 * @copyright   (C) 2025 web-service. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Pablop76\Plugin\Hikashop\Fakturownia\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\SubscriberInterface;

/**
 * Plugin integrujący HikaShop z systemem Fakturownia.pl
 * Automatycznie wystawia faktury/paragony po złożeniu zamówienia
 *
 * @since  2.0.0
 */
final class Fakturownia extends CMSPlugin implements SubscriberInterface
{
    /**
     * Autoload języków wtyczki
     *
     * @var    boolean
     * @since  2.0.0
     */
    protected $autoloadLanguage = true;

    /**
     * Czy tabela blokad została już sprawdzona w tym żądaniu
     *
     * @var    boolean
     * @since  2.0.0
     */
    private bool $lockTableReady = false;

    /**
     * Limit czasu żądania do API (s). Bez niego Joomla nie ustawia CURLOPT_TIMEOUT
     * i zawieszone API wstrzymuje zapis zamówienia; musi być dużo krótszy niż
     * 5-minutowe okno przejmowania blokady w claimOrder().
     *
     * @since  2.3.0
     */
    private const HTTP_TIMEOUT = 20;

    /**
     * Limit długości nazwy pozycji w KSeF (KSeF.md „Ograniczenia długości pól").
     *
     * @since  2.3.0
     */
    private const POSITION_NAME_MAX = 256;

    /**
     * Returns an array of events this subscriber will listen to.
     *
     * @return  array
     *
     * @since   2.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            // Utworzenie zamówienia (checkout) odpala onAfterOrderCreate, NIE
            // onAfterOrderUpdate — bez tej subskrypcji faktura przy statusie
            // początkowym (np. wystawianie przed zapłatą) nigdy by nie powstała.
            // Obie ścieżki obsługuje ta sama metoda (ten sam kształt argumentów).
            'onAfterOrderCreate'                  => 'onAfterOrderUpdate',
            'onAfterOrderUpdate'                  => 'onAfterOrderUpdate',
            'onAfterOrderProductsListingDisplay'  => 'onAfterOrderProductsListingDisplay',
            'onAjaxFakturownia'                   => 'onAjaxFakturownia',
        ];
    }

    /**
     * Wywołanie API Fakturowni: token w nagłówku Authorization (token w adresie URL
     * i w treści żądania API oznacza od 11.2026 jako przestarzały) oraz limit czasu.
     *
     * @param   object      $http       Klient HTTP Joomli
     * @param   string      $apiToken   Token API
     * @param   string      $subdomain  Subdomena konta
     * @param   string      $method     GET / POST / PUT
     * @param   string      $path       Ścieżka z ewentualnym query, np. /invoices.json
     * @param   array|null  $body       Treść JSON (POST/PUT)
     * @param   int         $timeout    Limit czasu w sekundach
     *
     * @return  object  Odpowiedź (code, body)
     *
     * @since   2.3.0
     */
    private function api(object $http, string $apiToken, string $subdomain, string $method, string $path, ?array $body = null, int $timeout = self::HTTP_TIMEOUT): object
    {
        $url     = 'https://' . $subdomain . '.fakturownia.pl' . $path;
        $headers = ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $apiToken];

        if ($method === 'GET') {
            return $http->get($url, $headers, $timeout);
        }

        $headers['Content-Type'] = 'application/json';
        $data                    = $body === null ? '' : json_encode($body);

        return $method === 'PUT' ? $http->put($url, $data, $headers, $timeout) : $http->post($url, $data, $headers, $timeout);
    }

    /**
     * Treść błędu z odpowiedzi API. Fakturownia zwraca błędy walidacji jako obiekt
     * (np. {"message":{"buyer_tax_no":["- nie może być puste"]}}) — zamieniamy na tekst.
     *
     * @param   object  $response  Odpowiedź API
     *
     * @return  string
     *
     * @since   2.3.0
     */
    private function apiError(object $response): string
    {
        $body = json_decode((string) $response->body, true);
        $msg  = is_array($body) ? ($body['message'] ?? $body['error'] ?? $body['errors'] ?? $response->body) : $response->body;

        return is_string($msg) ? $msg : json_encode($msg, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Główna funkcja wywoływana po aktualizacji zamówienia.
     * Pobiera pełne dane zamówienia, wysyła klienta, fakturę i płatność do Fakturowni.
     *
     * @param   mixed  $event  The event object or order object
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function onAfterOrderUpdate($event): void
    {
        // HikaShop może przekazać obiekt zamówienia bezpośrednio lub przez event
        if (is_object($event) && method_exists($event, 'getArgument')) {
            $order = $event->getArgument(0);
        } else {
            $order = &$event;
        }

        if (!is_object($order) || empty($order->order_id)) {
            return;
        }

        $orderId = (int) $order->order_id;

        $debugOrder = (int) $this->params->get('debug_order', 0);
        $debug      = (int) $this->params->get('debug', 0);

        // Log cyklu życia ZAWSZE (niezależnie od trybu debug) — dzięki temu widać,
        // czy i w którym miejscu przetwarzanie się zatrzymuje, bez włączania debug.
        $this->log("onAfterOrderUpdate: zamówienie #{$orderId} — start");

        $orderFull = $this->getOrderFull($orderId);

        if (!$orderFull) {
            $this->log("Zamówienie #{$orderId}: PRZERWANO — getOrderFull() nie zwróciło danych (helper HikaShop niedostępny lub brak zamówienia)", 'error');

            return;
        }

        $currentStatus = $orderFull->order_status ?? '(brak)';

        // Dane logowania — potrzebne zarówno przy wystawieniu, jak i przy korekcie.
        // Dane sprzedawcy NIE są wysyłane: Fakturownia bierze je z danych firmy na koncie
        // (README API: bez seller_name i department_id „wstawione domyślne dane Twojej firmy"),
        // a przy KSeF niepełne seller_* z ustawień wtyczki kończyły się błędem 422.
        $apiToken  = trim((string) $this->params->get('api_token'));
        $subdomain = $this->sanitizeSubdomain(trim((string) $this->params->get('subdomain')));

        $configErrors = $this->validateConfig($apiToken, $subdomain);
        if (!empty($configErrors)) {
            $errorMsg = "Fakturownia - błąd konfiguracji (zamówienie #{$orderId}): " . implode(', ', $configErrors);
            $this->log("BŁĄD KONFIGURACJI zamówienia #{$orderId}: " . implode(', ', $configErrors), 'error');
            $this->notifyAdmin($errorMsg);
            return;
        }

        // Zamówienie z JUŻ wystawionym dokumentem — inne gałęzie niż normalne wystawienie:
        // anulowanie/zwrot → korekta; przejście na status opłacony przy dokumencie
        // nieopłaconym → odroczona rejestracja płatności.
        if ($this->invoiceAlreadyExists($orderFull)) {
            $correctionStatuses = $this->getCorrectionStatuses();

            if (!empty($correctionStatuses) && in_array($currentStatus, $correctionStatuses, true)) {
                $this->handleCorrection($orderFull, $currentStatus, $apiToken, $subdomain, $debug);

                return;
            }

            if (in_array($currentStatus, $this->getPaidStatuses(), true)) {
                $this->handleDeferredPayment($orderFull, $currentStatus, $apiToken, $subdomain, $debug);

                return;
            }

            $this->log("Zamówienie #{$orderId}: pominięto — faktura już istnieje (tabela blokad)");

            return;
        }

        // Dane całego zamówienia w logu (dziennik HikaShopa maskuje wrażliwe klucze)
        if ($debugOrder) {
            $this->log("Zamówienie #{$orderId}: pełne dane zamówienia", 'debug', $orderFull);
        }

        // Status(y) wyzwalające wystawienie — konfigurowalne (domyślnie 'confirmed',
        // bo dopiero wtedy zamówienie jest realnie opłacone). Kilka statusów po przecinku.
        $triggerStatuses = $this->getTriggerStatuses();

        if (!in_array($currentStatus, $triggerStatuses, true)) {
            $this->log("Zamówienie #{$orderId}: pominięto — status='{$currentStatus}', oczekiwano jednego z: " . implode(', ', $triggerStatuses));

            return;
        }

        $this->log("Zamówienie #{$orderId}: status '{$currentStatus}' kwalifikuje — przygotowuję wystawienie faktury");

        // HikaShop bywa, że zwraca true/false zamiast obiektu (np. zamówienie bez wysyłki),
        // a operator ?? łapie tylko null. Metody niżej mają typowane argumenty `object`,
        // więc twardo normalizujemy do obiektu — inaczej leci TypeError.
        $billing   = is_object($orderFull->billing_address ?? null) ? $orderFull->billing_address : new \stdClass();
        $customer  = is_object($orderFull->customer ?? null) ? $orderFull->customer : new \stdClass();
        $products  = $orderFull->products ?? [];
        $shippings = $orderFull->shippings ?? [];

        // Czyszczenie i walidacja NIP nabywcy. Błędny NIP wywala fakturę (422), a literówka
        // klienta nie powinna blokować wystawienia. Czyścimy formatowanie (myślniki/spacje/PL),
        // a jeśli NIP jest faktycznie niepoprawny — usuwamy go (faktura idzie bez NIP).
        // Sam fakt podania NIP (sygnał B2B) zapamiętujemy PRZED czyszczeniem — wymusza fakturę.
        // Nabywca spoza Polski: numer VAT UE/inny bez walidacji polskiej sumy kontrolnej —
        // Fakturownia sama rozpozna rodzaj po prefiksie kraju (KSeF.md „tax_no_kind").
        $buyerProvidedNip = !empty($billing->address_vat);
        $countryCode      = strtoupper((string) ($billing->address_country_code_2 ?? ''));

        if (!empty($billing->address_vat)) {
            $rawVat   = (string) $billing->address_vat;
            $cleanVat = ($countryCode === '' || $countryCode === 'PL')
                ? $this->normalizeNip($rawVat)
                : strtoupper((string) preg_replace('/[\s.\-]+/', '', $rawVat));

            if ($cleanVat === '') {
                $this->log("Zamówienie #{$orderId}: NIP nabywcy '{$rawVat}' jest nieprawidłowy — faktura zostanie wystawiona BEZ NIP", 'warning');
                $this->notifyAdmin("Fakturownia - nieprawidłowy NIP nabywcy '{$rawVat}', faktura wystawiona bez NIP (zamówienie #{$orderId})", $orderId);
            }

            $billing->address_vat = $cleanVat;
        }

        // Waluta zamówienia
        $currencyCode = $this->getOrderCurrencyCode($orderFull);

        // Metoda płatności (payment też bywa nie-obiektem)
        $payment       = is_object($orderFull->payment ?? null) ? $orderFull->payment : new \stdClass();
        $paymentName   = $payment->payment_name ?? '';
        $paymentMethod = $this->mapPaymentMethod($paymentName);

        // Koszt metody płatności — Z ZAMÓWIENIA, nie z konfiguracji metody:
        // order_payment_price to kwota BRUTTO pobrana od klienta, a order_payment_tax
        // to zawarty w niej podatek. HikaShop liczy go sam wg kategorii podatkowej
        // przypisanej metodzie płatności (core classes/order.php: order_payment_price
        // = payment_price_with_tax) — dokument ma odzwierciedlać to, co klient
        // faktycznie zapłacił, a nie stawkę skonfigurowaną osobno we wtyczce.
        $paymentFeeGross = isset($orderFull->order_payment_price) ? (float) $orderFull->order_payment_price : 0.0;
        $paymentFeeTax   = isset($orderFull->order_payment_tax) ? (float) $orderFull->order_payment_tax : 0.0;
        $paymentFeeNet   = $paymentFeeGross - $paymentFeeTax;
        $paymentFeeVat   = $paymentFeeNet > 0 ? round(($paymentFeeTax / $paymentFeeNet) * 100, 2) : 0.0;

        // Data płatności - używamy rzeczywistej daty zamiast 00:00
        $paymentDate = date('Y-m-d H:i:s', $orderFull->order_created);

        // Dane kuponu rabatowego
        $couponCode  = $orderFull->order_discount_code ?? '';
        $couponValue = isset($orderFull->order_discount_price) ? (float) $orderFull->order_discount_price : 0.0;

        $invoiceMode = $this->params->get('invoice_mode', 'vat');
        $autoSendEmail = $this->params->get('auto_send_email', 0);
        $couponNoteEnabled = (bool) $this->params->get('coupon_note', 1);

        // Czy zamówienie jest OPŁACONE (status na liście paid_status)? Od tego zależy,
        // czy dokument dostanie od razu płatność, czy pozostanie nieopłacony do czasu
        // przejścia zamówienia na status opłacony (odroczona rejestracja).
        $isPaid = in_array($currentStatus, $this->getPaidStatuses(), true);

        // Sprawdzamy, czy klient zaznaczył pole invoice_request (pole adresu rozliczeniowego)
        $clientWantsInvoice = $this->checkIfClientWantsInvoice($orderFull, $billing, $customer);

        // Logika wyboru typu dokumentu
        $invoiceKind = $this->determineInvoiceKind($clientWantsInvoice, $invoiceMode, $billing, $buyerProvidedNip);

        // ATOMOWE ZAKLEPANIE zamówienia — zamyka wyścig dwóch równoległych aktualizacji
        // (np. zmiana statusu + IPN płatności), który powodował duplikaty faktur.
        if (!$this->claimOrder($orderId)) {
            $this->log("Zamówienie #{$orderId}: pominięto — zaklepane/wystawione przez inny proces (ochrona przed duplikatem)");

            return;
        }

        // Utwórz obiekt klienta HTTP
        $http = HttpFactory::getHttp();

        $userEmail = (string) ($customer->user_email ?? '');

        try {
            // 1. Buduje pozycje faktury. Klienta NIE zakładamy osobnymi żądaniami: Fakturownia
            // sama dopasowuje nabywcę do karty klienta albo ją tworzy (README API, „Klient:
            // nowy lub istniejący"), a dane nabywcy idą wprost na fakturę (sendInvoice).
            // Katalog prowadzi wtyczka (sync_products=1): produkt zapisujemy PRZED fakturą, a pozycja
            // dostaje jego product_id. Fakturownia dopisuje pozycje faktur do katalogu sama, z opóźnieniem,
            // więc zapis po fakturze nie widział jej wpisu i tworzył drugi (duplikat).
            $catalogIds = [];

            if ($this->params->get('sync_products', 1)) {
                foreach ($products as $product) {
                    if (is_object($product) && ($catalogId = $this->addOrUpdateProductToFakturownia($http, $apiToken, $subdomain, $product, $debug))) {
                        $catalogIds[$product->order_product_id ?? ''] = $catalogId;
                    }
                }
            }

            $positions = $this->buildPositions($orderFull, $products, $shippings, $paymentName, $paymentFeeGross, $paymentFeeVat, $couponCode, $couponValue, $catalogIds);

            // Kod kuponu nie jest już osobną pozycją (patrz buildPositions) — opcjonalnie
            // dopisujemy go razem z kwotą do pola 'Uwagi' dokumentu, żeby nie znikła informacja,
            // który kupon został użyty i o ile obniżył zamówienie. Wyłączalne w ustawieniach
            // wtyczki (coupon_note).
            $couponNoteText = ($couponNoteEnabled && !empty($couponCode) && $couponValue > 0)
                ? 'Kupon rabatowy: ' . $couponCode . ' (-' . number_format($couponValue, 2, ',', ' ') . ' ' . $currencyCode . ')'
                : '';

            // Strażnik uzgodnienia — porównaj sumę pozycji z kwotą zamówienia.
            // Ujawnia groszowe rozjazdy zaokrągleń (bug "kwota się nie zgadza") w logu.
            $sumPositions = 0.0;

            foreach ($positions as $p) {
                $sumPositions += (float) ($p['total_price_gross'] ?? 0) - (float) ($p['discount'] ?? 0);
            }

            $orderTotal = (float) ($orderFull->order_full_price ?? 0);
            $delta      = round($sumPositions - $orderTotal, 2);

            if (abs($delta) >= 0.01) {
                $this->log("Zamówienie #{$orderId}: UWAGA uzgodnienie — suma pozycji "
                    . number_format($sumPositions, 2) . " ≠ kwota zamówienia "
                    . number_format($orderTotal, 2) . " (różnica " . number_format($delta, 2) . ")", 'warning');
            } elseif ($debug) {
                $this->log("Zamówienie #{$orderId}: uzgodnienie OK (suma pozycji = "
                    . number_format($sumPositions, 2) . ")", 'debug');
            }

            // 2. Wysyła fakturę do Fakturowni i pobiera jej ID
            $invoiceId = $this->sendInvoice($orderFull, $http, $apiToken, $subdomain, $billing, $userEmail, $positions, $invoiceKind, $currencyCode, $paymentMethod, $debug, $couponNoteText);

            if ($invoiceId) {
                // 3. Trwałe oznaczenie w tabeli blokad — faktura rozliczona (zapobiega
                // duplikatom; invoice_id zapisany tu jest jedynym źródłem prawdy, patrz
                // invoiceAlreadyExists()/getExistingInvoiceId()). Stan 'done_unpaid'
                // = dokument bez płatności, czeka na przejście zamówienia na status
                // opłacony (handleDeferredPayment).
                $this->markOrderDone($orderId, (int) $invoiceId, $isPaid);

                if ($autoSendEmail) {
                    $this->sendInvoiceByEmail($http, $apiToken, $subdomain, (int) $invoiceId, $orderId, $debug);
                }

                // 4. Wysyła płatność powiązaną z fakturą — TYLKO gdy status zamówienia
                // oznacza opłacone (paid_status). Przy statusie nieopłaconym (np. faktura
                // przed zapłatą za pobraniem) dokument pozostaje nieopłacony, a płatność
                // zarejestruje się automatycznie przy przejściu na status opłacony.
                if ($isPaid) {
                    $this->sendPayment($http, $apiToken, $subdomain, $currencyCode, $orderFull, (int) $invoiceId, $paymentMethod, $paymentDate, $debug);
                } else {
                    $this->log("Zamówienie #{$orderId}: status '{$currentStatus}' nie jest statusem opłaconym — dokument wystawiony BEZ płatności (zarejestruje się przy przejściu na status opłacony)");
                }

                $this->log("Zamówienie #{$orderId}: ZAKOŃCZONO — Invoice ID: {$invoiceId}");
            } else {
                // Odpowiedź 2xx bez numeru dokumentu: nie wiadomo, czy powstał. Ponowienie jest
                // bezpieczne (oid_unique — Fakturownia odrzuci duplikat), ale admin musi to wiedzieć.
                $this->releaseOrder($orderId);
                $this->log("Zamówienie #{$orderId}: Fakturownia nie zwróciła numeru dokumentu — zwolniono blokadę do ponowienia", 'error');
                $this->notifyAdmin("Fakturownia nie zwróciła numeru dokumentu dla zamówienia #{$orderId}. Sprawdź w Fakturowni, czy dokument powstał; ponowna próba nie utworzy duplikatu.", $orderId);
            }
        } catch (\DomainException $e) {
            // Fakturownia odrzuciła dokument z tym samym numerem zamówienia (oid_unique):
            // dokument już istnieje (np. po zerwanym połączeniu). Nie ponawiamy.
            $this->markDuplicate($orderId, (string) ($orderFull->order_number ?? $orderId));
        } catch (\Throwable $e) {
            // Łapiemy \Throwable, nie tylko \Exception — TypeError/Error dziedziczą po \Error
            // i bez tego powodują fatal error, który psuje zapis zamówienia w HikaShop.
            // Błąd w trakcie — zwolnij blokadę, aby możliwe było ponowienie przy następnej aktualizacji
            $this->releaseOrder($orderId);

            // Zawsze loguj błędy (niezależnie od ustawienia debug)
            $errorMsg = "Fakturownia - błąd zamówienia #{$orderId}: " . $e->getMessage();
            $this->log("BŁĄD zamówienia #{$orderId}: " . $e->getMessage(), 'error');
            $this->notifyAdmin($errorMsg);
        }
    }

    /**
     * Mapuje metodę płatności Hikashop na typ płatności Fakturownia
     *
     * @param   string  $paymentName  Nazwa płatności
     *
     * @return  string
     *
     * @since   2.0.0
     */
    private function mapPaymentMethod(string $paymentName): string
    {
        $paymentName = mb_strtolower(trim($paymentName));

        // Wartości z listy API (README „Pole payment_type"); bramek spoza listy API nie ma —
        // każdy inny tekst Fakturownia drukuje dosłownie, więc podajemy czytelną nazwę.
        $mapping = [
            'payu'          => 'payu',
            'przelewy24'    => 'Przelewy24',
            'pobrani'       => 'cash_on_delivery',
            'przy odbiorze' => 'cash_on_delivery',
            'on delivery'   => 'cash_on_delivery',
            'przelew'       => 'transfer',
            'transfer'      => 'transfer',
            'gotówk'        => 'cash',
            'cash'          => 'cash',
            'karta'         => 'card',
            'card'          => 'card',
            'blik'          => 'BLIK',
            'dotpay'        => 'Dotpay',
            'tpay'          => 'Tpay',
            'paypal'        => 'paypal',
        ];

        foreach ($mapping as $key => $value) {
            if (mb_strpos($paymentName, $key) !== false) {
                return $value;
            }
        }

        // Domyślnie przelew
        return 'transfer';
    }

    /**
     * Wysyła płatność powiązaną z fakturą do Fakturowni przez API.
     *
     * @param   object  $http           HTTP client
     * @param   string  $apiToken       API token
     * @param   string  $subdomain      Subdomain
     * @param   string  $currencyCode   Currency code
     * @param   object  $orderFull      Full order object
     * @param   int     $invoiceId      Invoice ID in Fakturownia
     * @param   string  $paymentMethod  Payment method
     * @param   string  $paymentDate    Payment date
     * @param   int     $debug          Debug mode flag
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function sendPayment(
        object $http,
        string $apiToken,
        string $subdomain,
        string $currencyCode,
        object $orderFull,
        int $invoiceId,
        string $paymentMethod,
        string $paymentDate,
        int $debug
    ): void {
        $payload = [
            'banking_payment' => [
                'name'        => 'Płatność za zamówienie #' . $orderFull->order_id,
                'price'       => (float) $orderFull->order_full_price,
                'invoice_id'  => $invoiceId,
                'paid'        => true,
                'paid_date'   => $paymentDate,
                'currency'    => $currencyCode,
                'kind'        => 'api',
            ],
        ];

        if ($debug) {
            $this->log("Zamówienie #{$orderFull->order_id}: rejestruję płatność (metoda: {$paymentMethod}, data: {$paymentDate})", 'debug');
        }

        try {
            $response = $this->api($http, $apiToken, $subdomain, 'POST', '/banking/payments.json', $payload);

            if (in_array($response->code, [200, 201])) {
                if ($debug) {
                    $data = json_decode($response->body, true);
                    $this->log("Zamówienie #{$orderFull->order_id}: utworzono płatność ID " . ($data['id'] ?? '?'), 'debug');
                }
            } else {
                // Faktura już istnieje, ale płatność się nie zarejestrowała — to musi być widoczne,
                // bo inaczej dokument zostaje cicho nieopłacony w Fakturowni.
                $this->log("Zamówienie #{$orderFull->order_id}: BŁĄD rejestracji płatności (kod {$response->code}): " . $this->apiError($response), 'error');
                $this->notifyAdmin(
                    "Fakturownia - faktura wystawiona, ale nie udało się zarejestrować płatności (zamówienie #{$orderFull->order_id})",
                    (int) $orderFull->order_id
                );
            }
        } catch (\Exception $e) {
            // Zawsze loguj (nie tylko w debug) — płatność jest drugorzędna, ale cisza utrudnia diagnozę.
            // Nie rzucamy dalej: faktura już istnieje, płatność można dopiąć ręcznie.
            $this->log("Zamówienie #{$orderFull->order_id}: wyjątek API płatności: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Sprawdza czy faktura lub paragon już zostały utworzone dla tego zamówienia.
     * Źródłem prawdy jest tabela blokad (#__hikashop_fakturownia_invoices) — NIE
     * order_params. HikaShop 5.1.2 nie ma natywnej kolumny order_params (potwierdzone:
     * brak w schemacie SQL core i brak jakiegokolwiek odwołania w kodzie PHP core),
     * więc zapisy do niej przez poprzednią wersję tej metody cicho failowały (wyjątek
     * łapany przez try/catch, logowany tylko do PHP error_log — nigdy do naszego
     * pliku logu). invoiceAlreadyExists() zawsze zwracało false. Duplikaty faktur
     * i tak były blokowane przez claimOrder()/tabelę blokad (osobny, działający
     * mechanizm), więc bug był niewidoczny — do czasu aż funkcja korekty zaczęła
     * zależeć WYŁĄCZNIE od tego sprawdzenia (log: zamówienie z już utworzoną fakturą
     * mimo to leciało starą ścieżką "status nie pasuje do trigger_status").
     *
     * @param   object  $orderFull  Full order object
     *
     * @return  boolean
     *
     * @since   2.0.0
     */
    private function invoiceAlreadyExists(object $orderFull): bool
    {
        return $this->getExistingInvoiceId((int) $orderFull->order_id) !== null;
    }

    /**
     * Odczytuje ID już wystawionej faktury/paragonu dla zamówienia z tabeli blokad.
     *
     * @param   int  $orderId  ID zamówienia
     *
     * @return  int|null
     *
     * @since   2.1.0
     */
    private function getExistingInvoiceId(int $orderId): ?int
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $this->ensureLockTable($db);

            $query = $db->getQuery(true)
                ->select($db->quoteName('invoice_id'))
                ->from($db->quoteName('#__hikashop_fakturownia_invoices'))
                ->where($db->quoteName('order_id') . ' = ' . $orderId);
            $db->setQuery($query);
            $invoiceId = $db->loadResult();

            return ($invoiceId !== null && $invoiceId !== '') ? (int) $invoiceId : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Odczytuje cały wiersz blokady zamówienia (invoice_id + state) — do wyświetlenia
     * stanu w panelu przy przycisku „Wystaw ponownie".
     *
     * @param   int  $orderId  ID zamówienia
     *
     * @return  object|null
     *
     * @since   2.1.0
     */
    private function getLockRow(int $orderId): ?object
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $this->ensureLockTable($db);

            $query = $db->getQuery(true)
                ->select($db->quoteName(['invoice_id', 'state']))
                ->from($db->quoteName('#__hikashop_fakturownia_invoices'))
                ->where($db->quoteName('order_id') . ' = ' . $orderId);
            $db->setQuery($query);

            return $db->loadObject() ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Usuwa znaczniki wystawienia dla zamówienia (wiersz tabeli blokad) — pozwala
     * przyciskowi „Wystaw ponownie" przepuścić zamówienie przez normalną ścieżkę
     * wystawiania od zera. NICZEGO nie usuwa w Fakturowni: jeśli poprzedni dokument
     * tam istnieje, powstanie duplikat (admin potwierdza ostrzeżenie przed akcją).
     *
     * @param   int  $orderId  ID zamówienia
     *
     * @return  void
     *
     * @since   2.1.0
     */
    private function resetIssueMarkers(int $orderId): void
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $this->ensureLockTable($db);

            $query = $db->getQuery(true)
                ->delete($db->quoteName('#__hikashop_fakturownia_invoices'))
                ->where($db->quoteName('order_id') . ' = ' . $orderId);
            $db->setQuery($query);
            $db->execute();
        } catch (\Exception $e) {
            // Brak wiersza/tabeli — nieszkodliwe, claimOrder i tak zrobi świeży INSERT
        }
    }

    /**
     * Upewnia się, że istnieje tabela blokad zamówień (idempotentne, raz na żądanie).
     *
     * @param   object  $db  Database driver
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function ensureLockTable(object $db): void
    {
        if ($this->lockTableReady) {
            return;
        }

        $db->setQuery(
            'CREATE TABLE IF NOT EXISTS `#__hikashop_fakturownia_invoices` ('
            . '`order_id` INT(11) NOT NULL,'
            . '`invoice_id` INT(11) DEFAULT NULL,'
            . '`state` VARCHAR(16) NOT NULL DEFAULT ' . $db->quote('processing') . ','
            . '`created` DATETIME DEFAULT NULL,'
            . '`modified` DATETIME DEFAULT NULL,'
            . 'PRIMARY KEY (`order_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $db->execute();

        $this->lockTableReady = true;
    }

    /**
     * Atomowo "zaklepuje" zamówienie do wystawienia faktury.
     *
     * Klucz główny na order_id sprawia, że tylko jeden równoległy proces przejmie
     * zamówienie — to zamyka wyścig powodujący duplikaty faktur. Wiersze 'error'
     * lub zawieszone 'processing' (>5 min) są atomowo wznawiane.
     *
     * @param   int  $orderId  ID zamówienia
     *
     * @return  bool  true gdy bieżący proces ma prawo wystawić fakturę
     *
     * @since   2.0.0
     */
    private function claimOrder(int $orderId): bool
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $this->ensureLockTable($db);

            $now = Factory::getDate()->toSql();

            // 1. Atomowa próba wstawienia wiersza (PRIMARY KEY = gwarancja jednokrotności)
            try {
                $row = (object) [
                    'order_id' => $orderId,
                    'state'    => 'processing',
                    'created'  => $now,
                    'modified' => $now,
                ];
                $db->insertObject('#__hikashop_fakturownia_invoices', $row);

                return true;
            } catch (\Exception $insertEx) {
                // Wiersz już istnieje — analizujemy jego stan poniżej
            }

            // 2. Wiersz istnieje — sprawdź, czy można (i trzeba) wznowić
            $query = $db->getQuery(true)
                ->select($db->quoteName(['state', 'modified']))
                ->from($db->quoteName('#__hikashop_fakturownia_invoices'))
                ->where($db->quoteName('order_id') . ' = ' . $orderId);
            $db->setQuery($query);
            $existing = $db->loadObject();

            if (!$existing || $existing->state === 'done') {
                // Już wystawione (lub nieoczekiwany brak) — nie wystawiamy ponownie
                return false;
            }

            // 'processing' świeże = inny proces właśnie wystawia → odpuszczamy.
            // 'error' lub 'processing' starsze niż 5 min (zawieszony proces) → wznawiamy.
            $stale = Factory::getDate('-5 minutes')->toSql();

            if ($existing->state === 'error'
                || ($existing->state === 'processing' && $existing->modified < $stale)) {
                // Wznowienie atomowe: warunek na poprzednim 'modified' gwarantuje,
                // że wznowi dokładnie jeden proces.
                $query = $db->getQuery(true)
                    ->update($db->quoteName('#__hikashop_fakturownia_invoices'))
                    ->set($db->quoteName('state') . ' = ' . $db->quote('processing'))
                    ->set($db->quoteName('modified') . ' = ' . $db->quote($now))
                    ->where($db->quoteName('order_id') . ' = ' . $orderId)
                    ->where($db->quoteName('modified') . ' = ' . $db->quote($existing->modified));
                $db->setQuery($query);
                $db->execute();

                return $db->getAffectedRows() === 1;
            }

            return false;
        } catch (\Exception $e) {
            // W razie problemu z blokadą NIE wystawiamy — lepiej brak faktury niż duplikat
            $this->log("Błąd zaklepania zamówienia #{$orderId}: " . $e->getMessage(), 'error');

            return false;
        }
    }

    /**
     * Ustawia stan wiersza blokady zamówienia (i opcjonalnie invoice_id).
     * Wspólny helper dla wszystkich "końcowych"/przejściowych stanów: done, error,
     * notified, corrected, correction_error.
     *
     * @param   int       $orderId    ID zamówienia
     * @param   string    $state      Nowy stan
     * @param   int|null  $invoiceId  ID faktury do zapisania w kolumnie invoice_id (opcjonalnie)
     *
     * @return  void
     *
     * @since   2.1.0
     */
    private function setLockState(int $orderId, string $state, ?int $invoiceId = null): void
    {
        try {
            $db  = Factory::getContainer()->get('DatabaseDriver');
            $now = Factory::getDate()->toSql();

            $query = $db->getQuery(true)
                ->update($db->quoteName('#__hikashop_fakturownia_invoices'))
                ->set($db->quoteName('state') . ' = ' . $db->quote($state))
                ->set($db->quoteName('modified') . ' = ' . $db->quote($now));

            if ($invoiceId !== null) {
                $query->set($db->quoteName('invoice_id') . ' = ' . $invoiceId);
            }

            $query->where($db->quoteName('order_id') . ' = ' . $orderId);
            $db->setQuery($query);
            $db->execute();
        } catch (\Exception $e) {
            // Rzadka awaria DB — tabela blokad to jedyne źródło prawdy o stanie
            // zamówienia (patrz invoiceAlreadyExists()), ale to ta sama prosta
            // operacja UPDATE co w reszcie tabeli blokad, więc nie wymaga retry.
        }
    }

    /**
     * Oznacza zamówienie jako rozliczone (faktura wystawiona).
     * Stan 'done_unpaid' = dokument wystawiony bez płatności (status zamówienia
     * nie był opłacony) — czeka na odroczoną rejestrację (handleDeferredPayment).
     *
     * @param   int   $orderId    ID zamówienia
     * @param   int   $invoiceId  ID faktury w Fakturowni
     * @param   bool  $paid       Czy płatność została zarejestrowana
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function markOrderDone(int $orderId, int $invoiceId, bool $paid = true): void
    {
        $this->setLockState($orderId, $paid ? 'done' : 'done_unpaid', $invoiceId);
    }

    /**
     * Zwalnia blokadę zamówienia po nieudanej próbie (umożliwia ponowienie).
     * Wiersz zostaje ze stanem 'error' jako ślad.
     *
     * @param   int  $orderId  ID zamówienia
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function releaseOrder(int $orderId): void
    {
        $this->setLockState($orderId, 'error');
    }

    /**
     * Fakturownia odrzuciła dokument, bo dla tego numeru zamówienia (oid) już istnieje
     * (np. poprzednia próba utworzyła go, ale odpowiedź nie dotarła). Stan 'duplicate'
     * nie jest wznawiany przez claimOrder() — kolejne aktualizacje nie ponawiają.
     * Warunek state='processing': jeśli równoległy proces zdążył oznaczyć zamówienie
     * jako wystawione, nie nadpisujemy jego wyniku.
     *
     * @param   int     $orderId      ID zamówienia
     * @param   string  $orderNumber  Numer zamówienia (oid w Fakturowni)
     *
     * @return  void
     *
     * @since   2.3.0
     */
    private function markDuplicate(int $orderId, string $orderNumber): void
    {
        try {
            $db    = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__hikashop_fakturownia_invoices'))
                ->set($db->quoteName('state') . ' = ' . $db->quote('duplicate'))
                ->set($db->quoteName('modified') . ' = ' . $db->quote(Factory::getDate()->toSql()))
                ->where($db->quoteName('order_id') . ' = ' . $orderId)
                ->where($db->quoteName('state') . ' = ' . $db->quote('processing'));
            $db->setQuery($query);
            $db->execute();

            if ($db->getAffectedRows() !== 1) {
                return;
            }
        } catch (\Exception $e) {
            // Brak aktualizacji blokady — i tak informujemy admina poniżej
        }

        $this->log("Zamówienie #{$orderId}: Fakturownia odrzuciła duplikat — dokument z numerem zamówienia {$orderNumber} już istnieje", 'warning');
        $this->notifyAdmin("Dokument dla zamówienia #{$orderId} (numer {$orderNumber}) już istnieje w Fakturowni — wtyczka nie wystawiła drugiego. Sprawdź go w Fakturowni.", $orderId);
    }

    /**
     * Atomowo "zaklepuje" zamówienie z już wystawionym dokumentem do korekty
     * (anulowanie/zwrot). Przejście możliwe TYLKO ze stanu 'done'/'done_unpaid'
     * (dokument opłacony lub czekający na płatność) — to zamyka
     * wyścig (dwa równoległe wywołania nie skorygują tego samego zamówienia
     * dwukrotnie) i jednocześnie naturalnie blokuje ponowne powiadamianie admina
     * o zamówieniu, które już zostało obsłużone (stan 'done' nie wróci samoistnie).
     *
     * W odróżnieniu od claimOrder() nie ma tu logiki wznawiania zawieszonych prób —
     * to rzadka ścieżka (anulowanie), a admin i tak zawsze dostaje powiadomienie
     * niezależnie od tego, czy automatyczna korekta się powiedzie.
     *
     * @param   int  $orderId  ID zamówienia
     *
     * @return  bool  true gdy bieżący proces ma prawo obsłużyć korektę
     *
     * @since   2.1.0
     */
    private function claimCorrection(int $orderId): bool
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $this->ensureLockTable($db);

            $now = Factory::getDate()->toSql();

            $query = $db->getQuery(true)
                ->update($db->quoteName('#__hikashop_fakturownia_invoices'))
                ->set($db->quoteName('state') . ' = ' . $db->quote('correcting'))
                ->set($db->quoteName('modified') . ' = ' . $db->quote($now))
                ->where($db->quoteName('order_id') . ' = ' . $orderId)
                ->where($db->quoteName('state') . ' IN (' . $db->quote('done') . ', ' . $db->quote('done_unpaid') . ')');
            $db->setQuery($query);
            $db->execute();

            return $db->getAffectedRows() === 1;
        } catch (\Exception $e) {
            $this->log("Błąd zaklepania korekty zamówienia #{$orderId}: " . $e->getMessage(), 'error');

            return false;
        }
    }

    /**
     * Sprawdza czy klient chce fakturę
     *
     * @param   object  $order     Order object
     * @param   object  $billing   Billing address
     * @param   object  $customer  Customer object
     *
     * @return  boolean
     *
     * @since   2.0.0
     */
    private function checkIfClientWantsInvoice(object $order, object $billing, object $customer): bool
    {
        if (isset($order->invoice_request) && !empty($order->invoice_request)) {
            return true;
        }

        if (isset($billing->invoice_request) && !empty($billing->invoice_request)) {
            return true;
        }

        if (isset($customer->invoice_request) && !empty($customer->invoice_request)) {
            return true;
        }

        return false;
    }

    /**
     * Określa rodzaj faktury
     *
     * @param   boolean  $clientWantsInvoice  Whether client wants invoice
     * @param   string   $invoiceMode         Invoice mode from settings
     * @param   object   $billing             Billing address
     * @param   boolean  $buyerProvidedNip    Whether buyer provided a NIP (even if later invalid)
     *
     * @return  string
     *
     * @since   2.0.0
     */
    private function determineInvoiceKind(bool $clientWantsInvoice, string $invoiceMode, object $billing, bool $buyerProvidedNip = false): string
    {
        // Klient zaznaczył checkbox LUB podał NIP (sygnał B2B) → faktura, niezależnie od trybu.
        // Dzięki temu błędny NIP (usunięty przez walidację) nie degraduje faktury do paragonu.
        if ($clientWantsInvoice || $buyerProvidedNip) {
            return 'vat';
        }

        if ($invoiceMode === 'vat') {
            return 'vat';
        }

        if ($invoiceMode === 'receipt') {
            return 'receipt';
        }

        // Auto mode
        return empty($billing->address_vat) ? 'receipt' : 'vat';
    }

    /**
     * Zwraca listę statusów zamówienia wyzwalających wystawienie faktury.
     * Konfigurowalne polem 'trigger_status' (domyślnie 'confirmed') — prosta lista
     * kluczy statusów (namekey) po przecinku.
     *
     * Faktury PRZED zapłatą: admin tworzy/wskazuje własny status (np. „Nie opłacone"
     * dla płatności za pobraniem / przy odbiorze) i wpisuje jego namekey wprost tutaj
     * (np. confirmed,unpaid). ŚWIADOMIE nie sięgamy po flagę „Unpaid" HikaShopa
     * (order_unpaid_statuses): zawiera ona status 'created' (każde świeżo złożone
     * zamówienie), więc dokument powstawałby dla KAŻDEGO koszyka; a dodatkowo ta flaga
     * steruje przyciskiem „Zapłać" i samodzielnym anulowaniem na froncie, więc jej
     * ruszanie psułoby płatność klienta. O tym, czy dokument dostaje od razu płatność,
     * decyduje osobne pole 'paid_status'.
     *
     * @return  array
     *
     * @since   2.0.0
     */
    private function getTriggerStatuses(): array
    {
        $raw      = (string) $this->params->get('trigger_status', 'confirmed');
        $statuses = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)))));

        return !empty($statuses) ? $statuses : ['confirmed'];
    }

    /**
     * Zwraca listę statusów zamówienia traktowanych jako anulowanie/zwrot PO wystawieniu
     * dokumentu (wymagających korekty). Pole 'correction_status':
     * - 'auto' (domyślne) — lista pobierana z konfiguracji HikaShop
     *   (cancelled_order_status = statusy z zaznaczoną kolumną „cancelled" w
     *   System → Statusy zamówień), więc WŁASNE statusy admina z tą flagą działają
     *   bez konfigurowania wtyczki;
     * - jawna lista kluczy (namekey) po przecinku — ręczne nadpisanie;
     * - puste pole — funkcja wyłączona.
     *
     * @return  array
     *
     * @since   2.1.0
     */
    private function getCorrectionStatuses(): array
    {
        $raw = trim((string) $this->params->get('correction_status', 'auto'));

        if ($raw === '') {
            return [];
        }

        if (strcasecmp($raw, 'auto') === 0) {
            // hikashop_config() jest dostępne, bo getOrderFull() (wywoływane wcześniej
            // w onAfterOrderUpdate) ładuje helper HikaShop; guard na wszelki wypadek.
            $raw = '';

            if (function_exists('hikashop_config')) {
                $config = hikashop_config();
                $raw    = trim((string) $config->get('cancelled_order_status', ''));
            }

            if ($raw === '') {
                // Domyślne statusy anulowania rdzenia HikaShop (install.hikashop.php)
                $raw = 'cancelled,refunded';
            }
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Zwraca listę statusów zamówienia oznaczających OPŁACONE zamówienie — od nich
     * zależy, czy dokument w Fakturowni dostaje od razu płatność ('Opłacona'), czy
     * pozostaje nieopłacony do czasu przejścia zamówienia na status opłacony
     * (odroczona rejestracja). Pole 'paid_status':
     * - 'auto' (domyślne) — status z konfiguracji HikaShop (order_confirmed_status =
     *   kolumna „confirmed" w System → Statusy zamówień, standardowo 'confirmed');
     * - jawna lista kluczy (namekey) po przecinku — np. confirmed,zaplacono_gotowka;
     * - puste pole — płatność NIGDY nie jest rejestrowana automatycznie.
     *
     * @return  array
     *
     * @since   2.2.0
     */
    private function getPaidStatuses(): array
    {
        $raw = trim((string) $this->params->get('paid_status', 'auto'));

        if ($raw === '') {
            return [];
        }

        if (strcasecmp($raw, 'auto') === 0) {
            $raw = '';

            if (function_exists('hikashop_config')) {
                $config = hikashop_config();
                $raw    = trim((string) $config->get('order_confirmed_status', ''));
            }

            if ($raw === '') {
                $raw = 'confirmed';
            }
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Odczytuje kod waluty zamówienia (unserialize order_currency_info, fallback PLN).
     *
     * @param   object  $orderFull  Full order object
     *
     * @return  string
     *
     * @since   2.2.0
     */
    private function getOrderCurrencyCode(object $orderFull): string
    {
        $orderCurrencyInfo = $orderFull->order_currency_info ?? null;

        if ($orderCurrencyInfo && is_string($orderCurrencyInfo)) {
            // HikaShop zapisuje tu czysty stdClass (classes/order.php) — ograniczenie
            // klas przy unserialize zamyka wektor object injection z danych w bazie.
            // instanceof zamiast isset(): na zablokowanym obiekcie (__PHP_Incomplete_Class)
            // nawet isset() emitowałoby Warning.
            $obj = unserialize($orderCurrencyInfo, ['allowed_classes' => ['stdClass']]);

            if ($obj instanceof \stdClass && isset($obj->currency_code)) {
                return (string) $obj->currency_code;
            }
        }

        return 'PLN';
    }

    /**
     * Czyści i waliduje polski NIP (10 cyfr, suma kontrolna).
     * Usuwa formatowanie (spacje, myślniki, prefiks PL). Zwraca oczyszczony NIP
     * jeśli poprawny, albo pusty string gdy niepoprawny.
     *
     * @param   string  $nip  NIP w dowolnym formacie
     *
     * @return  string  Oczyszczony NIP lub '' gdy nieprawidłowy
     *
     * @since   2.0.0
     */
    private function normalizeNip(string $nip): string
    {
        // Zostaw tylko cyfry (usuwa spacje, myślniki, prefiks "PL" itp.)
        $digits = preg_replace('/\D+/', '', $nip);

        if (strlen($digits) !== 10) {
            return '';
        }

        $weights  = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum      = 0;

        for ($i = 0; $i < 9; $i++) {
            $sum += $weights[$i] * (int) $digits[$i];
        }

        $checksum = $sum % 11;

        // Suma kontrolna = 10 jest niedozwolona; musi zgadzać się z 10. cyfrą
        if ($checksum === 10 || $checksum !== (int) $digits[9]) {
            return '';
        }

        return $digits;
    }

    /**
     * Waliduje konfigurację wtyczki. Danych sprzedawcy nie ma w ustawieniach od 2.3.0 —
     * Fakturownia bierze je z danych firmy na koncie.
     *
     * @param   string  $apiToken   API token
     * @param   string  $subdomain  Subdomain
     *
     * @return  array  Lista błędów (pusta jeśli konfiguracja OK)
     *
     * @since   2.0.0
     */
    private function validateConfig(string $apiToken, string $subdomain): array
    {
        $errors = [];

        if (empty($apiToken)) {
            $errors[] = 'Brak API Token';
        }

        if (empty($subdomain)) {
            $errors[] = 'Brak subdomeny';
        }

        return $errors;
    }

    /**
     * Zapisuje błąd do historii zamówienia HikaShop
     *
     * @param   string  $message  Treść błędu
     * @param   int     $orderId  ID zamówienia (opcjonalne)
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function notifyAdmin(string $message, int $orderId = 0, bool $isError = true): void
    {
        try {
            // Wyodrębnij orderId z wiadomości jeśli nie podano
            if ($orderId === 0 && preg_match('/#(\d+)/', $message, $matches)) {
                $orderId = (int) $matches[1];
            }

            if ($orderId > 0) {
                // Zapisz wpis do historii zamówienia HikaShop
                $this->addOrderHistory($orderId, $message, $isError);
            }
        } catch (\Exception $e) {
            // Ignoruj - błąd już jest w logu
        }
    }

    /**
     * Dodaje wpis do historii zamówienia HikaShop
     *
     * @param   int     $orderId  ID zamówienia
     * @param   string  $message  Treść wiadomości
     * @param   bool    $isError  false = wpis informacyjny (np. sukces korekty),
     *                            bez etykiety błędu — inaczej sukcesy wyglądały
     *                            w historii jak awarie
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function addOrderHistory(int $orderId, string $message, bool $isError = true): void
    {
        try {
            if (!$this->loadHikashop()) {
                return;
            }

            // Natywny zapis historii HikaShopa (status, użytkownik i IP uzupełnia addRecord)
            hikashop_get('class.history')->addRecord((object) [
                'order_id' => $orderId,
                'history'  => (object) [
                    'history_type'     => $isError ? 'fakturownia_error' : 'fakturownia_info',
                    'history_data'     => ($isError ? 'BŁĄD FAKTUROWNIA: ' : 'FAKTUROWNIA: ') . $message,
                    'history_notified' => 0,
                ],
            ]);
        } catch (\Throwable $e) {
            // Ignoruj — błąd i tak jest w dzienniku
        }
    }

    /**
     * Pobiera pełne dane zamówienia z Hikashop.
     *
     * @param   int  $orderId  Order ID
     *
     * @return  object|false
     *
     * @since   2.0.0
     */
    private function getOrderFull(int $orderId): object|false
    {
        if (!$this->loadHikashop()) {
            return false;
        }

        return hikashop_get('class.order')->loadFullOrder($orderId, true, false);
    }

    /**
     * Ładuje helper HikaShopa (hikashop_get, hikashop_config, dziennik).
     *
     * @return  bool
     *
     * @since   2.3.0
     */
    private function loadHikashop(): bool
    {
        return function_exists('hikashop_get')
            || (bool) @include_once JPATH_ADMINISTRATOR . '/components/com_hikashop/helpers/helper.php';
    }

    /**
     * Wpis do dziennika HikaShopa (źródło „fakturownia"). Od HikaShopa 6.8.1 dziennik jest
     * w bazie (System → Logi, filtr po zamówieniu, maskowanie wrażliwych kluczy, retencja),
     * w starszych wersjach ta sama funkcja pisze do pliku logu HikaShopa.
     *
     * @param   string  $msg    Treść (numer zamówienia jako „#123" łączy wpis z zamówieniem)
     * @param   string  $level  debug / info / warning / error
     * @param   mixed   $data   Dodatkowe dane (np. pełne zamówienie przy debug_order)
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function log(string $msg, string $level = 'info', $data = null): void
    {
        $context = ['level' => $level, 'type' => 'plugin', 'order_id' => preg_match('/#(\d+)/', $msg, $m) ? (int) $m[1] : 0];

        if ($data !== null) {
            $context['data'] = $data;
        }

        if ($this->loadHikashop() && function_exists('hikashop_writeToLog')) {
            hikashop_writeToLog($msg, 'fakturownia', $context);

            return;
        }

        error_log('Fakturownia: ' . $msg);
    }

    /**
     * Sanityzuje subdomenę - usuwa niepotrzebne sufiksy i prefiksy.
     *
     * @param   string  $subdomain  Raw subdomain from config
     *
     * @return  string  Clean subdomain (only the account name)
     *
     * @since   2.0.0
     */
    private function sanitizeSubdomain(string $subdomain): string
    {
        // Usuń protokół jeśli jest
        $subdomain = preg_replace('#^https?://#i', '', $subdomain);
        
        // Usuń końcowe .fakturownia.pl lub .test.fakturownia.pl
        $subdomain = preg_replace('#\.?(test\.)?fakturownia\.pl.*$#i', '', $subdomain);
        
        // Usuń końcowy .test jeśli pozostał
        $subdomain = preg_replace('#\.test$#i', '', $subdomain);
        
        // Usuń ukośniki
        $subdomain = trim($subdomain, '/');

        // Zostaw wyłącznie znaki dozwolone w subdomenie — wyklucza wpisanie tu
        // obcego hosta/ścieżki (token API poleciałby wtedy poza fakturownia.pl).
        $subdomain = preg_replace('/[^a-z0-9-]/i', '', $subdomain);

        return $subdomain;
    }

    /**
     * Buduje tablicę pozycji faktury (produkty i wysyłka) na podstawie zamówienia.
     *
     * @param   object  $orderFull        Full order object
     * @param   array   $products         Products array
     * @param   array   $shippings        Shippings array
     * @param   string  $paymentName      Payment name
     * @param   float   $paymentFeeGross  Koszt płatności BRUTTO (order_payment_price)
     * @param   float   $paymentFeeVat    Stawka VAT kosztu płatności w %, wyliczona z zamówienia
     * @param   string  $couponCode       Coupon code
     * @param   float   $couponValue      Coupon value
     * @param   array   $catalogIds       order_product_id => ID produktu w katalogu Fakturowni
     *
     * @return  array
     *
     * @since   2.0.0
     */
    private function buildPositions(
        object $orderFull,
        array $products,
        array $shippings,
        string $paymentName,
        float $paymentFeeGross,
        float $paymentFeeVat,
        string $couponCode,
        float $couponValue,
        array $catalogIds = []
    ): array {
        $positions   = [];
        $aggregated  = [];

        foreach ($products as $product) {
            // Pomijamy pozycje-opcje bez ceny (dzieci zestawów), żeby nie dublować linii
            if (!empty($product->order_product_option_parent_id) && (float) $product->order_product_price <= 0) {
                continue;
            }

            $qty      = (float) $product->order_product_quantity;
            // order_product_price to cena PO rabacie wyświetlania (naliczonym już w sklepie,
            // zanim produkt trafił do koszyka) — order_product_price_before_discount to cena
            // SPRZED rabatu i użycie jej zawyżało fakturę o wartość rabatu (potwierdzone na
            // zamówieniach z polem order_product_discount_info: różnica dokładnie odpowiadała
            // kwocie rabatu, np. 3 zł przy 3 szt. i rabacie dającym różnicę 1 zł/szt.).
            $priceNet = (float) $product->order_product_price;

            // Pobranie stawki VAT
            $taxRate = 0;

            if (!empty($product->order_product_tax_info)) {
                $taxInfos = (array) $product->order_product_tax_info;
                $firstTax = reset($taxInfos);

                if (is_object($firstTax)) {
                    $firstTax = (array) $firstTax;
                }

                if (isset($firstTax['tax_rate'])) {
                    $taxRate = (float) $firstTax['tax_rate'];
                }
            }

            // Podatek jednostkowy taki, jaki policzył HikaShop (order_product_tax = brutto − netto
            // ceny jednostkowej, z zaokrągleniem wg opcji round_calculations). Liczenie od nowa
            // netto × stawka rozjeżdżało się o grosze z kwotą zapłaconą przez klienta.
            $priceTax   = isset($product->order_product_tax) ? (float) $product->order_product_tax : $priceNet * $taxRate;
            $priceGross = $priceNet + $priceTax;

            // Konwersja stawki VAT na procent
            $taxPercent = $taxRate * 100;

            // Utwórz podstawową pozycję
            $position = [
                'name'              => $this->positionName((string) $product->order_product_name),
                'quantity'          => $qty,
                'tax'               => $taxPercent,
                'total_price_gross' => round($priceGross * $qty, 2),
            ];

            if (isset($catalogIds[$product->order_product_id ?? ''])) {
                $position['product_id'] = $catalogIds[$product->order_product_id ?? ''];
            }

            // Rabat NIE jest dodawany osobno — order_product_price już go uwzględnia (patrz wyżej),
            // więc dodatkowe pole 'discount' byłoby podwójnym odjęciem tej samej kwoty.

            // Agregujemy identyczne pozycje (nazwa + VAT + cena jednostkowa brutto)
            $unitGross = $qty > 0 ? ($position['total_price_gross'] / $qty) : $position['total_price_gross'];
            $key = $position['name'] . '|' . number_format($position['tax'], 4) . '|' . number_format($unitGross, 4);

            if (isset($aggregated[$key])) {
                $aggregated[$key]['quantity'] += $qty;
                $aggregated[$key]['total_price_gross'] = round($aggregated[$key]['total_price_gross'] + $position['total_price_gross'], 2);
            } else {
                $aggregated[$key] = $position;
            }
        }

        // Dołóż zagregowane pozycje do listy wynikowej
        $positions = array_merge($positions, array_values($aggregated));

        // Pozycja wysyłki — KWOTA Z ZAMÓWIENIA (order_shipping_price/order_shipping_tax),
        // nie z konfiguracji metody wysyłki ($ship->shipping_price to tylko cena bazowa/
        // domyślna). Niektóre metody liczą cenę dynamicznie (np. InPost Paczkomaty wg API
        // rozmiaru paczki) — order_full_price klienta odzwierciedla realnie naliczoną kwotę,
        // która może różnić się od ceny bazowej metody (potwierdzone w core order.php:809,827:
        // order_shipping_price += shipping_price_with_tax, order_shipping_tax = brutto-netto —
        // identyczna konwencja jak order_payment_price/order_payment_tax). $shippings służy
        // tu tylko do etykiety (nazwa metody), nie do ceny.
        $shippingFeeGross = isset($orderFull->order_shipping_price) ? (float) $orderFull->order_shipping_price : 0.0;

        if ($shippingFeeGross > 0) {
            $shippingFeeTax  = isset($orderFull->order_shipping_tax) ? (float) $orderFull->order_shipping_tax : 0.0;
            $shippingFeeNet  = $shippingFeeGross - $shippingFeeTax;
            $shippingTaxRate = $shippingFeeNet > 0 ? round(($shippingFeeTax / $shippingFeeNet) * 100, 2) : 0.0;

            $shippingNames = [];

            foreach ($shippings as $ship) {
                if (is_object($ship) && !empty($ship->shipping_name)) {
                    $shippingNames[] = $ship->shipping_name;
                }
            }

            $shippingLabel = !empty($shippingNames) ? implode(', ', array_unique($shippingNames)) : 'Wysyłka';

            $positions[] = [
                'name'              => $this->positionName('Wysyłka: ' . $shippingLabel),
                'quantity'          => 1,
                'tax'               => $shippingTaxRate,
                'total_price_gross' => round($shippingFeeGross, 2),
            ];
        }

        // Dodaj koszt płatności, jeśli istnieje i ma wartość > 0. Kwota brutto i stawka
        // VAT pochodzą z ZAMÓWIENIA (order_payment_price/order_payment_tax — HikaShop
        // liczy je wg kategorii podatkowej metody płatności), a nie z osobnej
        // konfiguracji wtyczki — pozycja odzwierciedla to, co klient realnie zapłacił.
        if ($paymentFeeGross > 0) {
            $positions[] = [
                'name'              => $this->positionName('Koszt płatności: ' . ($paymentName ?: 'Płatność')),
                'quantity'          => 1,
                'tax'               => $paymentFeeVat,
                'total_price_gross' => round($paymentFeeGross, 2),
            ];
        }

        // Kupon rabatowy — NIE jako osobna pozycja z ujemną ceną. Fakturownia nie obsługuje
        // rabatu jako oddzielnej pozycji dokumentu, a kasy/drukarki fiskalne (moduł paragony.pl)
        // odrzucają pozycje z ujemną ceną błędem "Niepoprawna cena (cena musi być dodatnia)" —
        // to wymóg protokołu fiskalnego: rabat to osobny typ operacji, a nie sprzedaż towaru za
        // ujemną kwotę. Zgodnie z API Fakturowni rabat trzeba rozłożyć jako pole 'discount'
        // (kwotowo) na już istniejących pozycjach — Fakturownia sama odejmuje tę kwotę od
        // total_price_gross przy wyliczaniu dokumentu. Rozkładamy proporcjonalnie do wartości
        // każdej pozycji (poprawne też pod względem VAT przy mieszanych stawkach), a resztę
        // zaokrąglenia dokłada ostatnia pozycja, żeby suma rabatów zgadzała się co do grosza.
        // sendInvoice() wykrywa pole 'discount' na pozycjach i sam ustawia show_discount/discount_kind.
        if (!empty($couponCode) && $couponValue > 0 && !empty($positions)) {
            $grandTotal = array_sum(array_column($positions, 'total_price_gross'));

            if ($grandTotal > 0) {
                // Kupon nie powinien przewyższać wartości zamówienia — zabezpieczenie przed
                // rabatem większym niż pozycja (co znów dałoby ujemną wartość po odjęciu).
                $couponToDistribute = min($couponValue, $grandTotal);
                $remaining          = round($couponToDistribute, 2);
                $lastIndex          = array_key_last($positions);

                foreach ($positions as $i => &$pos) {
                    if ($i === $lastIndex) {
                        $share = $remaining;
                    } else {
                        $share = round($couponToDistribute * ($pos['total_price_gross'] / $grandTotal), 2);
                        $remaining -= $share;
                    }

                    if ($share > 0) {
                        $pos['discount'] = min($share, $pos['total_price_gross']);
                    }
                }
                unset($pos);
            }
        }

        return $positions;
    }

    /**
     * Nazwa pozycji: bez HTML, najwyżej 256 znaków (limit KSeF — dłuższa nazwa przy
     * aktywnym KSeF kończy się błędem 422 i brakiem dokumentu).
     *
     * @param   string  $name  Nazwa
     *
     * @return  string
     *
     * @since   2.3.0
     */
    private function positionName(string $name): string
    {
        return mb_substr(trim(strip_tags($name)), 0, self::POSITION_NAME_MAX);
    }

    /**
     * Wysyła fakturę do Fakturowni przez API. Dane nabywcy idą wprost na dokument —
     * Fakturownia sama dopasuje je do karty klienta albo ją założy (README API, „Klient:
     * nowy lub istniejący"). Sprzedawca pochodzi z danych firmy na koncie Fakturowni.
     *
     * @param   object  $orderFull     Full order object
     * @param   object  $http          HTTP client
     * @param   string  $apiToken      API token
     * @param   string  $subdomain     Subdomain
     * @param   object  $billing       Billing address
     * @param   string  $userEmail     E-mail klienta (do dopasowania klienta i wysyłki dokumentu)
     * @param   array   $positions     Invoice positions
     * @param   string  $invoiceKind   Invoice kind (vat/receipt)
     * @param   string  $currencyCode  Currency code (PLN, EUR, etc.)
     * @param   string  $paymentMethod Payment method (transfer, cash, card, etc.)
     * @param   int     $debug         Debug mode flag
     * @param   string  $couponNote    Treść do pola 'Uwagi' dokumentu (puste = brak), np. info o kuponie
     *
     * @return  int|null  ID dokumentu; null gdy odpowiedź 2xx nie zawierała ID
     *
     * @throws  \DomainException  gdy Fakturownia odrzuciła dokument jako duplikat numeru zamówienia (oid)
     *
     * @since   2.0.0
     */
    private function sendInvoice(
        object $orderFull,
        object $http,
        string $apiToken,
        string $subdomain,
        object $billing,
        string $userEmail,
        array $positions,
        string $invoiceKind,
        string $currencyCode,
        string $paymentMethod,
        int $debug,
        string $couponNote = ''
    ): ?int {
        // Sprawdź czy w pozycji jest chociaż jeden rabat
        $showDiscount = false;
        $discountKind = null;

        foreach ($positions as $pos) {
            if (isset($pos['discount']) && $pos['discount'] > 0) {
                $showDiscount = true;
                $discountKind = 'amount';
                break;
            }

            if (isset($pos['discount_percent']) && $pos['discount_percent'] > 0) {
                $showDiscount = true;
                $discountKind = 'percent_unit';
                break;
            }
        }

        // Termin płatności — konfigurowalny (domyślnie 0 dni: przy statusie wyzwalającym
        // 'confirmed' zamówienie jest już opłacone, więc dokument nie powinien pokazywać
        // przyszłego terminu; wcześniej sztywne +7 dni).
        $paymentDays = max(0, (int) $this->params->get('payment_days', 0));

        // Data wystawienia. HikaShop ustawia order_invoice_created dopiero, gdy status
        // trafia na listę invoice_order_statuses (domyślnie confirmed,shipped — patrz
        // core classes/order.php). Przy wystawianiu dokumentu WCZEŚNIEJ (faktura przed
        // zapłatą: status 'created'/'unpaid') pole jest jeszcze puste, a
        // date('Y-m-d', 0) dawało 1970-01-01 — dokument powstawał, ale z datą sprzed lat
        // (niewidoczny na liście bieżących faktur). Fallback: data złożenia zamówienia,
        // ostatecznie „dziś".
        $issueTimestamp = !empty($orderFull->order_invoice_created)
            ? (int) $orderFull->order_invoice_created
            : (!empty($orderFull->order_created) ? (int) $orderFull->order_created : time());

        $sellTimestamp = !empty($orderFull->order_created)
            ? (int) $orderFull->order_created
            : $issueTimestamp;

        $company   = trim((string) ($billing->address_company ?? ''));
        $firstName = trim((string) ($billing->address_firstname ?? ''));
        $lastName  = trim((string) ($billing->address_lastname ?? ''));
        $taxNo     = (string) ($billing->address_vat ?? '');

        // Firma = podana nazwa firmy albo NIP. Od buyer_company zależy kwalifikacja do KSeF
        // (KSeF.md: „Zawsze wysyłaj to pole"); osoba prywatna wymaga imienia i nazwiska.
        $isCompany = $company !== '' || $taxNo !== '';

        $invoice = [
            'kind'             => $invoiceKind,
            'number'           => null,
            'sell_date'        => date('Y-m-d', $sellTimestamp),
            'issue_date'       => date('Y-m-d', $issueTimestamp),
            'payment_to'       => date('Y-m-d', strtotime('+' . $paymentDays . ' days', $issueTimestamp)),
            'buyer_company'    => $isCompany,
            'buyer_name'       => $company !== '' ? $company : trim($firstName . ' ' . $lastName),
            'buyer_first_name' => $firstName,
            'buyer_last_name'  => $lastName,
            'buyer_tax_no'     => $taxNo,
            'buyer_post_code'  => (string) ($billing->address_post_code ?? ''),
            'buyer_city'       => (string) ($billing->address_city ?? ''),
            // Limit Fakturowni 255 znaków — dłuższy adres blokował wystawienie dokumentu
            'buyer_street'     => mb_substr(trim((string) ($billing->address_street ?? '')), 0, 255),
            // Kod ISO 3166 (HikaShop dokłada go do adresu); nazwa kraju („Polska") nie spełnia
            // warunku buyer_country = PL w trybie automatycznej wysyłki KSeF „Tylko polskie firmy"
            'buyer_country'    => strtoupper((string) ($billing->address_country_code_2 ?? '')),
            // Numer zamówienia + oid_unique: Fakturownia sama odrzuci drugi dokument dla tego
            // samego zamówienia (np. ponowienie po zerwanym połączeniu, gdy pierwszy już powstał)
            'oid'              => (string) (($orderFull->order_number ?? '') ?: $orderFull->order_id),
            'oid_unique'       => 'yes',
            'positions'        => $positions,
            'currency'         => $currencyCode,
            'payment_type'     => $paymentMethod,
            'show_discount'    => $showDiscount,
        ];

        // Firma bez numeru identyfikacyjnego (KSeF.md „tax_no_kind": wtedy NIP nie jest wymagany)
        if ($isCompany && $taxNo === '') {
            $invoice['buyer_tax_no_kind'] = 'empty';
        }

        if (filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            $invoice['buyer_email'] = $userEmail;
        }

        if ($showDiscount && $discountKind) {
            $invoice['discount_kind'] = $discountKind;
        }

        // Notatka o kuponie w polu 'Uwagi' dokumentu (nie pozycja — bezpieczne dla druku
        // fiskalnego, drukarka fiskalna drukuje tylko strukturalne dane pozycji/płatności,
        // nie dowolny tekst notatki). Wyłączalne w ustawieniach wtyczki (coupon_note).
        if ($couponNote !== '') {
            $invoice['description'] = $couponNote;
        }

        if ($debug) {
            $this->log("Zamówienie #{$orderFull->order_id}: wysyłam dokument do Fakturowni", 'debug', $invoice);
        }

        try {
            $response = $this->api($http, $apiToken, $subdomain, 'POST', '/invoices.json', ['invoice' => $invoice]);
        } catch (\Exception $e) {
            throw new \Exception('Błąd tworzenia faktury: ' . $e->getMessage());
        }

        if (in_array($response->code, [200, 201])) {
            $data = json_decode((string) $response->body, true);
            $id   = is_array($data) ? (int) ($data['id'] ?? 0) : 0;

            if ($debug) {
                $this->log("Zamówienie #{$orderFull->order_id}: odpowiedź {$response->code}, ID dokumentu: " . ($id ?: 'brak'), 'debug');
            }

            return $id ?: null;
        }

        $error = $this->apiError($response);
        $body  = json_decode((string) $response->body, true);
        $msg   = is_array($body) ? ($body['message'] ?? null) : null;

        // Odrzucenie przez oid_unique. Format komunikatu niepotwierdzony na żywym API —
        // sprawdzamy klucz „oid" w błędach walidacji albo słowo „oid" w treści.
        if ($response->code === 422 && ((is_array($msg) && isset($msg['oid'])) || (is_string($msg) && stripos($msg, 'oid') !== false))) {
            throw new \DomainException($error);
        }

        throw new \Exception("Błąd tworzenia faktury: Błąd API Fakturownia ({$response->code}): {$error}");
    }

    /**
     * Obsługuje zamówienie, które ma już wystawiony dokument w Fakturowni, a zmieniło
     * status na anulowany/zwrócony (getCorrectionStatuses). ZAWSZE powiadamia admina —
     * niezależnie od ustawienia auto_correction — bo faktura/paragon wymaga korekty,
     * a wtyczka może tego nie zrobić automatycznie. Obsługuje TYLKO pełne anulowanie/
     * zwrot całego zamówienia (bez obsługi zwrotów częściowych — jak w oficjalnej
     * wtyczce Fakturownia dla WooCommerce).
     *
     * @param   object  $orderFull      Full order object
     * @param   string  $currentStatus  Aktualny status zamówienia
     * @param   string  $apiToken       API token
     * @param   string  $subdomain      Subdomain
     * @param   int     $debug          Debug mode flag
     *
     * @return  void
     *
     * @since   2.1.0
     */
    private function handleCorrection(
        object $orderFull,
        string $currentStatus,
        string $apiToken,
        string $subdomain,
        int $debug
    ): void {
        $orderId = (int) $orderFull->order_id;

        // Atomowe zaklepanie (done -> correcting) — zamyka wyścig I jednocześnie
        // naturalnie blokuje powtórne powiadamianie o już obsłużonym zamówieniu.
        if (!$this->claimCorrection($orderId)) {
            if ($debug) {
                $this->log("Zamówienie #{$orderId}: korekta pominięta — już obsłużona lub w trakcie", 'debug');
            }

            return;
        }

        $existingInvoiceId = $this->getExistingInvoiceId($orderId) ?? 0;
        $autoCorrection    = (bool) $this->params->get('auto_correction', 0);

        $this->log("Zamówienie #{$orderId}: status zmienił się na '{$currentStatus}', a dokument (ID {$existingInvoiceId}) już istnieje w Fakturowni — wymaga korekty");
        $this->notifyAdmin(
            "Zamówienie #{$orderId} zmieniło status na '{$currentStatus}', a ma już wystawiony dokument (ID {$existingInvoiceId}) w Fakturowni. "
                . ($autoCorrection ? 'Trwa automatyczne wystawianie korekty...' : 'Wystaw korektę ręcznie w Fakturowni.'),
            $orderId,
            false
        );

        if (!$autoCorrection) {
            $this->setLockState($orderId, 'notified');

            return;
        }

        try {
            $http = HttpFactory::getHttp();

            // Korekta do zera odpowiada DOKUMENTOWI w Fakturowni, a nie bieżącemu zamówieniu
            // (zamówienie mogło zostać zmienione po wystawieniu faktury).
            $response = $this->api($http, $apiToken, $subdomain, 'GET', '/invoices/' . $existingInvoiceId . '.json');
            $original = json_decode((string) $response->body, true);

            if ($response->code !== 200 || !is_array($original) || empty($original['positions'])) {
                throw new \Exception("Nie udało się pobrać korygowanego dokumentu z Fakturowni ({$response->code}): " . $this->apiError($response));
            }

            $correctionPositions = $this->buildCorrectionPositions($original);

            if (empty($correctionPositions)) {
                $this->log("Zamówienie #{$orderId}: korekta pominięta — brak pozycji do skorygowania");
                $this->setLockState($orderId, 'notified');

                return;
            }

            $correctionId = $this->sendCorrectionInvoice($http, $apiToken, $subdomain, $original, $correctionPositions, $currentStatus, $orderId, $debug);

            if (!$correctionId) {
                throw new \Exception('Fakturownia nie zwróciła ID korekty mimo odpowiedzi bez błędu');
            }

            $this->setLockState($orderId, 'corrected');
            $this->log("Zamówienie #{$orderId}: korekta wystawiona automatycznie (ID {$correctionId})");
            $this->notifyAdmin("Korekta wystawiona automatycznie dla zamówienia #{$orderId} (ID {$correctionId})", $orderId, false);
        } catch (\Throwable $e) {
            $this->setLockState($orderId, 'correction_error');
            $this->log("Zamówienie #{$orderId}: BŁĄD automatycznej korekty: " . $e->getMessage(), 'error');
            $this->notifyAdmin("Fakturownia - nie udało się automatycznie wystawić korekty dla zamówienia #{$orderId}: " . $e->getMessage(), $orderId);
        }
    }

    /**
     * Przekształca pozycje korygowanego dokumentu (pobranego z Fakturowni) w pozycje
     * korekty do zera — format z README API: ujemna delta na poziomie głównym plus
     * correction_before_attributes (stan przed) i correction_after_attributes (stan po,
     * zerowy). Pozycje o zerowej wartości są pomijane.
     *
     * @param   array  $invoice  Dokument z GET /invoices/{id}.json
     *
     * @return  array
     *
     * @since   2.1.0
     */
    private function buildCorrectionPositions(array $invoice): array
    {
        $positions = array_values($invoice['positions'] ?? []);
        $values    = array_map(fn ($p) => round((float) ($p['total_price_gross'] ?? 0), 2), $positions);

        // Czy total_price_gross pozycji z rabatem jest przed czy po rabacie — niepotwierdzone
        // na żywym API. Rozstrzyga suma dokumentu (price_gross): jeśli suma pozycji się z nią
        // nie zgadza, a po odjęciu rabatów tak — wartości są przed rabatem.
        if (isset($invoice['price_gross']) && abs(array_sum($values) - (float) $invoice['price_gross']) >= 0.01) {
            $values = array_map(fn ($p, $v) => round($v - (float) ($p['discount'] ?? 0), 2), $positions, $values);
        }

        $correctionPositions = [];

        foreach ($positions as $i => $pos) {
            $beforeGross = $values[$i];

            if ($beforeGross <= 0) {
                continue;
            }

            $name     = (string) ($pos['name'] ?? '');
            $quantity = (float) ($pos['quantity'] ?? 1);
            $tax      = $pos['tax'] ?? 0;

            $correctionPositions[] = [
                'name'              => $name,
                'quantity'          => -$quantity,
                'total_price_gross' => -$beforeGross,
                'tax'               => $tax,
                'kind'              => 'correction',
                'correction_before_attributes' => [
                    'name'              => $name,
                    'quantity'          => $quantity,
                    'total_price_gross' => $beforeGross,
                    'tax'               => $tax,
                    'kind'              => 'correction_before',
                ],
                'correction_after_attributes' => [
                    'name'              => $name,
                    'quantity'          => 0,
                    'total_price_gross' => 0,
                    'tax'               => $tax,
                    'kind'              => 'correction_after',
                ],
            ];
        }

        return $correctionPositions;
    }

    /**
     * Wysyła fakturę korygującą (do zera) do Fakturowni przez API. Dane nabywcy są
     * przepisywane z korygowanego dokumentu — KSeF nie pozwala ich zmienić w korekcie
     * (KSeF.md „Korekty faktur"), a karta klienta mogła się od tego czasu zmienić.
     *
     * @param   object  $http           HTTP client
     * @param   string  $apiToken       API token
     * @param   string  $subdomain      Subdomain
     * @param   array   $original       Korygowany dokument (GET /invoices/{id}.json)
     * @param   array   $positions      Pozycje korekty (buildCorrectionPositions)
     * @param   string  $currentStatus  Aktualny status zamówienia (do uzasadnienia korekty)
     * @param   int     $orderId        ID zamówienia
     * @param   int     $debug          Debug mode flag
     *
     * @return  int|null
     *
     * @since   2.1.0
     */
    private function sendCorrectionInvoice(
        object $http,
        string $apiToken,
        string $subdomain,
        array $original,
        array $positions,
        string $currentStatus,
        int $orderId,
        int $debug
    ): ?int {
        $buyerKeys = ['client_id', 'buyer_name', 'buyer_tax_no', 'buyer_tax_no_kind', 'buyer_company', 'buyer_first_name',
            'buyer_last_name', 'buyer_street', 'buyer_post_code', 'buyer_city', 'buyer_country', 'buyer_email'];

        $invoice = array_filter(array_intersect_key($original, array_flip($buyerKeys)), fn ($v) => $v !== null) + [
            'kind'              => 'correction',
            // KSeF wymaga powiązania z korygowaną fakturą polem invoice_id (KSeF.md „Korekty faktur");
            // from_invoice_id jak w przykładzie korekty z README API
            'invoice_id'        => (int) $original['id'],
            'from_invoice_id'   => (int) $original['id'],
            'correction_reason' => "Zamówienie #{$orderId} — status zmieniony na '{$currentStatus}'",
            'positions'         => $positions,
        ];

        if ($debug) {
            $this->log("Zamówienie #{$orderId}: wysyłam korektę do Fakturowni", 'debug', $invoice);
        }

        $response = $this->api($http, $apiToken, $subdomain, 'POST', '/invoices.json', ['invoice' => $invoice]);

        if (in_array($response->code, [200, 201])) {
            $data = json_decode((string) $response->body, true);

            return is_array($data) && !empty($data['id']) ? (int) $data['id'] : null;
        }

        $errorMsg = $this->apiError($response);
        $this->log("Zamówienie #{$orderId}: BŁĄD API korekty ({$response->code}): {$errorMsg}", 'error');

        throw new \Exception("Błąd API Fakturownia korekta ({$response->code}): {$errorMsg}");
    }

    /**
     * Odroczona rejestracja płatności: dokument wystawiono przy statusie nieopłaconym
     * (stan 'done_unpaid'), a zamówienie właśnie przeszło na status opłacony —
     * rejestrujemy płatność w Fakturowni. Atomowe przejście done_unpaid → done
     * gwarantuje, że dwa równoległe wywołania nie zarejestrują płatności dwukrotnie.
     *
     * @param   object  $orderFull      Full order object
     * @param   string  $currentStatus  Aktualny (opłacony) status zamówienia
     * @param   string  $apiToken       API token
     * @param   string  $subdomain      Subdomain
     * @param   int     $debug          Debug mode flag
     *
     * @return  void
     *
     * @since   2.2.0
     */
    private function handleDeferredPayment(
        object $orderFull,
        string $currentStatus,
        string $apiToken,
        string $subdomain,
        int $debug
    ): void {
        $orderId = (int) $orderFull->order_id;

        // Atomowe zaklepanie: tylko jeden proces przejdzie done_unpaid → done
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $this->ensureLockTable($db);

            $query = $db->getQuery(true)
                ->update($db->quoteName('#__hikashop_fakturownia_invoices'))
                ->set($db->quoteName('state') . ' = ' . $db->quote('done'))
                ->set($db->quoteName('modified') . ' = ' . $db->quote(Factory::getDate()->toSql()))
                ->where($db->quoteName('order_id') . ' = ' . $orderId)
                ->where($db->quoteName('state') . ' = ' . $db->quote('done_unpaid'));
            $db->setQuery($query);
            $db->execute();

            if ($db->getAffectedRows() !== 1) {
                // Dokument już opłacony/w innym stanie — nic do zrobienia
                if ($debug) {
                    $this->log("Zamówienie #{$orderId}: odroczona płatność pominięta — dokument nie czeka na płatność", 'debug');
                }

                return;
            }
        } catch (\Exception $e) {
            $this->log("Zamówienie #{$orderId}: błąd zaklepania odroczonej płatności: " . $e->getMessage(), 'error');

            return;
        }

        $invoiceId = $this->getExistingInvoiceId($orderId) ?? 0;

        $this->log("Zamówienie #{$orderId}: status zmienił się na opłacony ('{$currentStatus}') — rejestruję odroczoną płatność dla dokumentu ID {$invoiceId}");

        $payment       = is_object($orderFull->payment ?? null) ? $orderFull->payment : new \stdClass();
        $currencyCode  = $this->getOrderCurrencyCode($orderFull);
        $paymentMethod = $this->mapPaymentMethod($payment->payment_name ?? '');

        // Data płatności = teraz (moment przejścia na status opłacony), nie data
        // złożenia zamówienia — zamówienie zostało opłacone dopiero teraz.
        $paymentDate = date('Y-m-d H:i:s');

        // sendPayment sam loguje i powiadamia admina przy błędzie (dokument zostałby
        // wtedy nieopłacony mimo stanu 'done' — widoczne w logu i historii zamówienia)
        $this->sendPayment(HttpFactory::getHttp(), $apiToken, $subdomain, $currencyCode, $orderFull, $invoiceId, $paymentMethod, $paymentDate, $debug);

        $this->notifyAdmin("Płatność zarejestrowana dla dokumentu (ID {$invoiceId}) po przejściu zamówienia #{$orderId} na status '{$currentStatus}'", $orderId, false);
    }

    /**
     * Wyświetla sekcję Fakturowni (stan dokumentu + przycisk „Wystaw ponownie")
     * pod listą produktów w widoku zamówienia w panelu administracyjnym HikaShop.
     * HikaShop odpala ten trigger w środku szablonu — wtyczki echo'ują HTML wprost.
     *
     * @param   mixed  $event  Event object lub obiekt zamówienia (wywołanie bezpośrednie)
     *
     * @return  void
     *
     * @since   2.1.0
     */
    public function onAfterOrderProductsListingDisplay($event): void
    {
        if (is_object($event) && method_exists($event, 'getArgument')) {
            $order  = $event->getArgument(0);
            $layout = (string) $event->getArgument(1);
        } else {
            $args   = func_get_args();
            $order  = $args[0] ?? null;
            $layout = (string) ($args[1] ?? '');
        }

        // Tylko backendowy podgląd/edycja zamówienia — NIE wydruk faktury HikaShop
        // (ten sam trigger leci z layoutem 'order_back_invoice').
        if ($layout !== 'order_back_show' || !is_object($order) || empty($order->order_id)) {
            return;
        }

        $app = Factory::getApplication();

        if (!$app->isClient('administrator')) {
            return;
        }

        $orderId = (int) $order->order_id;
        $lockRow = $this->getLockRow($orderId);

        if ($lockRow && !empty($lockRow->invoice_id)) {
            $statusText = Text::sprintf('PLG_HIKASHOP_FAKTUROWNIA_STATE_ISSUED', (int) $lockRow->invoice_id);

            if (in_array($lockRow->state, ['corrected', 'notified'], true)) {
                $statusText .= ' ' . Text::_('PLG_HIKASHOP_FAKTUROWNIA_STATE_' . strtoupper($lockRow->state));
            } elseif ($lockRow->state === 'done_unpaid') {
                $statusText .= ' ' . Text::_('PLG_HIKASHOP_FAKTUROWNIA_STATE_UNPAID');
            }

            $ksef = $this->getKsefStatus((int) $lockRow->invoice_id);

            if ($ksef !== '') {
                $statusText .= ' ' . $ksef;
            }
        } elseif ($lockRow && $lockRow->state === 'duplicate') {
            $statusText = Text::_('PLG_HIKASHOP_FAKTUROWNIA_STATE_DUPLICATE');
        } elseif ($lockRow && in_array($lockRow->state, ['error', 'correction_error'], true)) {
            $statusText = Text::_('PLG_HIKASHOP_FAKTUROWNIA_STATE_ERROR');
        } else {
            $statusText = Text::_('PLG_HIKASHOP_FAKTUROWNIA_STATE_NONE');
        }

        $url = Uri::base() . 'index.php?option=com_ajax&group=hikashop&plugin=fakturownia&format=raw'
            . '&order_id=' . $orderId . '&' . Session::getFormToken() . '=1';

        $confirm = htmlspecialchars(Text::_('PLG_HIKASHOP_FAKTUROWNIA_REISSUE_CONFIRM'), ENT_QUOTES, 'UTF-8');

        echo '<fieldset class="adminform" id="htmlfieldset_fakturownia">'
            . '<legend>Fakturownia</legend>'
            . '<p>' . htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<a class="btn btn-primary" href="' . $url . '" onclick="return confirm(\'' . $confirm . '\');">'
            . htmlspecialchars(Text::_('PLG_HIKASHOP_FAKTUROWNIA_REISSUE_BUTTON'), ENT_QUOTES, 'UTF-8')
            . '</a>'
            . '</fieldset>';
    }

    /**
     * Status dokumentu w KSeF wprost z Fakturowni (gov_status, gov_id, gov_error_messages —
     * KSeF.md „Sprawdzanie statusu wysyłki"). Pusty tekst, gdy API nie odpowie — panel
     * zamówienia nie może przez to przestać działać, stąd krótki limit czasu.
     *
     * @param   int  $invoiceId  ID dokumentu w Fakturowni
     *
     * @return  string
     *
     * @since   2.3.0
     */
    private function getKsefStatus(int $invoiceId): string
    {
        $apiToken  = trim((string) $this->params->get('api_token'));
        $subdomain = $this->sanitizeSubdomain(trim((string) $this->params->get('subdomain')));

        if ($apiToken === '' || $subdomain === '') {
            return '';
        }

        try {
            $response = $this->api(HttpFactory::getHttp(), $apiToken, $subdomain, 'GET',
                '/invoices/' . $invoiceId . '.json?fields[invoice]=gov_status,gov_id,gov_error_messages', null, 5);
        } catch (\Exception $e) {
            return '';
        }

        $data = json_decode((string) $response->body, true);

        if ($response->code !== 200 || !is_array($data)) {
            return '';
        }

        $text = Text::sprintf('PLG_HIKASHOP_FAKTUROWNIA_KSEF_STATUS', ($data['gov_status'] ?? '') ?: Text::_('PLG_HIKASHOP_FAKTUROWNIA_KSEF_NONE'));

        if (!empty($data['gov_id'])) {
            $text .= ', ' . Text::sprintf('PLG_HIKASHOP_FAKTUROWNIA_KSEF_NUMBER', $data['gov_id']);
        }

        if (!empty($data['gov_error_messages'])) {
            $text .= ' — ' . implode('; ', (array) $data['gov_error_messages']);
        }

        return $text;
    }

    /**
     * Obsługa przycisku „Wystaw ponownie" (com_ajax: index.php?option=com_ajax&group=
     * hikashop&plugin=fakturownia). Czyści znaczniki wystawienia i przepuszcza
     * zamówienie przez normalną ścieżkę wystawiania, po czym wraca do widoku
     * zamówienia z komunikatem o wyniku.
     *
     * @param   mixed  $event  Event object (nieużywany — dane z requestu)
     *
     * @return  void
     *
     * @since   2.1.0
     */
    public function onAjaxFakturownia($event = null): void
    {
        $app = Factory::getApplication();

        // Wyłącznie panel administracyjny
        if (!$app->isClient('administrator')) {
            return;
        }

        $orderId     = $app->getInput()->getInt('order_id', 0);
        $redirectUrl = 'index.php?option=com_hikashop&ctrl=order&task=edit&cid[]=' . $orderId;

        // CSRF — token sesji w URL przycisku
        if (!Session::checkToken('get')) {
            $app->enqueueMessage(Text::_('JINVALID_TOKEN'), 'error');
            $app->redirect($redirectUrl);

            return;
        }

        // Uprawnienia do zarządzania HikaShop
        $user = $app->getIdentity();

        if (!$user || $user->guest || !$user->authorise('core.manage', 'com_hikashop')) {
            $app->enqueueMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');
            $app->redirect($redirectUrl);

            return;
        }

        if ($orderId <= 0) {
            $app->enqueueMessage(Text::_('PLG_HIKASHOP_FAKTUROWNIA_REISSUE_NO_ORDER'), 'error');
            $app->redirect('index.php?option=com_hikashop&ctrl=order');

            return;
        }

        $this->log("Zamówienie #{$orderId}: admin '{$user->username}' użył 'Wystaw ponownie' — czyszczę znaczniki i ponawiam wystawienie");

        $this->resetIssueMarkers($orderId);

        // Ponowne wystawienie dokładnie tą samą ścieżką co normalna aktualizacja
        // zamówienia (walidacje, statusy, ochrona przed duplikatem — wszystko jak zwykle).
        $this->onAfterOrderUpdate((object) ['order_id' => $orderId]);

        $newInvoiceId = $this->getExistingInvoiceId($orderId);

        if ($newInvoiceId) {
            $app->enqueueMessage(Text::sprintf('PLG_HIKASHOP_FAKTUROWNIA_REISSUE_SUCCESS', $newInvoiceId));
        } else {
            $app->enqueueMessage(Text::_('PLG_HIKASHOP_FAKTUROWNIA_REISSUE_FAILED'), 'warning');
        }

        $app->redirect($redirectUrl);
    }

    /**
     * Dodaje produkt do katalogu Fakturowni lub aktualizuje istniejący (opcja sync_products).
     * Niezmieniony produkt nie jest wysyłany ponownie — każde żądanie wydłuża zapis zamówienia.
     *
     * @param   object  $http       HTTP client
     * @param   string  $apiToken   API token
     * @param   string  $subdomain  Subdomain
     * @param   object  $product    Product object
     * @param   int     $debug      Debug mode flag
     *
     * @return  int|null  ID produktu w katalogu (do product_id pozycji) albo null przy błędzie
     *
     * @since   2.0.0
     */
    private function addOrUpdateProductToFakturownia(
        object $http,
        string $apiToken,
        string $subdomain,
        object $product,
        int $debug
    ): ?int {
        // Pobierz stawkę VAT
        $taxRate = 23; // Domyślna

        if (!empty($product->order_product_tax_info)) {
            $taxInfos     = (array) $product->order_product_tax_info;
            $firstTaxInfo = reset($taxInfos);

            if (is_object($firstTaxInfo)) {
                $firstTaxInfo = (array) $firstTaxInfo;
            }

            if (isset($firstTaxInfo['tax_rate'])) {
                $taxRate = (float) $firstTaxInfo['tax_rate'] * 100;
            }
        }

        // Stabilny kod produktu — żeby ten sam towar nie tworzył nowego wpisu w katalogu
        // Fakturowni przy każdym zamówieniu. Priorytet: kod z HikaShop, potem product_id
        // (stały dla danego produktu), a dopiero w ostateczności kod per-zamówienie.
        if (!empty($product->order_product_code)) {
            $productCode = $product->order_product_code;
        } elseif (!empty($product->product_id)) {
            $productCode = 'hs_product_' . (int) $product->product_id;
        } else {
            $productCode = 'order_' . $product->order_id . '_prod_' . $product->order_product_id;
        }

        $productName = $this->positionName((string) $product->order_product_name);  // ta sama nazwa co w pozycji faktury
        $priceNet    = (float) $product->order_product_price;

        $payload = [
            'product' => [
                'name'      => $productName,
                'code'      => $productCode,
                'price_net' => $priceNet,
                'tax'       => $taxRate,
            ],
        ];

        try {
            // Szukamy po kodzie, a gdy go nie ma — po nazwie: produkt bez naszego kodu (dodany przez
            // Fakturownię z pozycji faktury, ręcznie albo przed ujednoliceniem kodów) dostaje kod przez
            // PUT niżej, więc kolejne wyszukiwania trafiają już po kodzie. Filtruje parametr `query`
            // (kod i nazwa, fragment, bez wielkości liter); `search` API ignoruje i zwraca 1. stronę
            // całego katalogu — przy większym katalogu produkt „znikał” i co zamówienie powstawał nowy.
            $found = null;

            foreach (['code' => $productCode, 'name' => $productName] as $field => $value) {
                $response = $this->api($http, $apiToken, $subdomain, 'GET', '/products.json?per_page=100&query=' . urlencode($value));
                $list     = json_decode((string) $response->body, true);

                if ($debug) {
                    $this->log("Szukam produktu {$field}={$value}, wyników: " . (is_array($list) ? count($list) : 0), 'debug');
                }

                foreach (is_array($list) ? $list : [] as $p) {
                    if (is_array($p) && mb_strtolower(trim((string) ($p[$field] ?? ''))) === mb_strtolower(trim($value))) {
                        $found = $p;
                        break 2;
                    }
                }
            }

            if ($found !== null) {
                $unchanged = strtolower(trim((string) ($found['code'] ?? ''))) === strtolower(trim($productCode))
                    && trim((string) ($found['name'] ?? '')) === trim($productName)
                    && abs((float) ($found['price_net'] ?? -1) - $priceNet) < 0.005
                    && (string) (float) ($found['tax'] ?? '') === (string) (float) $taxRate;

                if ($unchanged) {
                    if ($debug) {
                        $this->log("Produkt ID={$found['id']} bez zmian — pominięto aktualizację", 'debug');
                    }

                    return (int) $found['id'];
                }

                $this->api($http, $apiToken, $subdomain, 'PUT', '/products/' . (int) $found['id'] . '.json', $payload);

                if ($debug) {
                    $this->log("Zaktualizowano produkt ID {$found['id']}", 'debug');
                }

                return (int) $found['id'];
            }

            $response = $this->api($http, $apiToken, $subdomain, 'POST', '/products.json', $payload);
            $newId    = (int) (json_decode((string) $response->body, true)['id'] ?? 0);

            if ($debug) {
                $this->log("Dodano nowy produkt {$productCode} (HTTP {$response->code}, ID {$newId})", 'debug');
            }

            return $response->code < 300 && $newId > 0 ? $newId : null;
        } catch (\Throwable $e) {
            if ($debug) {
                $this->log("Wyjątek API product: " . $e->getMessage(), 'debug');
            }

            // Nie rzucamy wyjątku — bez katalogu faktura i tak powstanie (pozycja bez product_id)
            return null;
        }
    }

    /**
     * Wysyła fakturę e-mailem do klienta przez API Fakturowni. Przy aktywnym KSeF
     * Fakturownia odmawia wysyłki dokumentu dla firmy przed nadaniem numeru KSeF —
     * odpowiedzią HTTP 200 z "status":"error" (KSeF.md „Wysyłanie faktur emailem"),
     * dlatego oprócz kodu sprawdzamy treść.
     *
     * @param   object  $http       HTTP client
     * @param   string  $apiToken   API token
     * @param   string  $subdomain  Subdomain
     * @param   int     $invoiceId  Invoice ID
     * @param   int     $orderId    ID zamówienia (wpis w historii przy odmowie)
     * @param   int     $debug      Debug mode flag
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function sendInvoiceByEmail(
        object $http,
        string $apiToken,
        string $subdomain,
        int $invoiceId,
        int $orderId,
        int $debug
    ): void {
        try {
            $response = $this->api($http, $apiToken, $subdomain, 'POST', '/invoices/' . $invoiceId . '/send_by_email.json');
            $data     = json_decode((string) $response->body, true);

            if (in_array($response->code, [200, 201]) && ($data['status'] ?? '') !== 'error') {
                if ($debug) {
                    $this->log("Zamówienie #{$orderId}: wysłano dokument e-mailem (ID {$invoiceId})", 'debug');
                }

                return;
            }

            $reason = $this->apiError($response);
            $this->log("Zamówienie #{$orderId}: Fakturownia nie wysłała dokumentu e-mailem (ID {$invoiceId}, kod {$response->code}): {$reason}", 'warning');
            $this->notifyAdmin(
                "Fakturownia nie wysłała dokumentu (ID {$invoiceId}) e-mailem do klienta: {$reason}. "
                    . 'Przy KSeF faktura dla firmy może być wysłana dopiero po nadaniu numeru KSeF — wyślij ją z Fakturowni.',
                $orderId
            );
        } catch (\Exception $e) {
            $this->log("Zamówienie #{$orderId}: wyjątek przy wysyłce e-mail: " . $e->getMessage(), 'warning');
        }
    }
}
