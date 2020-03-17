<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\TwoFactorAuth\Controller\Adminhtml\Tfa;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\TwoFactorAuth\Api\ProviderInterface;
use Magento\TwoFactorAuth\Api\TfaInterface;
use Magento\TwoFactorAuth\Controller\Adminhtml\AbstractAction;
use Magento\TwoFactorAuth\Model\UserConfig\HtmlAreaTokenVerifier;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Config\ConfigResource\ConfigInterface as ConfigResource;
use Magento\Framework\App\Config\ReinitableConfigInterface as ConfigInterface;

/**
 * Configure 2FA for the application.
 */
class ConfigureLater extends AbstractAction implements HttpPostActionInterface
{
    const ADMIN_RESOURCE = 'Magento_TwoFactorAuth::config';

    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @var ConfigResource
     */
    private $configResource;

    /**
     * @var TfaInterface
     */
    private $tfa;

    /**
     * @var HtmlAreaTokenVerifier
     */
    private $tokenVerifier;

    /**
     * @var string
     */
    private $startUpUrl;

    /**
     * @var Session
     */
    private $session;

    /**
     * @param Context $context
     * @param ConfigInterface $config
     * @param ConfigResource $configResource
     * @param TfaInterface $tfa
     * @param HtmlAreaTokenVerifier $tokenVerifier
     * @param Session $session
     */
    public function __construct(
        Context $context,
        ConfigInterface $config,
        ConfigResource $configResource,
        TfaInterface $tfa,
        HtmlAreaTokenVerifier $tokenVerifier,
        Session $session
    ) {
        parent::__construct($context);
        $this->startUpUrl = $context->getBackendUrl()->getStartupPageUrl();
        $this->config = $config;
        $this->configResource = $configResource;
        $this->tfa = $tfa;
        $this->tokenVerifier = $tokenVerifier;
        $this->session = $session;
    }

    /**
     * @inheritDoc
     */
    protected function _isAllowed()
    {
        $forced = $this->tfa->getForcedProviders();
        $toActivate = $this->tfa->getProvidersToActivate((int)$this->session->getUser()->getId());

        if (count($toActivate) < count($forced)) {
            return parent::_isAllowed();
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute()
    {
        $provider = $this->getRequest()->getParam('provider');

        if (empty($provider)) {
            throw new \InvalidArgumentException('Invalid provider');
        }

        $forced = $this->tfa->getUserProviders((int)$this->session->getUser()->getId());
        $providerCodes = [];
        foreach ($forced as $forcedProvider) {
            $providerCodes[] = $forcedProvider->getCode();
        }

        if (!in_array($provider, $providerCodes)) {
            throw new \InvalidArgumentException('This provider is not eligible for configuration');
        }

        $currentlySkipped = $this->session->getData('tfa_skipped_config') ?? [];
        $currentlySkipped[$provider] = true;
        $this->session->setTfaSkippedConfig($currentlySkipped);

        $redirect = $this->resultRedirectFactory->create();
        return $redirect->setPath('tfa/tfa/index');
    }
}