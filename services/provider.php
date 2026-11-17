<?php

/**
 * @package     Events Booking - Kunena Subscribe
 * @copyright   Copyright (C) 2026 Agence AGERIX
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Agerix\Plugin\EventBooking\KunenaSubscribe\Extension\KunenaSubscribe;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new KunenaSubscribe(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('eventbooking', 'kunenasubscribe')
                );

                $plugin->setApplication(Factory::getApplication());
                $plugin->setDatabase($container->get(DatabaseInterface::class));

                return $plugin;
            }
        );
    }
};
