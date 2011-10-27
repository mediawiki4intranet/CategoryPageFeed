<?php

/* CategoryPageFeed extension class.
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

class SpecialCategoryPageFeed extends SpecialPage
{
    var $opts, $skin, $pager, $parserOptions;
    var $showNavigation = false;

    public function __construct()
    {
        parent::__construct('CategoryPageFeed');
        $this->includable(true);
        wfLoadExtensionMessages('CategoryPageFeed');
    }

    // Parse options
    protected function setup($par)
    {
        global $wgRequest, $wgUser;

        $this->opts = $opts = new FormOptions();
        $opts->add('limit', (int)$wgUser->getOption('rclimit'));
        $opts->add('offset', '');
        $opts->add('category', '');
        $opts->add('feed', '');

        // Set values
        $opts->fetchValuesFromRequest($wgRequest);
        if ($par)
            $this->parseParams($par);

        $this->pager = new CategoryPageFeedPager($this, $this->opts);
        $this->pager->mLimit = $this->opts->getValue('limit');
        $this->pager->mOffset = $this->opts->getValue('offset');
        $this->pager->getQueryInfo();

        // Validate
        $opts->validateIntBounds('limit', 0, 5000);

        // Store some objects
        $this->skin = $wgUser->getSkin();
    }

    // Parse parameters passed as special page subpage
    // Special:CategoryPageFeed/0/50/Category (/offset/limit/categoryname)
    protected function parseParams($par)
    {
        if (preg_match('#^(\d+)/#s', $par, $m))
        {
            $this->opts->setValue('offset', $m[1]);
            $par = substr($par, strlen($m[0]));
        }
        if (preg_match('#^(\d+)/#s', $par, $m))
        {
            $this->opts->setValue('limit', $m[1]);
            $par = substr($par, strlen($m[0]));
        }
        $this->opts->setValue('category', $par);
    }

    public function execute($par)
    {
        global $wgLang, $wgOut;

        $this->setHeaders();
        $this->outputHeader();

        $this->showNavigation = !$this->including(); // Maybe changed in setup
        $this->setup($par);

        if (!$this->including())
            $this->form();

        if ($this->pager->getQueryInfo() && $this->pager->getNumRows())
        {
            if (!$this->including())
            {
                $this->setSyndicated();
                $feedType = $this->opts->getValue('feed');
                if ($feedType)
                    return $this->feed($feedType);
            }
            $navigation = '';
            if ($this->showNavigation)
                $navigation = $this->pager->getNavigationBar();
            $wgOut->addHTML($navigation . $this->pager->getBody() . $navigation);
        }
        else
            $wgOut->addWikiMsg('specialpage-empty');
        $wgOut->setPageTitle(wfMsg('categorypagefeed', $this->pager->mCategory ? $this->pager->mCategory->getText() : ''));
    }

    protected function form()
    {
        global $wgOut, $wgScript;

        // Consume values
        $this->opts->consumeValue('offset'); // don't carry offset, DWIW

        $category = $this->opts->consumeValue('category');

        // Store query values in hidden fields so that form submission doesn't lose them
        $hidden = array();
        foreach ($this->opts->getUnconsumedValues() as $key => $value)
            $hidden[] = Xml::hidden($key, $value);
        $hidden = implode("\n", $hidden);

        $fields[] = Xml::label(wfMsg('categorypagefeed-desc'), 'mw-np-category');
        $attr = array('id' => 'mw-np-category');
        if ($category !== "" && !$this->pager->mCategory)
            $attr['style'] = 'background-color: #ffe0e0';
        $fields[] = Xml::input('category', 30, $category, $attr);
        $fields[] = Xml::submitButton(wfMsg('allpagessubmit'));

        $form = implode('&nbsp;', $fields);

        $form = Xml::openElement('form', array('action' => $wgScript)) .
            Xml::hidden('title', $this->getTitle()->getPrefixedDBkey()) .
            "$form $hidden</form>";

        $wgOut->addHTML($form);
    }

    protected function setSyndicated()
    {
        global $wgOut;
        $wgOut->setSyndicated(true);
        $wgOut->setFeedAppendQuery(wfArrayToCGI($this->opts->getAllValues()));
    }

    /**
     * Format a row, providing the timestamp and link to the page.
     *
     * @param $skin Skin to use
     * @param $result Result row
     * @return string
     */
    public function formatRow($result)
    {
        global $wgLang, $wgContLang;

        $title = Title::newFromRow($result);
        // HaloACL/IntraACL support
        if (method_exists($title, 'userCanReadEx') && !$title->userCanReadEx())
            return '';

        $dm = $wgContLang->getDirMark();
        $date = htmlspecialchars($wgLang->date($result->cl_timestamp, true));
        $time = htmlspecialchars($wgLang->time($result->cl_timestamp, true));
        $plink = $this->skin->link($title);
        $colon = wfMsgForContent('colon-separator');

        return "<li>$dm$date $dm$time$colon $dm$plink</li>\n";
    }

    /**
     * Output a cached Atom/RSS feed with new page listing.
     * @param string $type
     */
    protected function feed($type)
    {
        global $wgFeed, $wgFeedClasses, $wgFeedLimit, $wgUser, $wgLang, $wgRequest;

        if (!$wgFeed)
        {
            global $wgOut;
            $wgOut->addWikiMsg('feed-unavailable');
            return;
        }

        if (!isset($wgFeedClasses[$type]))
        {
            global $wgOut;
            $wgOut->addWikiMsg('feed-invalid');
            return;
        }

        // Check modification time
        $limit = $this->opts->getValue('limit');
        $this->pager->mLimit = min($limit, $wgFeedLimit);
        $lastmod = $this->pager->lastModifiedTime();

        $userid = $wgUser->getId();
        $optionsHash = md5(serialize($this->opts->getAllValues()));
        $timekey = wfMemcKey('catpagesfeed', $userid, $optionsHash, 'timestamp');
        $key = wfMemcKey('catpagesfeed', $userid, $wgLang->getCode(), $optionsHash);

        // Check for ?action=purge
        FeedUtils::checkPurge($timekey, $key);

        $feed = new $wgFeedClasses[$type](
            $this->feedTitle(),
            wfMsgExt('tagline', 'parsemag'),
            $this->getTitle()->getFullUrl());

        // Check if the cached feed exists
        $cachedFeed = $this->loadFromCache($lastmod, $timekey, $key);
        if (is_string($cachedFeed))
        {
            wfDebug("CategoryPageFeed: Outputting cached feed\n");
            $feed->httpHeaders();
            echo $cachedFeed;
        }
        else
        {
            wfDebug("CategoryPageFeed: rendering new feed and caching it\n");
            ob_start();
            $this->generateFeed($this->pager, $feed);
            $cachedFeed = ob_get_contents();
            ob_end_flush();
            $this->saveToCache($cachedFeed, $timekey, $key);
        }
    }

    public function generateFeed($pager, $feed)
    {
        $feed->outHeader();
        if ($pager->getNumRows() > 0)
            while($row = $pager->mResult->fetchObject())
                $feed->outItem($this->feedItem($row));
        $feed->outFooter();
    }

    public function loadFromCache($lastmod, $timekey, $key)
    {
        global $wgFeedCacheTimeout, $messageMemc;
        $feedLastmod = $messageMemc->get($timekey);

        if(($wgFeedCacheTimeout > 0) && $feedLastmod) {
            /*
            * If the cached feed was rendered very recently, we may
            * go ahead and use it even if there have been edits made
            * since it was rendered. This keeps a swarm of requests
            * from being too bad on a super-frequently edited wiki.
            */

            $feedAge = time() - wfTimestamp(TS_UNIX, $feedLastmod);
            $feedLastmodUnix = wfTimestamp(TS_UNIX, $feedLastmod);
            $lastmodUnix = wfTimestamp(TS_UNIX, $lastmod);

            if($feedAge < $wgFeedCacheTimeout || $feedLastmodUnix > $lastmodUnix) {
                wfDebug("CategoryPageFeed: loading feed from cache ($key; $feedLastmod; $lastmod)...\n");
                return $messageMemc->get($key);
            } else {
                wfDebug("CategoryPageFeed: cached feed timestamp check failed ($feedLastmod; $lastmod)\n");
            }
        }
        return false;
    }

    public function saveToCache($feed, $timekey, $key)
    {
        global $messageMemc;
        $expire = 3600 * 24; # One day
        $messageMemc->set($key, $feed, $expire);
        $messageMemc->set($timekey, wfTimestamp(TS_MW), $expire);
    }

    protected function feedTitle()
    {
        global $wgContLanguageCode, $wgSitename;
        return wfMsg('categorypagefeed-title', $wgSitename, $this->pager->mCategory->getDBkey(), $wgContLanguageCode);
    }

    protected function feedItem($row)
    {
        $title = Title::newFromRow($row);
/*patch|2011-05-12|IntraACL|start*/
        if ($title && (!method_exists($title, 'userCanReadEx') ||
            $title->userCanReadEx()))
/*patch|2011-05-12|IntraACL|end*/
        {
            $date = $row->cl_timestamp;
            $comments = $title->getTalkPage()->getFullURL();

            return new FeedItem(
                $title->getPrefixedText(),
                $this->feedItemDesc($row),
                $title->getFullURL(),
                $date,
                $this->feedItemAuthor($row),
                $comments);
        } else {
            return null;
        }
    }

    protected function feedItemAuthor($row)
    {
        return isset($row->rev_user_text) ? $row->rev_user_text : '';
    }

    protected function feedItemDesc($row)
    {
        global $wgNewpagesFeedNoHtml, $wgUser, $wgParser;
        $t = $row->old_text;
        if ($wgNewpagesFeedNoHtml)
            $t = nl2br(htmlspecialchars($t));
        else
        {
            if (!$this->parserOptions)
            {
                $this->parserOptions = ParserOptions::newFromUser($wgUser);
                $this->parserOptions->setEditSection(false);
            }
            $t = $wgParser->getSection($t, 0);
            $t = $wgParser->parse($t, Title::newFromRow($row), $this->parserOptions);
            $t = $t->getText();
        }
        return "<div>$t</div>";
    }
}

