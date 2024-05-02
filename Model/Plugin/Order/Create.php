<?php

namespace Affirm\Telesales\Model\Plugin\Order;

use \Magento\Sales\Controller\Adminhtml\Order\Create\Save as SaveAction;
use \Magento\Framework\Controller\Result\RedirectFactory;
use \Affirm\Telesales\Model\Config;

class Create
{
    /**
     * Result redirect factory
     *
     * @var \Magento\Framework\Controller\Result\RedirectFactory
     */
    protected $forwardRedirectFactory;

    /**
     * Result redirect factory
     *
     * @var \Affirm\Telesales\Model\Config;
     */
    protected $config;

    /**
     * Inject redirect factory
     *
     * @param RedirectFactory $forwardFactory
     */
    public function __construct(
        RedirectFactory $forwardFactory,
        Config $config
    ) {
        $this->forwardRedirectFactory = $forwardFactory;
        $this->config = $config;
    }

    /**
     * Plugin for save order new order in admin
     *
     * @param SaveAction $controller
     * @param callable   $method
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function aroundExecute(SaveAction $controller, \Closure $method)
    {
        $data = $controller->getRequest()->getParam('payment');
        // If telesales is enabled, then skip this redirect logic and instead return the order save action
        if (!$this->config->getActive() && isset($data['method']) && $data['method'] == \Astound\Affirm\Model\Ui\ConfigProvider::CODE) {
            $resultRedirect = $this->forwardRedirectFactory->create();
            $resultRedirect->setPath('affirm_telesales/error');
            return $resultRedirect;
        }
        return $method();
    }

}
