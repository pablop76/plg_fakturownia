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
        $this->createInvoiceRequestField();
        
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
        $this->createInvoiceRequestField();
        
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
     * Tworzy pole invoice_request w HikaShop jeśli nie istnieje
     *
     * @return  void
     */
    private function createInvoiceRequestField(): void
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');

            // Sprawdź czy pole już istnieje
            $query = $db->getQuery(true)
                ->select('field_id')
                ->from('#__hikashop_field')
                ->where('field_namekey = ' . $db->quote('invoice_request'));
            $db->setQuery($query);
            $existingFieldId = $db->loadResult();

            // Pobierz najwyższy ordering dla pól adresowych
            $query = $db->getQuery(true)
                ->select('MAX(field_ordering)')
                ->from('#__hikashop_field')
                ->where('field_table = ' . $db->quote('address'));
            $db->setQuery($query);
            $maxOrdering = (int) $db->loadResult();

            // 1. Najpierw dodaj kolumnę do tabeli hikashop_address
            $columnExists = $this->columnExists($db, '#__hikashop_address', 'invoice_request');
            
            if (!$columnExists) {
                $db->setQuery('ALTER TABLE `#__hikashop_address` ADD COLUMN `invoice_request` TINYINT(1) NOT NULL DEFAULT 0');
                $db->execute();
            }

            // 2. Teraz utwórz lub zaktualizuj wpis w hikashop_field
            $field = new \stdClass();
            $field->field_table           = 'address';
            $field->field_namekey         = 'invoice_request';
            $field->field_realname        = 'invoice_request';
            $field->field_name            = 'Faktura VAT';
            $field->field_description     = '';
            $field->field_type            = 'checkbox';
            $field->field_value           = 'false::Chcę otrzymać fakturę VAT::0';
            $field->field_published       = 1;
            $field->field_ordering        = $maxOrdering + 1;
            $field->field_options         = '';
            $field->field_default         = '0';
            $field->field_required        = 0;
            $field->field_access          = 'all';
            $field->field_display         = 'billing';
            $field->field_categories      = 'all';
            $field->field_with_sub_categories = 0;
            $field->field_backend         = 1;
            $field->field_backend_listing = 0;
            $field->field_frontcomp       = 1;
            $field->field_core            = 0;
            $field->field_products        = 'all';
            $field->field_url             = '';
            $field->field_class           = '';

            if ($existingFieldId) {
                // Aktualizuj istniejące pole
                $field->field_id = $existingFieldId;
                $db->updateObject('#__hikashop_field', $field, 'field_id');
            } else {
                // Utwórz nowe pole
                $db->insertObject('#__hikashop_field', $field, 'field_id');
            }

            // 3. Dodaj tłumaczenie do pliku językowego HikaShop
            $this->addHikashopLanguageKey('INVOICE_REQUEST', 'Faktura VAT', 'VAT Invoice');

            Factory::getApplication()->enqueueMessage(
                Text::_('PLG_HIKASHOP_FAKTUROWNIA_FIELD_CREATED'),
                'success'
            );

        } catch (\Exception $e) {
            Factory::getApplication()->enqueueMessage(
                Text::sprintf('PLG_HIKASHOP_FAKTUROWNIA_FIELD_ERROR', $e->getMessage()),
                'warning'
            );
        }
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
