<?php
namespace Affirm\Telesales\Model\Plugin\Payment;

use Astound\Affirm\Model\Ui\ConfigProvider;
use Affirm\Telesales\Model\Config as ConfigAffirmTelesales;
use Magento\Payment\Model\Method\Adapter as PaymentAdapter;
// use Magento\Sales\Model\Order\Payment\Info;
// use Magento\Sales\Api\Data\OrderPaymentInterface;
// use \Magento\Framework\Controller\Result\RedirectFactory;
// use \Magento\Backend\Model\Session\Quote as QuoteSession;

/**
 * Class Adapter
 *
 * @package Affirm\Telesales\Model\Plugin\Payment
 */
class Adapter
{
    /**
     * @var string
     */
    protected $_eventPrefix = 'sales_order_payment';

    /**
     * @var \Magento\Backend\Model\Auth\Session
     */
    private $authSession;

    /**
     * @var \Affirm\Telesales\Model\Config
     */
    private $configTelesales;

    public function __construct(
        \Affirm\Telesales\Model\Config $config,
        \Magento\Backend\Model\Auth\Session $authSession,
    )
    {
        $this->authSession = $authSession;
        $this->configTelesales = $config;
    }

    public function afterGetConfigPaymentAction(PaymentAdapter $subject, $result)
    {
        $info = $subject->getInfoInstance();
        $_order = $info->getOrder();
        $paymentMethod = $_order->getPayment()->getMethod();
        $eventPrefix = $info->getEventPrefix();

        $_isAdmin = null;

        if ($eventPrefix === $this->_eventPrefix
            && $paymentMethod === ConfigProvider::CODE
            && $this->authSession->isLoggedIn()
        ) {
            // get user type and set true if admin
            $_authSessionUser = $this->authSession->getUser();
            $_userType = $_authSessionUser ? $_authSessionUser->getRole()->getUserType() : null;
            $_isAdmin = (\Magento\Authorization\Model\UserContextInterface::USER_TYPE_ADMIN === (int)$_userType);
        }

        // If admin then override value from PaymentAdapter->getConfigPaymentAction
        if ($_isAdmin) {
            $_order
                ->getPayment()
                ->setAdditionalInformation(
                    \Affirm\Telesales\Model\Adminhtml\Checkout::AFFIRM_TELESALES,
                    true
                );
            return null;
        } else {
            return $result;
        }
    }
}
