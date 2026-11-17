<?php

/**
 * @package     Events Booking - Kunena Subscribe
 * @copyright   Copyright (C) 2026 Agence AGERIX
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Subscribes Events Booking registrants to the Kunena categories selected on
 * the event, so that they receive Kunena's e-mail notifications without having
 * to subscribe themselves. Access to the categories is NOT handled here: Kunena
 * re-checks access before each notification, so pair this plugin with a group
 * assignment (e.g. the "Events Booking - Joomla Groups" plugin).
 */

namespace Agerix\Plugin\EventBooking\KunenaSubscribe\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

final class KunenaSubscribe extends CMSPlugin implements SubscriberInterface
{
    use \RADEventResult;
    use DatabaseAwareTrait;

    /** Key used in the event params. */
    private const PARAM_KEY = 'kunena_category_ids';

    public static function getSubscribedEvents(): array
    {
        return [
            'onEditEvent'             => 'onEditEvent',
            'onAfterSaveEvent'        => 'onAfterSaveEvent',
            'onAfterStoreRegistrant'  => 'onAfterStoreRegistrant',
            'onAfterPaymentSuccess'   => 'onAfterPaymentSuccess',
            'onRegistrationCancelled' => 'onRegistrationCancelled',
        ];
    }

    /**
     * Adds a "Kunena" tab to the event edit form.
     */
    public function onEditEvent(Event $eventObj): void
    {
        [$row] = array_values($eventObj->getArguments());

        if (!$this->canRun()) {
            return;
        }

        $this->loadLanguage();

        $params      = new Registry($row->params);
        $selectedIds = $this->toIds($params->get(self::PARAM_KEY, ''));
        $categories  = $this->getKunenaCategories();
        $isNew       = empty($row->id);

        ob_start();
        require PluginHelper::getLayoutPath($this->_type, $this->_name, 'form');

        $this->addResult($eventObj, [
            'title' => Text::_('PLG_EVENTBOOKING_KUNENASUBSCRIBE_TAB'),
            'form'  => ob_get_clean(),
        ]);
    }

    /**
     * Stores the selected categories in the event params and, if asked,
     * subscribes the registrants already confirmed for this event.
     */
    public function onAfterSaveEvent(Event $eventObj): void
    {
        [$row, $data] = array_values($eventObj->getArguments());

        if (!$this->canRun() || !array_key_exists('kunena_subscribe_form', $data)) {
            return;
        }

        $ids    = $this->toIds($data[self::PARAM_KEY] ?? []);
        $params = new Registry($row->params);
        $params->set(self::PARAM_KEY, implode(',', $ids));
        $row->params = $params->toString();
        $row->store();

        if ($ids && !empty($data['kunena_subscribe_existing'])) {
            $count = $this->subscribeExistingRegistrants((int) $row->id, $ids);
            $this->loadLanguage();
            $this->getApplication()->enqueueMessage(
                Text::sprintf('PLG_EVENTBOOKING_KUNENASUBSCRIBE_EXISTING_DONE', $count)
            );
        }
    }

    /**
     * Offline payment: optionally subscribe as soon as the registration is stored.
     */
    public function onAfterStoreRegistrant(Event $eventObj): void
    {
        [$row] = array_values($eventObj->getArguments());

        if ($row->user_id
            && $this->params->get('subscribe_offline_pending', 0)
            && str_contains((string) $row->payment_method, 'os_offline')) {
            $this->subscribeRegistrant($row);
        }
    }

    /**
     * Payment completed (or free registration confirmed): subscribe.
     */
    public function onAfterPaymentSuccess(Event $eventObj): void
    {
        [$row] = array_values($eventObj->getArguments());

        if (!$row->user_id) {
            return;
        }

        if (str_contains((string) $row->payment_method, 'os_offline')
            && $this->params->get('subscribe_offline_pending', 0)) {
            // Already handled by onAfterStoreRegistrant.
            return;
        }

        $this->subscribeRegistrant($row);
    }

    /**
     * Registration cancelled: unsubscribe from the categories that no other
     * active registration of the same user still requires.
     */
    public function onRegistrationCancelled(Event $eventObj): void
    {
        [$row] = array_values($eventObj->getArguments());

        if (!$row->user_id || !$this->params->get('unsubscribe_when_cancelled', 1) || !$this->kunenaAvailable()) {
            return;
        }

        $cancelled = $this->getEventCategoryIds((int) $row->event_id);

        if (!$cancelled) {
            return;
        }

        $db    = $this->getDatabase();
        $uid   = (int) $row->user_id;
        $eid   = (int) $row->event_id;
        $query = $db->getQuery(true)
            ->select('DISTINCT ' . $db->quoteName('e.params'))
            ->from($db->quoteName('#__eb_registrants', 'r'))
            ->join('INNER', $db->quoteName('#__eb_events', 'e'), 'e.id = r.event_id')
            ->where('r.user_id = :uid')
            ->where('r.published = 1')
            ->where('r.event_id <> :eid')
            ->bind(':uid', $uid, ParameterType::INTEGER)
            ->bind(':eid', $eid, ParameterType::INTEGER);

        $retain = [];

        foreach ($db->setQuery($query)->loadColumn() as $otherParams) {
            $retain = array_merge($retain, $this->toIds((new Registry($otherParams))->get(self::PARAM_KEY, '')));
        }

        $toRemove = array_values(array_diff($cancelled, $retain));

        if (!$toRemove) {
            return;
        }

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__kunena_user_categories'))
            ->set($db->quoteName('subscribed') . ' = 0')
            ->where($db->quoteName('user_id') . ' = :uid')
            ->whereIn($db->quoteName('category_id'), $toRemove)
            ->bind(':uid', $uid, ParameterType::INTEGER);
        $db->setQuery($query)->execute();
    }

