<?php
namespace Affirm\Telesales\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Checkout\Model\Session;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Api\OrderManagementInterface as OrderManagement;
use Affirm\Telesales\Model\Adminhtml\Checkout as AffirmCheckout;

class Confirm extends Action implements CsrfAwareActionInterface
{
    const CHECKOUT_STATUS_CONFIRMED = 'confirmed';
    /**
     * Inject objects to the Confirm action
     *
     * @param Context                 $context
     */
    public function __construct(
        Context $context,
        Session $checkoutSession,
        CartManagementInterface $cartManagement,
        OrderFactory $orderFactory,
        OrderManagement $orderManagement,
        CartRepositoryInterface $quoteRepository,
        AffirmCheckout $affirmCheckout,
        \Psr\Log\LoggerInterface $logger
    )	{
        parent::__construct($context);
        $this->_checkoutSession = $checkoutSession;
        $this->quoteManagement = $cartManagement;
        $this->orderFactory = $orderFactory;
        $this->orderManagement = $orderManagement;
        $this->quoteRepository = $quoteRepository;
        $this->affirmCheckout = $affirmCheckout;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(
        RequestInterface $request
    ): ?InvalidRequestException {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute()
    {
        $checkout_token = $this->getRequest()->getParam('checkout_token');
        $this->logger->debug('Affirm Telesales checkout confirm action: '.$checkout_token);
        if (!isset($checkout_token)) {
            return $this->cancelRedirect();
        }

        // Get orderIncrementId from checkout read
        $readCheckoutResponse = $this->affirmCheckout->readCheckout($checkout_token);
        $responseBody = json_decode($readCheckoutResponse->getBody(), true);
        $order_id = $responseBody['merchant_external_reference'] ?: $responseBody['order_id'];
        $checkout_status = $responseBody['checkout_status'];

        if (!isset($order_id)) {
            $this->logger->debug('Affirm Telesales - Missing merchant external reference');
            return $this->cancelRedirect();
        }

        // Load order
        $_order = $this->orderFactory->create()->loadByIncrementId($order_id);
        $quoteId = $_order->getQuoteId();
        $quote = $this->quoteRepository->get($quoteId);

        // Verify checkout_token and checkout_status
        if ($checkout_token !== $_order->getPayment()->getAdditionalInformation('checkout_token') || $checkout_status !== self::CHECKOUT_STATUS_CONFIRMED) {
            $this->logger->debug('Affirm Telesales - Invalid checkout token');
            return $this->cancelRedirect();
        }

        // Set checkout_token to quote
        $payment = $quote->getPayment();
        $payment->setAdditionalInformation('checkout_token', $checkout_token);
        $payment->save();

        try {
            $order = $this->orderManagement->place($_order);
            $quote->setIsActive(false);
            $this->quoteRepository->save($quote);
            $this->_eventManager->dispatch(
                'sales_model_service_quote_submit_success',
                [
                    'order' => $order,
                    'quote' => $quote
                ]
            );
        } catch (LocalizedException $e) {
            $this->messageManager->addExceptionMessage(
                $e,
                $e->getMessage()
            );
            $_order->setState(\Magento\Sales\Model\Order::STATE_CANCELED)
                ->setStatus(\Magento\Sales\Model\Order::STATE_CANCELED);
            $_order->addCommentToStatusHistory($e->getMessage());
            $_order->save();

            return $this->cancelRedirect();
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage(
                $e,
                __('We can\'t place the order.')
            );
            return $this->cancelRedirect();
        }

        $_orderState = $order->getState();
        $_orderStatus = $order->getStatus();


        $this->_checkoutSession
            ->setLastQuoteId($quoteId)
            ->setLastSuccessQuoteId($quoteId);
        $this->_checkoutSession
            ->setLastOrderId($order->getId())
            ->setLastRealOrderId($order->getIncrementId())
            ->setLastOrderStatus($_orderStatus);

        $this->_eventManager->dispatch(
            'affirm_place_order_success',
            ['order' => $order, 'quote' => $quote ]
        );

        $this->_redirect('checkout/onepage/success');
        return;
    }

    private function cancelRedirect() {
        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('telesales/payment/cancel');
        return $resultRedirect;
    }

}
