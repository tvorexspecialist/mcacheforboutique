<?php
/**
 * Manage tag-specific cache cleaning
 *
 * @package Made_Cache
 * @author info@madepeople.se
 * @copyright Copyright (c) 2012 Made People AB. (http://www.madepeople.se/)
 */
class Made_Cache_Model_Observer
{
    protected $_shouldProfile = null;

    /**
     * Observer that injects cache values into specific blocks, we want
     * to do it like this instead of block rewrites to prevent other
     * third-party modules from breaking
     *
     * @param Varien_Event_Observer $observer
     */
    public function setupBlock(Varien_Event_Observer $observer)
    {
        $block = $observer->getEvent()->getBlock();
        if ($block->getCacheLifetime() === null) {
            // null lifetime means don't use cache
            return;
        }

        // Allow developers to manipulate block cache data
        Mage::dispatchEvent('made_cache_setup_block_before', array(
            'block' => $block
        ));

        switch (true) {
            case $block instanceof Mage_Catalog_Block_Product_View:
                Mage::getSingleton('cache/observer_catalog')
                    ->applyProductView($block);
                break;
            case $block instanceof Mage_Catalog_Block_Category_View:
                Mage::getSingleton('cache/observer_catalog')
                    ->applyCategoryView($block);
                break;
            case $block instanceof Mage_Catalog_Block_Product_List:
                Mage::getSingleton('cache/observer_catalog')
                    ->applyProductList($block);
                break;
            case $block instanceof Mage_Cms_Block_Page:
                Mage::getSingleton('cache/observer_cms')
                    ->applyCmsPage($block);
                break;
            case $block instanceof Mage_Cms_Block_Block:
            case $block instanceof Mage_Cms_Block_Widget_Block:
                Mage::getSingleton('cache/observer_cms')
                    ->applyCmsBlock($block);
                break;
            case $block instanceof Mage_Checkout_Block_Cart_Sidebar:
                Mage::getSingleton('cache/observer_checkout')
                    ->applyCartSidebar($block);
                break;
        }

        Mage::dispatchEvent('made_cache_setup_block_after', array(
            'block' => $block
        ));
    }

    /**
     * Clear product cache after a review is saved
     *
     * @param Varien_Event_Observer $observer
     */
    public function reviewSaveAfter(Varien_Event_Observer $observer)
    {
        $_object = $observer->getObject();
        $_productCollection = $_object->getProductCollection()
                ->addAttributeToFilter('rt.review_id',
                        array('eq' => $_object->getId()));

        foreach ($_productCollection as $_product) {
            $_product->cleanCache();
        }
    }

    /**
     * CatalogRule invalidates cache on product save, so this must be cleaned
     *
     * @param Varien_Event_Observer $observer
     */
    public function cleanCacheAfterProductSave(Varien_Event_Observer $observer)
    {
        $invalidatedTypes = Mage::app()->getCacheInstance()
                ->getInvalidatedTypes();

        if (!is_array($invalidatedTypes)) {
            return;
        }

        $typesToCheck = array(
            Mage_Core_Block_Abstract::CACHE_GROUP,
            'full_page'
        );

        foreach ($typesToCheck as $type) {
            if (isset($invalidatedTypes[Mage_Core_Block_Abstract::CACHE_GROUP])) {
                Mage::app()->getCacheInstance()
                        ->cleanType($type);
            }
        }
    }

    /**
     * Clear quote cache on custom tag
     *
     * @param Varien_Event_Observer $observer
     */
    public function clearQuoteCache(Varien_Event_Observer $observer)
    {
        // Only runs when there is an active quote in the session
        $object = $observer->getEvent()->getQuote();
        Mage::app()->cleanCache(array('SALES_QUOTE_' . $object->getId()));
    }

    /**
     * Unset the uenc param for redirection of blocks that have cached links.
     * If we don't do this, links redirects to where user X came from when
     * the block was cached. This also means that cached return links for
     * ESI blocks return a visitor to the ESI URL, and we can't have that.
     *
     * @param Varien_Event_Observer $observer
     */
    public function removeUencParam(Varien_Event_Observer $observer)
    {
        $request = $observer->getEvent()->getControllerAction()
                ->getRequest();

        // To this day, the only used encoding type is PARAM_NAME_URL_ENCODED
        $key = Mage_Core_Controller_Varien_Action::PARAM_NAME_URL_ENCODED;
        if (($param = $request->getParam($key)) !== null) {
            // Always remove redirections to Varnish actions
            $paramValue = base64_decode($param);
            if (strstr($paramValue, '/madecache/varnish/') ||
                    Mage::getStoreConfig('cache/general/remove_redirect_param')) {
                $request->setParam($key, null);
            }
        }
    }

    /**
     * Private getter to determine if the profiler should be enabled
     *
     * @return bool
     */
    protected function _getShouldProfile()
    {
        if (is_null($this->_shouldProfile)) {
            $this->_shouldProfile = (bool)Mage::getStoreConfig('cache/general/enable_profiler');
        }

        return $this->_shouldProfile;
    }

    /**
     * Start counting the time of rendering an uncached block
     *
     * @param Varien_Event_Observer $observer
     */
    public function profilerStart(Varien_Event_Observer $observer)
    {
        $shouldProfile = $this->_getShouldProfile();
        if ($shouldProfile === true) {
            $blockName = $observer->getEvent()
                    ->getBlock()
                    ->getNameInLayout();

            Made_Cache_Model_Profiler::start($blockName);
        }
    }

    /**
     * Stop counting the time of rendering an uncached block
     *
     * @param Varien_Event_Observer $observer
     */
    public function profilerEnd(Varien_Event_Observer $observer)
    {
        $shouldProfile = $this->_getShouldProfile();
        if ($shouldProfile === true) {
            $blockName = $observer->getEvent()
                    ->getBlock()
                    ->getNameInLayout();

            Made_Cache_Model_Profiler::end($blockName);
        }
    }

    /**
     * We have to clear the product cache on order save in case the stock qty
     * for the affected product(s) turned the product out of stock
     *
     * @param Varien_Event_Observer $observer
     */
    public function clearProductCacheAfterOrderPlace(Varien_Event_Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();

        $tags = array();
        foreach ($order->getAllItems() as $item) {
            $tags[] = Mage_Catalog_Model_Product::CACHE_TAG . '_' . $item->getProductId();
        }

        Mage::app()->cleanCache($tags);
    }
}
