<?php

/**
 * Udawane API Fakturowni — wspólne dla testów jednostkowych (tests/run.php) i testów
 * na lokalnej kopii Joomli (tests/integration.php). Zachowanie odwzorowane z dokumentacji
 * github.com/fakturownia/API (README.md, KSeF.md; stan z 16.11.2026). Żadnego ruchu sieciowego.
 */

final class FakeResponse
{
    public function __construct(public int $code, public string $body = '', public array $headers = [])
    {
    }
}

final class FakeFakturownia
{
    public string $validToken = 'TEST_TOKEN_abc123';
    /** Konto z aktywnym KSeF i domyślnie włączoną blokadą faktur niezgodnych (KSeF.md) */
    public bool $ksef = false;
    public string $departmentName = 'Sklep Testowy Sp. z o.o.';

    public array $requests = [];
    public array $clients = [];
    public array $invoices = [];
    public array $payments = [];
    public array $products = [];
    public array $emails = [];
    /** 'METODA /trasa/{id}.json' => fn(self, string $path, array $body, array $query): ?FakeResponse */
    public array $faults = [];
    private int $nextId = 1000;

    public function get($url, array $headers = [], $timeout = null)
    {
        return $this->handle('GET', $url, null, $headers, $timeout);
    }

    public function post($url, $data, array $headers = [], $timeout = null)
    {
        return $this->handle('POST', $url, $data, $headers, $timeout);
    }

    public function put($url, $data, array $headers = [], $timeout = null)
    {
        return $this->handle('PUT', $url, $data, $headers, $timeout);
    }

    public function countRequests(string $method, string $route): int
    {
        return count(array_filter($this->requests, fn ($r) => $r['method'] === $method && $r['route'] === $route));
    }

    /** Ostatnia odpowiedź z błędem (kod ≥ 400 albo "status":"error") — do komunikatów testów */
    public function lastError(): string
    {
        foreach (array_reverse($this->requests) as $r) {
            $resp = $r['response'] ?? null;

            if ($resp && ($resp->code >= 400 || str_contains($resp->body, '"status":"error"'))) {
                return $r['method'] . ' ' . $r['route'] . ' → ' . $resp->code . ' ' . $resp->body;
            }
        }

        return '(brak błędu API)';
    }

    private function handle(string $method, string $url, $data, array $headers, $timeout): FakeResponse
    {
        $body  = (is_string($data) && $data !== '') ? (json_decode($data, true) ?? []) : [];
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $path  = $parts['path'] ?? '';
        $route = preg_replace('#/\d+#', '/{id}', $path);

        $auth  = null;
        $token = null;

        if (preg_match('/^Bearer\s+(.+)$/', (string) ($headers['Authorization'] ?? ''), $m)) {
            [$auth, $token] = ['header', $m[1]];
        } elseif (isset($query['api_token'])) {
            [$auth, $token] = ['query', $query['api_token']];
        } elseif (isset($body['api_token'])) {
            [$auth, $token] = ['body', $body['api_token']];
        }

        $i                  = count($this->requests);
        $this->requests[$i] = compact('method', 'url', 'route', 'path', 'body', 'query', 'headers', 'timeout', 'auth')
            + ['scheme' => $parts['scheme'] ?? '', 'host' => $parts['host'] ?? '', 'response' => null];

        if ($token !== $this->validToken) {
            $response = $this->json(401, ['code' => 'error', 'message' => 'Nieprawidłowy token API']);
        } else {
            $key      = $method . ' ' . $route;
            $response = isset($this->faults[$key]) ? ($this->faults[$key])($this, $path, $body, $query) : null;
            $response ??= $this->route($method, $route, $path, $body, $query);
        }

        $this->requests[$i]['response'] = $response;

        return $response;
    }

