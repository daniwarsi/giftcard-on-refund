<?php

namespace Mage4\GiftCard\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\Sales\Model\Order\Creditmemo\NotifierInterface;
use Magento\Sales\Model\Order\CreditmemoDocumentFactory;
use Magento\Sales\Model\Order\OrderStateResolverInterface;
use Magento\Sales\Model\Order\RefundAdapterInterface;
use Magento\Sales\Model\Order\Validation\RefundInvoiceInterface as RefundInvoiceValidator;
use Psr\Log\LoggerInterface;

/**
 * Class RefundInvoice
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class RefundInvoice extends \Magento\Sales\Model\RefundInvoice
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var OrderStateResolverInterface
     */
    private $orderStateResolver;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var InvoiceRepositoryInterface
     */
    private $invoiceRepository;

    /**
     * @var CreditmemoRepositoryInterface
     */
    private $creditmemoRepository;

    /**
     * @var RefundAdapterInterface
     */
    private $refundAdapter;

    /**
     * @var CreditmemoDocumentFactory
     */
    private $creditmemoDocumentFactory;

    /**
     * @var NotifierInterface
     */
    private $notifier;

    /**
     * @var OrderConfig
     */
    private $config;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var RefundInvoiceValidator
     */
    private $validator;

    private $helperData;

    /**
     * RefundInvoice constructor.
     *
     * @param ResourceConnection $resourceConnection
     * @param OrderStateResolverInterface $orderStateResolver
     * @param OrderRepositoryInterface $orderRepository
     * @param InvoiceRepositoryInterface $invoiceRepository
     * @param RefundInvoiceValidator $validator
     * @param CreditmemoRepositoryInterface $creditmemoRepository
     * @param RefundAdapterInterface $refundAdapter
     * @param CreditmemoDocumentFactory $creditmemoDocumentFactory
     * @param NotifierInterface $notifier
     * @param OrderConfig $config
     * @param LoggerInterface $logger
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        ResourceConnection                        $resourceConnection,
        OrderStateResolverInterface               $orderStateResolver,
        OrderRepositoryInterface                  $orderRepository,
        InvoiceRepositoryInterface                $invoiceRepository,
        RefundInvoiceValidator                    $validator,
        CreditmemoRepositoryInterface             $creditmemoRepository,
        RefundAdapterInterface                    $refundAdapter,
        CreditmemoDocumentFactory                 $creditmemoDocumentFactory,
        NotifierInterface                         $notifier,
        OrderConfig                               $config,
        LoggerInterface                           $logger,
        \Mage4\GiftCard\Helper\Data $helperData
    )
    {
        $this->resourceConnection = $resourceConnection;
        $this->orderStateResolver = $orderStateResolver;
        $this->orderRepository = $orderRepository;
        $this->invoiceRepository = $invoiceRepository;
        $this->validator = $validator;
        $this->creditmemoRepository = $creditmemoRepository;
        $this->refundAdapter = $refundAdapter;
        $this->creditmemoDocumentFactory = $creditmemoDocumentFactory;
        $this->notifier = $notifier;
        $this->config = $config;
        $this->logger = $logger;
        $this->helperData = $helperData;
    }

    /**
     * @inheritdoc
     */
    public function execute(
        $invoiceId,
        array $items = [],
        $isOnline = false,
        $notify = false,
        $appendComment = false,
        \Magento\Sales\Api\Data\CreditmemoCommentCreationInterface $comment = null,
        \Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterface $arguments = null
    )
    {
        $connection = $this->resourceConnection->getConnection('sales');
        $invoice = $this->invoiceRepository->get($invoiceId);
        $order = $this->orderRepository->get($invoice->getOrderId());
        $creditmemo = $this->creditmemoDocumentFactory->createFromInvoice(
            $invoice,
            $items,
            $comment,
            ($appendComment && $notify),
            $arguments
        );

        $validationMessages = $this->validator->validate(
            $invoice,
            $order,
            $creditmemo,
            $items,
            $isOnline,
            $notify,
            $appendComment,
            $comment,
            $arguments
        );

        if ($validationMessages->hasMessages()) {
            throw new \Magento\Sales\Exception\DocumentValidationException(
                __("Creditmemo Document Validation Error(s):\n" . implode("\n", $validationMessages->getMessages()))
            );
        }

        $connection->beginTransaction();
        try {
            $creditmemo->setState(\Magento\Sales\Model\Order\Creditmemo::STATE_REFUNDED);
            /* Check if Order has gift card */
            if (count($order->getExtensionAttributes()->getAmGiftcardOrder()->getGiftCards()) > 0) {
                $order = $this->helperData->generateNewGiftCardAccount($creditmemo);
                $isOnline = false;
                $notify = false;
                $creditmemo->setBaseGrandTotal(0);
                $creditmemo->setGrandTotal(0);
            }
            /* Check if Order has gift card */

            $order->setCustomerNoteNotify($notify);
            $order = $this->refundAdapter->refund($creditmemo, $order, $isOnline);
            $order->setState(
                $this->orderStateResolver->getStateForOrder($order, [])
            );

            $order->setStatus($this->config->getStateDefaultStatus($order->getState()));
            if (!$isOnline) {
                $invoice->setIsUsedForRefund(true);
                $invoice->setBaseTotalRefunded(
                    $invoice->getBaseTotalRefunded() + $creditmemo->getBaseGrandTotal()
                );
            }
            $this->invoiceRepository->save($invoice);
            $order = $this->orderRepository->save($order);
            $creditmemo = $this->creditmemoRepository->save($creditmemo);
            if (count($order->getExtensionAttributes()->getAmGiftcardOrder()->getGiftCards()) > 0) {
                $this->helperData->removeOfflineComment($order);
            }
            $connection->commit();
        } catch (\Exception $e) {
            $this->logger->critical($e);
            $connection->rollBack();
            throw new \Magento\Sales\Exception\CouldNotRefundException(
                __('Could not save a Creditmemo, see error log for details')
            );
        }
        if ($notify) {
            if (!$appendComment) {
                $comment = null;
            }
            $this->notifier->notify($order, $creditmemo, $comment);
        }

        return $creditmemo->getEntityId();
    }

}
