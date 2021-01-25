<?php


namespace Import\Csv\Model\Import;

use Exception;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\ImportExport\Helper\Data as ImportHelper;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\Entity\AbstractEntity;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregatorInterface;
use Magento\ImportExport\Model\ResourceModel\Helper;
use Magento\ImportExport\Model\ResourceModel\Import\Data;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Psr\Log\LoggerInterface;

class CustomEntity extends AbstractEntity
{
    const ENTITY_CODE = 'import_csv';
    const SKU = 'sku';

    /**
     * To check the column names
     */
    protected $needColumnCheck = true;

    /**
     * Log import history
     */
    protected $logInHistory = true;

    /**
     * Permanent entity columns
     */
    protected $_permanentAttributes = [
        'sku',
        'price',
        'qty',
        'value',
        'category'
    ];

    /**
     * Validation column names
     */
    protected $validColumnNames = [
        'sku',
        'price',
        'qty',
        'value',
        'category'
    ];

    /**
     * @var AdapterInterface
     */
    protected $connection;

    /**
     * @var ResourceConnection
     */
    protected $resource;

    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepositoryInterface;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var StockRegistryInterface
     */
    protected $stockRegistry;

    protected $categoryLinkManagement;
    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @param \Magento\Framework\Json\Helper\Data $jsonHelper
     * @param \Magento\ImportExport\Helper\Data $importExportData
     * @param \Magento\ImportExport\Model\ResourceModel\Import\Data $importData
     * @param \Magento\Framework\App\ResourceConnection $resource
     * @param \Magento\ImportExport\Model\ResourceModel\Helper $resourceHelper
     * @param \Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregatorInterface $errorAggregator
     * @param \Magento\Catalog\Api\ProductRepositoryInterface $productRepositoryInterface
     * @param StockRegistryInterface $stockRegistry
     * @param CollectionFactory $collectionFactory
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        JsonHelper $jsonHelper,
        ImportHelper $importExportData,
        Data $importData,
        ResourceConnection $resource,
        Helper $resourceHelper,
        ProcessingErrorAggregatorInterface $errorAggregator,
        ProductRepositoryInterface $productRepositoryInterface,
        StockRegistryInterface $stockRegistry,
        CollectionFactory $collectionFactory,
        LoggerInterface $logger
    ) {
        $this->jsonHelper = $jsonHelper;
        $this->_importExportData = $importExportData;
        $this->_resourceHelper = $resourceHelper;
        $this->_dataSourceModel = $importData;
        $this->resource = $resource;
        $this->connection = $resource->getConnection(ResourceConnection::DEFAULT_CONNECTION);
        $this->errorAggregator = $errorAggregator;
        $this->productRepositoryInterface = $productRepositoryInterface;
        $this->stockRegistry = $stockRegistry;
        $this->collectionFactory = $collectionFactory;
        $this->logger = $logger;
        $this->initMessageTemplates();
    }

    /**
     * Entity type code
     * @return string
     */
    public function getEntityTypeCode()
    {
        return static::ENTITY_CODE;
    }

    /**
     * Get available columns
     * @return array
     */
    public function getValidColumnNames()
    {
        return $this->validColumnNames;
    }

    /**
     * Validate Rows
     * @param array $rowData
     * @param int $rowNum
     * @return bool
     */
    public function validateRow(array $rowData, $rowNum): bool
    {
        $sku = $rowData['sku'] ?? '';
        $price = $rowData['price'] ?? '';
        $qty = $rowData['qty'] ?? '';
        $value = $rowData['value'] ?? '';
        $category = $rowData['category'] ?? '';

        if (!$sku) {
            $this->addRowError('SkuISRequired', $rowNum);
        }

        if (!$price) {
            $this->addRowError('PriceIsRequired', $rowNum);
        }

        if (!$qty) {
            $this->addRowError('QtyIsRequired', $rowNum);
        }

        if (!$value) {
            $this->addRowError('VisibilityIsRequired', $rowNum);
        }

        if (!$category) {
            $this->addRowError('CategoryIsRequired', $rowNum);
        }

        if (isset($this->_validatedRows[$rowNum])) {
            return !$this->getErrorAggregator()->isRowInvalid($rowNum);
        }

        $this->_validatedRows[$rowNum] = true;

        return !$this->getErrorAggregator()->isRowInvalid($rowNum);
    }

    /**
     * Error Messages
     */
    private function initMessageTemplates()
    {
        $this->addMessageTemplate(
            'SkuIsRequired',
            __('The sku is required')
        );

        $this->addMessageTemplate(
            'PriceIsRequired',
            __('The price is required')
        );

        $this->addMessageTemplate(
            'QtyIsRequired',
            __('The qty is required')
        );

        $this->addMessageTemplate(
            'VisibilityIsRequired',
            __('The visibility is required')
        );

        $this->addMessageTemplate(
            'CategoryIsRequired',
            __('The category is required')
        );
    }

    /**
     * Import the data
     * @return bool
     * @throws Exception
     */
    protected function _importData()
    {
        switch ($this->getBehavior()) {
            case Import::BEHAVIOR_DELETE:
                break;
            case Import::BEHAVIOR_REPLACE:
            case Import::BEHAVIOR_APPEND:
                $this->saveAndReplaceEntity();
                break;
        }
        return true;
    }

    /**
     *  Save and replace
     * @retrun void
     */
    private function saveAndReplaceEntity()
    {
        $behavior = $this->getBehavior();

        $rows = [];
        while ($bunch = $this->_dataSourceModel->getNextBunch()) {
            $entityList = [];

            foreach ($bunch as $rowNum => $row) {
                if (!$this->validateRow($row, $rowNum)) {
                    continue;
                }
                if ($this->getErrorAggregator()->hasToBeTerminated()) {
                    $this->getErrorAggregator()->addRowToSkip($rowNum);

                    continue;
                }

                $rowId = $row[static::SKU];
                $rows[] = $rowId;
                $columnValues = [];

                foreach ($this->getValidColumnNames() as $columnKey) {
                    $columnValues[$columnKey] = $row[$columnKey];
                }

                $entityList[$rowId][] = $columnValues;
                $this->countItemsCreated += (int)!isset($row[static::SKU]);
                $this->countItemsUpdated += (int)isset($row[static::SKU]);
            }

            $this->saveEntityFinish($entityList);
        }
    }

    /**
     * Save entities
     * @param array $entityData
     * @return bool
     */

    private function saveEntityFinish(array $entityData): bool
    {
        if ($entityData) {
            $rows = [];

            foreach ($entityData as $entityRows) {
                foreach ($entityRows as $row) {
                    $rows[] = $row;
                }
            }
            if ($rows) {
                foreach ($rows as $row) {
                    if ($product = $this->getBySku($row['sku'])) {
                        $product->setPrice($row['price']);
                        $array = [
                            1 => 'Not Visible Individually',
                            2 => 'Catalog',
                            3 => 'Search',
                            4 => 'Catalog, Search'
                        ];
                        $search = array_search($row['value'], $array);
                        $product->setVisibility($search);

                        $collection = $this->collectionFactory
                            ->create()
                            ->addAttributeToFilter('name', $row['category'])
                            ->setPageSize(1);

                        if ($collection->getSize()) {
                            $categoryId = $collection->getFirstItem()->getId();
                            $product->setCategory($categoryId);
                        }
//                        $this->getCategoryLinkManagement()->assignProductToCategories(
//                            $product->getSku($row['sku'])
//                        );
                        try {
                            $product = $this->productRepositoryInterface->save($product);
                        } catch (\Exception $e) {
                            $this->logger->critical($e);
                        }
                    }
                    $stock = $this->stockRegistry->getStockItemBySku($row['sku']);
                    $stock->setQty($row['qty']);
                    $stock->setIsInStock((bool) $row['qty']);
                    $this->stockRegistry->updateStockItemBySku($row['sku'], $stock);
                }
                return true;
            }
        }
        return false;
    }

    /**
     * Get available columns
     * @ return array
     */
    private function getAvailableColumns(): array
    {
        return $this->validColumnNames;
    }

    /**
     * Get product by SKU
     * @param string $sku
     * @param bool $editMode
     * @param int $storeId
     * @param bool $forceReload
     * @return mixed
     */

    public function getBySku($sku, $editMode = true, $storeId = null, $forceReload = false)
    {
        try {
            return $this->productRepositoryInterface->get($sku, $editMode, $storeId, $forceReload);
        } catch (\Exception $e) {
            $this->logger->critical($e);
            return false;
        }
    }

    private function getCategoryLinkManagement()
    {
        if (null === $this->categoryLinkManagement) {
            $this->categoryLinkManagement = \Magento\Framework\App\ObjectManager::getInstance()
                ->get('Magento\Catalog\Api\CategoryLinkManagementInterface');
        }
        return $this->categoryLinkManagement;
    }

}
