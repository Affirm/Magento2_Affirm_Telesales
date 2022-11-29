<?php
namespace Affirm\Telesales\Controller\Payment;

use Affirm\Telesales\Model\Adminhtml\Checkout as AffirmCheckout;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderManagementInterface as OrderManagement;
use Magento\Sales\Model\OrderFactory;
use Affirm\Telesales\Helper\Data as AffirmData;

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
        JsonFactory $resultJsonFactory,
        AffirmData $affirmData,
        \Psr\Log\LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->_checkoutSession = $checkoutSession;
        $this->quoteManagement = $cartManagement;
        $this->orderFactory = $orderFactory;
        $this->orderManagement = $orderManagement;
        $this->quoteRepository = $quoteRepository;
        $this->affirmCheckout = $affirmCheckout;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->affirmData = $affirmData;
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
        $result = $this->resultJsonFactory->create();
        $checkout_token = $this->getRequest()->getParam('checkout_token') ?? null;
        $currency_code = $this->getRequest()->getParam('currency_code') ?? 'USD';
        $this->logger->debug('Affirm Telesales checkout confirm action: ' . $checkout_token);
        if (!$checkout_token) {
            $result->setData([
                'success' => true,
                'checkout_status' => "Not sent",
                'checkout_status_message' => "Send checkout link to customer"
            ]);
            return $result;
        }

        // Get orderIncrementId from checkout read
        $checkout_status = '';
        try {
            $readCheckoutResponse = $this->affirmCheckout->readCheckout($checkout_token, $currency_code);
            $response_date = $readCheckoutResponse->getHeader('Date');
            $this->logger->debug('Affirm Telesales checkout confirm responseBody: ' . $readCheckoutResponse->getBody());
            $responseBody = json_decode($readCheckoutResponse->getBody(), true);
            if (isset($responseBody['checkout_status'])) {
                $checkout_status = $responseBody['checkout_status'];
            }
            $test = $this->affirmData->mapResponseToMessage($responseBody);
        } catch (\Exception $e) {
            $this->logger->debug('Affirm Telesales checkout confirm error: ' . $e);
            return $this->getErrorResult($result, $e);
        }

        // Verify checkout_token and checkout_status
        if ($checkout_status !== 'confirmed' && $checkout_status !=='authorized') {
            $result->setData([
                'success' => true,
                'message' => "Last refreshed: " . $response_date,
                'checkout_status' => "Application sent",
                'checkout_status_message' => "Waiting for customer to start the application"
            ]);
            return $result;
        }

        // Order ID
        $order_id = $responseBody['merchant_external_reference'] ?? $responseBody['order_id'];

        if (!isset($order_id)) {
            $_message = __('Affirm Telesales - Missing merchant external reference');
            $this->logger->debug($_message);
            return $this->getErrorResult($result, $_message);
        }

        // Load order
        $_order = $this->orderFactory->create()->loadByIncrementId($order_id);
        $quoteId = $_order->getQuoteId();
        $quote = $this->quoteRepository->get($quoteId);

        // Set checkout_token to quote
        $payment = $quote->getPayment();
        $payment->setAdditionalInformation('checkout_token', $checkout_token);
        $payment->save();

        try {
            $order = $this->orderManagement->place($_order);
            $quote->setIsActive(false);
            $this->quoteRepository->save($quote);
        } catch (LocalizedException $e) {
            $this->messageManager->addExceptionMessage(
                $e,
                $e->getMessage()
            );
            $_order->addCommentToStatusHistory($e->getMessage());
            $_order->save();

            return $this->getErrorResult($result, $e);
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage(
                $e,
                __('We can\'t place the order.')
            );
            return $this->getErrorResult($result, $e);
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

        $result->setData([
            'success' => true,
            'message' => "Success",
            'checkout_status' => "Payment authorized",
            'checkout_status_message' => "Payment is complete. Ready to finalize order"
        ]);
        return $result;
    }

    private function getErrorResult($result, $e)
    {
        $result->setData([
            'success' => false,
            'message' => $e
        ]);
        return $result;
    }
}
