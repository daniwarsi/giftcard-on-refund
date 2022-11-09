<?php

namespace InformaticsCommerce\GiftCard\Helper;


use Amasty\GiftCardAccount\Model\Notification\NotifiersProvider;
use Amasty\GiftCardAccount\Model\OptionSource\AccountStatus;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Store\Model\StoreManagerInterface;
use Amasty\GiftCardAccount\Api\Data\GiftCardAccountInterface;
use Amasty\GiftCardAccount\Model\GiftCardAccount\Repository;
use Amasty\GiftCardAccount\Model\Notification\NotificationsApplier;
use Amasty\GiftCardAccount\Model\GiftCardAccount\ResourceModel\Collection;
use Amasty\GiftCardAccount\Model\GiftCardAccount\ResourceModel\CollectionFactory;
use Amasty\GiftCard\Model\Code\ResourceModel\CollectionFactory as CodeCollectionFactory;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;

/**
 * AvaTax Config model
 */
class Data extends AbstractHelper
{

    /**
     * @var NotificationsApplier
     */
    private $notificationsApplier;

    /**
     * @var Repository
     */
    private $accountRepository;

    /**
     * @var CollectionFactory
     */
    private $accountCollectionFactory;

    /**
     * @var CollectionFactory
     */
    private $codeCollectionFactory;

    /**
     * @var OrderStatusHistoryRepositoryInterface
     */
    private $orderStatusRepository;

    public function __construct(
        Context                               $context,
        NotificationsApplier                  $notificationsApplier,
        Repository                            $accountRepository,
        CollectionFactory                     $accountCollectionFactory,
        CodeCollectionFactory                 $codeCollectionFactory,
        OrderStatusHistoryRepositoryInterface $orderStatusRepository
    )
    {
        parent::__construct($context);
        $this->notificationsApplier = $notificationsApplier;
        $this->accountRepository = $accountRepository;
        $this->accountCollectionFactory = $accountCollectionFactory;
        $this->codeCollectionFactory = $codeCollectionFactory;
        $this->orderStatusRepository = $orderStatusRepository;
    }

    /**
     * Generate new gift card
     *
     * @return mixed
     */
    public function generateNewGiftCardAccount(Creditmemo $creditmemo)
    {
        $order = $creditmemo->getOrder();
        $gCardOrder = $order->getExtensionAttributes()->getAmGiftcardOrder();
        $gCardMemo = $creditmemo->getExtensionAttributes()->getAmGiftcardCreditmemo();
        if (count($order->getCreditmemosCollection()) === 0) {
            $giftAmount = $gCardMemo->getGiftAmount() + $creditmemo->getBaseGrandTotal();
        } else {
            $giftCardTotal = 0;
            foreach ($order->getExtensionAttributes()->getAmGiftcardOrder()->getGiftCards() as $key => $card) {
                $giftCardTotal += $card['amount'];
            }
            $giftAmount = $creditmemo->getBaseGrandTotal() - $giftCardTotal;
        }
        $accounts = $gCardOrder->getGiftCards();
        if (count($accounts) > 0) {
            $accountId = (int)$accounts[0]['id'];

            /** @var Collection $accountCollectionFactory */
            $giftAccount = $this->accountCollectionFactory->create()
                ->addFieldToFilter(GiftCardAccountInterface::ACCOUNT_ID, ['eq' => $accountId])
                ->getFirstItem();

            /** @var Collection $codeCollectionFactory */
            $code = $this->codeCollectionFactory->create()
                ->addFieldToFilter(GiftCardAccountInterface::CODE_ID, ['eq' => $giftAccount->getCodeId()])
                ->getFirstItem();

            $data = array();
            $data[GiftCardAccountInterface::CODE_POOL] = $code->getCodePoolId();
            $data[GiftCardAccountInterface::IMAGE_ID] = $giftAccount->getImageId();
            $data[GiftCardAccountInterface::WEBSITE_ID] = $giftAccount->getWebsiteId();
            $data[GiftCardAccountInterface::STATUS] = AccountStatus::STATUS_ACTIVE;
            $data[GiftCardAccountInterface::RECIPIENT_EMAIL] = $order->getCustomerEmail();
            $data[GiftCardAccountInterface::INITIAL_VALUE] = $giftAmount;
            $data[GiftCardAccountInterface::CURRENT_VALUE] = $giftAmount;

            $account = $this->accountRepository->getEmptyAccountModel();
            $account->setIsSent(true);
            $account->addData($data);
            $this->accountRepository->save($account);

            $this->notificationsApplier->apply(
                NotifiersProvider::EVENT_ORDER_ACCOUNT_CREATE,
                $this->accountRepository->getById((int)$account->getAccountId()),
                $order->getCustomerName(),
                $order->getCustomerEmail()
            );

            $order->addCommentToStatusHistory(__(
                'New Gift Card code added %1 against this order with a total amount $%2',
                [
                    $account->getCodeModel()->getCode(),
                    $giftAmount
                ]
            ))->setIsCustomerNotified(false);

            return $order;
        }
    }

    /**
     * Remove Offline comment
     *
     * @return mixed
     */
    public function removeOfflineComment($order)
    {
        $commentFirstItem = $order->getStatusHistoryCollection()->getFirstItem();
        if ($commentFirstItem) {
            //check comment should be offline before delete
            if (strpos($commentFirstItem->getComment(), 'offline') !== false) {
                $orderStatusCommentObject = $this->orderStatusRepository->get($commentFirstItem->getEntityId());
                $this->orderStatusRepository->delete($orderStatusCommentObject);
            }
        }
    }
}
