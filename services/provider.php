<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Hikashop.Fakturownia
 *
 * @copyright   (C) 2025 web-service. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Pablop76\Plugin\Hikashop\Fakturownia\Extension\Fakturownia;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     */
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $config = (array) PluginHelper::getPlugin('hikashop', 'fakturownia');

                // Od Joomli 5.3 konstruktor przyjmuje samą konfigurację (dyspozytor jako
                // 1. argument zniknie w 7.0); 5.0–5.2 wymagają dyspozytora.
                $plugin = version_compare(JVERSION, '5.3', '>=')
                    ? new Fakturownia($config)
                    : new Fakturownia($container->get(DispatcherInterface::class), $config);
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
