<?php

/**
 * Test wtyczki na LOKALNEJ kopii Joomli z HikaShopem: prawdziwy rdzeń Joomli i HikaShopa,
 * prawdziwa baza MySQL, prawdziwe zdarzenia zamówień. API Fakturowni jest udawane
 * (tests/FakeFakturownia.php) — nic nie wychodzi do sieci.
 *
 * Bezpieczniki: inne wtyczki HikaShopa są odcięte (brak żądań do InPost, P24, GA itp.),
 * poczta wyłączona w pamięci (mailonline=0, configuration.php bez zmian), skrypt odmawia
 * pracy, jeśli baza nie jest na localhost. Dane fikcyjne; po teście usuwane są wszystkie
 * utworzone rekordy (także wpisy dziennika HikaShopa) i tabela blokad wtyczki.
 *
 * Uruchomienie (tylko kopia lokalna!): php tests/integration.php C:\laragon\www\sklep
 */

namespace Joomla\CMS\Http {
    // Podmiana przed autoloaderem Joomli: wtyczka dostaje udawane API zamiast prawdziwego klienta HTTP
    class HttpFactory
    {
        public static $http;

        public static function getHttp($options = [], $adapters = null)
        {
            return self::$http;
        }
    }
}

namespace {
    use Joomla\CMS\Factory;
    use Joomla\CMS\Http\HttpFactory;
    use Pablop76\Plugin\Hikashop\Fakturownia\Extension\Fakturownia;

    $root = rtrim((string) ($argv[1] ?? ''), '\\/');

    if ($root === '' || !is_file($root . '/configuration.php')) {
        fwrite(STDERR, "Podaj katalog lokalnej kopii Joomli, np. php tests/integration.php C:\\laragon\\www\\sklep\n");
        exit(2);
    }

    if (!preg_match('/public \$host = \'(localhost|127\.0\.0\.1)\'/', file_get_contents($root . '/configuration.php'))) {
        fwrite(STDERR, "Baza nie jest na localhost — to nie wygląda na kopię lokalną. Przerywam.\n");
        exit(2);
    }

    require __DIR__ . '/FakeFakturownia.php';

    const _JEXEC = 1;
    $_SERVER += ['HTTP_HOST' => 'localhost', 'HTTPS' => 'off', 'REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '127.0.0.1',
        'SCRIPT_NAME' => '/administrator/index.php', 'REQUEST_URI' => '/administrator/index.php'];
    define('JPATH_BASE', str_replace('/', DIRECTORY_SEPARATOR, $root) . DIRECTORY_SEPARATOR . 'administrator');
    require JPATH_BASE . '/includes/defines.php';
    require JPATH_BASE . '/includes/framework.php';

    $container = Factory::getContainer();

    foreach (['session', 'JSession', \Joomla\CMS\Session\Session::class, \Joomla\Session\Session::class, \Joomla\Session\SessionInterface::class] as $alias) {
        $container->alias($alias, 'session.cli');
    }

