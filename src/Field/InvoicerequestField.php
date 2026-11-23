<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Hikashop.Fakturownia
 *
 * @copyright   (C) 2025 web-service. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Pablop76\Plugin\Hikashop\Fakturownia\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

/**
 * Stan pola HikaShopa „Chcę otrzymać fakturę VAT” (invoice_request) w ustawieniach wtyczki
 * i przycisk, który tworzy je funkcją HikaShopa (onAjaxFakturownia, action=create_field).
 * Pole ma znaczenie tylko w trybie „Auto” (showon w manifeście).
 *
 * @since  2.3.3
 */
class InvoicerequestField extends FormField
{
    protected $type = 'Invoicerequest';

    protected function getInput()
    {
        try {
            $db    = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select($db->quoteName('field_published'))
                ->from($db->quoteName('#__hikashop_field'))
                ->where($db->quoteName('field_namekey') . ' = ' . $db->quote('invoice_request'))
                ->where($db->quoteName('field_table') . ' = ' . $db->quote('address'));
            $published = $db->setQuery($query)->loadResult();
        } catch (\Throwable $e) {
            return '<div class="alert alert-warning">' . Text::_('PLG_HIKASHOP_FAKTUROWNIA_FIELD_NO_HIKASHOP') . '</div>';
        }

        if ($published !== null) {
            $key = (int) $published ? 'PLG_HIKASHOP_FAKTUROWNIA_FIELD_EXISTS' : 'PLG_HIKASHOP_FAKTUROWNIA_FIELD_UNPUBLISHED';

            return '<div class="alert alert-' . ((int) $published ? 'success' : 'warning') . '">' . Text::_($key) . '</div>';
        }

        $url = Uri::base() . 'index.php?option=com_ajax&group=hikashop&plugin=fakturownia&format=raw&action=create_field'
            . '&extension_id=' . (int) $this->form->getValue('extension_id') . '&' . Session::getFormToken() . '=1';

        return '<div class="alert alert-info">' . Text::_('PLG_HIKASHOP_FAKTUROWNIA_FIELD_MISSING') . '</div>'
            . '<a class="btn btn-primary" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
            . Text::_('PLG_HIKASHOP_FAKTUROWNIA_FIELD_CREATE') . '</a>';
    }
}
