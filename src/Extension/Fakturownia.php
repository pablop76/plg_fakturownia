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
     * Zabezpieczenie przed wielokrotnym wykonaniem
     *
     * @var    array
     * @since  2.0.0
     */
    private array $processedOrders = [];

    /**
     * Czy tabela blokad została już sprawdzona w tym żądaniu
     *
     * @var    boolean
     * @since  2.0.0
     */
    private bool $lockTableReady = false;

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
            'onAfterOrderUpdate' => 'onAfterOrderUpdate',
        ];
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

        // Zabezpieczenie przed wielokrotnym wykonaniem dla tego samego zamówienia
        if (in_array($orderId, $this->processedOrders)) {
            return;
        }
        $this->processedOrders[] = $orderId;

        $logFile    = JPATH_ADMINISTRATOR . '/logs/hikashop_fakturownia.log';
        $debugOrder = (int) $this->params->get('debug_order', 0);
        $debug      = (int) $this->params->get('debug', 0);

        $this->initLogFile($logFile);

        // Log cyklu życia ZAWSZE (niezależnie od trybu debug) — dzięki temu widać,
        // czy i w którym miejscu przetwarzanie się zatrzymuje, bez włączania debug.
        $this->log($logFile, "onAfterOrderUpdate: zamówienie #{$orderId} — start");

        $orderFull = $this->getOrderFull($orderId);

        if (!$orderFull) {
            $this->log($logFile, "Zamówienie #{$orderId}: PRZERWANO — getOrderFull() nie zwróciło danych (helper HikaShop niedostępny lub brak zamówienia)");

            return;
        }

        // Sprawdź czy faktura już istnieje w Fakturownia (zapisz ID faktury w zamówieniu)
        if ($this->invoiceAlreadyExists($orderFull)) {
            $this->log($logFile, "Zamówienie #{$orderId}: pominięto — faktura już istnieje (znacznik w order_params)");

            return;
        }

        // Dane całego zamówienia w logu
        if ($debugOrder) {
            $this->logOrder($logFile, $orderFull);
        }

        $currentStatus = $orderFull->order_status ?? '(brak)';

        // Status(y) wyzwalające wystawienie — konfigurowalne (domyślnie 'confirmed',
        // bo dopiero wtedy zamówienie jest realnie opłacone). Kilka statusów po przecinku.
        $triggerStatuses = $this->getTriggerStatuses();

        if (!in_array($currentStatus, $triggerStatuses, true)) {
            $this->log($logFile, "Zamówienie #{$orderId}: pominięto — status='{$currentStatus}', oczekiwano jednego z: " . implode(', ', $triggerStatuses));

            return;
        }

        $this->log($logFile, "Zamówienie #{$orderId}: status '{$currentStatus}' kwalifikuje — przygotowuję wystawienie faktury");

        // HikaShop bywa, że zwraca true/false zamiast obiektu (np. zamówienie bez wysyłki),
        // a operator ?? łapie tylko null. Metody niżej mają typowane argumenty `object`,
        // więc twardo normalizujemy do obiektu — inaczej leci TypeError.
        $billing   = is_object($orderFull->billing_address ?? null) ? $orderFull->billing_address : new \stdClass();
        $shipping  = is_object($orderFull->shipping_address ?? null) ? $orderFull->shipping_address : new \stdClass();
        $customer  = is_object($orderFull->customer ?? null) ? $orderFull->customer : new \stdClass();
        $products  = $orderFull->products ?? [];
        $shippings = $orderFull->shippings ?? [];

        // Czyszczenie i walidacja NIP nabywcy. Błędny NIP wywala fakturę (422), a literówka
        // klienta nie powinna blokować wystawienia. Czyścimy formatowanie (myślniki/spacje/PL),
        // a jeśli NIP jest faktycznie niepoprawny — usuwamy go (faktura idzie bez NIP).
        // Sam fakt podania NIP (sygnał B2B) zapamiętujemy PRZED czyszczeniem — wymusza fakturę.
        $buyerProvidedNip = !empty($billing->address_vat);

        if (!empty($billing->address_vat)) {
            $rawVat   = (string) $billing->address_vat;
            $cleanVat = $this->normalizeNip($rawVat);

            if ($cleanVat === '') {
                $this->log($logFile, "Zamówienie #{$orderId}: NIP nabywcy '{$rawVat}' jest nieprawidłowy — faktura zostanie wystawiona BEZ NIP");
                $this->notifyAdmin("Fakturownia - nieprawidłowy NIP nabywcy '{$rawVat}', faktura wystawiona bez NIP (zamówienie #{$orderId})", $orderId);
            }

            $billing->address_vat = $cleanVat;
        }

        // Waluta zamówienia
        $orderCurrencyInfo = $orderFull->order_currency_info ?? new \stdClass();
        $currencyCode      = 'PLN';

        if ($orderCurrencyInfo && is_string($orderCurrencyInfo)) {
            $obj = unserialize($orderCurrencyInfo);

            if ($obj && isset($obj->currency_code)) {
                $currencyCode = $obj->currency_code;
            }
        }

        // Koszt metody płatności (payment też bywa nie-obiektem)
        $payment       = is_object($orderFull->payment ?? null) ? $orderFull->payment : new \stdClass();
        $paymentName   = $payment->payment_name ?? '';
        $paymentPrice  = isset($payment->payment_price) ? (float) $payment->payment_price : 0.0;
        $paymentMethod = $this->mapPaymentMethod($paymentName);

        // Data płatności - używamy rzeczywistej daty zamiast 00:00
        $paymentDate = date('Y-m-d H:i:s', $orderFull->order_created);

        // Dane kuponu rabatowego
        $couponCode  = $orderFull->order_discount_code ?? '';
        $couponValue = isset($orderFull->order_discount_price) ? (float) $orderFull->order_discount_price : 0.0;

        $apiToken   = trim($this->params->get('api_token'));
        $subdomain  = $this->sanitizeSubdomain(trim($this->params->get('subdomain')));
        $sellerName  = trim($this->params->get('seller_name'));
        $sellerTaxNo = trim($this->params->get('seller_tax_no'));
        $invoiceMode = $this->params->get('invoice_mode', 'vat');
        $autoSendEmail = $this->params->get('auto_send_email', 0);

        // Walidacja konfiguracji - loguj błędy konfiguracji
        $configErrors = $this->validateConfig($apiToken, $subdomain, $sellerName, $sellerTaxNo);
        if (!empty($configErrors)) {
            $errorMsg = "Fakturownia - błąd konfiguracji (zamówienie #{$orderId}): " . implode(', ', $configErrors);
            $this->log($logFile, "BŁĄD KONFIGURACJI zamówienia {$orderId}: " . implode(', ', $configErrors));
            $this->notifyAdmin($errorMsg);
            return;
        }

        // Sprawdzamy, czy klient ustawił pole invoice_request
        $clientWantsInvoice = $this->checkIfClientWantsInvoice($order, $billing, $customer);

        // Logika wyboru typu dokumentu
        $invoiceKind = $this->determineInvoiceKind($clientWantsInvoice, $invoiceMode, $billing, $buyerProvidedNip);

        // ATOMOWE ZAKLEPANIE zamówienia — zamyka wyścig dwóch równoległych aktualizacji
        // (np. zmiana statusu + IPN płatności), który powodował duplikaty faktur.
        if (!$this->claimOrder($orderId, $logFile)) {
            $this->log($logFile, "Zamówienie #{$orderId}: pominięto — zaklepane/wystawione przez inny proces (ochrona przed duplikatem)");

            return;
        }

        // Utwórz obiekt klienta HTTP
        $http = HttpFactory::getHttp();

        $userEmail = $customer->user_email ?? 'Brak danych';

        try {
            // 1. Wysyła dane klienta do Fakturowni
            $clientId = $this->addOrUpdateClientToFakturownia($http, $apiToken, $subdomain, $billing, $userEmail, $logFile, $debug);

            if (!$clientId) {
                throw new \Exception('Nie udało się utworzyć/znaleźć klienta w Fakturowni');
            }

            // 2. Buduje pozycje faktury
            $positions = $this->buildPositions($orderFull, $products, $shippings, $paymentName, $paymentPrice, $couponCode, $couponValue);

            // Strażnik uzgodnienia — porównaj sumę pozycji z kwotą zamówienia.
            // Ujawnia groszowe rozjazdy zaokrągleń (bug "kwota się nie zgadza") w logu.
            $sumPositions = 0.0;

            foreach ($positions as $p) {
                $sumPositions += (float) ($p['total_price_gross'] ?? 0);
            }

            $orderTotal = (float) ($orderFull->order_full_price ?? 0);
            $delta      = round($sumPositions - $orderTotal, 2);

            if (abs($delta) >= 0.01) {
                $this->log($logFile, "Zamówienie #{$orderId}: UWAGA uzgodnienie — suma pozycji "
                    . number_format($sumPositions, 2) . " ≠ kwota zamówienia "
                    . number_format($orderTotal, 2) . " (różnica " . number_format($delta, 2) . ")");
            } elseif ($debug) {
                $this->log($logFile, "Zamówienie #{$orderId}: uzgodnienie OK (suma pozycji = "
                    . number_format($sumPositions, 2) . ")");
            }

            // 3. Wysyła fakturę do Fakturowni i pobiera jej ID
            $invoiceId = $this->sendInvoice($orderFull, $http, $apiToken, $subdomain, $billing, $positions, $sellerName, $sellerTaxNo, $invoiceKind, $clientId, $currencyCode, $paymentMethod, $logFile, $debug);

            if ($invoiceId) {
                // 4. Zapisujemy ID faktury w zamówieniu (zapobiega duplikatom)
                $this->saveInvoiceIdToOrder($orderFull, $invoiceId);

                // Trwałe oznaczenie w tabeli blokad — faktura rozliczona
                $this->markOrderDone($orderId, (int) $invoiceId);

                if ($autoSendEmail) {
                    $this->sendInvoiceByEmail($http, $apiToken, $subdomain, $invoiceId, $logFile, $debug);
                }

                // 5. Wysyła płatność powiązaną z fakturą do Fakturowni
                $this->sendPayment($http, $apiToken, $subdomain, $currencyCode, $orderFull, $billing, $shipping, $userEmail, $clientId, $invoiceId, $paymentMethod, $paymentDate, $logFile, $debug);

                // 6. Wysyłka produktów do Fakturownia
                foreach ($products as $product) {
                    if (!is_object($product)) {
                        continue;
                    }

                    $this->addOrUpdateProductToFakturownia($http, $apiToken, $subdomain, $product, $logFile, $debug);
                }

                $this->log($logFile, "Zamówienie #{$orderId}: ZAKOŃCZONO — Invoice ID: {$invoiceId}");
            } else {
                // Faktura nie powstała — zwolnij blokadę, by kolejna aktualizacja mogła ponowić
                $this->releaseOrder($orderId);
                $this->log($logFile, "Zamówienie #{$orderId}: faktura nie powstała — zwolniono blokadę do ponowienia");
            }
        } catch (\Throwable $e) {
            // Łapiemy \Throwable, nie tylko \Exception — TypeError/Error dziedziczą po \Error
            // i bez tego powodują fatal error, który psuje zapis zamówienia w HikaShop.
            // Błąd w trakcie — zwolnij blokadę, aby możliwe było ponowienie przy następnej aktualizacji
            $this->releaseOrder($orderId);

            // Zawsze loguj błędy (niezależnie od ustawienia debug)
            $errorMsg = "Fakturownia - błąd zamówienia #{$orderId}: " . $e->getMessage();
            $this->log($logFile, "BŁĄD zamówienia {$orderId}: " . $e->getMessage());
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
        $paymentName = strtolower(trim($paymentName));

        $mapping = [
            'payu'       => 'payu',
            'przelewy24' => 'p24',
            'przelew'    => 'transfer',
            'transfer'   => 'transfer',
            'gotówka'    => 'cash',
            'cash'       => 'cash',
            'karta'      => 'card',
            'card'       => 'card',
            'blik'       => 'blik',
            'dotpay'     => 'dotpay',
            'tpay'       => 'tpay',
            'paypal'     => 'paypal',
        ];

        foreach ($mapping as $key => $value) {
            if (strpos($paymentName, $key) !== false) {
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
     * @param   object  $billing        Billing address
     * @param   object  $shipping       Shipping address
     * @param   string  $userEmail      User email
     * @param   int     $clientId       Client ID in Fakturownia
     * @param   int     $invoiceId      Invoice ID in Fakturownia
     * @param   string  $paymentMethod  Payment method
     * @param   string  $paymentDate    Payment date
     * @param   string  $logFile        Log file path
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
        object $billing,
        object $shipping,
        string $userEmail,
        int $clientId,
        int $invoiceId,
        string $paymentMethod,
        string $paymentDate,
        string $logFile,
        int $debug
    ): void {
        $payload = [
            'api_token'       => $apiToken,
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
            $logEmail = preg_replace('/^(.).+(@.+)$/', '$1***$2', $userEmail);
            $this->log($logFile, "Wysyłamy payment JSON (masked email {$logEmail}, method: {$paymentMethod}, date: {$paymentDate})");
        }

        try {
            $url      = 'https://' . $subdomain . '.fakturownia.pl/banking/payments.json';
            $response = $http->post($url, json_encode($payload), [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ]);

            if ($debug) {
                $this->log($logFile, "Odpowiedź API payments: {$response->code}");
            }

            if (in_array($response->code, [200, 201])) {
                $data      = json_decode($response->body, true);
                $paymentId = $data['id'] ?? null;

                if ($debug) {
                    $this->log($logFile, "Utworzono płatność ID: {$paymentId}, typ: {$paymentMethod}");
                }
            } else {
                // Faktura już istnieje, ale płatność się nie zarejestrowała — to musi być widoczne,
                // bo inaczej dokument zostaje cicho nieopłacony w Fakturowni.
                $this->log($logFile, "Zamówienie #{$orderFull->order_id}: BŁĄD rejestracji płatności (kod {$response->code}): {$response->body}");
                $this->notifyAdmin(
                    "Fakturownia - faktura wystawiona, ale nie udało się zarejestrować płatności (zamówienie #{$orderFull->order_id})",
                    (int) $orderFull->order_id
                );
            }
        } catch (\Exception $e) {
            // Zawsze loguj (nie tylko w debug) — płatność jest drugorzędna, ale cisza utrudnia diagnozę.
            // Nie rzucamy dalej: faktura już istnieje, płatność można dopiąć ręcznie.
            $this->log($logFile, "Zamówienie #{$orderFull->order_id}: wyjątek API płatności: " . $e->getMessage());
        }
    }

    /**
     * Sprawdza czy faktura lub paragon już zostały utworzone dla tego zamówienia
     *
     * @param   object  $orderFull  Full order object
     *
     * @return  boolean
     *
     * @since   2.0.0
     */
    private function invoiceAlreadyExists(object $orderFull): bool
    {
        if (isset($orderFull->order_params) && !empty($orderFull->order_params)) {
            $params = is_string($orderFull->order_params)
                ? json_decode($orderFull->order_params, true)
                : (array) $orderFull->order_params;

            if (!empty($params['fakturownia_document_id'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zapisuje ID faktury w parametrach zamówienia
     *
     * @param   object  $orderFull  Full order object
     * @param   int     $invoiceId  Invoice ID from Fakturownia
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function saveInvoiceIdToOrder(object $orderFull, int $invoiceId): void
    {
        try {
            $db      = Factory::getContainer()->get('DatabaseDriver');
            $orderId = (int) $orderFull->order_id;

            // Pobierz aktualne parametry
            $query = $db->getQuery(true)
                ->select('order_params')
                ->from('#__hikashop_order')
                ->where('order_id = ' . $orderId);
            $db->setQuery($query);
            $currentParams = $db->loadResult();

            $params = [];

            if (!empty($currentParams)) {
                $params = json_decode($currentParams, true) ?: [];
            }

            // Dodaj ID faktury lub paragonu
            $params['fakturownia_document_id'] = $invoiceId;
            $params['fakturownia_processed']   = date('Y-m-d H:i:s');

            // Zapisz z powrotem
            $query = $db->getQuery(true)
                ->update('#__hikashop_order')
                ->set('order_params = ' . $db->quote(json_encode($params)))
                ->where('order_id = ' . $orderId);
            $db->setQuery($query);
            $db->execute();
        } catch (\Exception $e) {
            // Log error but don't break the process
            error_log('Błąd zapisywania ID faktury: ' . $e->getMessage());
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
     * @param   int     $orderId  ID zamówienia
     * @param   string  $logFile  Ścieżka pliku logu
     *
     * @return  bool  true gdy bieżący proces ma prawo wystawić fakturę
     *
     * @since   2.0.0
     */
    private function claimOrder(int $orderId, string $logFile): bool
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
            $this->log($logFile, "Błąd zaklepania zamówienia {$orderId}: " . $e->getMessage());

            return false;
        }
    }

    /**
     * Oznacza zamówienie jako rozliczone (faktura wystawiona).
     *
     * @param   int  $orderId    ID zamówienia
     * @param   int  $invoiceId  ID faktury w Fakturowni
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function markOrderDone(int $orderId, int $invoiceId): void
    {
        try {
            $db  = Factory::getContainer()->get('DatabaseDriver');
            $now = Factory::getDate()->toSql();

            $query = $db->getQuery(true)
                ->update($db->quoteName('#__hikashop_fakturownia_invoices'))
                ->set($db->quoteName('invoice_id') . ' = ' . $invoiceId)
                ->set($db->quoteName('state') . ' = ' . $db->quote('done'))
                ->set($db->quoteName('modified') . ' = ' . $db->quote($now))
                ->where($db->quoteName('order_id') . ' = ' . $orderId);
            $db->setQuery($query);
            $db->execute();
        } catch (\Exception $e) {
            // Niekrytyczne — fakt wystawienia trzyma też order_params
        }
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
        try {
            $db  = Factory::getContainer()->get('DatabaseDriver');
            $now = Factory::getDate()->toSql();

            $query = $db->getQuery(true)
                ->update($db->quoteName('#__hikashop_fakturownia_invoices'))
                ->set($db->quoteName('state') . ' = ' . $db->quote('error'))
                ->set($db->quoteName('modified') . ' = ' . $db->quote($now))
                ->where($db->quoteName('order_id') . ' = ' . $orderId);
            $db->setQuery($query);
            $db->execute();
        } catch (\Exception $e) {
            // Ignorujemy — błąd główny jest już zalogowany
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
     * Konfigurowalne polem 'trigger_status' (domyślnie 'confirmed').
     *
     * @return  array
     *
     * @since   2.0.0
     */
    private function getTriggerStatuses(): array
    {
        $raw      = (string) $this->params->get('trigger_status', 'confirmed');
        $statuses = array_values(array_filter(array_map('trim', explode(',', $raw))));

        return !empty($statuses) ? $statuses : ['confirmed'];
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
     * Waliduje konfigurację wtyczki
     *
     * @param   string  $apiToken    API token
     * @param   string  $subdomain   Subdomain
     * @param   string  $sellerName  Seller name
     * @param   string  $sellerTaxNo Seller tax number
     *
     * @return  array  Lista błędów (pusta jeśli konfiguracja OK)
     *
     * @since   2.0.0
     */
    private function validateConfig(string $apiToken, string $subdomain, string $sellerName, string $sellerTaxNo): array
    {
        $errors = [];

        if (empty($apiToken)) {
            $errors[] = 'Brak API Token';
        }

        if (empty($subdomain)) {
            $errors[] = 'Brak subdomeny';
        }

        if (empty($sellerName)) {
            $errors[] = 'Brak nazwy firmy sprzedawcy';
        }

        if (empty($sellerTaxNo)) {
            $errors[] = 'Brak NIP sprzedawcy';
        }

        return $errors;
    }

    /**
     * Tworzy plik logu jeśli nie istnieje.
     *
     * @param   string  $logFile  Log file path
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function initLogFile(string $logFile): void
    {
        if (!file_exists($logFile)) {
            file_put_contents($logFile, "Utworzono plik hikashop_fakturownia.log\n");
        }
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
    private function notifyAdmin(string $message, int $orderId = 0): void
    {
        try {
            // Wyodrębnij orderId z wiadomości jeśli nie podano
            if ($orderId === 0 && preg_match('/#(\d+)/', $message, $matches)) {
                $orderId = (int) $matches[1];
            }

            if ($orderId > 0) {
                // Zapisz błąd do historii zamówienia HikaShop
                $this->addOrderHistory($orderId, $message);
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
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function addOrderHistory(int $orderId, string $message): void
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');

            $history = new \stdClass();
            $history->history_order_id = $orderId;
            $history->history_created = time();
            $history->history_type = 'fakturownia_error';
            $history->history_notified = 0;
            $history->history_data = 'BŁĄD FAKTUROWNIA: ' . $message;
            $history->history_user_id = 0;
            $history->history_ip = '';
            $history->history_new_status = '';
            $history->history_reason = '';
            $history->history_payment_id = '';
            $history->history_payment_method = '';
            $history->history_amount = 0;

            $db->insertObject('#__hikashop_history', $history);
        } catch (\Exception $e) {
            // Ignoruj
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
        $helperPath = JPATH_ADMINISTRATOR . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR
            . 'com_hikashop' . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'helper.php';

        if (!@include_once($helperPath)) {
            return false;
        }

        $orderClass = hikashop_get('class.order');

        return $orderClass->loadFullOrder($orderId, true, false);
    }

    /**
     * Dodaje wpis do pliku logu.
     *
     * @param   string  $file  Log file path
     * @param   string  $msg   Message to log
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function log(string $file, string $msg): void
    {
        file_put_contents($file, date('c') . " $msg\n", FILE_APPEND);
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
        
        return $subdomain;
    }

    /**
     * Loguje pełne dane zamówienia do pliku logu.
     *
     * @param   string  $file       Log file path
     * @param   object  $orderFull  Full order object
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function logOrder(string $file, object $orderFull): void
    {
        file_put_contents(
            $file,
            date('c') . " \$orderFull: " . json_encode($orderFull, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND
        );
    }

    /**
     * Wysyła dane klienta do Fakturowni przez API lub aktualizuje istniejącego.
     * Zwraca ID klienta.
     *
     * @param   object  $http       HTTP client
     * @param   string  $apiToken   API token
     * @param   string  $subdomain  Subdomain
     * @param   object  $billing    Billing address
     * @param   string  $userEmail  User email
     * @param   string  $logFile    Log file path
     * @param   int     $debug      Debug mode flag
     *
     * @return  int|null
     *
     * @since   2.0.0
     */
    private function addOrUpdateClientToFakturownia(
        object $http,
        string $apiToken,
        string $subdomain,
        object $billing,
        string $userEmail,
        string $logFile,
        int $debug
    ): ?int {
        $clientName = $billing->address_company ?: ($billing->address_firstname . ' ' . $billing->address_lastname);

        $payload = [
            'api_token' => $apiToken,
            'client'    => [
                'name'         => $clientName,
                'tax_no'       => $billing->address_vat ?? '',
                'bank'         => '',
                'bank_account' => '',
                'city'         => $billing->address_city ?? '',
                'country'      => $billing->address_country_name ?? '',
                'email'        => $userEmail,
                'person'       => $billing->address_firstname . ' ' . $billing->address_lastname,
                'post_code'    => $billing->address_post_code ?? '',
                'phone'        => $billing->address_telephone ?? '',
                'street'       => $billing->address_street ?? '',
            ],
        ];

        try {
            // 1. Wyszukiwanie klienta po emailu
            $searchUrl      = 'https://' . $subdomain . '.fakturownia.pl/clients.json?api_token='
                . $apiToken . '&email=' . urlencode($userEmail);
            $searchResponse = $http->get($searchUrl, ['Accept' => 'application/json']);
            $clients        = json_decode($searchResponse->body, true);

            $clientId = null;

            if (!empty($clients) && isset($clients[0]['id'])) {
                // Klient istnieje → aktualizujemy
                $clientId = $clients[0]['id'];
                $url      = 'https://' . $subdomain . '.fakturownia.pl/clients/' . $clientId . '.json';
                $response = $http->put($url, json_encode($payload), [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ]);

                if ($debug) {
                    $this->log($logFile, "Zaktualizowano klienta ID {$clientId}: {$response->code}");
                }
            } else {
                // Brak klienta → tworzymy nowego
                $url      = 'https://' . $subdomain . '.fakturownia.pl/clients.json';
                $response = $http->post($url, json_encode($payload), [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ]);

                if (in_array($response->code, [200, 201])) {
                    $clientData = json_decode($response->body, true);
                    $clientId   = $clientData['id'] ?? null;
                }

                if ($debug) {
                    $this->log($logFile, "Dodano nowego klienta ID {$clientId}: {$response->code}");
                }
            }

            return $clientId;
        } catch (\Exception $e) {
            if ($debug) {
                $this->log($logFile, "Wyjątek API client: " . $e->getMessage());
            }

            throw new \Exception('Błąd API Fakturowni (client): ' . $e->getMessage());
        }
    }

    /**
     * Buduje tablicę pozycji faktury (produkty i wysyłka) na podstawie zamówienia.
     *
     * @param   object  $orderFull     Full order object
     * @param   array   $products      Products array
     * @param   array   $shippings     Shippings array
     * @param   string  $paymentName   Payment name
     * @param   float   $paymentPrice  Payment price
     * @param   string  $couponCode    Coupon code
     * @param   float   $couponValue   Coupon value
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
        float $paymentPrice,
        string $couponCode,
        float $couponValue
    ): array {
        $positions   = [];
        $aggregated  = [];

        foreach ($products as $product) {
            // Pomijamy pozycje-opcje bez ceny (dzieci zestawów), żeby nie dublować linii
            if (!empty($product->order_product_option_parent_id) && (float) $product->order_product_price <= 0) {
                continue;
            }

            $qty      = (float) $product->order_product_quantity;
            $priceNet = (float) $product->order_product_price_before_discount;

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

            // Obliczenie kwoty podatku i ceny brutto
            $priceTax   = $priceNet * $taxRate;
            $priceGross = $priceNet + $priceTax;

            // Konwersja stawki VAT na procent
            $taxPercent = $taxRate * 100;

            // Utwórz podstawową pozycję
            $position = [
                'name'              => strip_tags($product->order_product_name),
                'quantity'          => $qty,
                'tax'               => $taxPercent,
                'total_price_gross' => round($priceGross * $qty, 2),
            ];

            // Dodaj rabat tylko jeśli istnieje
            if (isset($product->order_product_discount_info)) {
                $info    = $product->order_product_discount_info;
                $flat    = isset($info->discount_flat_amount) ? (float) $info->discount_flat_amount : 0.0;
                $percent = isset($info->discount_percent_amount) ? (float) $info->discount_percent_amount : 0.0;

                if ($flat > 0) {
                    $position['discount'] = $flat;
                } elseif ($percent > 0) {
                    $position['discount_percent'] = $percent;
                }
            }

            $hasDiscount = isset($position['discount']) || isset($position['discount_percent']);

            if ($hasDiscount) {
                // Pozycje z rabatem zostawiamy osobno, by nie gubić metadanych rabatu
                $positions[] = $position;
            } else {
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
        }

        // Dołóż zagregowane pozycje (bez rabatów) do listy wynikowej
        $positions = array_merge($positions, array_values($aggregated));

        // Dodaj pozycje wysyłki (tylko jeśli cena > 0)
        foreach ($shippings as $ship) {
            if (!is_object($ship)) {
                continue;
            }

            $priceNet = (float) ($ship->shipping_price ?? 0);
            
            // Pomijaj wysyłkę z ceną 0
            if ($priceNet <= 0) {
                continue;
            }

            // Some HikaShop payloads don't provide order_shipping_tax.
            // Prefer tax info when available, otherwise fall back to 0%.
            if (isset($ship->order_shipping_tax)) {
                $taxRate = (float) $ship->order_shipping_tax;
            } elseif (!empty($ship->shipping_tax_info)) {
                $taxInfos = (array) $ship->shipping_tax_info;
                $firstTax = reset($taxInfos);

                if (is_object($firstTax)) {
                    $firstTax = (array) $firstTax;
                }

                if (isset($firstTax['tax_rate'])) {
                    $taxRate = (float) $firstTax['tax_rate'] * 100;
                } else {
                    $taxRate = 0.0;
                }
            } elseif (isset($ship->shipping_tax)) {
                $val     = (float) $ship->shipping_tax;
                $taxRate = ($val > 0 && $val <= 1) ? ($val * 100) : $val;
            } else {
                $taxRate = 0.0;
            }

            $priceGross = $priceNet * (1 + $taxRate / 100);

            $positions[] = [
                'name'              => 'Wysyłka: ' . $ship->shipping_name,
                'quantity'          => 1,
                'tax'               => $taxRate,
                'total_price_gross' => round($priceGross, 2),
            ];
        }

        // Dodaj koszt płatności, jeśli istnieje i ma wartość > 0
        if ($paymentPrice > 0) {
            $taxRate    = 23.0;
            $priceGross = $paymentPrice * (1 + $taxRate / 100);

            $positions[] = [
                'name'              => 'Koszt płatności: ' . strip_tags($paymentName ?: 'Płatność'),
                'quantity'          => 1,
                'tax'               => $taxRate,
                'total_price_gross' => round($priceGross, 2),
            ];
        }

        // Dodaj pozycję kuponu rabatowego
        if (!empty($couponCode) && $couponValue > 0) {
            // Obliczenie stawki VAT dla rabatu kwota rabatu i kwota podatku rabatu
            $orderDiscountPrice = $orderFull->order_discount_price;
            $orderDiscountTax   = $orderFull->order_discount_tax;

            // Zabezpieczenie przed dzieleniem przez zero (np. rabat 100% lub brak netto)
            $discountNet = $orderDiscountPrice - $orderDiscountTax;
            $vatRate     = $discountNet != 0 ? round(($orderDiscountTax / $discountNet) * 100, 2) : 0.0;

            $positions[] = [
                'name'              => 'Kupon rabatowy: ' . $couponCode,
                'quantity'          => 1,
                'tax'               => $vatRate,
                'total_price_gross' => round(-1 * $couponValue, 2),
            ];
        }

        return $positions;
    }

    /**
     * Wysyła fakturę do Fakturowni przez API.
     *
     * @param   object  $orderFull     Full order object
     * @param   object  $http          HTTP client
     * @param   string  $apiToken      API token
     * @param   string  $subdomain     Subdomain
     * @param   object  $billing       Billing address
     * @param   array   $positions     Invoice positions
     * @param   string  $sellerName    Seller name
     * @param   string  $sellerTaxNo   Seller tax number
     * @param   string  $invoiceKind   Invoice kind (vat/receipt)
     * @param   int     $clientId      Client ID in Fakturownia
     * @param   string  $currencyCode  Currency code (PLN, EUR, etc.)
     * @param   string  $paymentMethod Payment method (transfer, cash, card, etc.)
     * @param   string  $logFile       Log file path
     * @param   int     $debug         Debug mode flag
     *
     * @return  int|null
     *
     * @since   2.0.0
     */
    private function sendInvoice(
        object $orderFull,
        object $http,
        string $apiToken,
        string $subdomain,
        object $billing,
        array $positions,
        string $sellerName,
        string $sellerTaxNo,
        string $invoiceKind,
        int $clientId,
        string $currencyCode,
        string $paymentMethod,
        string $logFile,
        int $debug
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

        $payload = [
            'api_token' => $apiToken,
            'invoice'   => [
                'kind'            => $invoiceKind,
                'number'          => null,
                'sell_date'       => date('Y-m-d', $orderFull->order_created),
                'issue_date'      => date('Y-m-d', $orderFull->order_invoice_created),
                'payment_to'      => date('Y-m-d', strtotime('+7 days', $orderFull->order_invoice_created)),
                'seller_name'     => $sellerName,
                'seller_tax_no'   => $sellerTaxNo,
                'buyer_name'      => $billing->address_company ?: $billing->address_firstname . ' ' . $billing->address_lastname,
                'buyer_tax_no'    => $billing->address_vat ?? '',
                'buyer_post_code' => $billing->address_post_code ?? '',
                'buyer_city'      => $billing->address_city ?? '',
                'buyer_street'    => $billing->address_street ?? '',
                'buyer_country'   => $billing->address_country_name ?? '',
                'client_id'       => $clientId,
                'positions'       => $positions,
                'currency'        => $currencyCode,
                'payment_type'    => $paymentMethod,
                'show_discount'   => $showDiscount,
            ],
        ];

        if ($showDiscount && $discountKind) {
            $payload['invoice']['discount_kind'] = $discountKind;
        }

        if ($debug) {
            // Maskujemy token w logu — nie zostawiamy sekretu w pliku logu
            $logPayload                 = $payload;
            $logPayload['api_token']    = '***';

            $this->log($logFile, "Wysyłamy fakturę JSON");
            $this->log($logFile, "Payload faktury: " . json_encode($logPayload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        $url = 'https://' . $subdomain . '.fakturownia.pl/invoices.json';

        try {
            $response = $http->post($url, json_encode($payload), [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ]);

            if ($debug) {
                $this->log($logFile, "Odpowiedź API invoices: {$response->code}");
            }

            if (in_array($response->code, [200, 201])) {
                $invoiceData = json_decode($response->body, true);
                $invoiceId   = $invoiceData['id'] ?? null;

                if ($debug) {
                    $this->log($logFile, "Utworzono fakturę ID: {$invoiceId}");
                }

                return $invoiceId;
            }

            // Zawsze loguj błędy API (nie tylko w trybie debug)
            $errorBody = json_decode($response->body, true);
            $errorMsg  = is_array($errorBody)
                ? ($errorBody['message'] ?? $errorBody['error'] ?? $errorBody['errors'] ?? $response->body)
                : $response->body;

            // Fakturownia zwraca błędy walidacji jako tablicę/obiekt (np. {"tax_no":["jest nieprawidłowy"]}).
            // Bez tego interpolacja tablicy w string dawała "Array to string conversion" i maskowała treść błędu.
            if (!is_string($errorMsg)) {
                $errorMsg = json_encode($errorMsg, JSON_UNESCAPED_UNICODE);
            }

            $this->log($logFile, "BŁĄD API Fakturownia ({$response->code}): {$errorMsg}");

            throw new \Exception("Błąd API Fakturownia ({$response->code}): {$errorMsg}");
        } catch (\Exception $e) {
            $this->log($logFile, "Wyjątek API invoice: " . $e->getMessage());

            throw new \Exception('Błąd tworzenia faktury: ' . $e->getMessage());
        }
    }

    /**
     * Dodaje produkt do Fakturowni przez API lub aktualizuje istniejący.
     *
     * @param   object  $http       HTTP client
     * @param   string  $apiToken   API token
     * @param   string  $subdomain  Subdomain
     * @param   object  $product    Product object
     * @param   string  $logFile    Log file path
     * @param   int     $debug      Debug mode flag
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function addOrUpdateProductToFakturownia(
        object $http,
        string $apiToken,
        string $subdomain,
        object $product,
        string $logFile,
        int $debug
    ): void {
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

        $payload = [
            'api_token' => $apiToken,
            'product'   => [
                'name'      => strip_tags($product->order_product_name),
                'code'      => $productCode,
                'price_net' => (float) $product->order_product_price,
                'tax'       => $taxRate,
            ],
        ];

        try {
            // Pobierz listę produktów z filtrem search
            $searchUrl = 'https://' . $subdomain . '.fakturownia.pl/products.json?api_token='
                . $apiToken . '&search=' . urlencode($productCode);

            $searchResponse = $http->get($searchUrl, ['Accept' => 'application/json']);
            $productsList   = json_decode($searchResponse->body, true);

            if ($debug) {
                $this->log($logFile, "Szukam produktu code={$productCode}, znaleziono: " . count($productsList ?? []));
            }

            // Znajdź produkt po code (dokładne dopasowanie)
            $productId = null;

            if (is_array($productsList)) {
                foreach ($productsList as $p) {
                    // Porównanie bez uwzględnienia wielkości liter i ze trim
                    if (isset($p['code']) && strtolower(trim($p['code'])) === strtolower(trim($productCode))) {
                        $productId = $p['id'];
                        if ($debug) {
                            $this->log($logFile, "Znaleziono istniejący produkt ID={$productId}");
                        }
                        break;
                    }
                }
            }

            if ($productId) {
                // Aktualizacja istniejącego produktu
                $url      = 'https://' . $subdomain . '.fakturownia.pl/products/' . $productId . '.json';
                $response = $http->put($url, json_encode($payload), [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ]);

                if ($debug) {
                    $this->log($logFile, "Zaktualizowano produkt ID {$productId}");
                }
            } else {
                // Dodanie nowego produktu
                $url      = 'https://' . $subdomain . '.fakturownia.pl/products.json';
                $response = $http->post($url, json_encode($payload), [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ]);

                if ($debug) {
                    $this->log($logFile, "Dodano nowy produkt");
                }
            }
        } catch (\Exception $e) {
            if ($debug) {
                $this->log($logFile, "Wyjątek API product: " . $e->getMessage());
            }
            // Nie rzucamy wyjątku - produkt jest drugorzędny
        }
    }

    /**
     * Wysyła fakturę e-mailem do klienta przez API Fakturowni.
     *
     * @param   object  $http       HTTP client
     * @param   string  $apiToken   API token
     * @param   string  $subdomain  Subdomain
     * @param   int     $invoiceId  Invoice ID
     * @param   string  $logFile    Log file path
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
        string $logFile,
        int $debug
    ): void {
        try {
            $url      = 'https://' . $subdomain . '.fakturownia.pl/invoices/' . $invoiceId . '/send_by_email.json?api_token=' . $apiToken;
            $response = $http->post($url, '', [
                'Accept' => 'application/json',
            ]);

            if ($debug) {
                $this->log($logFile, "Wysłano fakturę e-mailem (Invoice ID: {$invoiceId}), kod odpowiedzi: {$response->code}");
            }

            if (!in_array($response->code, [200, 201])) {
                $this->log($logFile, "Błąd wysyłania e-maila z fakturą (Invoice ID: {$invoiceId}): {$response->body}");
            }
        } catch (\Exception $e) {
            if ($debug) {
                $this->log($logFile, "Wyjątek send_by_email: " . $e->getMessage());
            }
        }
    }
}