    public function route(string $method, string $route, string $path, array $body, array $query): FakeResponse
    {
        preg_match('#/(\d+)#', $path, $m);
        $id = isset($m[1]) ? (int) $m[1] : 0;

        switch ($method . ' ' . $route) {
            case 'GET /clients.json':
                $email = $query['email'] ?? null;

                return $this->json(200, array_values(array_filter($this->clients, fn ($c) => $email === null || ($c['email'] ?? '') === $email)));

            case 'POST /clients.json':
            case 'PUT /clients/{id}.json':
                $c = $body['client'] ?? [];

                if (mb_strlen((string) ($c['street'] ?? '')) > 255) {
                    return $this->json(422, ['code' => 'error', 'message' => ['street' => ['- pole jest za długie (maksymalna ilość znaków: 255)']]]);
                }

                if ($method === 'PUT') {
                    $this->clients[$id] = array_merge($this->clients[$id] ?? [], $c, ['id' => $id]);

                    return $this->json(200, $this->clients[$id]);
                }

                $id = $this->nextId++;
                // README §Klienci: „Domyślnie każdy nowy klient ma status firmy ("company": true)"
                $this->clients[$id] = $c + ['company' => true, 'id' => $id];

                return $this->json(201, $this->clients[$id]);

            case 'POST /invoices.json':
                return $this->createInvoice($body['invoice'] ?? [], $body);

            case 'GET /invoices/{id}.json':
                return isset($this->invoices[$id]) ? $this->json(200, $this->invoices[$id]) : $this->json(404, ['code' => 'error', 'message' => 'Not found']);

            case 'POST /banking/payments.json':
                $pid                  = $this->nextId++;
                $this->payments[$pid] = $body['banking_payment'] ?? [];

                return $this->json(201, ['id' => $pid]);

            case 'POST /invoices/{id}/send_by_email.json':
                $inv = $this->invoices[$id] ?? null;

                // KSeF.md §„Wysyłanie faktur emailem": firmom dopiero po nadaniu numeru KSeF; HTTP 200 + "status":"error"
                if ($this->ksef && $inv && $inv['buyer_company'] && empty($inv['gov_id'])) {
                    return $this->json(200, ['message' => 'Faktura nie może zostać wysłana - brak numeru KSeF', 'status' => 'error']);
                }

                $this->emails[] = $id;

                return $this->json(200, ['status' => 'ok']);

            case 'GET /products.json':
                // Jak żywe API (sprawdzone 23.11.2026): filtruje `query` (fragment kodu lub nazwy), `search` ignoruje
                $s = mb_strtolower((string) ($query['query'] ?? ''));

                return $this->json(200, array_values(array_filter($this->products, fn ($p) => $s === ''
                    || str_contains(mb_strtolower((string) ($p['code'] ?? '')), $s)
                    || str_contains(mb_strtolower((string) ($p['name'] ?? '')), $s))));

            case 'POST /products.json':
                $pid                  = $this->nextId++;
                $this->products[$pid] = ($body['product'] ?? []) + ['id' => $pid];

                return $this->json(201, $this->products[$pid]);

            case 'PUT /products/{id}.json':
                $this->products[$id] = array_merge($this->products[$id] ?? [], $body['product'] ?? []);

                return $this->json(200, $this->products[$id]);
        }

        return $this->json(404, ['code' => 'error', 'message' => 'Unknown route ' . $method . ' ' . $route]);
    }

