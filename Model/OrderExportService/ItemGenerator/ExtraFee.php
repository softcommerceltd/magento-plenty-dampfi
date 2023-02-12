<?php
/**
 * Copyright © Soft Commerce Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace SoftCommerce\PlentyDampfi\Model\OrderExportService\ItemGenerator;

use Elogic\Extrafee\Helper\Data as ExtraFeeHelper;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\InventorySalesApi\Model\GetSkuFromOrderItemInterface;
use Magento\InventoryShipping\Model\ResourceModel\ShipmentSource\GetSourceCodeByShipmentId;
use Magento\Store\Model\ScopeInterface;
use SoftCommerce\Core\Framework\DataStorageInterfaceFactory;
use SoftCommerce\Core\Framework\MessageStorageInterfaceFactory;
use SoftCommerce\Core\Framework\SearchMultidimensionalArrayInterface;
use SoftCommerce\PlentyOrder\Model\GetSalesOrderTaxRateInterface;
use SoftCommerce\PlentyOrder\Model\SalesOrderReservationRepositoryInterface;
use SoftCommerce\PlentyOrderClient\Api\ShippingCountryRepositoryInterface;
use SoftCommerce\PlentyOrderProfile\Model\OrderExportService\Generator\Order\Items\ItemAbstract;
use SoftCommerce\PlentyOrderRestApi\Model\OrderInterface as HttpClient;
use SoftCommerce\PlentyStock\Model\GetOrderItemSourceSelectionInterface;
use SoftCommerce\PlentyStockProfile\Model\Config\StockConfigInterfaceFactory;
use SoftCommerce\Profile\Model\ServiceAbstract\ProcessorInterface;

/**
 * @inheritdoc
 * Class ExtraFee used to export order extra fee.
 */
class ExtraFee extends ItemAbstract implements ProcessorInterface
{
    private const METADATA_FEE = 'fee';
    private const XML_PATH_EXTRAFEE_STATUS = 'Extrafee/Extrafee/status';

    /**
     * @var ExtraFeeHelper
     */
    private ExtraFeeHelper $helper;

    /**
     * @param ExtraFeeHelper $helper
     * @param GetOrderItemSourceSelectionInterface $getOrderItemSourceSelection
     * @param GetSalesOrderTaxRateInterface $getSalesOrderTaxRate
     * @param GetSkuFromOrderItemInterface $getSkuFromOrderItem
     * @param GetSourceCodeByShipmentId $getSourceCodeByShipmentIdRepository
     * @param SalesOrderReservationRepositoryInterface $salesOrderReservationRepository
     * @param SearchMultidimensionalArrayInterface $searchMultidimensionalArray
     * @param ScopeConfigInterface $scopeConfig
     * @param ShippingCountryRepositoryInterface $shippingCountryRepository
     * @param StockConfigInterfaceFactory $stockConfigFactory
     * @param DataStorageInterfaceFactory $dataStorageFactory
     * @param MessageStorageInterfaceFactory $messageStorageFactory
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param array $data
     */
    public function __construct(
        ExtraFeeHelper $helper,
        GetOrderItemSourceSelectionInterface $getOrderItemSourceSelection,
        GetSalesOrderTaxRateInterface $getSalesOrderTaxRate,
        GetSkuFromOrderItemInterface $getSkuFromOrderItem,
        GetSourceCodeByShipmentId $getSourceCodeByShipmentIdRepository,
        SalesOrderReservationRepositoryInterface $salesOrderReservationRepository,
        SearchMultidimensionalArrayInterface $searchMultidimensionalArray,
        ScopeConfigInterface $scopeConfig,
        ShippingCountryRepositoryInterface $shippingCountryRepository,
        StockConfigInterfaceFactory $stockConfigFactory,
        DataStorageInterfaceFactory $dataStorageFactory,
        MessageStorageInterfaceFactory $messageStorageFactory,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        array $data = []
    ) {
        $this->helper = $helper;
        parent::__construct(
            $getOrderItemSourceSelection,
            $getSalesOrderTaxRate,
            $getSkuFromOrderItem,
            $getSourceCodeByShipmentIdRepository,
            $salesOrderReservationRepository,
            $searchMultidimensionalArray,
            $scopeConfig,
            $shippingCountryRepository,
            $stockConfigFactory,
            $dataStorageFactory,
            $messageStorageFactory,
            $searchCriteriaBuilder,
            $data
        );
    }

    /**
     * @inheritdoc
     */
    public function execute(): void
    {
        $this->initialize();
        $this->generate();
        $this->finalize();
    }

    /**
     * @return void
     * @throws LocalizedException
     */
    private function generate(): void
    {
        $salesOrder = $this->getContext()->getSalesOrder();
        $amount = $salesOrder->getData(self::METADATA_FEE);

        if (!$this->canProcess() || $amount < 0.0001) {
            return;
        }

        $amounts[] = [
            HttpClient::IS_SYSTEM_CURRENCY => true,
            HttpClient::CURRENCY => $salesOrder->getBaseCurrencyCode(),
            HttpClient::EXCHANGE_RATE => 1,
            HttpClient::PRICE_ORIGINAL_GROSS => $amount,
            HttpClient::SURCHARGE => 0,
            HttpClient::DISCOUNT => 0,
            HttpClient::IS_PERCENTAGE => false
        ];

        $this->getRequestStorage()->addData(
            [
                HttpClient::TYPE_ID => HttpClient::ITEM_TYPE_PAYMENT_SURCHARGE,
                HttpClient::REFERRER_ID => $this->getContext()->orderConfig()->getOrderReferrerId(
                    $salesOrder->getStoreId()
                ),
                HttpClient::QUANTITY => 1,
                HttpClient::COUNTRY_VAT_ID => $this->getCountryId(
                    $salesOrder->getBillingAddress()->getCountryId()
                ),
                HttpClient::VAT_FIELD => 0,
                HttpClient::VAT_RATE => 0,
                HttpClient::ORDER_ITEM_NAME => $this->helper->getFeeLabel() ?: __('Extra fee'),
                HttpClient::AMOUNTS => $amounts,
            ]
        );
    }

    /**
     * @return bool
     * @throws LocalizedException
     */
    private function canProcess(): bool
    {
        return !!$this->scopeConfig->getValue(
            self::XML_PATH_EXTRAFEE_STATUS,
            ScopeInterface::SCOPE_STORE,
            $this->getContext()->getSalesOrder()->getStoreId()
        );
    }
}
