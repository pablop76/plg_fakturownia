<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Hikashop.Fakturownia
 *
 * @copyright   (C) 2025 web-service. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;

return new class () implements InstallerScriptInterface {

    /**
     * Minimalna wymagana wersja Joomla
     *
     * @var string
     */
    private string $minimumJoomla = '5.0.0';

    /**
     * Minimalna wymagana wersja PHP
     *
     * @var string
     */
    private string $minimumPhp = '8.1.0';

    /**
     * Wykonywane przy instalacji
     *
     * @param   InstallerAdapter  $adapter  Adapter instalatora
     *
     * @return  bool
     */
    public function install(InstallerAdapter $adapter): bool
    {
        $this->setupInvoiceRequestField();

        Factory::getApplication()->enqueueMessage(
            Text::_('PLG_HIKASHOP_FAKTUROWNIA_INSTALL_SUCCESS'),
            'success'
        );

        return true;
    }

    /**
     * Wykonywane przy aktualizacji
     *
     * @param   InstallerAdapter  $adapter  Adapter instalatora
     *
     * @return  bool
     */
    public function update(InstallerAdapter $adapter): bool
    {
        $this->setupInvoiceRequestField();

        Factory::getApplication()->enqueueMessage(
            Text::_('PLG_HIKASHOP_FAKTUROWNIA_UPDATE_SUCCESS'),
            'success'
        );

        return true;
    }

    /**
     * Wykonywane przy odinstalowaniu
     *
     * @param   InstallerAdapter  $adapter  Adapter instalatora
     *
     * @return  bool
     */
    public function uninstall(InstallerAdapter $adapter): bool
    {
        // Nie usuwamy pola przy odinstalowaniu - dane klientów są ważne
        Factory::getApplication()->enqueueMessage(
            Text::_('PLG_HIKASHOP_FAKTUROWNIA_UNINSTALL_SUCCESS'),
            'info'
        );

        return true;
    }

    /**
     * Wykonywane przed instalacją/aktualizacją
     *
     * @param   string            $type     Typ operacji (install, update, uninstall)
     * @param   InstallerAdapter  $adapter  Adapter instalatora
     *
     * @return  bool
     */
    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        // Sprawdź wersję PHP
        if (version_compare(PHP_VERSION, $this->minimumPhp, '<')) {
            Factory::getApplication()->enqueueMessage(
                sprintf(Text::_('JLIB_INSTALLER_MINIMUM_PHP'), $this->minimumPhp),
                'error'
            );

            return false;
        }

        // Sprawdź wersję Joomla
        if (version_compare(JVERSION, $this->minimumJoomla, '<')) {
            Factory::getApplication()->enqueueMessage(
                sprintf(Text::_('JLIB_INSTALLER_MINIMUM_JOOMLA'), $this->minimumJoomla),
                'error'
            );

            return false;
        }

        // Sprawdź czy HikaShop jest zainstalowany
        if ($type !== 'uninstall' && !$this->isHikashopInstalled()) {
            Factory::getApplication()->enqueueMessage(
                Text::_('PLG_HIKASHOP_FAKTUROWNIA_HIKASHOP_REQUIRED'),
                'error'
            );

            return false;
        }

        return true;
    }

    /**
     * Wykonywane po instalacji/aktualizacji
     *
     * @param   string            $type     Typ operacji (install, update, uninstall)
     * @param   InstallerAdapter  $adapter  Adapter instalatora
     *
     * @return  bool
     */
    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        return true;
    }

    /**
     * Sprawdza czy HikaShop jest zainstalowany
     *
     * @return  bool
     */
    private function isHikashopInstalled(): bool
    {
        $helperPath = JPATH_ADMINISTRATOR . '/components/com_hikashop/helpers/helper.php';

        return file_exists($helperPath);
    }

    /**
     * Sprząta nieudaną próbę pola zamówienia i informuje administratora, jak utworzyć
     * pole „Chcę otrzymać fakturę VAT".
     *
     * Pole tworzy się RĘCZNIE w panelu HikaShop (Display → Custom fields). Programowe
     * tworzenie definicji pola zależy od wewnętrznej struktury tabel HikaShopa (różni się
     * między wersjami — np. brak kolumny field_name) i okazało się zawodne. HikaShop
     * tworzy wtedy poprawne, edytowalne pole, a wtyczka odczytuje je automatycznie. Pola
     * ADRESU celowo nie ruszamy, aby aktualizacja nie skasowała pola utworzonego przez admina.
     *
     * @return  void
     */
    private function setupInvoiceRequestField(): void
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');

            // Sprzątnij nieudaną próbę pola ZAMÓWIENIA (Starter go nie pokazuje w checkoucie).
            // Pola ADRESU celowo NIE ruszamy.
            $query = $db->getQuery(true)
                ->delete($db->quoteName('#__hikashop_field'))
                ->where($db->quoteName('field_namekey') . ' = ' . $db->quote('invoice_request'))
                ->where($db->quoteName('field_table') . ' = ' . $db->quote('order'));
            $db->setQuery($query);
            $db->execute();
        } catch (\Throwable $e) {
            // Niekrytyczne — pole i tak tworzy się ręcznie w panelu
        }

        // Instrukcja dla administratora: jak utworzyć pole i skonfigurować checkout.
        Factory::getApplication()->enqueueMessage(
            Text::_('PLG_HIKASHOP_FAKTUROWNIA_FIELD_CREATED'),
            'notice'
        );
    }

    /**
     * Sprawdza czy kolumna istnieje w tabeli
     *
     * @param   object  $db          Database driver
     * @param   string  $table       Nazwa tabeli
     * @param   string  $column      Nazwa kolumny
     *
     * @return  bool
     */
    private function columnExists(object $db, string $table, string $column): bool
    {
        $table = str_replace('#__', $db->getPrefix(), $table);
        
        $db->setQuery("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        $result = $db->loadResult();
        
        return !empty($result);
    }

    /**
     * Dodaje tłumaczenie do pliku nadpisań językowych Joomla
     *
     * @param   string  $key    Klucz językowy
     * @param   string  $value  Tłumaczenie
     *
     * @return  void
     */
    private function addLanguageOverride(string $key, string $value): void
    {
        $languages = ['pl-PL', 'en-GB'];
        $values = [
            'pl-PL' => $value,
            'en-GB' => 'VAT Invoice'
        ];

        foreach ($languages as $lang) {
            $overridePath = JPATH_ADMINISTRATOR . '/language/overrides/' . $lang . '.override.ini';
            
            // Utwórz folder jeśli nie istnieje
            $dir = dirname($overridePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Odczytaj istniejący plik
            $content = '';
            if (file_exists($overridePath)) {
                $content = file_get_contents($overridePath);
            }

            // Sprawdź czy klucz już istnieje
            if (strpos($content, $key . '=') === false) {
                // Dodaj nowe tłumaczenie
                $content .= "\n" . $key . '="' . $values[$lang] . '"';
                file_put_contents($overridePath, trim($content) . "\n");
            }
        }
    }

    /**
     * Dodaje tłumaczenie do pliku językowego HikaShop
     *
     * @param   string  $key      Klucz językowy
     * @param   string  $valuePl  Tłumaczenie polskie
     * @param   string  $valueEn  Tłumaczenie angielskie
     *
     * @return  void
     */
    private function addHikashopLanguageKey(string $key, string $valuePl, string $valueEn): void
    {
        $files = [
            'pl-PL' => [
                JPATH_ROOT . '/language/pl-PL/pl-PL.com_hikashop.ini',
                JPATH_ADMINISTRATOR . '/language/overrides/pl-PL.override.ini',
            ],
            'en-GB' => [
                JPATH_ROOT . '/language/en-GB/en-GB.com_hikashop.ini',
                JPATH_ADMINISTRATOR . '/language/overrides/en-GB.override.ini',
            ],
        ];

        $values = [
            'pl-PL' => $valuePl,
            'en-GB' => $valueEn,
        ];

        foreach ($files as $lang => $paths) {
            foreach ($paths as $path) {
                if (!file_exists($path)) {
                    // Jeśli to override, utwórz folder
                    if (strpos($path, 'overrides') !== false) {
                        $dir = dirname($path);
                        if (!is_dir($dir)) {
                            mkdir($dir, 0755, true);
                        }
                    } else {
                        continue;
                    }
                }

                $content = file_exists($path) ? file_get_contents($path) : '';

                // Sprawdź czy klucz już istnieje
                if (strpos($content, $key . '=') === false) {
                    $content .= "\n" . $key . '="' . $values[$lang] . '"';
                    file_put_contents($path, trim($content) . "\n");
                }

                // Dla głównego pliku HikaShop wystarczy jeden wpis
                if (strpos($path, 'com_hikashop.ini') !== false) {
                    break;
                }
            }
        }
    }
};
