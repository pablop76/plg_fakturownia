<?php

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;

class plgHikashopFakturownia extends JPlugin
{
    /**
     * Główna funkcja wywoływana po aktualizacji zamówienia.
     * Pobiera pełne dane zamówienia, wysyła klienta, fakturę i płatność do Fakturowni.
     */
    public function onAfterOrderUpdate(&$order, &$send_email)
    {
        $logFile = JPATH_ADMINISTRATOR . '/logs/hikashop_fakturownia.log';
        $debug = (int) $this->params->get('debug', 0);

        $this->initLogFile($logFile);

        $orderFull = $this->getOrderFull($order->order_id);
        if (!$orderFull) return;

        //dane całego zamówienia w logu
        if ($debug) $this->logOrder($logFile, $orderFull);

        if (empty($orderFull->order_status) || $orderFull->order_status !== 'confirmed') {
            $this->log($logFile, "Status nie confirmed, wychodzimy");
            return;
        }

        $billing = $orderFull->billing_address ?? new stdClass;
        $shipping = $orderFull->shipping_address ?? new stdClass;
        $customer = $orderFull->customer ?? new stdClass;
        $products = $orderFull->products ?? [];
        $shippings = $orderFull->shippings ?? [];

        //koszt metody płatności np. "Płatność przy odbiorze"
        //nazwa np. "Płatność przy odbiorze"
        $paymentName = $orderFull->payment->payment_name ?? '';
        //kwota netto
        $paymentPrice = isset($orderFull->payment->payment_price) ? (float)$orderFull->payment->payment_price : 0.0;
        
        // Dane kuponu rabatowego (jeśli istnieje)
        $couponCode = $orderFull->order_discount_code ?? '';
        $couponValue = isset($orderFull->order_discount_price) ? (float)$orderFull->order_discount_price : 0.0;

        $apiToken = trim($this->params->get('api_token'));
        $subdomain = trim($this->params->get('subdomain'));

        $seller_name = trim($this->params->get('seller_name'));
        $seller_tax_no = trim($this->params->get('seller_tax_no'));

        $exportShipping = (int) $this->params->get('add_shipping_to_invoice', 0);
        $invoiceMode = $this->params->get('invoice_mode', 'auto');

        /** sprawdzamy, czy klient ustawił pole invoice_request, 
         * czy chce fakturę 
         */ 
        $clientWantsInvoice = false;
        if (isset($order->invoice_request) && !empty($order->invoice_request)) {
            $clientWantsInvoice = true;
        } elseif (isset($billing->invoice_request) && !empty($billing->invoice_request)) {
            $clientWantsInvoice = true;
        } elseif (isset($user->invoice_request) && !empty($user->invoice_request)) {
            $clientWantsInvoice = true;
        }

        // logika wyboru typu dokumentu
        if ($clientWantsInvoice) {
            $invoiceKind = 'vat';
        } else {
            if ($invoiceMode === 'vat') {
                $invoiceKind = 'vat';
            } elseif ($invoiceMode === 'receipt') {
                $invoiceKind = 'receipt';
            } else { // auto
                $invoiceKind = empty($billing->address_vat) ? 'receipt' : 'vat';
            }
        }

        /** Utwórz (lub pobierz) obiekt klienta HTTP w Joomla, 
         * który umożliwia wykonywanie zapytań sieciowych 
         * — np. GET, POST, PUT itp. 
         */ 
        $http = HttpFactory::getHttp();

        $userEmail = $customer->user_email ?? 'Brak danych';
        $userId = $customer->user_id ?? 'Brak danych';

        // Wysyła dane klienta do Fakturowni
        $this->addOrUpdateClientToFakturownia($http, $apiToken, $subdomain, $billing, $userEmail, $logFile, $debug);

        // Buduje pozycje faktury (produkty + wysyłka)
        $positions = $this->buildPositions($products,$exportShipping, $shippings, $paymentName, $paymentPrice,  $couponCode, $couponValue);

        // Wysyła fakturę do Fakturowni i pobiera jej ID
        $invoiceId = $this->sendInvoice($http, $apiToken, $subdomain, $billing, $positions, $seller_name, $seller_tax_no, $invoiceKind, $logFile, $debug);

        // Wysyła płatność powiązaną z fakturą do Fakturowni
        $this->sendPayment($http, $apiToken, $subdomain, $orderFull, $billing, $shipping, $userEmail, $userId, $invoiceId, $logFile, $debug);

        // wysyłka produktów do Fakturownia lub robi update istniejących
        foreach ($products as $product) {
            $this->addOrUpdateProductToFakturownia($http, $apiToken, $subdomain, $product, $logFile, $debug);
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
     */
private function addOrUpdateClientToFakturownia($http, $apiToken, $subdomain, $billing, $userEmail, $logFile, $debug)
{
    // budujemy payload
    $payload = [
        'api_token' => $apiToken,
        'client' => [
            'name' => $billing->address_company ?: ($billing->address_firstname . ' ' . $billing->address_lastname),
            'tax_no' => $billing->address_vat,
            'bank' => '',
            'bank_account' => '',
            'city' => $billing->address_city,
            'country' => $billing->address_country_name,
            'email' => $userEmail,
            'person' => $billing->address_firstname . ' ' . $billing->address_lastname,
            'post_code' => $billing->address_post_code,
            'phone' => $billing->address_telephone,
            'street' => $billing->address_street
        ]
    ];

    try {
        // 1. wyszukiwanie klienta po emailu lub NIP
        $searchUrl = 'https://' . $subdomain . '.fakturownia.pl/clients.json?api_token='
                     . $apiToken . '&email=' . urlencode($userEmail);
        $searchResponse = $http->get($searchUrl, ['Accept'=>'application/json']);
        $clients = json_decode($searchResponse->body, true);

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
            if ($debug) $this->log($logFile, "Dodano nowego klienta: {$response->code}");
        }
    } catch (\Exception $e) {
        if ($debug) $this->log($logFile, "Wyjątek API client: " . $e->getMessage());
        Factory::getApplication()->enqueueMessage('Wyjątek API Fakturowni (client): ' . $e->getMessage(), 'error');
    }
}

    /**
     * Wysyła płatność powiązaną z fakturą do Fakturowni przez API.
     */
    private function sendPayment($http, $apiToken, $subdomain, $orderFull, $billing, $shipping, $userEmail, $userId, $invoiceId, $logFile, $debug)
    {
        $payload = [
            'api_token' => $apiToken,
            'banking_payment' => [
                "city" => $shipping->address_city,
                "client_id" => $userId,
                "comment" => null,
                "country" => $billing->address_country_name,
                "currency" => "PLN",
                "deleted" => false,
                "department_id" => null,
                "description" => "status confirmed",
                "email" => $userEmail,
                "first_name" => $billing->address_firstname,
                "generate_invoice" => false,
                "invoice_city" => $billing->address_city,
                "invoice_comment" => "",
                "invoice_country" => $billing->address_country_name,
                "invoice_id" => $invoiceId,
                "invoice_name" => $billing->address_company,
                "invoice_post_code" => $billing->address_post_code,
                "invoice_street" => $billing->address_street,
                "invoice_tax_no" => $billing->address_vat,
                "last_name" => $billing->address_lastname,
                "name" => "Plantność za zamówienie id:" . $orderFull->order_id,
                "oid" => "",
                "paid" => true,
                "paid_date" => date('Y-m-d H:i:s', $orderFull->order_created),
                "phone" => $billing->address_telephone,
                "post_code" => $billing->address_post_code,
                "price" => $orderFull->order_full_price,
                "product_id" => 1,
                "promocode" => "",
                "provider" => "transfer",
                "provider_response" => null,
                "provider_status" => null,
                "provider_title" => null,
                "quantity" => 1,
                "street" => $billing->address_street,
                "kind" => "api"
            ]
        ];

        if ($debug) {
            $logEmail = preg_replace('/^(.).+(@.+)$/', '$1***$2', $userEmail);
            $this->log($logFile, "Wysyłamy JSON do Fakturowni (masked email {$logEmail}): " . json_encode($payload, JSON_UNESCAPED_UNICODE));
        }

        try {
            $url = 'https://' . $subdomain . '.fakturownia.pl/banking/payments.json';
            $response = $http->post($url, json_encode($payload), [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]);
            if ($debug) $this->log($logFile, "Odpowiedź API payments: {$response->code} {$response->body}");
            if (in_array($response->code, [200, 201])) {
                $data = json_decode($response->body, true);
                $paymentId = $data['id'] ?? null;
                Factory::getApplication()->enqueueMessage(
                    'Zarejestrowano płatność w Fakturowni. Payment ID: ' . $paymentId .
                    ($invoiceId ? ', Invoice ID: ' . $invoiceId : ''),
                    'message'
                );
            } else {
                Factory::getApplication()->enqueueMessage('Błąd API Fakturowni: ' . $response->body, 'error');
            }
        } catch (\Exception $e) {
            if ($debug) $this->log($logFile, "Wyjątek API: " . $e->getMessage());
            Factory::getApplication()->enqueueMessage('Wyjątek API Fakturowni: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Buduje tablicę pozycji faktury (produkty i wysyłka) na podstawie zamówienia.
     */
    private function buildPositions($products, $exportShipping, $shippings, $paymentName, $paymentPrice, $couponCode, $couponValue)
    {
        $positions = [];

        foreach ($products as $product) {
            $qty = (float)$product->order_product_quantity; // ilość produktów
            $priceNet = (float)$product->order_product_price_before_discount; //cena zawsze przed rabatem

            // Pobranie stawki VAT
            $taxRate = 0;
            if (!empty($product->order_product_tax_info)) {
                $taxInfos = (array)$product->order_product_tax_info;
                $firstTax = reset($taxInfos);
                if (is_object($firstTax)) $firstTax = (array)$firstTax;
                if (isset($firstTax['tax_rate'])) {
                    $taxRate = (float)$firstTax['tax_rate']; // np. 0.23
                }
            }

            // Obliczenie kwoty podatku i ceny brutto
            $priceTax = $priceNet * $taxRate;
            $priceGross = $priceNet + $priceTax;

            if (!empty($product->order_product_tax_info)) {
                $taxInfos = (array)$product->order_product_tax_info;
                $firstTax = reset($taxInfos);
                if (is_object($firstTax)) $firstTax = (array)$firstTax;
                if (isset($firstTax['tax_rate'])) {
                    $taxRate = (float)$firstTax['tax_rate'] * 100;
                }
            }

            // Utwórz podstawową pozycję
            $position = [
                'name' => strip_tags($product->order_product_name),
                'quantity' => $qty,
                'tax' => $taxRate, // procent
                'total_price_gross' => $priceGross * $qty,
            ];

            // Dodaj rabat tylko jeśli istnieje
            if (isset($product->order_product_discount_info)) {
                $info = $product->order_product_discount_info;
                $flat = isset($info->discount_flat_amount) ? (float)$info->discount_flat_amount : 0.0; // kwotowa zniżka
                $percent = isset($info->discount_percent_amount) ? (float)$info->discount_percent_amount : 0.0; // procentowa zniżka

                if ($flat > 0) {
                    $position['discount'] = $flat;
                } elseif ($percent > 0) {
                    $position['discount_percent'] = $percent;
                }
            }

            // Dodaj produkt do tablicy pozycji
            $positions[] = $position;
        }

        // Dodaj pozycje wysyłki (jeśli włączone)
        if ($exportShipping) {
            foreach ($shippings as $ship) {
                if (!is_object($ship)) continue;

                $priceNet = (float)$ship->shipping_price;
                $taxRate = 23.0;
                $priceGross = $priceNet * (1 + $taxRate / 100);

                $positions[] = [
                    'name' => 'Wysyłka: ' . $ship->shipping_name,
                    'quantity' => 1,
                    'tax' => $taxRate,
                    'total_price_gross' => $priceGross,
                ];
            }
        }
        // Dodaj koszt płatności, jeśli istnieje i ma wartość > 0
        if ($paymentPrice > 0) {
            $taxRate = 23.0; // domyślnie 23%
            $priceGross = $paymentPrice * (1 + $taxRate / 100);

            $positions[] = [
                'name' => 'Koszt płatności: ' . strip_tags($paymentName ?: 'Płatność'),
                'quantity' => 1,
                'tax' => $taxRate,
                'total_price_gross' => $priceGross,
            ];
        }
        // Dodaj pozycję kuponu rabatowego jako osobny wiersz na fakturze 
        if (!empty($couponCode) && $couponValue > 0) {
            $positions[] = [
                'name' => 'Kupon rabatowy: ' . $couponCode,
                'quantity' => 1,
                'tax' => $taxRate, // używamy ostatnio zdefiniowanej stawki VAT
                'total_price_gross' => -1 * $couponValue, // ujemna wartość (odejmujemy rabat)
            ];
        }

        return $positions;
    }


    /**
     * Wysyła fakturę do Fakturowni przez API i zwraca jej ID.
     */
    private function sendInvoice($http, $apiToken, $subdomain, $billing, $positions, $seller_name, $seller_tax_no, $invoiceKind, $logFile, $debug)
    {
        // Sprawdź, czy w pozycji jest chociaż jeden rabat
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
                'sell_date' => date('Y-m-d'),
                'issue_date' => date('Y-m-d'),
                'payment_to' => date('Y-m-d', strtotime('+7 days')),
                'seller_name' => $seller_name,
                'seller_tax_no' => $seller_tax_no,
                'buyer_name' => $billing->address_company ?: $billing->address_firstname . ' ' . $billing->address_lastname,
                'buyer_tax_no' => $billing->address_vat,
                'positions' => $positions,
                'show_discount' => $showDiscount,
            ],
        ];
        // jezeli jest rabat to dodaj rodzaj rabatu
        if ($showDiscount && $discountKind) {
            $payload['invoice']['discount_kind'] = $discountKind;
        }

        if ($debug) {
            $this->log($logFile, "Wysyłamy fakturę JSON: " . json_encode($payload, JSON_UNESCAPED_UNICODE));
        }

        $url = 'https://' . $subdomain . '.fakturownia.pl/invoices.json';
        try {
            $response = $http->post($url, json_encode($payload), [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]);
            if ($debug) {
                $this->log($logFile, "Odpowiedź API invoices: {$response->code} {$response->body}");
            }
            if (in_array($response->code, [200, 201])) {
                $invoiceData = json_decode($response->body, true);
                $invoiceId = $invoiceData['id'] ?? null;
                Factory::getApplication()->enqueueMessage(
                    'Utworzono fakturę w Fakturowni. ID: ' . $invoiceId, 'message'
                );
                return $invoiceId;
            } else {
                Factory::getApplication()->enqueueMessage(
                    'Błąd tworzenia faktury: ' . $response->body, 'error'
                );
                return null;
            }
        } catch (\Exception $e) {
            Factory::getApplication()->enqueueMessage(
                'Wyjątek API Fakturowni (invoice): ' . $e->getMessage(), 'error'
            );
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

        // Unikalny code (jeżeli w produkcie brak)
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
            // 1️⃣ Pobierz listę produktów z filtrem search
            $searchUrl = 'https://' . $subdomain . '.fakturownia.pl/products.json?api_token='
                        . $apiToken . '&search=' . urlencode($productCode);

            $searchResponse = $http->get($searchUrl, ['Accept' => 'application/json']);
            $productsList = json_decode($searchResponse->body, true);

            // 2️⃣ Znajdź produkt po code
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
                // 3️⃣ Aktualizacja istniejącego produktu
                $url = 'https://' . $subdomain . '.fakturownia.pl/products/' . $productId . '.json';
                $response = $http->put($url, json_encode($payload), [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ]);
                if ($debug) {
                    $this->log($logFile, "Zaktualizowano produkt {strip_tags($product->order_product_name)} (ID {$productId}): {$response->code} {$response->body}");
                }
            } else {
                // 4️⃣ Dodanie nowego produktu
                $url = 'https://' . $subdomain . '.fakturownia.pl/products.json';
                $response = $http->post($url, json_encode($payload), [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ]);
                if ($debug) {
                    $this->log($logFile, "Dodano produkt {strip_tags($product->order_product_name)}: {$response->code} {$response->body}");
                }
            }

            if (!in_array($response->code, [200, 201])) {
                \Joomla\CMS\Factory::getApplication()
                    ->enqueueMessage('Błąd API Fakturowni (product): ' . $response->body, 'error');
            }

        } catch (\Exception $e) {
            if ($debug) $this->log($logFile, "Wyjątek API product: " . $e->getMessage());
            \Joomla\CMS\Factory::getApplication()
                ->enqueueMessage('Wyjątek API Fakturowni (product): ' . $e->getMessage(), 'error');
        }
    }
}
