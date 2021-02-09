<?php
namespace Affirm\Telesales\Block\Payment;

class Cancel extends \Magento\Framework\View\Element\Template
{
  protected $_postFactory;

    public function __construct(
        \Magento\Backend\Block\Template\Context $context
    )
    {
        parent::__construct($context);
    }

    public function getTest()
    {
        return 'test';
    }

    public function getPostCollection()
    {
        $post = $this->_postFactory->create();
        return $post->getCollection();
    }
}
?>
