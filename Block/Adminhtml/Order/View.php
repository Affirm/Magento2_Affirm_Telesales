<?php
namespace Affirm\Telesales\Block\Adminhtml\Order;

use Affirm\Telesales\Model\Adminhtml\Checkout;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Url;
use Magento\Sales\Api\OrderManagementInterface as OrderManagement;

/**
 * Class View
 * @package Affirm\Telesales\Block\Adminhtml\Order
 */
class View extends \Magento\Backend\Block\Template
{
    /**#@+
     * Define constants
     */
    const TELESALES_CHECKOUT_ENDPOINT = 'affirm_telesales/checkout';
    const TELESALES_AUTH_ENDPOINT = 'affirm_telesales/auth';
    const TELESALES_CONFIRM_ENDPOINT = 'telesales/payment/confirm';
    const STATUS_OPENED = 'opened';
    const STATUS_APPROVED = 'approved';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_AUTHORIZED = 'authorized';
    const STATUS_NOT_APPROVED = 'not_approved';
    const STATUS_MORE_INFORMATION_NEEDED = 'more_information_needed';
    const STATUS_UNKNOWN = 'unknown';
    const STATUS_VOIDED = 'voided';
    const ORDER_ID = 'order_id';
    const CHECKOUT_TOKEN = 'checkout_token';
    const TRANSACTION_ID = 'transaction_id';

