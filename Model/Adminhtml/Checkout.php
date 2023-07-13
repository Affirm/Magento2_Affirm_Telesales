<?php

namespace Affirm\Telesales\Model\Adminhtml;

use Affirm\Telesales\Model\Config as ConfigAffirmTelesales;
use Astound\Affirm\Gateway\Helper\Util;
use Astound\Affirm\Model\Config as ConfigAffirm;
use Astound\Affirm\Model\Ui\ConfigProvider;
use Magento\Catalog\Model\ProductRepository;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Laminas\Http\Client;
use Magento\Framework\Model\Context;
use Magento\Framework\Module\ResourceInterface;
use Magento\Framework\Registry;
use Magento\Framework\Url as Url;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class Checkout extends \Magento\Framework\Model\AbstractModel
{
    /**#@+
     * Define constants
     */
    const API_CHECKOUT_TELESALES_PATH = '/api/v2/checkout/telesales';
    const API_CHECKOUT_RESEND_PATH = '/api/v2/checkout/resend';
    const API_CHECKOUT_READ_PATH = '/api/v2/checkout/';
    const API_TRANSACTIONS_PATH = '/api/v1/transactions/';
    const CHECKOUT_MODE = 'telesales';
    const PLATFORM_TYPE_APPEND = ' 2';
    const METHOD_GET = 'GET';
    const METHOD_POST = 'POST';
    const AFFIRM_TELESALES = 'affirm_telesales';
    const CURRENCY_CODE_CAD = 'CAD';
    const CURRENCY_CODE_USD = 'USD';
    const COUNTRY_CODE_CAN = 'CAN';
    const COUNTRY_CODE_USA = 'USA';
    const COUNTRY_SUFFIX_CA = '_ca';
    /**#@-*/

    public function __construct(
        Context $context,
        Registry $coreRegistry,
        Client $httpClientFactory,
        JsonFactory $resultJsonFactory,
        OrderRepositoryInterface $orderRepository,
        OrderStatusHistoryInterface $orderStatusRepository,
        ProductRepository $productRepository,
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
        $this->productRepository = $productRepository;
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
     * @param $currencyCode
     * @return \Http_Response|null
     */
    public function sendCheckout($data, $currencyCode = null)
    {
        $send_checkout_url = $this->getApiUrl(self::API_CHECKOUT_TELESALES_PATH);
        return $this->_apiRequestClient($send_checkout_url, $data, false, self::METHOD_POST, $currencyCode);
    }

    /**
     * @param $checkout_id
     * @param $currencyCode
     */
    public function resendCheckout($checkout_id, $currencyCode = null)
    {
        $resend_checkout_url = $this->getApiUrl(self::API_CHECKOUT_RESEND_PATH);
        $data = ['checkout_id' => $checkout_id];
        return $this->_apiRequestClient($resend_checkout_url, $data, true, self::METHOD_POST, $currencyCode);
    }

    /**
     * @param $checkout_id
     * @param $currencyCode
     */
    public function readCheckout($checkout_id, $currencyCode = null)
    {
        $read_checkout_url = $this->getApiUrl(self::API_CHECKOUT_READ_PATH);
        return $this->_apiRequestClient($read_checkout_url . $checkout_id, null, true, self::METHOD_GET, $currencyCode);
    }

    /**
     * @param $transaction_id
     * @param $currency_code
     */
    public function readTransaction($transaction_id, $currency_code = null)
    {
        $read_charge_url = $this->getApiUrl(self::API_TRANSACTIONS_PATH);
        return $this->_apiRequestClient($read_charge_url . $transaction_id, null, true, self::METHOD_GET, $currency_code);
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
            return false;
        }

        try {
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

            $_items = [];
            foreach ($order->getAllItems() as $item) {
                $product = $this->productRepository->getById($item->getProductId());
                $_items[] = [
                    'display_name' => $item['name'],
                    'sku' => $item['sku'],
                    'unit_price' => intval($item['price'] * 100),
                    'qty' => intval($item->getQtyOrdered()),
                    'item_url' => $product->getProductUrl(),
                    'item_image_url' => $product->getThumbnail(),
                ];
            }

            // Prepare Affirm Checkout Data
            $shippingAmount = $order->getShippingAmount();
            $taxAmount = $order->getTaxAmount();
            $total = $order->getGrandTotal();
            $currency_code = $order->getOrderCurrencyCode();
            $data = [
                'shipping' => $shippingObject,
                'billing' => $billingObject,
                'merchant' => [
                    'user_confirmation_url' => $this->urlHelper->getUrl('telesales/payment/success'),
                    'user_cancel_url' => $this->urlHelper->getUrl('telesales/payment/cancel'),
                    'user_decline_url' => $this->urlHelper->getUrl('telesales/payment/decline'),
                    'user_confirmation_url_action' => self::METHOD_GET,
                    'public_api_key' => $this->getPublicApiKey($currency_code)
                ],
                'metadata' => [
                    'platform_type' => $this->productMetadata->getName() . self::PLATFORM_TYPE_APPEND,
                    'platform_version' => $this->productMetadata->getVersion() . ' ' . $this->productMetadata->getEdition(),
                    'platform_affirm' => 'affirm_telesales_' . $this->moduleResource->getDbVersion('Affirm_Telesales'),
                    'mode' => self::CHECKOUT_MODE
                ],
                'items' => $_items,
                'order_id' => $order->getIncrementId(),
                'shipping_amount' => Util::formatToCents($shippingAmount),
                'tax_amount'=> Util::formatToCents($taxAmount),
                'total'=> Util::formatToCents($total)
            ];
        } catch (\Exception $e) {
            $this->_logger->debug($e->getMessage());
            return false;
        }

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
    protected function getPrivateApiKey($currency_code = null)
    {
        $country_suffix = isset($currency_code) ? $this->getCountrySuffixByCurrency($currency_code) : '';
        return $this->scopeConfig->getValue('payment/affirm_gateway/mode') == 'sandbox'
            ? $this->scopeConfig->getValue('payment/affirm_gateway/private_api_key_sandbox' . $country_suffix)
            : $this->scopeConfig->getValue('payment/affirm_gateway/private_api_key_production' . $country_suffix);
    }

    /**
     * Get public API key
     *
     * @return string
     */
    protected function getPublicApiKey($currency_code = null)
    {
        $country_suffix = isset($currency_code) ? $this->getCountrySuffixByCurrency($currency_code) : '';
        return $this->scopeConfig->getValue('payment/affirm_gateway/mode') == 'sandbox'
            ? $this->scopeConfig->getValue('payment/affirm_gateway/public_api_key_sandbox' . $country_suffix)
            : $this->scopeConfig->getValue('payment/affirm_gateway/public_api_key_production' . $country_suffix);
    }

    /**
     * Send Affirm checkout API request
     *
     * @param $url
     * @param null $data
     * @param bool $requireKeys
     * @param string $method
     * @param string|null $currencyCode
     * @return \Http_Response|null
     */
    protected function _apiRequestClient($url, $data = null, bool $requireKeys = false, string $method = self::METHOD_POST, string $currencyCode = null)
    {
        try {
            $client = $this->httpClientFactory;
            $client->setUri($url);
            $headers = $client->getRequest()->getHeaders();
            if ($currencyCode) {
                $countryCode = $this->getCountryCodeByCurrency($currencyCode);
            }
            if (isset($countryCode)) {
                $headers->addHeaderLine('Country-Code', $countryCode);
            }
            if ($requireKeys) {
                $client->setAuth($this->getPublicApiKey($currencyCode), $this->getPrivateApiKey($currencyCode));
            }
            if ($data) {
                $dataEncoded = json_encode($data, JSON_UNESCAPED_SLASHES);
                $client->setEncType('application/json');
                $client->setRawBody($dataEncoded);;
            }
            $client->setMethod($method);
            $response = $client->send();
            return $response;
        } catch (\Exception $e) {
            return $this->_logger->debug($e->getMessage());
        }
    }

    /**
     * Map currency to country code
     *
     * @param string $currency_code
     * @return string
     */
    protected function getCountryCodeByCurrency(string $currency_code): string
    {
        $currencyCodeToCountryCode = [
            self::CURRENCY_CODE_CAD => self::COUNTRY_CODE_CAN,
            self::CURRENCY_CODE_USD => self::COUNTRY_CODE_USA,
        ];

        return $currencyCodeToCountryCode[$currency_code] ?? '';
    }

    /**
     * Map currency to country suffix
     *
     * @param string $currency_code
     * @return string
     */
    protected function getCountrySuffixByCurrency(string $currency_code): string
    {
        $currencyCodeToSuffix = [
            self::CURRENCY_CODE_CAD => self::COUNTRY_SUFFIX_CA,
            self::CURRENCY_CODE_USD => '',
        ];

        return $currencyCodeToSuffix[$currency_code] ?? '';
    }
}
