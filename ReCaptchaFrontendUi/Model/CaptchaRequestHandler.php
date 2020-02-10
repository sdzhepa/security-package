<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ReCaptchaFrontendUi\Model;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\HttpInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\ReCaptcha\Model\ValidateInterface;

/**
 * @inheritdoc
 */
class CaptchaRequestHandler implements CaptchaRequestHandlerInterface
{
    /**
     * @var ValidateInterface
     */
    private $validate;

    /**
     * @var RemoteAddress
     */
    private $remoteAddress;

    /**
     * @var MessageManagerInterface
     */
    private $messageManager;

    /**
     * @var ActionFlag
     */
    private $actionFlag;

    /**
     * @var FrontendConfigInterface
     */
    private $reCaptchaFrontendConfig;

    /**
     * @param ValidateInterface $validate
     * @param RemoteAddress $remoteAddress
     * @param MessageManagerInterface $messageManager
     * @param ActionFlag $actionFlag
     * @param FrontendConfigInterface $reCaptchaFrontendConfig
     */
    public function __construct(
        ValidateInterface $validate,
        RemoteAddress $remoteAddress,
        MessageManagerInterface $messageManager,
        ActionFlag $actionFlag,
        FrontendConfigInterface $reCaptchaFrontendConfig
    ) {
        $this->validate = $validate;
        $this->remoteAddress = $remoteAddress;
        $this->messageManager = $messageManager;
        $this->actionFlag = $actionFlag;
        $this->reCaptchaFrontendConfig = $reCaptchaFrontendConfig;
    }

    /**
     * @inheritdoc
     */
    public function execute(
        RequestInterface $request,
        HttpInterface $response,
        string $redirectOnFailureUrl
    ): void {
        $reCaptchaResponse = $request->getParam(ValidateInterface::PARAM_RECAPTCHA_RESPONSE);
        $remoteIp = $this->remoteAddress->getRemoteAddress();
        $options['threshold'] = $this->reCaptchaFrontendConfig->getMinScore();

        if (false === $this->validate->validate($reCaptchaResponse, $remoteIp, $options)) {
            $this->messageManager->addErrorMessage($this->reCaptchaFrontendConfig->getErrorDescription());
            $this->actionFlag->set('', Action::FLAG_NO_DISPATCH, true);

            $response->setRedirect($redirectOnFailureUrl);
        }
    }
}
