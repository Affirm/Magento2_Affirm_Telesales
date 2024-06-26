<?php

namespace Affirm\Telesales\Controller\Adminhtml\Checkout;

use Affirm\Telesales\Model\Adminhtml\Checkout;
use Astound\Affirm\Model\Config as ConfigAffirm;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Laminas\Http\Client;
use Magento\Framework\Module\ResourceInterface;
use Magento\Framework\Registry;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Astound\Affirm\Helper\ErrorTracker;

class Index extends Action
{
    /**
     * @var JsonFactory
     */
    private $resultJsonFactory;

    /**
     * @var Registry
     */
    private $_coreRegistry;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;


    /**
     * @var Client
     */
    private $httpClientFactory;

    /**
     * @var OrderStatusHistoryInterface
     */
    private $orderStatusRepository;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var ResourceInterface
     */
    private $moduleResource;

    /**
     * @var ProductMetadataInterface
     */
    private $productMetadata;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Checkout
     */
    private $affirmCheckout;

    /**
     * @var ConfigAffirm
     */
    private $affirmConfig;

    /**
     * @var ErrorTracker
     */
    private $errorTracker;



    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Registry $coreRegistry,
        Client $httpClientFactory,
        OrderRepositoryInterface $orderRepository,
        OrderStatusHistoryInterface $orderStatusRepository,
        ProductMetadataInterface $productMetadata,
        ResourceInterface $moduleResource,
        ScopeConfigInterface $scopeConfig,
        ConfigAffirm $configAffirm,
        Checkout $affirmCheckout,
        \Psr\Log\LoggerInterface $logger,
        ErrorTracker $errorTracker
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->_coreRegistry = $coreRegistry;
        $this->httpClientFactory = $httpClientFactory;
        $this->productMetadata = $productMetadata;
        $this->moduleResource = $moduleResource;
        $this->scopeConfig = $scopeConfig;
        $this->orderRepository = $orderRepository;
        $this->orderStatusRepository = $orderStatusRepository;
        $this->affirmConfig = $configAffirm;
        $this->affirmCheckout = $affirmCheckout;
        $this->logger = $logger;
        $this->errorTracker = $errorTracker;
        parent::__construct($context);
    }

    /**
     * Handles ajax request from send checkout button
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        /** @var \Magento\Framework\Controller\Result\Json $result */
        $result = $this->resultJsonFactory->create();
        $success = false;
        $successMessage = '';

        // Initiate order
        $order = $this->_initOrder();
        if (!$this->affirmCheckout->isAffirmPaymentMethod($order)) {
            return $result->setData([
                'success' => false,
                'message' => "Error",
                'checkout_status' => "Error",
                'checkout_status_message' => "Selected payment method is not Affirm",
                'checkout_action' => false
            ]);
        }

        // Check if the extension active
        if (!$this->affirmCheckout->isAffirmTelesalesActive()) {
            return $result->setData([
                'success' => false,
                'message' => "Error",
                'checkout_status' => "Error",
                'checkout_status_message' => "Affirm Telesales is not currently active",
                'checkout_action' => false
            ]);
        }

        // Resend if checkout token exists
        $checkout_token = $this->getPaymentCheckoutToken($order);
        $currency_code = $order->getOrderCurrencyCode();
        if ($checkout_token) {
            try {
                $resendCheckoutResponse = $this->affirmCheckout->resendCheckout($checkout_token, $currency_code);
                $responseStatus = $resendCheckoutResponse->getStatusCode();
                $responseBody = json_decode($resendCheckoutResponse->getBody(), true);

                if ($responseStatus > 200) {
                    $this->logger->debug('Affirm_Telesales__resendCheckout_status_code: ', [$responseStatus]);
                    $this->logger->debug('Affirm_Telesales__resendCheckout_response_body: ', [$responseBody]);
                    $this->errorTracker->logErrorToAffirm(
                        transaction_step: 'pre_auth',
                        error_type: ErrorTracker::TRANSACTION_DECLINED,
                        error_message: $resendCheckoutResponse->getMessage(),
                        is_telesales: true
                    );
                    return $result->setData([
                        'success' => false,
                        'message' => $resendCheckoutResponse->getMessage(),
                        'checkout_status' => "Error",
                        'checkout_status_message' => $responseBody['message'] ?: "API Error - Affirm checkout resend could not be processed",
                        'checkout_action' => true
                    ]);
                }
                // JSON result
                $success = true;
                $successMessage = 'Checkout link was re-sent successfully';
            } catch (\Exception $e) {
                $this->logger->debug($e->getMessage());
                $this->errorTracker->logErrorToAffirm(
                    transaction_step: 'pre_auth',
                    error_type: ErrorTracker::INTERNAL_SERVER_ERROR,
                    exception: $e,
                    is_telesales: true
                );
                return $result->setData([
                    'success' => false,
                    'message' => "Error",
                    'checkout_status' => "Error",
                    'checkout_status_message' => "API Error - Affirm checkout resend could not be processed",
                    'checkout_action' => true
                ]);
            }
        }

        if (!$checkout_token && $order->getState() === \Magento\Sales\Model\Order::STATE_NEW) {
            // Generate checkout object
            $data = $this->affirmCheckout->getCheckoutObject($order);
            if (!$data) {
                return $result->setData([
                    'success' => false,
                    'message' => 'Checkout data unavailable',
                    'checkout_status' => "Error",
                    'checkout_status_message' => "Checkout data unavailable",
                    'checkout_action' => true
                ]);
            }
            try {
                $currency_code = $order->getOrderCurrencyCode();
                $sendCheckoutResponse = $this->affirmCheckout->sendCheckout($data, $currency_code);
                $responseStatus = $sendCheckoutResponse->getStatusCode();
                $responseBody = json_decode($sendCheckoutResponse->getBody(), true);
                if ($responseStatus > 200) {
                    $this->logger->debug('Affirm_Telesales__sendCheckout_status_code: ', [$responseStatus]);
                    $this->logger->debug('Affirm_Telesales__sendCheckout_response_body: ', [$responseBody]);
                    $bodyMessage = $responseBody['message'] && $responseBody['field'] ? "{$responseBody['message']} ({$responseBody['field']})" : "API Error - Affirm checkout could not be processed";
                    $this->errorTracker->logErrorToAffirm(
                        transaction_step: 'pre_auth',
                        error_type: ErrorTracker::TRANSACTION_DECLINED,
                        error_message: $sendCheckoutResponse->getMessage(),
                        is_telesales: true
                    );
                    return $result->setData([
                        'success' => false,
                        'message' => $sendCheckoutResponse->getMessage(),
                        'checkout_status' => "Error",
                        'checkout_status_message' => $bodyMessage,
                        'checkout_action' => true
                    ]);
                }

                // JSON result
                $success = true;
                $successMessage = 'Checkout link was sent successfully';

                $checkout_token = $responseBody['checkout_id'];
            } catch (\Exception $e) {
                $this->logger->debug($e->getMessage());
                $this->errorTracker->logErrorToAffirm(
                    transaction_step: 'pre_auth',
                    error_type: ErrorTracker::INTERNAL_SERVER_ERROR,
                    exception: $e,
                    is_telesales: true
                );
                return $result->setData([
                    'success' => false,
                    'message' => 'API Error - Affirm checkout could not be processed',
                    'checkout_status' => "Error",
                    'checkout_status_message' => "API Error - Affirm checkout could not be processed",
                    'checkout_action' => true
                ]);
            }

            // Save checkout token
            $payment = $order->getPayment();
            try {
                $payment->setAdditionalInformation('checkout_token', $checkout_token);
                $payment->save();
            } catch (\Exception $e) {
                $this->errorTracker->logErrorToAffirm(
                    transaction_step: 'pre_auth',
                    error_type: ErrorTracker::INTERNAL_SERVER_ERROR,
                    exception: $e,
                    is_telesales: true
                );
                $this->logger->debug($e->getMessage());
            }

            // Update order history comment
            $order->addCommentToStatusHistory('Affirm checkout has been sent to the customer. Checkout token: ' . $checkout_token, false, false);
            $this->logger->debug('Affirm Telesales checkout token sent to customer: ' . $checkout_token);
            try {
                $this->orderRepository->save($order);
            } catch (\Exception $e) {
                $this->errorTracker->logErrorToAffirm(
                    transaction_step: 'pre_auth',
                    error_type: ErrorTracker::INTERNAL_SERVER_ERROR,
                    exception: $e,
                    is_telesales: true
                );
                $this->logger->debug($e->getMessage());
            }
        }
        // Return JSON result
        return $result->setData([
            'success' => $success,
            'message' => $successMessage,
            'checkout_status' => "Application sent",
            'checkout_status_message' => "Waiting for customer to start the application"
        ]);
    }

    /**
     * Initialize order model instance
     *
     * @return \Magento\Sales\Api\Data\OrderInterface|false
     */
    protected function _initOrder()
    {
        $id = $this->getRequest()->getParam('order_id');
        try {
            $order = $this->orderRepository->get($id);
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage(__('This order no longer exists'));
            $this->_actionFlag->set('', self::FLAG_NO_DISPATCH, true);
            return false;
        } catch (InputException $e) {
            $this->messageManager->addErrorMessage(__('This order no longer exists'));
            $this->_actionFlag->set('', self::FLAG_NO_DISPATCH, true);
            return false;
        }
        $this->_coreRegistry->register('sales_order', $order);
        $this->_coreRegistry->register('current_order', $order);
        return $order;
    }

    /**
     * Retrieve order model
     *
     * @return \Magento\Sales\Model\Order
     */
    public function getOrder()
    {
        $_order = $this->_coreRegistry->registry('sales_order');
        return $_order;
    }

    /**
     * Get checkout token
     *
     * @return string
     */
    protected function getPaymentCheckoutToken($order)
    {
        $_token = $order->getPayment()->getAdditionalInformation('checkout_token');
        return $_token;
    }
}
