<?php
namespace Affirm\Telesales\Block\Payment;

/**
 * Success constructor.
 * @param \Magento\Backend\Block\Template\Context $context
 *
 */
class Success extends \Magento\Framework\View\Element\Template
{
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context
    ) {
        parent::__construct($context);
    }
}