    private function createInvoice(array $inv, array $envelope): FakeResponse
    {
        $errors = [];

        // client_id nadpisuje buyer_* danymi z karty klienta (README §f7; KSeF.md „Zachowanie przy podaniu ID klienta")
        $buyer = $inv;

        if (!empty($inv['client_id']) && isset($this->clients[$inv['client_id']])) {
            $c     = $this->clients[$inv['client_id']];
            $buyer = array_merge($inv, [
                'buyer_name'       => $c['name'] ?? '',
                'buyer_tax_no'     => $c['tax_no'] ?? '',
                'buyer_company'    => $c['company'] ?? true,
                'buyer_email'      => $c['email'] ?? '',
                'buyer_street'     => $c['street'] ?? '',
                'buyer_country'    => $c['country'] ?? '',
                'buyer_first_name' => $c['first_name'] ?? '',
                'buyer_last_name'  => $c['last_name'] ?? '',
            ]);
        }

        $company = filter_var($buyer['buyer_company'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $kind    = $inv['kind'] ?? 'vat';

        if (empty($inv['positions'])) {
            $errors['positions'][] = '- nie może być puste';
        }

        if (mb_strlen((string) ($buyer['buyer_street'] ?? '')) > 255) {
            $errors['buyer_street'][] = '- pole jest za długie (maksymalna ilość znaków: 255)';
        }

        // README §Pola faktury: oid_unique=yes — system nie pozwoli stworzyć 2 faktur o tym samym OID
        if (($inv['oid_unique'] ?? '') === 'yes' && !empty($inv['oid'])) {
            foreach ($this->invoices as $existing) {
                if (($existing['oid'] ?? null) === $inv['oid']) {
                    $errors['oid'][] = '- jest już zajęte';
                }
            }
        }

        // KSeF.md: walidacja przy aktywnym KSeF i włączonej (domyślnie) blokadzie faktur niezgodnych
        if ($this->ksef && in_array($kind, ['vat', 'correction'], true)) {
            foreach ($inv['positions'] ?? [] as $p) {
                if (mb_strlen((string) ($p['name'] ?? '')) > 256) {
                    $errors['positions.name'][] = '- pole jest za długie (maksymalna ilość znaków: 256)';
                }
            }

            // Bez seller_name i department_id → domyślny dział (README §Pola faktury);
            // seller_name zgodne z działem → reszta uzupełniona (KSeF.md „uzupełnianie po nazwie")
            if (!empty($inv['seller_name']) && $inv['seller_name'] !== $this->departmentName && empty($inv['seller_street'])) {
                $errors['seller_street'][]    = '- nie może być puste';
                $errors['seller_post_code'][] = '- nie może być puste';
                $errors['seller_city'][]      = '- nie może być puste';
            }

            if ($company && empty($buyer['buyer_tax_no']) && ($buyer['buyer_tax_no_kind'] ?? '') !== 'empty') {
                $errors['buyer_tax_no'][] = '- nie może być puste';
            }

            if (!$company && (empty($buyer['buyer_first_name']) || empty($buyer['buyer_last_name']))) {
                $errors['buyer_first_name'][] = '- nie może być puste';
            }

            if ($kind === 'correction' && (empty($inv['invoice_id']) || !isset($this->invoices[(int) $inv['invoice_id']]))) {
                $errors['invoice_id'][] = '- musi wskazywać na istniejącą fakturę';
            }

            // KSeF.md „Korekty faktur": pola nabywcy nie mogą różnić się od korygowanej faktury
            if ($kind === 'correction' && isset($this->invoices[(int) ($inv['invoice_id'] ?? 0)])) {
                $orig = $this->invoices[(int) $inv['invoice_id']];

                foreach (['buyer_tax_no', 'buyer_email'] as $k) {
                    if ((string) ($buyer[$k] ?? '') !== (string) ($orig[$k] ?? '')) {
                        $errors[$k][] = '- nie może być zmienione względem korygowanej faktury';
                    }
                }

                if ($company !== $orig['buyer_company']) {
                    $errors['buyer_company'][] = '- nie może być zmienione względem korygowanej faktury';
                }
            }
        }

        if ($errors) {
            return $this->json(422, ['code' => 'error', 'message' => $errors]);
        }

        // Suma dokumentu: rabat kwotowy pozycji jest odejmowany od wartości brutto
        $total = 0.0;

        foreach ($inv['positions'] ?? [] as $p) {
            $total += (float) ($p['total_price_gross'] ?? 0) - (float) ($p['discount'] ?? 0);
        }

        $id                  = $this->nextId++;
        $this->invoices[$id] = array_filter($buyer, fn ($k) => str_starts_with($k, 'buyer_'), ARRAY_FILTER_USE_KEY) + [
            'id'              => $id,
            'kind'            => $kind,
            'oid'             => $inv['oid'] ?? null,
            'price_gross'     => round($total, 2),
            'positions'       => $inv['positions'] ?? [],
            'client_id'       => $inv['client_id'] ?? null,
            'from_invoice_id' => $inv['from_invoice_id'] ?? null,
            'invoice_id'      => $inv['invoice_id'] ?? null,
            'gov_status'      => ($this->ksef && in_array($kind, ['vat', 'correction'], true)) ? 'processing' : null,
            'gov_id'          => null,
            'raw'             => $inv,
            'envelope'        => $envelope,
        ];
        $this->invoices[$id]['buyer_company'] = $company;

        return $this->json(201, ['id' => $id, 'kind' => $kind, 'gov_status' => $this->invoices[$id]['gov_status']]);
    }

    public function json(int $code, $data): FakeResponse
    {
        return new FakeResponse($code, json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
