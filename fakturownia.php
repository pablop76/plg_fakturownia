<?php

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;

class plgHikashopFakturownia extends JPlugin
{
    private $processedOrders = []; // Zabezpieczenie przed wielokrotnym wykonaniem

    /**
     * Główna funkcja wywoływana po aktualizacji zamówienia.
     * Pobiera pełne dane zamówienia, wysyła klienta, fakturę i płatność do Fakturowni.
     */
    public function onAfterOrderUpdate(&$order)
    {
        $orderId = (int)$order->order_id;

        // Zabezpieczenie przed wielokrotnym wykonaniem dla tego samego zamówienia
        if (in_array($orderId, $this->processedOrders)) {
            return;
        }
        $this->processedOrders[] = $orderId;

        $logFile = JPATH_ADMINISTRATOR . '/logs/hikashop_fakturownia.log';
        $debugOrder = (int) $this->params->get('debug_order', 0);
        $debug = (int) $this->params->get('debug', 0);

        $this->initLogFile($logFile);

        $orderFull = $this->getOrderFull($orderId);
        if (!$orderFull) return;

        // Sprawdź czy faktura już istnieje w Fakturownia (zapisz ID faktury w zamówieniu)
        if ($this->invoiceAlreadyExists($orderFull)) {
            if ($debug) $this->log($logFile, "Faktura już istnieje dla zamówienia {$orderId}, pomijam");
            return;
        }

        //dane całego zamówienia w logu
        if ($debugOrder) $this->logOrder($logFile, $orderFull);

        if (empty($orderFull->order_status) || $orderFull->order_status !== 'confirmed') {
            if ($debug) $this->log($logFile, "Status nie confirmed, wychodzimy");
            return;
        }

        $billing = $orderFull->billing_address ?? new stdClass;
        $shipping = $orderFull->shipping_address ?? new stdClass;
        $customer = $orderFull->customer ?? new stdClass;
        $products = $orderFull->products ?? [];
        $shippings = $orderFull->shippings ?? [];

        //waluta zamówienia
        $orderCurrencyInfo = $orderFull->order_currency_info ?? new stdClass;
        $currencyCode = 'PLN'; // domyślnie
        if ($orderCurrencyInfo && is_string($orderCurrencyInfo)) {
            $obj = unserialize($orderCurrencyInfo);
            if ($obj && isset($obj->currency_code)) {
                $currencyCode = $obj->currency_code;
            }
        }

        //koszt metody płatności
        $paymentName = $orderFull->payment->payment_name ?? '';
        $paymentPrice = isset($orderFull->payment->payment_price) ? (float)$orderFull->payment->payment_price : 0.0;

        // Dane kuponu rabatowego
        $couponCode = $orderFull->order_discount_code ?? '';
        $couponValue = isset($orderFull->order_discount_price) ? (float)$orderFull->order_discount_price : 0.0;

        $apiToken = trim($this->params->get('api_token'));
        $subdomain = trim($this->params->get('subdomain'));

        $seller_name = trim($this->params->get('seller_name'));
        $seller_tax_no = trim($this->params->get('seller_tax_no'));

        $invoiceMode = $this->params->get('invoice_mode', 'vat');

        $autoSendEmail = $this->params->get('auto_send_email', 0);

        /** sprawdzamy, czy klient ustawił pole invoice_request */
        $clientWantsInvoice = $this->checkIfClientWantsInvoice($order, $billing, $customer);

        // logika wyboru typu dokumentu
        $invoiceKind = $this->determineInvoiceKind($clientWantsInvoice, $invoiceMode, $billing);

        /** Utwórz obiekt klienta HTTP */
        $http = HttpFactory::getHttp();

        $userEmail = $customer->user_email ?? 'Brak danych';
        $userId = $customer->user_id ?? 'Brak danych';

        try {
            // 1. Wysyła dane klienta do Fakturowni
            $clientId = $this->addOrUpdateClientToFakturownia($http, $apiToken, $subdomain, $billing, $userEmail, $logFile, $debug);

            // 2. Buduje pozycje faktury
            $positions = $this->buildPositions($products, $shippings, $paymentName, $paymentPrice, $couponCode, $couponValue);

            // 3. Wysyła fakturę do Fakturowni i pobiera jej ID
            $invoiceId = $this->sendInvoice($orderFull, $http, $apiToken, $subdomain, $billing, $positions, $seller_name, $seller_tax_no, $invoiceKind, $clientId, $logFile, $debug);

            if ($invoiceId) {
                // 4. Zapisujemy ID faktury w zamówieniu (zapobiega duplikatom)
                $this->saveInvoiceIdToOrder($orderFull, $invoiceId);

                if ($autoSendEmail) {
                    $this->sendInvoiceByEmail($http, $apiToken, $subdomain, $invoiceId, $logFile, $debug);
                }


                // 5. Wysyła płatność powiązaną z fakturą do Fakturowni
                $this->sendPayment($http, $apiToken, $subdomain, $currencyCode, $orderFull, $billing, $shipping, $userEmail, $clientId, $invoiceId, $logFile, $debug);

                // 6. Wysyłka produktów do Fakturownia
                foreach ($products as $product) {
                    $this->addOrUpdateProductToFakturownia($http, $apiToken, $subdomain, $product, $logFile, $debug);
                }

                if ($debug) $this->log($logFile, "Zakończono przetwarzanie zamówienia {$orderId}, Invoice ID: {$invoiceId}");
            }
        } catch (\Exception $e) {
            if ($debug) $this->log($logFile, "Błąd przetwarzania zamówienia {$orderId}: " . $e->getMessage());
            Factory::getApplication()->enqueueMessage('Błąd przetwarzania zamówienia w Fakturowni: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Sprawdza czy faktura lub paragon już zostały utworzone dla tego zamówienia
     */
    private function invoiceAlreadyExists($orderFull)
    {
        if (isset($orderFull->order_params) && !empty($orderFull->order_params)) {
            $params = is_string($orderFull->order_params) ? json_decode($orderFull->order_params, true) : (array)$orderFull->order_params;
            if (!empty($params['fakturownia_document_id'])) {
                return true;
            }
        }
        return false;
    }


    /**
     * Zapisuje ID faktury w parametrach zamówienia
     */
    private function saveInvoiceIdToOrder($orderFull, $invoiceId)
    {
        try {
            $db = Factory::getDbo();
            $orderId = (int)$orderFull->order_id;

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
            $params['fakturownia_processed'] = date('Y-m-d H:i:s');

            // Zapisz z powrotem
            $query = $db->getQuery(true)
                ->update('#__hikashop_order')
                ->set('order_params = ' . $db->quote(json_encode($params)))
                ->where('order_id = ' . $orderId);
            $db->setQuery($query);
            $db->execute();
        } catch (\Exception $e) {
            // Log error but don't break the process
            error_log("Błąd zapisywania ID faktury: " . $e->getMessage());
        }
    }

    /**
     * Sprawdza czy klient chce fakturę
     */
    private function checkIfClientWantsInvoice($order, $billing, $customer)
    {
        if (isset($order->invoice_request) && !empty($order->invoice_request)) {
            return true;
        } elseif (isset($billing->invoice_request) && !empty($billing->invoice_request)) {
            return true;
        } elseif (isset($customer->invoice_request) && !empty($customer->invoice_request)) {
            return true;
        }
        return false;
    }

    /**
     * Określa rodzaj faktury
     */
    private function determineInvoiceKind($clientWantsInvoice, $invoiceMode, $billing)
    {
        if ($clientWantsInvoice) {
            return 'vat';
        } else {
            if ($invoiceMode === 'vat') {
                return 'vat';
            } elseif ($invoiceMode === 'receipt') {
                return 'receipt';
            } else { // auto
                return empty($billing->address_vat) ? 'receipt' : 'vat';
            }
        }
    }

    /**
     * Tworzy plik logu jeśli nie istnieje.
     */
    private function initLogFile($logFile)
    {
        if (!file_exists($logFile)) {
            file_put_contents($logFile, "Utworzono plik hikashop_fakturownia.log\n");
        }
    }

    /**
     * Pobiera pełne dane zamówienia z Hikashop.
     */
    private function getOrderFull($orderId)
    {
        if (!@include_once(rtrim(JPATH_ADMINISTRATOR, DS) . DS . 'components' . DS . 'com_hikashop' . DS . 'helpers' . DS . 'helper.php')) {
            return false;
        }
        $orderClass = hikashop_get('class.order');
        return $orderClass->loadFullOrder($orderId, true, false);
    }

    /**
     * Dodaje wpis do pliku logu.
     */
    private function log($file, $msg)
    {
        file_put_contents($file, date('c') . " $msg\n", FILE_APPEND);
    }

    /**
     * Loguje pełne dane zamówienia do pliku logu.
     */
    private function logOrder($file, $orderFull)
    {
        file_put_contents($file, date('c') . " \$orderFull: " . json_encode($orderFull, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }

    /**
     * Wysyła dane klienta do Fakturowni przez API lub aktualizuje istniejącego.
     * Zwraca ID klienta.
     */
    private function addOrUpdateClientToFakturownia($http, $apiToken, $subdomain, $billing, $userEmail, $logFile, $debug)
    {
        $clientName = $billing->address_company ?: ($billing->address_firstname . ' ' . $billing->address_lastname);

        $payload = [
            'api_token' => $apiToken,
            'client' => [
                'name' => $clientName,
                'tax_no' => $billing->address_vat ?? '',
                'bank' => '',
                'bank_account' => '',
                'city' => $billing->address_city ?? '',
                'country' => $billing->address_country_name ?? '',
                'email' => $userEmail,
                'person' => $billing->address_firstname . ' ' . $billing->address_lastname,
                'post_code' => $billing->address_post_code ?? '',
                'phone' => $billing->address_telephone ?? '',
                'street' => $billing->address_street ?? ''
            ]
        ];

        try {
            // 1. wyszukiwanie klienta po emailu
            $searchUrl = 'https://' . $subdomain . '.fakturownia.pl/clients.json?api_token='
                . $apiToken . '&email=' . urlencode($userEmail);
            $searchResponse = $http->get($searchUrl, ['Accept' => 'application/json']);
            $clients = json_decode($searchResponse->body, true);

            $clientId = null;
            if (!empty($clients) && isset($clients[0]['id'])) {
                // klient istnieje → aktualizujemy
                $clientId = $clients[0]['id'];
                $url = 'https://' . $subdomain . '.fakturownia.pl/clients/' . $clientId . '.json';
                $response = $http->put($url, json_encode($payload), [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ]);
                if ($debug) $this->log($logFile, "Zaktualizowano klienta ID {$clientId}: {$response->code}");
            } else {
                // brak klienta → tworzymy nowego
                $url = 'https://' . $subdomain . '.fakturownia.pl/clients.json';
                $response = $http->post($url, json_encode($payload), [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ]);
                if (in_array($response->code, [200, 201])) {
                    $clientData = json_decode($response->body, true);
                    $clientId = $clientData['id'] ?? null;
                }
                if ($debug) $this->log($logFile, "Dodano nowego klienta ID {$clientId}: {$response->code}");
            }

            return $clientId;
        } catch (\Exception $e) {
            if ($debug) $this->log($logFile, "Wyjątek API client: " . $e->getMessage());
            Factory::getApplication()->enqueueMessage('Wyjątek API Fakturowni (client): ' . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Wysyła płatność powiązaną z fakturą do Fakturowni przez API.
     */
    private function sendPayment($http, $apiToken, $subdomain, $currencyCode, $orderFull, $billing, $shipping, $userEmail, $clientId, $invoiceId, $logFile, $debug)
    {
        $payload = [
            'api_token' => $apiToken,
            'banking_payment' => [
                "city" => $shipping->address_city ?? $billing->address_city,
                "client_id" => $clientId,
                "comment" => null,
                "country" => $billing->address_country_name ?? '',
                "currency" => $currencyCode ?? 'PLN',
                "deleted" => false,
                "department_id" => null,
                "description" => "Płatność za zamówienie id:" . $orderFull->order_id,
                "email" => $userEmail,
                "first_name" => $billing->address_firstname ?? '',
                "generate_invoice" => false,
                "invoice_city" => $billing->address_city ?? '',
                "invoice_comment" => "",
                "invoice_country" => $billing->address_country_name ?? '',
                "invoice_id" => $invoiceId,
                "invoice_name" => $billing->address_company ?? ($billing->address_firstname . ' ' . $billing->address_lastname),
                "invoice_post_code" => $billing->address_post_code ?? '',
                "invoice_street" => $billing->address_street ?? '',
                "invoice_tax_no" => $billing->address_vat ?? '',
                "last_name" => $billing->address_lastname ?? '',
                "name" => "Płatność za zamówienie id:" . $orderFull->order_id,
                "oid" => "",
                "paid" => true,
                "paid_date" => date('Y-m-d', $orderFull->order_created),
                "phone" => $billing->address_telephone ?? '',
                "post_code" => $billing->address_post_code ?? '',
                "price" => $orderFull->order_full_price,
                "product_id" => 1,
                "promocode" => "",
                "provider" => "transfer",
                "provider_response" => null,
                "provider_status" => null,
                "provider_title" => null,
                "quantity" => 1,
                "street" => $billing->address_street ?? '',
                "kind" => "api"
            ]
        ];

        if ($debug) {
            $logEmail = preg_replace('/^(.).+(@.+)$/', '$1***$2', $userEmail);
            $this->log($logFile, "Wysyłamy payment JSON (masked email {$logEmail})");
        }

        try {
            $url = 'https://' . $subdomain . '.fakturownia.pl/banking/payments.json';
            $response = $http->post($url, json_encode($payload), [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]);
            if ($debug) $this->log($logFile, "Odpowiedź API payments: {$response->code}");
            if (in_array($response->code, [200, 201])) {
                $data = json_decode($response->body, true);
                $paymentId = $data['id'] ?? null;
                if ($debug) $this->log($logFile, "Utworzono płatność ID: {$paymentId}");
            } else {
                $this->log($logFile, "Błąd tworzenia płatności: {$response->body}");
            }
        } catch (\Exception $e) {
            if ($debug) $this->log($logFile, "Wyjątek API payments: " . $e->getMessage());
        }
    }

    /**
     * Buduje tablicę pozycji faktury (produkty i wysyłka) na podstawie zamówienia.
     */
    private function buildPositions($products, $shippings, $paymentName, $paymentPrice, $couponCode, $couponValue)
    {
        $positions = [];

        foreach ($products as $product) {
            $qty = (float)$product->order_product_quantity;
            $priceNet = (float)$product->order_product_price_before_discount;

            // Pobranie stawki VAT
            $taxRate = 0;
            if (!empty($product->order_product_tax_info)) {
                $taxInfos = (array)$product->order_product_tax_info;
                $firstTax = reset($taxInfos);
                if (is_object($firstTax)) $firstTax = (array)$firstTax;
                if (isset($firstTax['tax_rate'])) {
                    $taxRate = (float)$firstTax['tax_rate'];
                }
            }

            // Obliczenie kwoty podatku i ceny brutto
            $priceTax = $priceNet * $taxRate;
            $priceGross = $priceNet + $priceTax;

            // Konwersja stawki VAT na procent
            $taxPercent = $taxRate * 100;

            // Utwórz podstawową pozycję
            $position = [
                'name' => strip_tags($product->order_product_name),
                'quantity' => $qty,
                'tax' => $taxPercent,
                'total_price_gross' => round($priceGross * $qty, 2),
            ];

            // Dodaj rabat tylko jeśli istnieje
            if (isset($product->order_product_discount_info)) {
                $info = $product->order_product_discount_info;
                $flat = isset($info->discount_flat_amount) ? (float)$info->discount_flat_amount : 0.0;
                $percent = isset($info->discount_percent_amount) ? (float)$info->discount_percent_amount : 0.0;

                if ($flat > 0) {
                    $position['discount'] = $flat;
                } elseif ($percent > 0) {
                    $position['discount_percent'] = $percent;
                }
            }

            $positions[] = $position;
        }

        // Dodaj pozycje wysyłki 
        foreach ($shippings as $ship) {
            if (!is_object($ship)) continue;

            $priceNet = (float)$ship->shipping_price;
            $taxRate = (float)($ship->order_shipping_tax ?? 0);
            $priceGross = $priceNet * (1 + $taxRate / 100);

            $positions[] = [
                'name' => 'Wysyłka: ' . $ship->shipping_name,
                'quantity' => 1,
                'tax' => $taxRate,
                'total_price_gross' => round($priceGross, 2),
            ];
        }

        // Dodaj koszt płatności, jeśli istnieje i ma wartość > 0
        if ($paymentPrice > 0) {
            $taxRate = 23.0;
            $priceGross = $paymentPrice * (1 + $taxRate / 100);

            $positions[] = [
                'name' => 'Koszt płatności: ' . strip_tags($paymentName ?: 'Płatność'),
                'quantity' => 1,
                'tax' => $taxRate,
                'total_price_gross' => round($priceGross, 2),
            ];
        }

        // Dodaj pozycję kuponu rabatowego
        if (!empty($couponCode) && $couponValue > 0) {
            $positions[] = [
                'name' => 'Kupon rabatowy: ' . $couponCode,
                'quantity' => 1,
                'tax' => 23.0,
                'total_price_gross' => round(-1 * $couponValue, 2),
            ];
        }

        return $positions;
    }

    /**
     * Wysyła fakturę do Fakturowni przez API.
     */
    private function sendInvoice($orderFull, $http, $apiToken, $subdomain, $billing, $positions, $seller_name, $seller_tax_no, $invoiceKind, $clientId, $logFile, $debug)
    {
        // Sprawdź czy w pozycji jest chociaż jeden rabat
        $showDiscount = false;
        $discountKind = null;

        foreach ($positions as $pos) {
            if (isset($pos['discount']) && $pos['discount'] > 0) {
                $showDiscount = true;
                $discountKind = 'amount';
                break;
            } elseif (isset($pos['discount_percent']) && $pos['discount_percent'] > 0) {
                $showDiscount = true;
                $discountKind = 'percent_unit';
                break;
            }
        }

        $payload = [
            'api_token' => $apiToken,
            'invoice' => [
                'kind' => $invoiceKind,
                'number' => null,
                'sell_date' => date('Y-m-d', $orderFull->order_created),
                'issue_date' => date('Y-m-d', $orderFull->order_invoice_created),
                'payment_to' => date('Y-m-d', strtotime('+7 days', $orderFull->order_invoice_created)),
                'seller_name' => $seller_name,
                'seller_tax_no' => $seller_tax_no,
                'buyer_name' => $billing->address_company ?: $billing->address_firstname . ' ' . $billing->address_lastname,
                'buyer_tax_no' => $billing->address_vat ?? '',
                'buyer_post_code' => $billing->address_post_code ?? '',
                'buyer_city' => $billing->address_city ?? '',
                'buyer_street' => $billing->address_street ?? '',
                'buyer_country' => $billing->address_country_name ?? '',
                'client_id' => $clientId,
                'positions' => $positions,
                'show_discount' => $showDiscount,
            ],
        ];

        if ($showDiscount && $discountKind) {
            $payload['invoice']['discount_kind'] = $discountKind;
        }

        if ($debug) {
            $this->log($logFile, "Wysyłamy fakturę JSON");
        }

        $url = 'https://' . $subdomain . '.fakturownia.pl/invoices.json';
        try {
            $response = $http->post($url, json_encode($payload), [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]);
            if ($debug) {
                $this->log($logFile, "Odpowiedź API invoices: {$response->code}");
            }
            if (in_array($response->code, [200, 201])) {
                $invoiceData = json_decode($response->body, true);
                $invoiceId = $invoiceData['id'] ?? null;
                if ($debug) $this->log($logFile, "Utworzono fakturę ID: {$invoiceId}");
                return $invoiceId;
            } else {
                $this->log($logFile, "Błąd tworzenia faktury: {$response->body}");
                return null;
            }
        } catch (\Exception $e) {
            $this->log($logFile, "Wyjątek API invoice: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Dodaje produkt do Fakturowni przez API lub aktualizuje istniejacy.
     */
    private function addOrUpdateProductToFakturownia($http, $apiToken, $subdomain, $product, $logFile, $debug)
    {
        // Pobierz stawkę VAT
        $taxRate = 23; // domyślna
        if (!empty($product->order_product_tax_info)) {
            $taxInfos = (array)$product->order_product_tax_info;
            $firstTaxInfo = reset($taxInfos);
            if (is_object($firstTaxInfo)) $firstTaxInfo = (array)$firstTaxInfo;
            if (isset($firstTaxInfo['tax_rate'])) {
                $taxRate = (float)$firstTaxInfo['tax_rate'] * 100;
            }
        }

        // Unikalny code
        $productCode = !empty($product->order_product_code)
            ? $product->order_product_code
            : 'order_' . $product->order_id . '_prod_' . $product->order_product_id;

        $payload = [
            'api_token' => $apiToken,
            'product' => [
                'name'      => strip_tags($product->order_product_name),
                'code'      => $productCode,
                'price_net' => (float)$product->order_product_price,
                'tax'       => $taxRate
            ]
        ];

        try {
            // Pobierz listę produktów z filtrem search
            $searchUrl = 'https://' . $subdomain . '.fakturownia.pl/products.json?api_token='
                . $apiToken . '&search=' . urlencode($productCode);

            $searchResponse = $http->get($searchUrl, ['Accept' => 'application/json']);
            $productsList = json_decode($searchResponse->body, true);

            // Znajdź produkt po code
            $productId = null;
            if (is_array($productsList)) {
                foreach ($productsList as $p) {
                    if (isset($p['code']) && $p['code'] === $productCode) {
                        $productId = $p['id'];
                        break;
                    }
                }
            }

            if ($productId) {
                // Aktualizacja istniejącego produktu
                $url = 'https://' . $subdomain . '.fakturownia.pl/products/' . $productId . '.json';
                $response = $http->put($url, json_encode($payload), [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ]);
                if ($debug) {
                    $this->log($logFile, "Zaktualizowano produkt ID {$productId}");
                }
            } else {
                // Dodanie nowego produktu
                $url = 'https://' . $subdomain . '.fakturownia.pl/products.json';
                $response = $http->post($url, json_encode($payload), [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ]);
                if ($debug) {
                    $this->log($logFile, "Dodano nowy produkt");
                }
            }
        } catch (\Exception $e) {
            if ($debug) $this->log($logFile, "Wyjątek API product: " . $e->getMessage());
        }
    }
    /**
     * Wysyła fakturę e-mailem do klienta przez API Fakturowni.
     */
    private function sendInvoiceByEmail($http, $apiToken, $subdomain, $invoiceId, $logFile, $debug)
    {
        try {
            $url = 'https://' . $subdomain . '.fakturownia.pl/invoices/' . $invoiceId . '/send_by_email.json?api_token=' . $apiToken;
            $response = $http->post($url, '', [
                'Accept' => 'application/json'
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
