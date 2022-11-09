<?php
/**
 * @category   Mage4
 * @package    Mage4_GiftCard
 * @copyright  All rights reserved
 */
declare(strict_types=1);

namespace Mage4\GiftCard\Plugin\Sales\Model\Service;

use Amasty\GiftCardAccount\Model\ConfigProvider;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Service\CreditmemoService;
use Psr\Log\LoggerInterface;

class CreditmemoServicePlugin
{
    private $creditMemoAmount;
    /**
     * @var ConfigProvider
     */
    private $configProvider;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    private $helperData;

    public function __construct(
        ConfigProvider                            $configProvider,
        OrderRepositoryInterface                  $orderRepository,
        LoggerInterface                           $logger,
        \Mage4\GiftCard\Helper\Data $helperData
    )
    {
        $this->configProvider = $configProvider;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
        $this->helperData = $helperData;
        $this->creditMemoAmount = 0;
    }

    public function beforeRefund(
        CreditmemoService $subject,
        Creditmemo        $creditmemo,
                          $offlineRequested = false
    )
    {
        try {
            $order = $creditmemo->getOrder();
            $storeId = $order->getStore()->getId();
            if ($this->configProvider->isEnabled($storeId)
                && $this->configProvider->isRefundAllowed($storeId)
                && count($creditmemo->getOrder()->getExtensionAttributes()->getAmGiftcardOrder()->getGiftCards()) > 0
            ) {
                $_order = $this->helperData->generateNewGiftCardAccount($creditmemo);
                $this->orderRepository->save($_order);
                $this->creditMemoAmount = $creditmemo->getBaseGrandTotal();
                $creditmemo->setBaseGrandTotal(0);
                $creditmemo->setGrandTotal(0);
                $offlineRequested = true;
            }
        } catch (\Exception $e) {
            $this->logger->critical($e->getMessage());
        }
        return array($creditmemo, $offlineRequested);
    }


    public function afterRefund(
        CreditmemoService $subject,
        Creditmemo        $creditmemo
    )
    {
        $order = $creditmemo->getOrder();
        $storeId = $order->getStore()->getId();
        try {
            if ($this->configProvider->isEnabled($storeId)
                && $this->configProvider->isRefundAllowed($storeId)
                && count($creditmemo->getOrder()->getExtensionAttributes()->getAmGiftcardOrder()->getGiftCards()) > 0
            ) {
                $this->helperData->removeOfflineComment($order);
            }
        } catch (\Exception $e) {
            $this->logger->critical($e->getMessage());
        }
        return $creditmemo;
    }
}
