<?php

/**
 * @package     Events Booking - Kunena Subscribe
 * @copyright   Copyright (C) 2026 Agence AGERIX
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * @var array $categories  list of {id, name}
 * @var array $selectedIds
 * @var bool  $isNew
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

?>
<input type="hidden" name="kunena_subscribe_form" value="1">
<?php if (!$categories) : ?>
    <div class="alert alert-info"><?php echo Text::_('PLG_EVENTBOOKING_KUNENASUBSCRIBE_NO_CATEGORY'); ?></div>
<?php else : ?>
<div class="control-group">
    <div class="control-label">
        <?php echo EventbookingHelperHtml::getFieldLabel(
            'kunena_category_ids',
            Text::_('PLG_EVENTBOOKING_KUNENASUBSCRIBE_CATEGORIES_LABEL'),
            Text::_('PLG_EVENTBOOKING_KUNENASUBSCRIBE_CATEGORIES_DESC')
        ); ?>
    </div>
    <div class="controls">
        <?php echo EventbookingHelperHtml::getChoicesJsSelect(
            HTMLHelper::_(
                'select.genericlist',
                $categories,
                'kunena_category_ids[]',
                ' multiple size="6" ',
                'id',
                'name',
                $selectedIds,
                'kunena_category_ids'
            )
        ); ?>
    </div>
</div>
<?php if (!$isNew) : ?>
<div class="control-group">
    <div class="control-label">
        <?php echo EventbookingHelperHtml::getFieldLabel(
            'kunena_subscribe_existing',
            Text::_('PLG_EVENTBOOKING_KUNENASUBSCRIBE_EXISTING_LABEL'),
            Text::_('PLG_EVENTBOOKING_KUNENASUBSCRIBE_EXISTING_DESC')
        ); ?>
    </div>
    <div class="controls">
        <input type="checkbox" name="kunena_subscribe_existing" id="kunena_subscribe_existing" value="1">
    </div>
</div>
<?php endif; ?>
<?php endif; ?>
