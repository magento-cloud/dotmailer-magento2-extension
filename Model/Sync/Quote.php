<?php

namespace Dotdigitalgroup\Email\Model\Sync;

class Quote
{
	private $_start;
	private $_quotes;
	private $_count = 0;
	private $_quoteIds;

	protected $_helper;
	protected $_storeManager;
	protected $_resource;
	protected $_scopeConfig;
	protected $_objectManager;

	public function __construct(
		\Magento\Framework\App\Resource $resource,
		\Dotdigitalgroup\Email\Helper\Data $helper,
		\Magento\Store\Model\StoreManagerInterface $storeManagerInterface,
		\Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
		\Magento\Framework\ObjectManagerInterface $objectManager
	)
	{
		$this->_helper = $helper;
		$this->_storeManager = $storeManagerInterface;
		$this->_resource = $resource;
		$this->_scopeConfig = $scopeConfig;
		$this->_objectManager = $objectManager;
	}
	/**
	 * sync
	 *
	 * @return array
	 */
	public function sync()
	{
		$response = array('success' => true, 'message' => '');

		//resource allocation
		$this->_helper->allowResourceFullExecution();
		$websites = $this->_helper->getWebsites(true);
		foreach ($websites as $website) {
			$apiEnabled = $this->_helper->isEnabled($website);
			$quoteEnabled = $this->_helper->getWebsiteConfig(\Dotdigitalgroup\Email\Helper\Config::XML_PATH_CONNECTOR_SYNC_QUOTE_ENABLED, $website);
			$storeIds = $website->getStoreIds();
			if ($quoteEnabled && $apiEnabled && !empty($storeIds)) {
				//using bulk api
				$this->_helper->log('---------- Start quote bulk sync ----------');
				$this->_start = microtime(true);
				$this->_exportQuoteForWebsite($website);
				//send quote as transactional data
				if (isset($this->_quotes[$website->getId()])) {
					$websiteQuotes = $this->_quotes[$website->getId()];
					//register in queue with importer
					$check = $this->_objectManager->create('Dotmailer\Email\Model\Proccessor')->registerQueue(
						\Dotdigitalgroup\Email\Model\Proccessor::IMPORT_TYPE_QUOTE,
						$websiteQuotes,
						\Dotdigitalgroup\Email\Model\Proccessor::MODE_BULK,
						$website->getId()
					);
					//set imported
					if ($check) {
						$this->_setImported($this->_quoteIds);
					}
				}
				$message = 'Total time for quote bulk sync : ' . gmdate("H:i:s", microtime(true) - $this->_start);
				$this->_helper->log($message);

				//update quotes
				$this->_exportQuoteForWebsiteInSingle($website);

			}
		}
		$response['message'] = "quote updated: ". $this->_count;
		return $response;
	}

	/**
	 * export quotes to website
	 *
	 */
	private function _exportQuoteForWebsite( $website)
	{
		try{
			//reset quotes
			$this->_quotes = array();
			$this->_quoteIds = array();
			$limit = $this->_helper->getWebsiteConfig(\Dotdigitalgroup\Email\Helper\Config::XML_PATH_CONNECTOR_TRANSACTIONAL_DATA_SYNC_LIMIT, $website);
			//quote collection for import
			$collection = $this->_getQuoteToImport($website, $limit);

			foreach($collection as $emailQuote){
				$store = $this->_storeManager->getStore($emailQuote->getStoreId());
				$quote = $this->_objectManager->create('Magento\Quote\Model\Quote')
					->setStore($store)
					->load($emailQuote->getQuoteId());
				//quote found
				if($quote->getId()) {
					$connectorQuote = $this->_objectManager->create('Dotdigitalgroup\Email\Model\Connector\Quote')
						->setQuote($quote);
					$this->_quotes[$website->getId()][] = $connectorQuote;
				}
				$this->_quoteIds[] = $emailQuote->getQuoteId();
				$this->_count++;
			}
		}catch(\Exception $e){

		}
	}

	/**
	 * get quotes to import
	 *
	 * @param int $limit
	 * @param $modified
	 *
	 * @return mixed
	 */
	private function _getQuoteToImport( $website, $limit = 100, $modified = false)
	{
		$collection = $this->_objectManager->create('Dotdigitalgroup\Email\Model\Quote')->getCollection()
               ->addFieldToFilter('store_id', array('in' => $website->getStoreIds()))
               ->addFieldToFilter('customer_id', array('notnull' => true));

		if ($modified) {
			$collection->addFieldToFilter('modified', 1)
			           ->addFieldToFilter('imported', 1);
		} else {
			$collection->addFieldToFilter('imported', array('null' => true));
		}

		$collection->getSelect()->limit($limit);
		return $collection;
	}

	/**
	 * update quotes for website in single
	 *
	 */
	private function _exportQuoteForWebsiteInSingle( $website)
	{
		try {
			$limit = $this->_helper->getWebsiteConfig(\Dotdigitalgroup\Email\Helper\Config::XML_PATH_CONNECTOR_TRANSACTIONAL_DATA_SYNC_LIMIT, $website);
			$collection = $this->_getQuoteToImport($website, $limit, true);
			foreach ($collection as $emailQuote) {
				//register in queue with importer
				$check = $this->_objectManager->create('Dotdigitalgroup\Email\Model\Proccessor')->registerQueue(
					\Dotdigitalgroup\Email\Model\Proccessor::IMPORT_TYPE_QUOTE,
					array($emailQuote->getQuoteId()),
					\Dotdigitalgroup\Email\Model\Proccessor::MODE_SINGLE,
					$website->getId()
				);
				if ($check) {
					$this->_helper->log('Quote updated : ' . $emailQuote->getQuoteId());
					$emailQuote->setModified(null)
						->save();
					$this->_count++;
				}
			}
		} catch (\Exception $e) {
		}
	}



	/**
	 * Reset the email quote for reimport.
	 *
	 * @return int
	 */
	public function resetQuotes()
	{
		$coreResource = $this->_resource;

		$conn = $coreResource->getConnection();
		try{
			$num = $conn->update($coreResource->getTableName('email_quote'),
				array('imported' => new \Zend_Db_Expr('null'), 'modified' => new \Zend_Db_Expr('null'))
			);
		}catch (\Exception $e){
		}

		return $num;
	}

	/**
	 * set imported in bulk query
	 *
	 * @param $ids
	 */
	private function _setImported($ids)
	{
		try{
			$coreResource = $this->_resource;
			$write = $coreResource->getConnection();
			$tableName = $coreResource->getTableName('email_quote');
			$ids = implode(', ', $ids);
			$write->update($tableName, array('imported' => 1, 'updated_at' => gmdate('Y-m-d H:i:s'),
			                                 'modified' => new \Zend_Db_Expr('null')), "quote_id IN ($ids)");
		}catch (\Exception $e){
		}
	}
}