    /**
     * Core registry
     *
     * @var \Magento\Framework\Registry
     */
    protected $_coreRegistry = null;

    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Sales\Helper\Admin $adminHelper,
        \Laminas\Http\Client $httpClientFactory,
        \Astound\Affirm\Model\Config $affirmConfig,
        Checkout $affirmCheckout,
        OrderManagement $orderManagement,
        Url $urlHelper
    ) {
        parent::__construct($context);
        $this->_coreRegistry = $registry;
        $this->adminHelper = $adminHelper;
        $this->httpClientFactory = $httpClientFactory;
        $this->affirmConfig = $affirmConfig;
        $this->affirmCheckout = $affirmCheckout;
        $this->orderManagement = $orderManagement;
        $this->urlHelper = $urlHelper;
        $this->_logger = $context->getLogger();
    }

    /**
     * Send Checkout Button
     *
     * @return string
     *
     **/
    public function getSendCheckoutButtonHtml()
    {
        // Send or Resend
        $checkout_token = $this->getPaymentCheckoutToken();
        $label = $checkout_token ? 'Re-send Affirm Checkout Link' : 'Send Affirm Checkout Link';

        $button = $this->getLayout()->createBlock(
            'Magento\Backend\Block\Widget\Button'
        )->setData(
            [
                'id' => 'send_checkout_button',
                'class' => 'action-secondary',
                'label' => __($label),
                'display' => false
            ]
        );

        return $button->toHtml();
    }

    /**
     * Refresh/Auth Button
     *
     * @return string
     * @throws LocalizedException
     */
    public function getSendAuthButtonHtml()
    {
        $label = 'Refresh';

        $button = $this->getLayout()->createBlock(
            'Magento\Backend\Block\Widget\Button'
        )->setData(
            [
                'id' => 'send_auth_button',
                'class' => 'action-secondary',
                'label' => __($label),
                'display' => false
            ]
        );

        return $button->toHtml();
    }

    /**
     * Is Affirm payment method
     *
     * @return bool
     */
    public function isAffirmPaymentMethod()
    {
        $order = $this->getOrder();
        return $this->affirmCheckout->isAffirmPaymentMethod($order);
    }

    /**
     * Return ajax url for send Affirm checkout button
     *
     * @return string
     */
    public function getAjaxUrl()
    {
        return $this->getUrl(self::TELESALES_CHECKOUT_ENDPOINT, [self::ORDER_ID => $this->getOrder()->getId()]);
    }

    /**
     * Return ajax url for send Affirm auth button
     *
     * @return string
     */
    public function getAjaxUrlAuth()
    {
        return $this->getUrl(self::TELESALES_AUTH_ENDPOINT, [self::ORDER_ID => $this->getOrder()->getId()]);
    }

    /**
     * Return ajax url for send Affirm auth button
     *
     * @return string
     */
    public function getAjaxUrlConfirm()
    {
        return $this->urlHelper->getUrl(self::TELESALES_CONFIRM_ENDPOINT);
    }

    /**
     * Get stat uses
     *
     * @return array
     */
    public function getStatuses()
    {
        $state = $this->getOrder()->getState();
        $statuses = $this->getOrder()->getConfig()->getStateStatuses($state);
        return $statuses;
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
     * Get order currency code
     *
     * @return float|string
     */
    public function getCurrencyCode(): float|string
    {
        $_order = $this->getOrder();
        return $_order->getOrderCurrencyCode();
    }

    /**
     * Get checkout token
     *
     * @return string
     */
    public function getPaymentCheckoutToken()
    {
        $order = $this->getOrder() ?: null;
        return $order ? $order->getPayment()->getAdditionalInformation(self::CHECKOUT_TOKEN) : null;
    }

    /**
     * Get transaction id
     *
     * @return string
     */
    public function getTransactionId()
    {
        $order = $this->getOrder() ?: null;
        return $order ? $order->getPayment()->getAdditionalInformation(self::TRANSACTION_ID) : null;
    }

    /**
     * GET checkout read API
     *
     * @return array
     */
    public function getReadCheckoutAPI()
    {
        // Check order state and return null to skip Telesales template rendering
        $order = $this->getOrder();
        if (!$order) {
            return null;
        }

        switch ($order->getState()) {
            case \Magento\Sales\Model\Order::STATE_CANCELED:
            case \Magento\Sales\Model\Order::STATE_CLOSED:
            case \Magento\Sales\Model\Order::STATE_COMPLETE:
                return null;
        }

        $result = [];
        $result['checkout_action'] = null;

        // Check checkout token exists and display action
        $checkout_token = $this->getPaymentCheckoutToken();
        if (!$checkout_token) {
            $result['checkout_status'] = "Not sent";
            $result['checkout_status_message'] = "Send checkout link to customer";
            $result['checkout_action'] = true;
            return $result;
        }

        // Get order currency code
        $currencyCode = $this->getCurrencyCode();
        $readCheckoutResponse = $this->affirmCheckout->readCheckout($checkout_token, $currencyCode);
        if (isset($readCheckoutResponse)) {
            $responseStatus = $readCheckoutResponse->getStatusCode();
            $responseBody = json_decode($readCheckoutResponse->getBody(), true);
            $checkout_status = $responseBody['checkout_status'] ?? null;
        } else {
            return null;
        }
        // Read transaction endpoint if transaction id exists
        $transaction_id = $this->getTransactionId();
        if ($transaction_id) {
            $readTransactionResponse = $this->affirmCheckout->readTransaction($transaction_id, $currencyCode);
            $responseBody = $readTransactionResponse ? json_decode($readTransactionResponse->getBody(), true) : null;
            $transaction_status = $responseBody ? $responseBody['status'] : null;
            if ($transaction_status == self::STATUS_AUTHORIZED) {
                $checkout_status = $transaction_status;
            } elseif ($transaction_status && $transaction_status != self::STATUS_AUTHORIZED) {
                // return null if there is no further action for checkout status update
                return null;
            }
        }

        if ((isset($responseStatus) && $responseStatus > 200) || !isset($checkout_status)) {
            $this->_logger->debug('Affirm_Telesales__readCheckout_status_code: ', [$responseStatus]);
            $this->_logger->debug('Affirm_Telesales__readCheckout_response_body: ', [$responseBody]);
            $result['checkout_status'] = 'Error';
            $result['checkout_status_message'] = $readCheckoutResponse->getBody();
            $result['checkout_action'] = true;
        } else {
            switch ($checkout_status) {
                case self::STATUS_OPENED:
                    $result['checkout_status']  = "Application started";
                    $result['checkout_status_message'] = "Customer's application has begun. They must create an account or log in, get approved, then select and confirm their loan terms";
                    $result['checkout_action'] = true;
                    break;
                case self::STATUS_APPROVED:
                    $result['checkout_status']  = "Loan approved";
                    $result['checkout_status_message'] = "Customer's application is approved. Waiting on customer to select their loan terms and confirm the loan terms";
                    break;
                case self::STATUS_CONFIRMED:
                    $result['checkout_status']  = "Loan completed";
                    $result['checkout_status_message'] = "Customer's application is done. Waiting on merchant to authorize the loan";
                    $result['checkout_action'] = true;
                    break;
                case self::STATUS_AUTHORIZED:
                    $result['checkout_status']  = "Payment authorized";
                    $result['checkout_status_message'] = "Payment is complete. Ready to finalize order";
                    break;
                case self::STATUS_MORE_INFORMATION_NEEDED:
                    $result['checkout_status']  = "Pending loan approval";
                    $result['checkout_status_message'] = "Waiting for loan approval";
                    break;
                case self::STATUS_UNKNOWN:
                    $result['checkout_status']  = "Application sent";
                    $result['checkout_status_message'] = "Waiting for customer to start the application";
                    $result['checkout_action'] = true;
                    break;
                case self::STATUS_NOT_APPROVED:
                case self::STATUS_VOIDED:
                    $result['checkout_status']  = "Checkout voided";
                    $result['checkout_status_message'] = "Checkout token has been voided";
                    $this->cancelOrderWithComment($result['checkout_status_message']);
                    break;
                default:
                    $result['checkout_status']  = "Application sent";
                    $result['checkout_status_message'] = "Waiting for customer to start the application";
                    $result['checkout_action'] = true;
                    break;
            }
        }

        return $result;
    }

    /**
     * Replace links in string
     *
     * @param array|string $data
     * @param null|array $allowedTags
     * @return string
     */
    public function escapeHtml($data, $allowedTags = null)
    {
        return $this->adminHelper->escapeHtmlWithLinks($data, $allowedTags);
    }

    /**
     * Cancel the order with comment
     *
     * @param null|string $comment
     * @return null
     */
    private function cancelOrderWithComment($comment = null)
    {
        $_order = $this->getOrder();
        $_order->setState(\Magento\Sales\Model\Order::STATE_CANCELED)
            ->setStatus(\Magento\Sales\Model\Order::STATE_CANCELED);
        $_order->addCommentToStatusHistory($comment);
        $_order->save();
        return;
    }
}
