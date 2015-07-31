<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Migration\Step\UrlRewrite;

use Migration\App\Step\RollbackInterface;
use Migration\App\Step\StageInterface;
use Migration\Reader\MapInterface;
use Migration\Step\DatabaseStage;
use Migration\Resource\Document;

/**
 * Class Version11410to2000
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class Version11410to2000 extends DatabaseStage implements StageInterface, RollbackInterface
{
    /**
     * Temporary table name
     *
     * @var string
     */
    protected $tableName;

    /**
     * @var array
     */
    protected $duplicateIndex;

    /**
     * @var array
     */
    protected $resolvedDuplicates = [];

    /**
     * Resource of source
     *
     * @var \Migration\Resource\Source
     */
    protected $source;

    /**
     * Resource of destination
     *
     * @var \Migration\Resource\Destination
     */
    protected $destination;

    /**
     * Record Factory
     *
     * @var \Migration\Resource\RecordFactory
     */
    protected $recordFactory;

    /**
     * Record Collection Factory
     *
     * @var \Migration\Resource\Record\CollectionFactory
     */
    protected $recordCollectionFactory;

    /**
     * LogLevelProcessor instance
     *
     * @var \Migration\App\ProgressBar\LogLevelProcessor
     */
    protected $progress;

    /**
     * Logger instance
     *
     * @var \Migration\Logger\Logger
     */
    protected $logger;

    /**
     * @var string
     */
    protected $stage;

    /**
     * @var bool
     */
    protected $dataInitialized = false;

    /**
     * @var array
     */
    protected $suffixData;

    /**
     * @var array
     */
    protected $structure = [
        MapInterface::TYPE_SOURCE => [
            'enterprise_url_rewrite' => [
                'url_rewrite_id',
                'request_path',
                'target_path',
                'is_system',
                'guid',
                'identifier',
                'inc',
                'value_id',
                'store_id',
                'entity_type'
            ],
            'catalog_category_entity_url_key' => [
                'value_id',
                'entity_type_id',
                'attribute_id',
                'store_id',
                'entity_id',
                'value'
            ],
            'catalog_product_entity_url_key' => [
                'value_id',
                'entity_type_id',
                'attribute_id',
                'store_id',
                'entity_id',
                'value'
            ],
            'enterprise_url_rewrite_redirect' => [
                'redirect_id',
                'identifier',
                'target_path',
                'options',
                'description',
                'category_id',
                'product_id',
                'store_id'
            ],
        ],
        MapInterface::TYPE_DEST => [
            'url_rewrite' => [
                'url_rewrite_id',
                'entity_type',
                'entity_id',
                'request_path',
                'target_path',
                'redirect_type',
                'store_id',
                'description',
                'is_autogenerated',
                'metadata'
            ],
            'catalog_category_entity_varchar' => [
                'value_id',
                'attribute_id',
                'store_id',
                'entity_id',
                'value',
            ],
            'catalog_product_entity_varchar' => [
                'value_id',
                'attribute_id',
                'store_id',
                'entity_id',
                'value',
            ]
        ],
    ];

    /**
     * @param \Migration\App\ProgressBar\LogLevelProcessor $progress
     * @param \Migration\Logger\Logger $logger
     * @param \Migration\Config $config
     * @param \Migration\Resource\Source $source
     * @param \Migration\Resource\Destination $destination
     * @param \Migration\Resource\Record\CollectionFactory $recordCollectionFactory
     * @param \Migration\Resource\RecordFactory $recordFactory
     * @param string $stage
     * @throws \Migration\Exception
     */
    public function __construct(
        \Migration\App\ProgressBar\LogLevelProcessor $progress,
        \Migration\Logger\Logger $logger,
        \Migration\Config $config,
        \Migration\Resource\Source $source,
        \Migration\Resource\Destination $destination,
        \Migration\Resource\Record\CollectionFactory $recordCollectionFactory,
        \Migration\Resource\RecordFactory $recordFactory,
        $stage
    ) {
        $this->progress = $progress;
        $this->logger = $logger;
        $this->source = $source;
        $this->destination = $destination;
        $this->recordCollectionFactory = $recordCollectionFactory;
        $this->recordFactory = $recordFactory;
        $this->tableName = 'url_rewrite_m2' . md5('url_rewrite_m2');
        $this->stage = $stage;
        parent::__construct($config);
    }

    /**
     * {@inheritdoc}
     */
    public function perform()
    {
        if (!method_exists($this, $this->stage)) {
            throw new \Migration\Exception('Invalid step configuration');
        }

        return call_user_func([$this, $this->stage]);
    }

    /**
     * Data migration
     *
     * @return bool
     * @throws \Migration\Exception
     */
    protected function data()
    {
        $this->getRewritesSelect();
        $this->progress->start($this->getIterationsCount());

        $sourceDocument = $this->source->getDocument($this->tableName);
        $destinationDocument = $this->destination->getDocument('url_rewrite');
        $destProductCategory = $this->destination->getDocument('catalog_url_rewrite_product_category');

        $duplicates = $this->getDuplicatesList();
        if (!empty($duplicates) && !empty($this->configReader->getOption('auto_resolve_urlrewrite_duplicates'))
            && empty($this->duplicateIndex)
        ) {
            foreach ($duplicates as $row) {
                $this->duplicateIndex[$row['request_path']][] = $row;
            }
        }

        $pageNumber = 0;
        while (!empty($data = $this->source->getRecords($sourceDocument->getName(), $pageNumber))) {
            $pageNumber++;
            $records = $this->recordCollectionFactory->create();
            $destProductCategoryRecords = $destProductCategory->getRecords();
            foreach ($data as $row) {
                $this->progress->advance();
                $records->addRecord($this->recordFactory->create(['data' => $row]));
                $productCategoryRecord = $this->getProductCategoryRecord($destProductCategory, $row);
                if ($productCategoryRecord) {
                    $destProductCategoryRecords->addRecord($productCategoryRecord);
                }
            }
            $destinationRecords = $destinationDocument->getRecords();
            $this->migrateRewrites($records, $destinationRecords);
            $this->destination->saveRecords($destinationDocument->getName(), $destinationRecords);
            $this->destination->saveRecords($destProductCategory->getName(), $destProductCategoryRecords);
        }
        $this->copyEavData('catalog_category_entity_url_key', 'catalog_category_entity_varchar', 'category');
        $this->copyEavData('catalog_product_entity_url_key', 'catalog_product_entity_varchar', 'product');
        $this->progress->finish();
        return true;
    }

    /**
     * @param Document $destProductCategory
     * @param array $row
     * @return \Migration\Resource\Record|null
     * @throws \Migration\Exception
     */
    private function getProductCategoryRecord(Document $destProductCategory, array $row)
    {
        $destProductCategoryRecord = null;
        if ($row['is_system'] && $row['product_id'] && $row['category_id']) {
            $destProductCategoryRecord = $this->recordFactory->create(['document' => $destProductCategory]);
            $destProductCategoryRecord->setValue('url_rewrite_id', $row['id']);
            $destProductCategoryRecord->setValue('category_id', $row['category_id']);
            $destProductCategoryRecord->setValue('product_id', $row['product_id']);
        }
        return $destProductCategoryRecord;
    }

    /**
     * @return \Magento\Framework\DB\Select
     */
    protected function getRewritesSelect()
    {
        if (!$this->dataInitialized) {
            $this->initTemporaryTable();
        }
        /** @var \Migration\Resource\Adapter\Mysql $adapter */
        $adapter = $this->source->getAdapter();
        $select = $adapter->getSelect();
        $select->from(['r' => $this->source->addDocumentPrefix($this->tableName)]);
        return $select;
    }

    /**
     * @param \Migration\Resource\Record\Collection $source
     * @param \Migration\Resource\Record\Collection $destination
     * @return void
     */
    protected function migrateRewrites($source, $destination)
    {
        /** @var \Migration\Resource\Record $sourceRecord */
        foreach ($source as $sourceRecord) {
            /** @var \Migration\Resource\Record $destinationRecord */
            $destinationRecord = $this->recordFactory->create();
            $destinationRecord->setStructure($destination->getStructure());

            $destinationRecord->setValue('url_rewrite_id', null);
            $destinationRecord->setValue('store_id', $sourceRecord->getValue('store_id'));
            $destinationRecord->setValue('description', $sourceRecord->getValue('description'));
            $destinationRecord->setValue('redirect_type', 0);
            $destinationRecord->setValue('is_autogenerated', $sourceRecord->getValue('is_system'));
            $destinationRecord->setValue('metadata', '');
            $destinationRecord->setValue('redirect_type', $sourceRecord->getValue('redirect_type'));
            $destinationRecord->setValue('entity_type', $sourceRecord->getValue('entity_type'));
            $destinationRecord->setValue('request_path', $sourceRecord->getValue('request_path'));

            $targetPath = $sourceRecord->getValue('target_path');

            $productId = $sourceRecord->getValue('product_id');
            $categoryId = $sourceRecord->getValue('category_id');
            if (!empty($productId) && !empty($categoryId)) {
                $length = strlen($categoryId);
                $metadata = sprintf('a:1:{s:11:"category_id";s:%s:"%s";}', $length, $categoryId);
                $destinationRecord->setValue('metadata', $metadata);
                $destinationRecord->setValue('entity_type', 'product');
                $destinationRecord->setValue('entity_id', $productId);
                $targetPath = "catalog/product/view/id/$productId/category/$categoryId";
            } elseif (!empty($productId) && empty($categoryId)) {
                $destinationRecord->setValue('entity_type', 'product');
                $destinationRecord->setValue('entity_id', $productId);
                $targetPath = 'catalog/product/view/id/' . $productId;
            } elseif (empty($productId) && !empty($categoryId)) {
                $destinationRecord->setValue('entity_type', 'category');
                $destinationRecord->setValue('entity_id', $categoryId);
                if ($sourceRecord->getValue('entity_type') != 'custom') {
                    $targetPath = 'catalog/category/view/id/' . $categoryId;
                }
            } else {
                $destinationRecord->setValue('entity_id', 0);
            }

            if (!empty($this->duplicateIndex[$sourceRecord->getValue('request_path')])) {
                $shouldResolve = false;
                foreach ($this->duplicateIndex[$sourceRecord->getValue('request_path')] as &$duplicate) {
                    $onStore = $duplicate['store_id'] == $sourceRecord->getValue('store_id');
                    if ($onStore && empty($duplicate['used'])) {
                        $duplicate['used'] = true;
                        break;
                    }
                    if ($onStore) {
                        $shouldResolve = true;
                    }
                }
                if ($shouldResolve) {
                    $hash = md5(mt_rand());
                    $requestPath = preg_replace(
                        '/^(.*)\.([^\.]+)$/i',
                        '$1-' . $hash . '.$2',
                        $sourceRecord->getValue('request_path')
                    );
                    $this->resolvedDuplicates[$destinationRecord->getValue('entity_type')]
                        [$destinationRecord->getValue('entity_id')]
                        [$sourceRecord->getValue('store_id')] = $hash;
                    $destinationRecord->setValue('request_path', $requestPath);
                    $message = 'Duplicate resolved. '
                        . sprintf(
                            'Request path was: %s Target path was: %s Store ID: %s New request path: %s',
                            $sourceRecord->getValue('request_path'),
                            $sourceRecord->getValue('target_path'),
                            $sourceRecord->getValue('store_id'),
                            $destinationRecord->getValue('request_path')
                        );
                    $this->logger->addInfo($message);
                }
            }

            $destinationRecord->setValue(
                'target_path',
                $targetPath
            );
            $destination->addRecord($destinationRecord);
        }
    }

    /**
     * @param string $sourceName
     * @param string $destinationName
     * @param string $type
     * @return void
     */
    protected function copyEavData($sourceName, $destinationName, $type)
    {
        $destinationDocument = $this->destination->getDocument($destinationName);
        $pageNumber = 0;
        while (!empty($recordsData = $this->source->getRecords($sourceName, $pageNumber))) {
            $pageNumber++;
            $records = $destinationDocument->getRecords();
            foreach ($recordsData as $row) {
                $this->progress->advance();
                $row['value_id'] = null;
                unset($row['entity_type_id']);
                if (!empty($this->resolvedDuplicates[$type][$row['entity_id']][$row['store_id']])) {
                    $row['value'] = $row['value'] . '-'
                        . $this->resolvedDuplicates[$type][$row['entity_id']][$row['store_id']];
                } elseif (!empty($this->resolvedDuplicates[$type][$row['entity_id']]) && $row['store_id'] == 0) {
                    foreach ($this->resolvedDuplicates[$type][$row['entity_id']] as $storeId => $urlKey) {
                        $storeRow = $row;
                        $storeRow['store_id'] = $storeId;
                        $storeRow['value'] = $storeRow['value'] . '-' . $urlKey;
                        $records->addRecord($this->recordFactory->create(['data' => $storeRow]));
                        if (!isset($this->resolvedDuplicates[$destinationName])) {
                            $this->resolvedDuplicates[$destinationName] = 0;
                        }
                        $this->resolvedDuplicates[$destinationName]++;
                    }
                }
                $records->addRecord($this->recordFactory->create(['data' => $row]));
            }
            $this->destination->saveRecords($destinationName, $records, true);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function integrity()
    {
        $errors = false;
        $this->progress->start(
            count($this->structure[MapInterface::TYPE_SOURCE]) + count($this->structure[MapInterface::TYPE_DEST])
        );
        foreach ($this->structure as $resourceName => $documentList) {
            $resource = $resourceName == MapInterface::TYPE_SOURCE ? $this->source : $this->destination;
            foreach ($documentList as $documentName => $documentFields) {
                $this->progress->advance();
                $document = $resource->getDocument($documentName);
                if ($document === false) {
                    $message = sprintf('%s table does not exist: %s', ucfirst($resourceName), $documentName);
                    $this->logger->error($message);
                    $errors = true;
                    continue;
                }
                $structure = array_keys($document->getStructure()->getFields());
                if (!(empty(array_diff($structure, $documentFields))
                    && empty(array_diff($documentFields, $structure)))
                ) {
                    $message = sprintf(
                        '%s table structure does not meet expectation: %s',
                        ucfirst($resourceName),
                        $documentName
                    );
                    $this->logger->error($message);
                    $errors = true;
                }
            }
        }
        $this->progress->finish();

        return !$errors && !$this->processDuplicatesList();
    }

    /**
     * @return bool
     */
    private function processDuplicatesList()
    {
        $errors = false;
        $data = $this->getDuplicatesList();
        if (!empty($data)) {
            $duplicates = [];
            foreach ($data as $row) {
                $duplicates[] = sprintf(
                    'Request path: %s Store ID: %s Target path: %s',
                    $row['request_path'],
                    $row['store_id'],
                    $row['target_path']
                );
            }

            $message = sprintf(
                'There are duplicates in URL rewrites:%s',
                PHP_EOL . implode(PHP_EOL, $duplicates)
            );

            if (!empty($this->configReader->getOption('auto_resolve_urlrewrite_duplicates'))) {
                $this->logger->addInfo($message);
            } else {
                $this->logger->error($message);
                $errors = true;
            }
        }
        if ($this->destination->getDocument('url_rewrite') && $this->destination->getRecordsCount('url_rewrite') != 0) {
            $this->logger->error('Destination table is not empty: url_rewrite');
            $errors = true;
        }
        return $errors;
    }

    /**
     * {@inheritdoc}
     */
    protected function volume()
    {
        $this->progress->start(1);
        $this->getRewritesSelect();
        $this->progress->advance();
        $result = $this->source->getRecordsCount($this->tableName)
            == $this->destination->getRecordsCount('url_rewrite');
        if (!$result) {
            $this->logger->warning('Mismatch of entities in the document: url_rewrite');
        }
        $this->progress->finish();
        return $result;
    }

    /**
     * Get iterations count for step
     *
     * @return int
     */
    protected function getIterationsCount()
    {
        return $this->source->getRecordsCount($this->tableName)
        + $this->source->getRecordsCount('catalog_category_entity_url_key')
        + $this->source->getRecordsCount('catalog_product_entity_url_key');
    }

    /**
     * @return array
     */
    protected function getDuplicatesList()
    {
        $subSelect = $this->getRewritesSelect();
        $subSelect->group(['request_path', 'store_id'])
            ->having('COUNT(*) > 1');

        /** @var \Migration\Resource\Adapter\Mysql $adapter */
        $adapter = $this->source->getAdapter();

        /** @var \Magento\Framework\DB\Select $select */
        $select = $adapter->getSelect();
        $select->from(['t' => $this->source->addDocumentPrefix($this->tableName)], ['t.*'])
            ->join(
                ['t2' => new \Zend_Db_Expr(sprintf('(%s)', $subSelect->assemble()))],
                't2.request_path = t.request_path AND t2.store_id = t.store_id',
                []
            )
            ->order(['store_id', 'request_path', 'priority']);
        $resultData = $adapter->loadDataFromSelect($select);

        return $resultData;
    }

    /**
     * Get product suffix query
     *
     * @codeCoverageIgnore
     * @param string $suffixFor Can be 'product' or 'category'
     * @param string $mainTable
     * @return string
     */
    protected function getSuffix($suffixFor, $mainTable = 's')
    {
        if (empty($this->suffixData[$suffixFor])) {
            /** @var \Migration\Resource\Adapter\Mysql $adapter */
            $adapter = $this->source->getAdapter();
            $select = $adapter->getSelect();

            $select->from(
                ['s' => $this->source->addDocumentPrefix('core_store')],
                ['store_id' => 's.store_id']
            );

            $select->joinLeft(
                ['c1' => $this->source->addDocumentPrefix('core_config_data')],
                "c1.scope='stores' AND c1.path = 'catalog/seo/{$suffixFor}_url_suffix' AND c1.scope_id=s.store_id",
                ['store_path' => 'c1.path', 'store_value' => 'c1.value']
            );
            $select->joinLeft(
                ['c2' => $this->source->addDocumentPrefix('core_config_data')],
                "c2.scope='websites' AND c2.path = 'catalog/seo/{$suffixFor}_url_suffix' AND c2.scope_id=s.website_id",
                ['website_path' => 'c2.path', 'website_value' => 'c2.value']
            );
            $select->joinLeft(
                ['c3' => $this->source->addDocumentPrefix('core_config_data')],
                "c3.scope='default' AND c3.path = 'catalog/seo/{$suffixFor}_url_suffix'",
                ['admin_path' => 'c3.path', 'admin_value' => 'c3.value']
            );

            $result = $select->getAdapter()->fetchAll($select);
            foreach ($result as $row) {
                $suffix = 'html';
                if ($row['admin_path'] !== null) {
                    $suffix = $row['admin_value'];
                }
                if ($row['website_path'] !== null) {
                    $suffix = $row['website_value'];
                }
                if ($row['store_path'] !== null) {
                    $suffix = $row['store_value'];
                }
                $this->suffixData[$suffixFor][] = [
                    'store_id' => $row['store_id'],
                    'suffix' => $suffix ? '.' . $suffix : ''
                ];
            }
        }

        $suffix = "CASE {$mainTable}.store_id";
        foreach ($this->suffixData[$suffixFor] as $row) {
            $suffix .= sprintf(" WHEN '%s' THEN '%s'", $row['store_id'], $row['suffix']);
        }
        $suffix .= " ELSE '.html' END";

        return $suffix;
    }

    /**
     * Initialize temporary table and insert UrlRewrite data
     *
     * @codeCoverageIgnore
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @return void
     */
    protected function initTemporaryTable()
    {
        /** @var \Migration\Resource\Adapter\Mysql $adapter */
        $adapter = $this->source->getAdapter();
        $this->createTemporaryTable($adapter);
        $this->collectProductRewrites($adapter);
        $this->collectCategoryRewrites($adapter);
        $this->collectRedirects($adapter);
        $this->dataInitialized = true;
    }

    /**
     * Crete temporary table
     *
     * @param \Migration\Resource\Adapter\Mysql $adapter
     * @return void
     */
    protected function createTemporaryTable(\Migration\Resource\Adapter\Mysql $adapter)
    {
        $select = $adapter->getSelect();
        $select->getAdapter()->dropTable($this->source->addDocumentPrefix($this->tableName));
        /** @var \Magento\Framework\DB\Ddl\Table $table */
        $table = $select->getAdapter()->newTable($this->source->addDocumentPrefix($this->tableName))
            ->addColumn(
                'id',
                \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
                null,
                ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true]
            )
            ->addColumn(
                'request_path',
                \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                255
            )
            ->addColumn(
                'target_path',
                \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                255
            )
            ->addColumn(
                'is_system',
                \Magento\Framework\DB\Ddl\Table::TYPE_SMALLINT,
                null,
                ['nullable' => false, 'default' => '0']
            )
            ->addColumn(
                'store_id',
                \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER
            )
            ->addColumn(
                'entity_type',
                \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                32
            )
            ->addColumn(
                'redirect_type',
                \Magento\Framework\DB\Ddl\Table::TYPE_SMALLINT,
                null,
                ['unsigned' => true, 'nullable' => false, 'default' => '0']
            )
            ->addColumn(
                'product_id',
                \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER
            )
            ->addColumn(
                'category_id',
                \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER
            )
            ->addColumn(
                'priority',
                \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER
            )
            ->addIndex(
                'url_rewrite',
                ['request_path', 'target_path', 'store_id'],
                ['type' => \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE]
            )        ;
        $select->getAdapter()->createTable($table);
    }

    /**
     * Fulfill temporary table with category url rewrites
     *
     * @param \Migration\Resource\Adapter\Mysql $adapter
     * @return void
     */
    protected function collectCategoryRewrites(\Migration\Resource\Adapter\Mysql $adapter)
    {
        $select = $adapter->getSelect();
        $select->from(
            ['r' => $this->source->addDocumentPrefix('enterprise_url_rewrite')],
            [
                'id' => 'IFNULL(NULL, NULL)',
                'request_path' => sprintf("CONCAT(`r`.`request_path`, %s)", $this->getSuffix('category', 'r')),
                'target_path' => 'r.target_path',
                'is_system' => 'r.is_system',
                'store_id' => 'r.store_id',
                'entity_type' => "trim('category')",
                'redirect_type' => "trim('0')",
                'product_id' => "trim('0')",
                'category_id' => "c.entity_id",
                'priority' => "trim('3')"
            ]
        );
        $select->join(
            ['c' => $this->source->addDocumentPrefix('catalog_category_entity_url_key')],
            'r.value_id = c.value_id',
            []
        );
        $query = $select->where('`r`.`entity_type` = 2')
            ->insertFromSelect($this->source->addDocumentPrefix($this->tableName));
        $select->getAdapter()->query($query);
    }

    /**
     * Fulfill temporary table with product url rewrites
     *
     * @param \Migration\Resource\Adapter\Mysql $adapter
     * @return void
     */
    protected function collectProductRewrites(\Migration\Resource\Adapter\Mysql $adapter)
    {
        /** @var \Magento\Framework\Db\Select $select */
        $select = $adapter->getSelect();
        $subSelect = $adapter->getSelect();
        $subSelect->from(
            ['cr' => $this->source->addDocumentPrefix('enterprise_url_rewrite')],
            ['request_path' => 'cr.request_path']
        );
        $subSelect->where('`cr`.`value_id` = `cu`.`value_id`');
        $subSelect->where('`cr`.`entity_type` = 2');
        $subSelect->where('`cr`.`store_id` = s.`store_id`');
        $subConcatCategories = $select->getAdapter()->getConcatSql([
            "($subSelect)",
            "'/'",
            '`r`.`request_path`',
            $this->getSuffix('product')
        ]);
        $storeSubSelect = $adapter->getSelect();
        $storeSubSelect->from(
            ['sr' => $this->source->addDocumentPrefix('enterprise_url_rewrite')],
            ['store_id' => 'sr.store_id']
        );
        $storeSubSelect->join(
            ['srcu' => $this->source->addDocumentPrefix('catalog_product_entity_url_key')],
            'srcu.value_id = sr.value_id',
            []
        );
        $storeSubSelect->where('sr.entity_type = 3')
            ->where('srcu.entity_id = p.entity_id')
            ->where('sr.store_id > 0');

        $targetPath = 'IF(ISNULL(c.category_id), r.target_path, CONCAT(r.target_path, "/category/", c.category_id))';
        $select = $adapter->getSelect();
        $select->from(
            ['r' => $this->source->addDocumentPrefix('enterprise_url_rewrite')],
            [
                'id' => 'IFNULL(NULL, NULL)',
                'request_path' => $subConcatCategories,
                'target_path' => $targetPath,
                'is_system' => 'r.is_system',
                'store_id' => 's.store_id',
                'entity_type' => "trim('product')",
                'redirect_type' => "trim('0')",
                'product_id' => "p.entity_id",
                'category_id' => "c.category_id",
                'priority' => "trim('4')"
            ]
        );
        $select->join(
            ['p' => $this->source->addDocumentPrefix('catalog_product_entity_url_key')],
            'r.value_id = p.value_id',
            []
        );
        $select->join(
            ['c' => $this->source->addDocumentPrefix('catalog_category_product')],
            'p.entity_id = c.product_id',
            []
        );
        $select->join(
            ['cu' => $this->source->addDocumentPrefix('catalog_category_entity_url_key')],
            'cu.entity_id = c.category_id',
            []
        );
        $select->join(
            ['cpw' => $this->source->addDocumentPrefix('catalog_product_website')],
            'c.product_id = cpw.product_id',
            []
        );
        $select->join(
            ['s' => $this->source->addDocumentPrefix('core_store')],
            sprintf('cpw.website_id = s.website_id and s.store_id not in (%s)', $storeSubSelect),
            []
        );
        $select->where('`r`.`entity_type` = 3')->where('`r`.`store_id` = 0');

        $query = $adapter->getSelect()->from(['result' => new \Zend_Db_Expr("($select)")])
            ->where('result.request_path IS NOT NULL')
            ->insertFromSelect($this->source->addDocumentPrefix($this->tableName))
        ;
        $select->getAdapter()->query($query);

        $select = $adapter->getSelect();
        $subConcat = $select->getAdapter()->getConcatSql([
           '`r`.`request_path`',
           $this->getSuffix('product')
        ]);

        $select = $adapter->getSelect();
        $select->from(
            ['r' => $this->source->addDocumentPrefix('enterprise_url_rewrite')],
            [
               'id' => 'IFNULL(NULL, NULL)',
               'request_path' => $subConcat,
               'target_path' => 'r.target_path',
               'is_system' => 'r.is_system',
               'store_id' => 's.store_id',
               'entity_type' => "trim('product')",
               'redirect_type' => "trim('0')",
               'product_id' => "p.entity_id",
               'category_id' => "trim('0')",
               'priority' => "trim('4')"
           ]
        );
        $select->join(
            ['p' => $this->source->addDocumentPrefix('catalog_product_entity_url_key')],
            'r.value_id = p.value_id',
            []
        );
        $select->join(
            ['cpw' => $this->source->addDocumentPrefix('catalog_product_website')],
            'p.entity_id = cpw.product_id',
            []
        );
        $select->join(
            ['s' => $this->source->addDocumentPrefix('core_store')],
            sprintf('cpw.website_id = s.website_id and s.store_id not in (%s)', $storeSubSelect),
            []
        );
        $query = $select->where('`r`.`entity_type` = 3')
            ->where('`r`.`store_id` = 0')
            ->insertFromSelect($this->source->addDocumentPrefix($this->tableName));
        $select->getAdapter()->query($query);

        $select = $adapter->getSelect();
        $subConcatCategories = $select->getAdapter()->getConcatSql([
            "($subSelect)",
            "'/'",
            '`s`.`request_path`',
            $this->getSuffix('product')
        ]);
        $select->from(
            ['s' => $this->source->addDocumentPrefix('enterprise_url_rewrite')],
            [
                'id' => 'IFNULL(NULL, NULL)',
                'request_path' => $subConcatCategories,
                'target_path' => 's.target_path',
                'is_system' => 's.is_system',
                'store_id' => 's.store_id',
                'entity_type' => "trim('product')",
                'redirect_type' => "trim('0')",
                'product_id' => "p.entity_id",
                'category_id' => "c.category_id",
                'priority' => "trim('4')"
            ]
        );
        $select->join(
            ['p' => $this->source->addDocumentPrefix('catalog_product_entity_url_key')],
            's.value_id = p.value_id',
            []
        );
        $select->join(
            ['c' => $this->source->addDocumentPrefix('catalog_category_product')],
            'p.entity_id = c.product_id',
            []
        );
        $select->join(
            ['cu' => $this->source->addDocumentPrefix('catalog_category_entity_url_key')],
            'cu.entity_id = c.category_id',
            []
        );
        $query = $select->where('`s`.`entity_type` = 3')
            ->where('`s`.`store_id` > 0')
            ->insertFromSelect($this->source->addDocumentPrefix($this->tableName));
        $select->getAdapter()->query($query);

        $select = $adapter->getSelect();
        $subConcat = $select->getAdapter()->getConcatSql([
            '`s`.`request_path`',
            $this->getSuffix('product')
        ]);
        $select->from(
            ['s' => $this->source->addDocumentPrefix('enterprise_url_rewrite')],
            [
                'id' => 'IFNULL(NULL, NULL)',
                'request_path' => $subConcat,
                'target_path' => 's.target_path',
                'is_system' => 's.is_system',
                'store_id' => 's.store_id',
                'entity_type' => "trim('product')",
                'redirect_type' => "trim('0')",
                'product_id' => "p.entity_id",
                'category_id' => "trim('0')",
                'priority' => "trim('4')"
            ]
        );
        $select->join(
            ['p' => $this->source->addDocumentPrefix('catalog_product_entity_url_key')],
            's.value_id = p.value_id',
            []
        );
        $query = $select->where('`s`.`entity_type` = 3')
            ->where('`s`.`store_id` > 0')
            ->insertFromSelect($this->source->addDocumentPrefix($this->tableName));
        $select->getAdapter()->query($query);
    }

    /**
     * Fulfill temporary table with redirects
     *
     * @param \Migration\Resource\Adapter\Mysql $adapter
     * @return void
     */
    protected function collectRedirects(\Migration\Resource\Adapter\Mysql $adapter)
    {
        $select = $adapter->getSelect();
        $select->from(
            ['r' => $this->source->addDocumentPrefix('enterprise_url_rewrite')],
            [
                'id' => 'IFNULL(NULL, NULL)',
                'request_path' => 'r.request_path',
                'target_path' => 'r.target_path',
                'is_system' => 'r.is_system',
                'store_id' => 'r.store_id',
                'entity_type' => "trim('custom')",
                'redirect_type' => "trim('0')",
                'product_id' => "trim('0')",
                'category_id' => "trim('0')",
                'priority' => "trim('2')"
            ]
        );
        $query = $select->where('`r`.`entity_type` = 1')
            ->insertFromSelect($this->source->addDocumentPrefix($this->tableName));
        $select->getAdapter()->query($query);

        $select = $adapter->getSelect();
        $select->from(
            ['r' => $this->source->addDocumentPrefix('enterprise_url_rewrite_redirect')],
            [
                'id' => 'IFNULL(NULL, NULL)',
                'request_path' => 'r.identifier',
                'target_path' => 'r.target_path',
                'is_system' => "trim('0')",
                'store_id' => 'r.store_id',
                'entity_type' => "trim('custom')",
                'redirect_type' => "(SELECT CASE r.options WHEN 'RP' THEN 301 WHEN 'R' THEN 302 ELSE 0 END)",
                'product_id' => "r.product_id",
                'category_id' => "r.category_id",
                'priority' => "trim('1')"
            ]
        );
        $query = $select->insertFromSelect($this->source->addDocumentPrefix($this->tableName));
        $select->getAdapter()->query($query);
    }

    /**
     * Perform rollback
     *
     * @return void
     */
    public function rollback()
    {
        $this->destination->clearDocument('url_rewrite');
        $this->destination->clearDocument('catalog_url_rewrite_product_category');
    }
}
