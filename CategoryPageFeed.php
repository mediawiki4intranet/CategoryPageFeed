<?php

/* CategoryPageFeed extension
 * Copyright (c) 2011, Vitaliy Filippov <vitalif[d.o.g]mail.ru>
 * License: GPLv3.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

# This extension adds a special page Special:CategoryPageFeed
# which allows to list pages recently added to a specific category

$wgExtensionCredits['specialpage'][] = array(
    'name'           => 'CategoryPageFeed',
    'version'        => '2011-10-26',
    'author'         => 'Vitaliy Filippov',
    'url'            => 'http://wiki.4intra.net/CategoryPageFeed',
    'description'    => 'Allows to list pages recently added to a specific category (Special:CategoryPageFeed)',
);

$dir = dirname(__FILE__);
$wgAutoloadClasses += array(
    'SpecialCategoryPageFeed' => "$dir/CategoryPageFeed.class.php",
    'CategoryPageFeedPager'   => "$dir/CategoryPageFeed.class.php",
);
$wgSpecialPages['CategoryPageFeed'] = 'SpecialCategoryPageFeed';
$wgExtensionMessagesFiles['CategoryPageFeed'] = "$dir/CategoryPageFeed.i18n.php";
