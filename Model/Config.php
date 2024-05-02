<?php
namespace Affirm\Telesales\Model;

use Magento\Payment\Model\Method\ConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Payment\Block\ConfigurableInfo;


class Config extends ConfigurableInfo
{
    const KEY_TELESALES_ACTIVE = 'telesales_active';

    /**
     * Payment code
     *
     * @var string
     */
    protected $methodCode = 'affirm_gateway';

    /**
     * Telesales extension code
     *
     * @var string
     */
    protected $extensionCode = 'affirm_telesales';

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    public function __construct(
        ScopeConfigInterface $scopeConfig
    )
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Get config data
     *
     * @param        $field
     * @param null   $id
     * @param string $scope
     * @return mixed
     */
    public function getConfigData($field, $id = null, $scope = ScopeInterface::SCOPE_STORE)
    {
        if ($this->methodCode) {
            $path = 'payment/' . $this->methodCode . '/' . $this->extensionCode . '/' . $field;
            $res = $this->scopeConfig->getValue($path, $scope, $id);
            return $res;
        }
        return false;
    }

    /**
     * Get payment method active field
     *
     * @return mixed
     */
    public function getActive()
    {
        $result = $this->getValue(self::KEY_TELESALES_ACTIVE);
        return $result;
    }

    /**
     * @inheritDoc
     */
    public function setMethodCode($methodCode)
    {
        $this->methodCode = $methodCode;
    }

    /**
     * @inheritDoc
     */
    public function setPathPattern($pathPattern)
    {
        $this->pathPattern = $pathPattern;
    }

    /**
     * Returns payment configuration value
     *
     * @param string $key
     * @param null   $storeId
     * @return mixed
     */
    public function getValue($key, $storeId = null)
    {
        $underscored = strtolower(preg_replace('/(.)([A-Z])/', "$1_$2", $key));
        $path = $this->_getSpecificConfigPath($underscored);
        if ($path !== null) {
            $value = $this->scopeConfig->getValue(
                $path,
                ScopeInterface::SCOPE_WEBSITE
            );
            return $value;
        }
        return false;
    }

    /**
     * Map payment method into a config path by specified field name
     *
     * @param string $fieldName
     * @return string|null
     */
    protected function _getSpecificConfigPath($fieldName)
    {
        return "payment/{$this->methodCode}/{$this->extensionCode}/{$fieldName}";
    }
}