/**
 * Pages added to category
 * @ingroup SpecialPage Pager
 */
class CategoryPageFeedPager extends ReverseChronologicalPager
{
    // Saved options and SpecialPage
    var $opts, $mForm, $mTitle;
    // Cached query info
    var $mQueryInfo, $mCategory;

    function __construct($form, FormOptions $opts)
    {
        parent::__construct();
        $this->mForm = $form;
        $this->opts = $opts;
    }

    function getTitle()
    {
        if (!$this->mTitle)
            $this->mTitle = $this->mForm->getTitle();
        return $this->mTitle;
    }

    function getQueryInfo()
    {
        // Evaluate query options once
        if ($this->mQueryInfo)
            return $this->mQueryInfo;

        // Check category
        $category = $this->opts->getValue('category');
        $categoryTitle = $category !== '' ? Title::newFromText($category, NS_CATEGORY) : NULL;

        // Recheck namespace of created title, this also supports HaloACL/IntraACL
        if (!$categoryTitle || !$categoryTitle->exists() || $categoryTitle->getNamespace() != NS_CATEGORY)
            return NULL;
        $this->mCategory = $categoryTitle;

        $info = array(
            'tables' => array('categorylinks', 'page', 'revision', 'text'),
            'fields' => 'page.*, cl_timestamp, old_text, rev_user, rev_user_text',
            'conds' => array('cl_to' => $categoryTitle->getDBkey(), 'page_id=cl_from', 'rev_id=page_latest', 'old_id=rev_text_id'),
            'options' => array(),
            'join_conds' => NULL,
        );

        return $this->mQueryInfo = $info;
    }

    // Get modification timestamp
    function lastModifiedTime()
    {
        $q = $this->getQueryInfo();
        if (!$q)
            return NULL;
        $dbr = wfGetDB(DB_SLAVE);
        $res = $dbr->select(
            $q['tables'], 'MAX(cl_timestamp)',
            $q['conds'], __FUNCTION__,
            $q['options'], $q['join_conds']
        );
        $lastmod = $res->fetchRow();
        $lastmod = $lastmod[0];
        return $lastmod;
    }

    function getIndexField()
    {
        return 'cl_timestamp';
    }

    function formatRow($row)
    {
        return $this->mForm->formatRow($row);
    }

    function getStartBody()
    {
        return "<ul style=\"margin-top: 1em\">";
    }

    function getEndBody()
    {
        return "</ul>";
    }
}
