<?php

namespace Affirm\Telesales\Model\Adminhtml;

use Astound\Affirm\Gateway\Helper\Util;
use Astound\Affirm\Model\Config as ConfigAffirm;
use Astound\Affirm\Model\Ui\ConfigProvider;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\ZendClientFactory;
use Magento\Framework\Model\Context;
use Magento\Framework\Module\ResourceInterface;
use Magento\Framework\Registry;
use Magento\Framework\Url as Url;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Affirm\Telesales\Model\Config as ConfigAffirmTelesales;

class Checkout extends \Magento\Framework\Model\AbstractModel
{
    /**#@+
     * Define constants
     */
    const API_STORE_CHECKOUT_PATH = '/api/v2/checkout/store';
    const API_STORE_RESEND_PATH = '/api/v2/checkout/resend';
    const API_STORE_READ_PATH = '/api/v2/checkout/';
    const API_CHARGES_PATH = '/api/v2/charges/';
    const CHECKOUT_FLOW_TYPE = 'In-Store';
    const PLATFORM_TYPE_APPEND = ' 2';
    const METHOD_GET = 'GET';
    const METHOD_POST = 'POST';
    const AFFIRM_TELESALES = 'affirm_telesales';
    /**#@-*/

    public function __construct(
        Context $context,
        Registry $coreRegistry,
        ZendClientFactory $httpClientFactory,
        JsonFactory $resultJsonFactory,
        OrderRepositoryInterface $orderRepository,
        OrderStatusHistoryInterface $orderStatusRepository,
        ProductMetadataInterface $productMetadata,
        ResourceInterface $moduleResource,
        ScopeConfigInterface $scopeConfig,
        Url $urlHelper,
        ConfigAffirm $configAffirm,
        ConfigAffirmTelesales $configAffirmTelesales
    ) {
        $this->_coreRegistry = $coreRegistry;
        $this->httpClientFactory = $httpClientFactory;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->orderRepository = $orderRepository;
        $this->orderStatusRepository = $orderStatusRepository;
        $this->productMetadata = $productMetadata;
        $this->moduleResource = $moduleResource;
        $this->scopeConfig = $scopeConfig;
        $this->urlHelper = $urlHelper;
        $this->affirmConfig = $configAffirm;
        $this->telesalesConfig = $configAffirmTelesales;
        $this->_logger = $context->getLogger();
        parent::__construct($context, $coreRegistry);
    }

    /**
     * @param $data
     * @return string
     */
    public function sendCheckout($data)
    {
        $send_checkout_url = $this->getApiUrl(self::API_STORE_CHECKOUT_PATH);
        return $this->_apiRequestClient($send_checkout_url, $data);
    }

    /**
     * @param $checkout_id
     * @return string
     */
    public function resendCheckout($checkout_id)
    {
        $resend_checkout_url = $this->getApiUrl(self::API_STORE_RESEND_PATH);
        $data = ['checkout_id' => $checkout_id];
        return $this->_apiRequestClient($resend_checkout_url, $data, true);
    }

    /**
     * @param $checkout_id
     * @return string
     */
    public function readCheckout($checkout_id)
    {
        $read_checkout_url = $this->getApiUrl(self::API_STORE_READ_PATH);
        return $this->_apiRequestClient($read_checkout_url . $checkout_id, null, true, self::METHOD_GET);
    }

    /**
     * @param $charge_id
     * @return string
     */
    public function readCharge($charge_id)
    {
        $read_charge_url = $this->getApiUrl(self::API_CHARGES_PATH);
        return $this->_apiRequestClient($read_charge_url . $charge_id, null, true, self::METHOD_GET);
    }