    $app                  = $container->get(\Joomla\CMS\Application\AdministratorApplication::class);
    Factory::$application = $app;
    $app->createExtensionNamespaceMap();
    $app->set('mailonline', 0);
    $lang = $container->get(\Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage('pl-PL');
    $app->loadLanguage($lang);
    Factory::$language = $lang;
    $lang->load('plg_hikashop_fakturownia', dirname(__DIR__));

    require_once JPATH_ADMINISTRATOR . '/components/com_hikashop/helpers/helper.php';

    // Odcięcie wszystkich wtyczek z bazy: importPlugin() nie załaduje nic poza naszym subskrybentem
    $pluginsProp = new ReflectionProperty(\Joomla\CMS\Plugin\PluginHelper::class, 'plugins');
    $pluginsProp->setValue(null, []);

    require dirname(__DIR__) . '/src/Extension/Fakturownia.php';
    require dirname(__DIR__) . '/src/Field/InvoicerequestField.php';

    $db     = $container->get('DatabaseDriver');
    $prefix = $app->get('dbprefix');

    $lockTable = $prefix . 'hikashop_fakturownia_invoices';

    if ($db->setQuery("SHOW TABLES LIKE " . $db->quote($lockTable))->loadResult()) {
        fwrite(STDERR, "Tabela $lockTable już istnieje (wtyczka używana na tej kopii?) — przerywam, żeby jej nie skasować.\n");
        exit(2);
    }

    // Stan wyjściowy do sprzątania
    $tables = ['hikashop_order' => 'order_id', 'hikashop_order_product' => 'order_product_id', 'hikashop_history' => 'history_id',
        'hikashop_address' => 'address_id', 'hikashop_user' => 'user_id', 'hikashop_log' => 'log_id'];
    $snapshot = [];

    foreach ($tables as $t => $pk) {
        $snapshot[$t] = (int) $db->setQuery("SELECT COALESCE(MAX($pk), 0) FROM {$prefix}$t")->loadResult();
    }

    $results  = [];
    $current  = null;
    $srv      = null;
    $defaults = ['api_token' => 'TEST_TOKEN_abc123', 'subdomain' => 'sklep-testowy', 'seller_name' => 'Sklep Testowy Sp. z o.o.',
        'seller_tax_no' => '5252445767', 'trigger_status' => 'confirmed', 'paid_status' => 'auto', 'invoice_mode' => 'vat',
        'auto_send_email' => 0, 'coupon_note' => 1, 'correction_status' => 'auto', 'auto_correction' => 0, 'payment_days' => 0,
        'debug' => 0, 'debug_order' => 0];

    final class IntegrationFailed extends Exception
    {
    }

    function check(bool $cond, string $msg): void
    {
        if (!$cond) {
            throw new IntegrationFailed($msg);
        }
    }

    /** Nowa instancja wtyczki podpięta do dyspozytora Joomli + świeże udawane API */
    function env(array $params = [], bool $ksef = false): FakeFakturownia
    {
        global $app, $current, $srv, $defaults;

        if ($current) {
            $app->getDispatcher()->removeSubscriber($current);
        }

        $current = makePlugin($params);
        $app->getDispatcher()->addSubscriber($current);
        $srv               = new FakeFakturownia();
        $srv->ksef         = $ksef;
        HttpFactory::$http = $srv;

        return $srv;
    }

    function makePlugin(array $params = []): Fakturownia
    {
        global $app, $defaults;

        // Sygnatura Joomli 5/6: sama konfiguracja (jak w services/provider.php rdzenia J6)
        $plugin = new Fakturownia(['name' => 'fakturownia', 'type' => 'hikashop', 'params' => json_encode($params + $defaults)]);
        $plugin->setApplication($app);

        return $plugin;
    }

    function t(string $id, string $title, callable $fn): void
    {
        global $results;

        try {
            $detail = (string) ($fn() ?? '');
            $results[] = [$id, $title, 'PASS', $detail];
        } catch (IntegrationFailed $e) {
            $results[] = [$id, $title, 'FAIL', $e->getMessage()];
        } catch (Throwable $e) {
            $results[] = [$id, $title, 'FAIL', 'Wyjątek: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()];
        }
    }

    function insertRow(string $table, array $row): int
    {
        global $db, $prefix;
        $obj = (object) $row;
        $db->insertObject($prefix . $table, $obj);

        return (int) $db->insertid();
    }

    function customer(array $address = []): array
    {
        static $n = 0;
        $n++;
        $userId = insertRow('hikashop_user', ['user_cms_id' => 0, 'user_email' => "test-fakturownia-$n@example.invalid",
            'user_created' => time(), 'user_params' => '', 'user_partner_email' => '']);
        $addrId = insertRow('hikashop_address', $address + ['address_user_id' => $userId, 'address_firstname' => 'Jan',
            'address_lastname' => 'Testowy', 'address_company' => '', 'address_vat' => '', 'address_street' => 'ul. Polna 1',
            'address_post_code' => '00-950', 'address_city' => 'Warszawa', 'address_country' => 'country_Poland_170',
            'address_telephone' => '500600700', 'address_published' => 1, 'address_type' => 'billing', 'address_default' => 1,
            'cechy' => '', 'regulamin' => '']);

        return [$userId, $addrId];
    }

    function line(string $name, int $qty, float $net, float $rate, ?float $unitTax = null): stdClass
    {
        $tax = $unitTax ?? round($net * $rate, 5);

        return (object) ['product_id' => 0, 'order_product_name' => $name, 'order_product_code' => '',
            'order_product_quantity' => $qty, 'order_product_price' => $net, 'order_product_tax' => $tax,
            'order_product_tax_info' => [(object) ['tax_namekey' => 'VAT' . ($rate * 100), 'tax_rate' => $rate, 'tax_amount' => $tax]],
            'order_product_options' => '', 'order_product_dimension_unit' => 'cm', 'order_product_weight_unit' => 'kg', 'cart_product_id' => 0];
    }

    /** Zamówienie zapisane przez prawdziwą klasę zamówień HikaShopa (zdarzenie onAfterOrderCreate) */
    function makeOrder(array $fields, array $lines, array $address = []): int
    {
        [$userId, $addrId] = customer($address);
        $order             = (object) ($fields + ['order_user_id' => $userId, 'order_billing_address_id' => $addrId,
            'order_shipping_address_id' => $addrId, 'order_status' => 'created', 'order_currency_id' => 125,
            'order_currency_info' => serialize((object) ['currency_id' => 125, 'currency_code' => 'PLN']),
            'order_payment_id' => 2, 'order_payment_method' => 'banktransfer', 'order_payment_price' => 0, 'order_payment_tax' => 0,
            'order_shipping_id' => '', 'order_shipping_method' => '', 'order_shipping_price' => 0, 'order_shipping_tax' => 0,
            'order_discount_code' => '', 'order_discount_price' => 0]);
        $order->cart           = new stdClass();
        $order->cart->products = $lines;
        $order->history        = (object) ['history_notified' => 0];

        global $app;
        check(hikashop_get('class.order')->save($order) !== false, 'HikaShop nie zapisał zamówienia: ' . json_encode($app->getMessageQueue(true), JSON_UNESCAPED_UNICODE));

        return (int) $order->order_id;
    }

    /** Zmiana statusu przez klasę zamówień HikaShopa (zdarzenie onAfterOrderUpdate) */
    function setStatus(int $orderId, string $status): void
    {
        $o = (object) ['order_id' => $orderId, 'order_status' => $status, 'history' => (object) ['history_notified' => 0]];
        hikashop_get('class.order')->save($o);
    }

    function lockState(int $orderId): ?string
    {
        global $db, $lockTable;

        try {
            $r = $db->setQuery("SELECT state FROM $lockTable WHERE order_id = $orderId")->loadResult();

            return $r === null ? null : (string) $r;
        } catch (Throwable $e) {
            return null;
        }
    }

    function historyText(int $orderId): string
    {
        global $db, $prefix;
        $rows = $db->setQuery("SELECT history_type, history_data FROM {$prefix}hikashop_history WHERE history_order_id = $orderId AND history_type LIKE 'fakturownia%'")->loadAssocList();

        return implode(' || ', array_map(fn ($h) => $h['history_type'] . ': ' . $h['history_data'], $rows));
    }

    function sumInv(array $inv): float
    {
        $t = 0.0;

        foreach ($inv['positions'] as $p) {
            $t += (float) $p['total_price_gross'] - (float) ($p['discount'] ?? 0);
        }

        return round($t, 2);
    }

    function invoices(FakeFakturownia $s, ?string $kind = null): array
    {
        return array_values(array_filter($s->invoices, fn ($i) => $kind === null || $i['kind'] === $kind));
    }

    function fullPrice(int $orderId): float
    {
        global $db, $prefix;

        return (float) $db->setQuery("SELECT order_full_price FROM {$prefix}hikashop_order WHERE order_id = $orderId")->loadResult();
    }

    try {
        // =================================================================================

        t('H1', 'Joomla 6 + HikaShop: zdarzenia zamówienia docierają do wtyczki (created → confirmed)', function () {
            $s  = env();
            $id = makeOrder(['order_full_price' => 46.20], [line('Sok jabłkowy 0,5 l', 2, 10.00, 0.23), line('Susz 100 g', 1, 20.00, 0.08)]);
            check(count($s->requests) === 0, 'żądania przy statusie created: ' . count($s->requests));
            setStatus($id, 'confirmed');
            $inv = invoices($s);
            check(count($inv) === 1, 'faktur: ' . count($inv) . ' ' . $s->lastError());
            check(abs(sumInv($inv[0]) - fullPrice($id)) < 0.005, 'suma faktury ' . sumInv($inv[0]) . ' ≠ order_full_price ' . fullPrice($id));
            check(count($s->payments) === 1, 'płatności: ' . count($s->payments));
            check(lockState($id) === 'done', 'stan blokady w MySQL: ' . lockState($id));

            return "zamówienie #$id, faktura " . $inv[0]['id'] . ', suma ' . sumInv($inv[0]);
        });

        t('H2', 'Płatność przy odbiorze: faktura przy pending, płatność po confirmed, wpis w historii HikaShopa', function () {
            $s  = env(['trigger_status' => 'confirmed,pending']);
            $id = makeOrder(['order_full_price' => 24.60, 'order_payment_id' => 3, 'order_payment_method' => 'collectondelivery'], [line('Sok', 2, 10.00, 0.23)]);
            setStatus($id, 'pending');
            check(count(invoices($s)) === 1 && count($s->payments) === 0 && lockState($id) === 'done_unpaid', 'po pending: faktur ' . count(invoices($s)) . ', płatności ' . count($s->payments) . ', stan ' . lockState($id));
            setStatus($id, 'confirmed');
            setStatus($id, 'confirmed');
            check(count($s->payments) === 1, 'płatności po confirmed: ' . count($s->payments));
            check(str_contains(historyText($id), 'Płatność zarejestrowana'), 'historia: ' . historyText($id));
            $type = $s->invoices[array_key_first($s->invoices)]['raw']['payment_type'] ?? '';

            check($type === 'cash_on_delivery', "payment_type: \"$type\" (metoda: Płatność przy odbiorze)");
        });

        t('H3', 'Powtórne zapisy statusu (confirmed ×2, shipped) — jedna faktura', function () {
            $s  = env();
            $id = makeOrder(['order_full_price' => 24.60], [line('Sok', 2, 10.00, 0.23)]);
            setStatus($id, 'confirmed');
            setStatus($id, 'confirmed');
            setStatus($id, 'shipped');
            check(count(invoices($s)) === 1, 'faktur: ' . count(invoices($s)));
        });

        t('H4', 'Dane zapisane przez HikaShop: 2 stawki VAT, kupon, wysyłka kurierem i koszt płatności — suma = zamówienie', function () {
            $s  = env();
            $id = makeOrder(['order_full_price' => 57.11, 'order_discount_code' => 'KUPON10', 'order_discount_price' => 10.00,
                'order_shipping_id' => '11', 'order_shipping_method' => 'manual', 'order_shipping_price' => 18.45, 'order_shipping_tax' => 3.45,
                'order_payment_price' => 2.46, 'order_payment_tax' => 0.46],
                [line('Sok', 2, 10.00, 0.23), line('Susz', 1, 20.00, 0.08)]);
            setStatus($id, 'confirmed');
            $inv = invoices($s)[0] ?? null;
            check($inv !== null, 'brak faktury ' . $s->lastError());
            check(abs(sumInv($inv) - 57.11) < 0.005, 'suma ' . sumInv($inv) . ' ≠ 57,11');
            $names = array_column($inv['positions'], 'name');
            check(in_array('Wysyłka: Kurier', $names, true), 'pozycje: ' . implode(' | ', $names));

            return 'pozycje: ' . implode(' | ', $names);
        });

        t('H5', 'Prawdziwe zaokrąglenia HikaShopa (round_calculations = 1): 4,07 zł netto × 7, VAT 23%', function () {
            $cfg  = hikashop_config();
            $orig = $cfg->get('round_calculations', 0);
            $cfg->set('round_calculations', 1); // tylko w pamięci — config->save() nie jest wołane

            try {
                $price = (object) ['price_currency_id' => 125, 'price_min_quantity' => 0, 'price_value' => 4.07, 'price_value_with_tax' => 4.07 * 1.23];
                hikashop_get('class.currency')->quantityPrice($price, 7);
            } finally {
                $cfg->set('round_calculations', $orig);
            }

            $total   = round((float) $price->price_value_with_tax, 2);
            $unitTax = round((float) $price->unit_price->price_value_with_tax - (float) $price->unit_price->price_value, 5);
            $s       = env();
            $id      = makeOrder(['order_full_price' => $total], [line('Konfitura 200 g', 7, 4.07, 0.23, $unitTax)]);
            setStatus($id, 'confirmed');
            $sum = sumInv(invoices($s)[0]);
            check(abs($sum - $total) < 0.005, "HikaShop: klient płaci $total zł (podatek jednostkowy $unitTax), faktura $sum zł");
        });

        t('H6', 'Dwa równoległe procesy na prawdziwej bazie MySQL (klucz główny tabeli blokad)', function () {
            $s  = env();
            $id = makeOrder(['order_full_price' => 24.60], [line('Sok', 2, 10.00, 0.23)]);
            $p2 = makePlugin();
            $s->faults['POST /invoices.json'] = function () use ($p2, $id) {
                static $done = false;

                if (!$done) {
                    $done = true;
                    $p2->onAfterOrderUpdate((object) ['order_id' => $id]);
                }

                return null;
            };
            setStatus($id, 'confirmed');
            check(count(invoices($s)) === 1, 'faktur: ' . count(invoices($s)));
        });

        t('H7', 'Zerwane połączenie po utworzeniu faktury, potem powtórzone confirmed', function () {
            $s  = env();
            $id = makeOrder(['order_full_price' => 24.60], [line('Sok', 2, 10.00, 0.23)]);
            $s->faults['POST /invoices.json'] = function ($srv, $path, $body, $query) {
                static $n = 0;

                if ($n++ === 0) {
                    $srv->route('POST', '/invoices.json', $path, $body, $query);
                    throw new RuntimeException('Connection reset by peer');
                }

                return null;
            };
            setStatus($id, 'confirmed');
            $h = historyText($id);
            setStatus($id, 'confirmed');
            check(count(invoices($s)) === 1, 'w Fakturowni ' . count(invoices($s)) . ' faktury dla zamówienia #' . $id . '; historia po pierwszej próbie: ' . $h);
        });

        t('H8', 'Anulowanie z auto-korektą: korekta do zera na danych z HikaShopa', function () {
            $s  = env(['auto_correction' => 1]);
            $id = makeOrder(['order_full_price' => 46.20], [line('Sok', 2, 10.00, 0.23), line('Susz', 1, 20.00, 0.08)]);
            setStatus($id, 'confirmed');
            setStatus($id, 'cancelled');
            $corr = invoices($s, 'correction')[0] ?? null;
            check($corr && abs(sumInv($corr) + 46.20) < 0.005, 'korekta: ' . ($corr ? sumInv($corr) : 'brak ' . $s->lastError()));
            check(lockState($id) === 'corrected', 'stan ' . lockState($id));
            check(str_contains(historyText($id), 'Korekta wystawiona'), 'historia: ' . historyText($id));
        });

        t('H9', 'KSeF aktywny: osoba prywatna — faktura przyjęta (bez błędu 422)', function () {
            $s  = env([], true);
            $id = makeOrder(['order_full_price' => 24.60], [line('Sok', 2, 10.00, 0.23)]);
            setStatus($id, 'confirmed');
            check(count(invoices($s)) === 1, 'faktura odrzucona; historia w panelu: ' . historyText($id));
        });

        t('H10', 'Panel zamówienia: sekcja Fakturownia (stan dokumentu i przycisk „Wystaw ponownie”)', function () {
            global $app;
            $s  = env();
            $id = makeOrder(['order_full_price' => 24.60], [line('Sok', 2, 10.00, 0.23)]);
            setStatus($id, 'confirmed');
            $order = hikashop_get('class.order')->loadFullOrder($id, true, false);
            ob_start();
            $app->triggerEvent('onAfterOrderProductsListingDisplay', [&$order, 'order_back_show']);
            $html = ob_get_clean();
            check(str_contains($html, 'htmlfieldset_fakturownia') && str_contains($html, 'plugin=fakturownia') && str_contains($html, (string) array_key_first($s->invoices)) && str_contains($html, 'Status KSeF'), 'HTML: ' . substr(strip_tags($html), 0, 300));
            ob_start();
            $app->triggerEvent('onAfterOrderProductsListingDisplay', [&$order, 'order_back_invoice']);
            check(ob_get_clean() === '', 'sekcja trafia też na wydruk faktury HikaShopa');
        });

        t('H11', 'services/provider.php tworzy wtyczkę na Joomli 6.2 bez ostrzeżenia o przestarzałym konstruktorze', function () {
            global $defaults, $pluginsProp;
            $pluginsProp->setValue(null, [(object) ['type' => 'hikashop', 'name' => 'fakturownia', 'params' => json_encode($defaults), 'id' => 0]]);
            $deprecated = [];
            set_error_handler(function ($no, $str) use (&$deprecated) {
                $deprecated[] = $str;

                return true;
            }, E_USER_DEPRECATED);

            try {
                $container = new \Joomla\DI\Container(Factory::getContainer());
                (require dirname(__DIR__) . '/services/provider.php')->register($container);
                $p = $container->get(\Joomla\CMS\Extension\PluginInterface::class);
            } finally {
                restore_error_handler();
                $pluginsProp->setValue(null, []);
            }

            check($p instanceof Fakturownia && $p->params->get('subdomain') === 'sklep-testowy', 'parametry nie dotarły');
            check(!$deprecated, 'Joomla zgłasza: ' . implode(' | ', $deprecated));
        });

        t('H12', 'Osoba prywatna z prawdziwego adresu HikaShopa: buyer_company=false, imię, nazwisko, kraj PL', function () {
            $s  = env();
            $id = makeOrder(['order_full_price' => 24.60], [line('Sok', 2, 10.00, 0.23)]);
            setStatus($id, 'confirmed');
            $raw = invoices($s)[0]['raw'] ?? [];
            check(($raw['buyer_company'] ?? null) === false && ($raw['buyer_first_name'] ?? '') === 'Jan' && ($raw['buyer_last_name'] ?? '') === 'Testowy', 'nabywca: ' . json_encode(array_intersect_key($raw, array_flip(['buyer_company', 'buyer_first_name', 'buyer_last_name'])), JSON_UNESCAPED_UNICODE));
            check(($raw['buyer_country'] ?? '') === 'PL', 'kraj z loadFullOrder: „' . ($raw['buyer_country'] ?? '') . '”');
            check(($raw['oid'] ?? '') !== '' && ($raw['oid_unique'] ?? '') === 'yes', 'oid: ' . json_encode([$raw['oid'] ?? null, $raw['oid_unique'] ?? null]));
        });

        t('H13', 'Wpisy wtyczki w dzienniku HikaShopa 6.8 (źródło fakturownia, powiązane z zamówieniem, bez tokenu)', function () {
            global $db, $prefix, $defaults;
            $s  = env(['debug' => 1, 'debug_order' => 1]);
            $id = makeOrder(['order_full_price' => 24.60], [line('Sok', 2, 10.00, 0.23)]);
            setStatus($id, 'confirmed');
            $rows = $db->setQuery("SELECT log_level, log_message, log_data FROM {$prefix}hikashop_log WHERE log_source = 'fakturownia' AND log_order_id = $id")->loadAssocList();
            check(count($rows) >= 3, 'wpisów: ' . count($rows));
            $all = json_encode($rows, JSON_UNESCAPED_UNICODE);
            check(!str_contains($all, $defaults['api_token']), 'token w dzienniku');
            check(str_contains($all, 'ZAKOŃCZONO'), 'brak wpisu o zakończeniu');

            return count($rows) . ' wpisów, poziomy: ' . implode(', ', array_unique(array_column($rows, 'log_level')));
        });

        t('H14', 'Przycisk „Utwórz pole w HikaShopie”: pole invoice_request przez class.field HikaShopa (wpis + kolumna adresu), status w ustawieniach, drugi raz bez duplikatu', function () {
            global $db, $prefix;
            $count = fn () => (int) $db->setQuery("SELECT COUNT(*) FROM {$prefix}hikashop_field WHERE field_namekey = 'invoice_request'")->loadResult();
            check($count() === 0, 'pole invoice_request już jest w tej kopii sklepu — test by je skasował, przerywam');
            $render = function () {
                $f    = new \Pablop76\Plugin\Hikashop\Fakturownia\Field\InvoicerequestField();
                $form = new \Joomla\CMS\Form\Form('fakturownia_test');
                $form->load('<form><field name="extension_id" type="hidden"/></form>');
                $form->bind(['extension_id' => 4242]);
                $f->setForm($form);

                return (new ReflectionMethod($f, 'getInput'))->invoke($f);
            };
            $p      = makePlugin();
            $create = new ReflectionMethod($p, 'createInvoiceRequestField');
            $before = $render();
            check(str_contains($before, 'action=create_field') && str_contains($before, 'extension_id=4242'), 'brak przycisku: ' . $before);

            try {
                [$msg, $type] = $create->invoke($p);
                check($type === 'message' && $count() === 1, "utworzenie: $type $msg, pól: " . $count());
                $row = $db->setQuery("SELECT * FROM {$prefix}hikashop_field WHERE field_namekey = 'invoice_request'")->loadObject();
                check($row->field_table === 'address' && $row->field_type === 'checkbox' && $row->field_address_type === 'billing'
                    && (int) $row->field_published === 1 && (int) $row->field_frontcomp === 1 && $row->field_value === '1::Chcę otrzymać fakturę VAT::0',
                    'pole: ' . json_encode($row, JSON_UNESCAPED_UNICODE));
                check(isset($db->getTableColumns($prefix . 'hikashop_address')['invoice_request']), 'brak kolumny invoice_request w tabeli adresów');
                $billing = hikashop_get('class.field')->getFields('frontcomp', $null, 'address');
                check(isset($billing['invoice_request']), 'HikaShop nie widzi pola w formularzu adresu na stronie sklepu');
                check(str_contains($render(), 'alert-success'), 'status po utworzeniu: ' . $render());
                [$msg2, $type2] = $create->invoke($p);
                check($count() === 1 && $type2 === 'message', "drugie kliknięcie: $type2 $msg2, pól: " . $count());

                return "„{$msg}” | drugi raz: „{$msg2}”";
            } finally {
                $id = (int) $db->setQuery("SELECT field_id FROM {$prefix}hikashop_field WHERE field_namekey = 'invoice_request'")->loadResult();

                if ($id) {
                    $ids = [$id];
                    hikashop_get('class.field')->delete($ids, false);  // false = usuń też kolumnę z tabeli adresów
                }
            }
        });
    } finally {
        // ======================= Sprzątanie: stan sprzed testu ===========================
        foreach ($tables as $t => $pk) {
            $db->setQuery("DELETE FROM {$prefix}$t WHERE $pk > " . $snapshot[$t])->execute();
        }

        $db->setQuery("DROP TABLE IF EXISTS $lockTable")->execute();

        $after = [];

        foreach ($tables as $t => $pk) {
            $after[] = $t . '=' . (int) $db->setQuery("SELECT COALESCE(MAX($pk), 0) FROM {$prefix}$t")->loadResult() . '/' . $snapshot[$t];
        }
    }

    foreach ($results as [$id, $title, $status, $detail]) {
        printf("%-4s %-4s %s%s\n", $status, $id, $title, $detail !== '' ? "\n          → $detail" : '');
    }

    $c = array_count_values(array_column($results, 2));
    printf("\nPASS: %d, FAIL: %d\nSprzątanie (MAX id po/przed): %s; tabela blokad usunięta\n",
        $c['PASS'] ?? 0, $c['FAIL'] ?? 0, implode(', ', $after));
}
