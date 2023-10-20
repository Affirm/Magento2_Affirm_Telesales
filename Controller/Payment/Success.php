<?php
namespace Affirm\Telesales\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Success extends Action
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
        $messagesItems = array();
        foreach ($this->messageManager->getMessages()->getItems() as $item) {
            $messagesItems[] = $item->getText();
        }
        if ($messagesItems) {
            $_messages = implode('; ', $messagesItems);
            $this->messageManager->addError($_messages);
        }
        return $this->_pageFactory->create();
    }
}