    private function subscribeRegistrant($row): void
    {
        $eventIds = [(int) $row->event_id];

        // Shopping cart: one registration record may carry several events.
        if (class_exists('EventbookingHelper') && \EventbookingHelper::getConfig()->multiple_booking) {
            $db    = $this->getDatabase();
            $rid   = (int) $row->id;
            $query = $db->getQuery(true)
                ->select($db->quoteName('event_id'))
                ->from($db->quoteName('#__eb_registrants'))
                ->where($db->quoteName('cart_id') . ' = :rid')
                ->bind(':rid', $rid, ParameterType::INTEGER);
            $eventIds = array_unique(array_merge($eventIds, array_map('intval', $db->setQuery($query)->loadColumn())));
        }

        $catIds = [];

        foreach ($eventIds as $eventId) {
            $catIds = array_merge($catIds, $this->getEventCategoryIds($eventId));
        }

        $this->subscribe((int) $row->user_id, array_unique($catIds));
    }

    /**
     * Subscribes every confirmed registrant (with a user account) of an event.
     *
     * @return int Number of users processed.
     */
    private function subscribeExistingRegistrants(int $eventId, array $catIds): int
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('DISTINCT ' . $db->quoteName('user_id'))
            ->from($db->quoteName('#__eb_registrants'))
            ->where($db->quoteName('event_id') . ' = :eid')
            ->where($db->quoteName('user_id') . ' > 0')
            ->where($db->quoteName('published') . ' = 1')
            ->bind(':eid', $eventId, ParameterType::INTEGER);

        $userIds = array_map('intval', $db->setQuery($query)->loadColumn());

        foreach ($userIds as $userId) {
            $this->subscribe($userId, $catIds);
        }

        return \count($userIds);
    }

    /**
     * Upserts the subscription rows; never touches the read-tracking columns.
     */
    private function subscribe(int $userId, array $catIds): void
    {
        if (!$userId || !$catIds || !$this->kunenaAvailable()) {
            return;
        }

        $db    = $this->getDatabase();
        $table = $db->quoteName('#__kunena_user_categories');

        // Kunena only notifies subscribers that have a #__kunena_users row
        // (KunenaAccess::loadSubscribers filters on ku.banned). That row is
        // normally created on the first forum visit: create it now, otherwise
        // a registrant who never opened the forum would receive nothing.
        $db->setQuery(
            'INSERT IGNORE INTO ' . $db->quoteName('#__kunena_users') . ' (' . $db->quoteName('userid') . ')'
            . ' VALUES (' . (int) $userId . ')'
        )->execute();

        foreach ($catIds as $catId) {
            $sql = 'INSERT INTO ' . $table . ' (user_id, category_id, role, allreadtime, subscribed, params)'
                . ' VALUES (' . (int) $userId . ', ' . (int) $catId . ', 0, 0, 1, ' . $db->quote('') . ')'
                . ' ON DUPLICATE KEY UPDATE subscribed = 1';
            $db->setQuery($sql)->execute();
        }
    }

    private function getEventCategoryIds(int $eventId): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__eb_events'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $eventId, ParameterType::INTEGER);

        return $this->toIds((new Registry((string) $db->setQuery($query)->loadResult()))->get(self::PARAM_KEY, ''));
    }

    private function getKunenaCategories(): array
    {
        if (!$this->kunenaAvailable()) {
            return [];
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select([$db->quoteName('id'), $db->quoteName('parentid'), $db->quoteName('name')])
            ->from($db->quoteName('#__kunena_categories'))
            ->where($db->quoteName('published') . ' IN (0, 1)')
            ->order([$db->quoteName('parentid'), $db->quoteName('ordering')]);

        $rows     = $db->setQuery($query)->loadObjectList('id');
        $children = [];

        foreach ($rows as $r) {
            $children[(int) $r->parentid][] = $r;
        }

        // Depth-first walk so that sub-categories appear under their parent.
        $out  = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$out, $children): void {
            foreach ($children[$parent] ?? [] as $r) {
                $out[] = (object) ['id' => (int) $r->id, 'name' => str_repeat('- ', $depth) . $r->name];
                $walk((int) $r->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }

    private function kunenaAvailable(): bool
    {
        static $ok = null;

        return $ok ??= ComponentHelper::isEnabled('com_kunena');
    }

    private function toIds($value): array
    {
        if (\is_string($value)) {
            $value = explode(',', $value);
        }

        return array_values(array_unique(array_filter(array_map('intval', (array) $value))));
    }

    private function canRun(): bool
    {
        return !$this->getApplication()->isClient('site') || $this->params->get('show_on_frontend', 0);
    }
}
