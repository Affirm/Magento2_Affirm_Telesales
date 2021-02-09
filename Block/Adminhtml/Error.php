<?php

namespace Affirm\Telesales\Block\Adminhtml;

class Error extends \Magento\Framework\View\Element\Template
{
    /**
     * Fixed url for Affirm Telesales Documentation.
     */
    const TELESALES_DOC_URL = "https://www.affirm.com/docs";

    /**
     * Retrieve Affirm Telesales Doc URL
     *
     * @return string
     */
    public function getTelesalesDocUrl()
    {
        return self::TELESALES_DOC_URL;
    }
}
