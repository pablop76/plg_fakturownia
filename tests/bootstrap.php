<?php

/**
 * Środowisko testowe bez Joomli i HikaShopa: atrapy klas rdzenia, baza SQLite w pamięci
 * i udawany serwer API Fakturowni (zachowanie wg dokumentacji github.com/fakturownia/API,
 * stan z 16.11.2026). Żadne żądanie nie wychodzi do sieci.
 *
 * Uruchomienie: php tests/run.php
 */

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

namespace {
    define('_JEXEC', 1);
    date_default_timezone_set('Europe/Warsaw');

    // Atrapa katalogu administratora: plik helpera HikaShopa (getOrderFull robi include_once) i logs/
    $tmpAdmin = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'plg_fakturownia_tests_' . getmypid();
    @mkdir($tmpAdmin . '/logs', 0777, true);
    @mkdir($tmpAdmin . '/components/com_hikashop/helpers', 0777, true);
    file_put_contents($tmpAdmin . '/components/com_hikashop/helpers/helper.php', "<?php\n");
    define('JPATH_ADMINISTRATOR', $tmpAdmin);

    final class TestParams
    {
        public function __construct(private array $data)
        {
        }

        public function get($key, $default = null)
        {
            return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
        }
    }

    // --- HikaShop: tylko funkcje, których używa wtyczka ---------------------------------
    $GLOBALS['HS_ORDERS'] = [];
    $GLOBALS['HS_CONFIG'] = [];
    $GLOBALS['HS_LOG']    = [];

    function hikashop_get($name)
    {
        if ($name === 'class.order') {
            return new class () {
                public function loadFullOrder($id, $additional = false, $checkUser = true)
                {
                    // Głęboka kopia — jak świeży odczyt z bazy przy każdym wywołaniu
                    return isset($GLOBALS['HS_ORDERS'][$id]) ? unserialize(serialize($GLOBALS['HS_ORDERS'][$id])) : false;
                }
            };
        }

        if ($name === 'class.history') {
            // Jak hikashopHistoryClass::addRecord: pola z $order->history + order_id
            return new class () {
                public function addRecord($order)
                {
                    $h = (array) ($order->history ?? []);
                    \Joomla\CMS\Factory::$db->insertObject('#__hikashop_history', (object) [
                        'history_order_id' => (int) $order->order_id,
                        'history_created'  => time(),
                        'history_type'     => $h['history_type'] ?? '',
                        'history_data'     => $h['history_data'] ?? '',
                        'history_notified' => (int) ($h['history_notified'] ?? 0),
                    ]);

                    return true;
                }
            };
        }

        return null;
    }

    // Dziennik HikaShopa (6.8.1+: hikashop_writeToLog → hikashop_log → tabela #__hikashop_log)
    function hikashop_writeToLog($data = null, $name = '', $context = [])
    {
        $GLOBALS['HS_LOG'][] = ['message' => (string) $data, 'source' => $name, 'context' => $context];
    }

    function hikashop_config()
    {
        return new class () {
            public function get($key, $default = null)
            {
                return $GLOBALS['HS_CONFIG'][$key] ?? $default;
            }
        };
    }

    // --- Baza: minimalny sterownik Joomli na SQLite --------------------------------------
    final class FakeQuery
    {
        private string $type = '';
        private string $table = '';
        private array $cols = [];
        private array $sets = [];
        private array $wheres = [];

        public function select($cols)
        {
            $this->type = 'SELECT';
            $this->cols = array_merge($this->cols, (array) $cols);

            return $this;
        }

        public function from($table)
        {
            $this->table = $table;

            return $this;
        }

        public function update($table)
        {
            $this->type  = 'UPDATE';
            $this->table = $table;

            return $this;
        }

        public function delete($table = null)
        {
            $this->type = 'DELETE';

            if ($table) {
                $this->table = $table;
            }

            return $this;
        }

        public function set($set)
        {
            $this->sets[] = $set;

            return $this;
        }

        public function where($where)
        {
            $this->wheres = array_merge($this->wheres, (array) $where);

            return $this;
        }

        public function __toString(): string
        {
            $where = $this->wheres ? ' WHERE ' . implode(' AND ', $this->wheres) : '';

            return match ($this->type) {
                'SELECT' => 'SELECT ' . implode(', ', $this->cols) . ' FROM ' . $this->table . $where,
                'UPDATE' => 'UPDATE ' . $this->table . ' SET ' . implode(', ', $this->sets) . $where,
                'DELETE' => 'DELETE FROM ' . $this->table . $where,
            };
        }
    }

    final class FakeDb
    {
        public PDO $pdo;
        private string $sql = '';
        private int $affected = 0;

        public function __construct()
        {
            $this->pdo = new PDO('sqlite::memory:');
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec('CREATE TABLE jos_hikashop_history (history_id INTEGER PRIMARY KEY AUTOINCREMENT,'
                . ' history_order_id INT, history_created INT, history_type TEXT, history_notified INT, history_data TEXT,'
                . ' history_user_id INT, history_ip TEXT, history_new_status TEXT, history_reason TEXT,'
                . ' history_payment_id TEXT, history_payment_method TEXT, history_amount REAL)');
        }

