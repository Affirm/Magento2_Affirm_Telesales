<?php
namespace Affirm\Telesales\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Cancel extends Action
{
    protected $_pageFactory;

    public function __construct(
        Context $context,
        PageFactory $pageFactory
    )   {
        $this->_pageFactory = $pageFactory;
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute()
    {
        $messages = $this->messageManager->getMessages();
        if ($messages) {
            $this->messageManager->addErrorMessage($messages);
        }
        return $this->_pageFactory->create();
    }
}