    /**
     * Generates checkout object with cart info
     *
     * @param $order
     * @return array|false
     */
    public function getCheckoutObject($order)
    {
        if (!$this->isAffirmPaymentMethod($order)) {
            return false; // TODO: Exception handling
        }

        $shippingAddress = $order->getShippingAddress();
        $shippingObject = [
            'name' => [
                'full_name' => $shippingAddress->getName(),
                'first' => $shippingAddress->getFirstname(),
                'last' => $shippingAddress->getLastname()
            ],
            'address'=>[
                'line1' => $shippingAddress->getStreetLine(1),
                'line2' => empty($shippingAddress->getStreetLine(2)) ? null : $shippingAddress->getStreetLine(2),
                'city' => $shippingAddress->getCity(),
                'state' => empty($shippingAddress->getRegionCode()) ? $this->getRegionCode($shippingAddress->getRegionId()) : $shippingAddress->getRegionCode(),
                'zipcode' => $shippingAddress->getPostcode(),
                'country' => $shippingAddress->getCountryId()
            ],
            'email' => $shippingAddress->getEmail(),
            'phone_number' => $shippingAddress->getTelephone()
        ];

        $billingAddress = $order->getBillingAddress();
        $billingObject = [
            'name' => [
                'full_name' => $billingAddress->getName(),
                'first' => $billingAddress->getFirstname(),
                'last' => $billingAddress->getLastname()
            ],
            'address'=>[
                'line1' => $billingAddress->getStreetLine(1),
                'line2' => empty($billingAddress->getStreetLine(2)) ? null : $billingAddress->getStreetLine(2),
                'city' => $billingAddress->getCity(),
                'state' => empty($billingAddress->getRegionCode()) ? $this->getRegionCode($billingAddress->getRegionId()) : $billingAddress->getRegionCode(),
                'zipcode' => $billingAddress->getPostcode(),
                'country' => $billingAddress->getCountryId()
            ],
            'email' => $billingAddress->getEmail(),
            'phone_number' => $billingAddress->getTelephone()
        ];

        // Prepare Affirm Checkout Data
        $shippingAmount = $order->getShippingAmount();
        $taxAmount = $order->getTaxAmount();
        $total = $order->getGrandTotal();
        $data = [
            'shipping' => $shippingObject,
            'billing' => $billingObject,
            'merchant' => [
                'user_confirmation_url' => $this->urlHelper->getUrl('telesales/payment/confirm'),
                'user_cancel_url' => $this->urlHelper->getUrl('telesales/payment/cancel'),
                'user_decline_url' => $this->urlHelper->getUrl('telesales/payment/decline'),
                'user_confirmation_url_action' => self::METHOD_POST,
                'public_api_key' => $this->getPublicApiKey()
            ],
            'metadata' => [
                'platform_type' => $this->productMetadata->getName() . self::PLATFORM_TYPE_APPEND,
                'platform_version' => $this->productMetadata->getVersion() . ' ' . $this->productMetadata->getEdition(),
                'platform_affirm' => $this->moduleResource->getDbVersion('Astound_Affirm'),
                'mode' => self::CHECKOUT_FLOW_TYPE
            ],
            'order_id' => $order->getIncrementId(),
            'shipping_amount' => Util::formatToCents($shippingAmount),
            'tax_amount'=> Util::formatToCents($taxAmount),
            'total'=> Util::formatToCents($total)
        ];

        return $data;
    }

    /**
     * Get checkout token
     *
     * @return string
     */
    public function getPaymentCheckoutToken($order)
    {
        return $order->getPayment()->getAdditionalInformation('checkout_token');
    }

    /**
     * Is Affirm payment method
     *
     * @param $order
     * @return bool
     */
    public function isAffirmPaymentMethod($order)
    {
        $_isTelesales = $order->getPayment()->getAdditionalInformation(self::AFFIRM_TELESALES);
        return $_isTelesales && $order->getId() && $order->getPayment()->getMethod() == ConfigProvider::CODE;
    }

    /**
     * Is Affirm Telesales active
     *
     * @param null
     * @return bool
     */
    public function isAffirmTelesalesActive()
    {
        $result = $this->telesalesConfig->getActive();
        return $result;
    }

    /**
     * Get API url
     *
     * @param string $additionalPath
     * @return string
     */
    protected function getApiUrl($additionalPath)
    {
        $gateway = $this->scopeConfig->getValue('payment/affirm_gateway/mode') == 'sandbox'
            ? ConfigAffirm::API_URL_SANDBOX
            : ConfigAffirm::API_URL_PRODUCTION;

        $result = trim($gateway, '/') . sprintf('%s', $additionalPath);
        return $result;
    }

    /**
     * Get private API key
     *
     * @return string
     */
    protected function getPrivateApiKey()
    {
        return $this->scopeConfig->getValue('payment/affirm_gateway/mode') == 'sandbox'
            ? $this->scopeConfig->getValue('payment/affirm_gateway/private_api_key_sandbox')
            : $this->scopeConfig->getValue('payment/affirm_gateway/private_api_key_production');
    }

    /**
     * Get public API key
     *
     * @return string
     */
    protected function getPublicApiKey()
    {
        return $this->scopeConfig->getValue('payment/affirm_gateway/mode') == 'sandbox'
            ? $this->scopeConfig->getValue('payment/affirm_gateway/public_api_key_sandbox')
            : $this->scopeConfig->getValue('payment/affirm_gateway/public_api_key_production');
    }

    /**
     * Send Affirm checkout API request
     *
     * @return string
     */
    protected function _apiRequestClient($url, $data = null, $requireKeys = false, $method = self::METHOD_POST)
    {
        try {
            $client = $this->httpClientFactory->create();
            $client->setUri($url);
            if ($requireKeys) {
                $client->setAuth($this->getPublicApiKey(), $this->getPrivateApiKey());
            }
            if ($data) {
                $dataEncoded = json_encode($data, JSON_UNESCAPED_SLASHES);
                $client->setRawData($dataEncoded, 'application/json');
            }
            $response = $client->request($method);
            return $response;
        } catch (\Exception $e) {
            return $this->_logger->debug($e->getMessage());
        }
    }

}