        private function prep(string $sql): string
        {
            $sql = str_replace('#__', 'jos_', $sql);

            return preg_replace('/\)\s*ENGINE=.*$/s', ')', $sql);
        }

        public function getQuery($new = false)
        {
            return new FakeQuery();
        }

        public function quoteName($name)
        {
            return is_array($name) ? array_map(fn ($n) => $this->quoteName($n), $name) : '`' . $name . '`';
        }

        public function quote($value)
        {
            return $this->pdo->quote((string) $value);
        }

        public function setQuery($query)
        {
            $this->sql = $this->prep((string) $query);

            return $this;
        }

        public function execute()
        {
            $stmt = $this->pdo->prepare($this->sql);
            $stmt->execute();
            $this->affected = $stmt->rowCount();

            return true;
        }

        public function loadResult()
        {
            $row = $this->pdo->query($this->sql)->fetch(PDO::FETCH_NUM);

            return $row ? $row[0] : null;
        }

        public function loadObject()
        {
            $row = $this->pdo->query($this->sql)->fetch(PDO::FETCH_OBJ);

            return $row ?: null;
        }

        public function insertObject($table, $object)
        {
            $vars = get_object_vars($object);
            $cols = implode(',', array_map(fn ($c) => '`' . $c . '`', array_keys($vars)));
            $ph   = implode(',', array_fill(0, count($vars), '?'));
            $this->pdo->prepare('INSERT INTO ' . $this->prep($table) . " ($cols) VALUES ($ph)")->execute(array_values($vars));

            return true;
        }

        public function getAffectedRows()
        {
            return $this->affected;
        }
    }

    // --- Aplikacja Joomli (tylko panel: przycisk „Wystaw ponownie") ----------------------
    final class RedirectException extends Exception
    {
    }

    final class FakeUser
    {
        public function __construct(public bool $guest = false, public string $username = 'admin', public bool $canManage = true)
        {
        }

        public function authorise($action, $asset)
        {
            return $this->canManage;
        }
    }

    final class FakeApp
    {
        public string $client = 'administrator';
        public array $messages = [];
        public array $input = [];
        public ?FakeUser $user;

        public function __construct()
        {
            $this->user = new FakeUser();
        }

        public function isClient($client)
        {
            return $this->client === $client;
        }

        public function getInput()
        {
            return new class ($this->input) {
                public function __construct(private array $in)
                {
                }

                public function getInt($key, $default = 0)
                {
                    return (int) ($this->in[$key] ?? $default);
                }
            };
        }

        public function enqueueMessage($msg, $type = 'message')
        {
            $this->messages[] = [$type, $msg];
        }

        public function redirect($url)
        {
            throw new RedirectException($url);
        }

        public function getIdentity()
        {
            return $this->user;
        }
    }

    require __DIR__ . "/FakeFakturownia.php";
}

// --- Atrapy klas Joomli (tylko to, czego używa wtyczka) -----------------------------------
namespace Joomla\Event {
    interface SubscriberInterface
    {
        public static function getSubscribedEvents(): array;
    }
}

namespace Joomla\CMS\Plugin {
    abstract class CMSPlugin
    {
        public $params;

        public function __construct(array $params = [])
        {
            $this->params = new \TestParams($params);
        }
    }
}

namespace Joomla\CMS {
    class Factory
    {
        public static $db;
        public static $app;

        public static function getContainer()
        {
            return new class () {
                public function get($name)
                {
                    return Factory::$db;
                }
            };
        }

        public static function getDate($time = 'now')
        {
            return new class (strtotime($time)) {
                public function __construct(private int $ts)
                {
                }

                public function toSql()
                {
                    return gmdate('Y-m-d H:i:s', $this->ts);
                }
            };
        }

        public static function getApplication()
        {
            return self::$app;
        }
    }
}

namespace Joomla\CMS\Http {
    class HttpFactory
    {
        public static $http;

        public static function getHttp()
        {
            return self::$http;
        }
    }
}

namespace Joomla\CMS\Language {
    class Text
    {
        public static function _($key)
        {
            return $key;
        }

        public static function sprintf($key, ...$args)
        {
            return $key . '|' . implode('|', $args);
        }
    }
}

namespace Joomla\CMS\Session {
    class Session
    {
        public static bool $tokenOk = true;

        public static function getFormToken()
        {
            return 'csrftoken';
        }

        public static function checkToken($method = 'post')
        {
            return self::$tokenOk;
        }
    }
}

namespace Joomla\CMS\Installer {
    // Tylko na potrzeby testu script.php (migracja starego pliku logu)
    interface InstallerScriptInterface
    {
    }

    class InstallerAdapter
    {
    }
}

namespace Joomla\CMS\Uri {
    class Uri
    {
        public static function base()
        {
            return 'https://sklep.test/administrator/';
        }
    }
}

namespace {
    require dirname(__DIR__) . '/src/Extension/Fakturownia.php';
}
