<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\NotifierApi\Model;

use Magento\NotifierApi\Model\AdapterEngine\AdapterEngineInterface;
use Magento\NotifierApi\Model\AdapterEngine\AdapterValidatorInterface;
use Magento\NotifierApi\Api\AdapterInterface;

class Adapter implements AdapterInterface
{
    /**
     * @var AdapterEngineInterface
     */
    private $engine;

    /**
     * @var string
     */
    private $code;

    /**
     * @var string
     */
    private $description;

    /**
     * @var AdapterValidatorInterface
     */
    private $adapterValidator;

    /**
     * @var array
     */
    private $template_mapping;

    /**
     * Adapter constructor.
     * @param AdapterEngineInterface $engine
     * @param AdapterValidatorInterface $adapterValidator
     * @param string $code
     * @param string $description
     * @param array $template_mapping
     */
    public function __construct(
        AdapterEngineInterface $engine,
        AdapterValidatorInterface $adapterValidator,
        string $code,
        string $description,
        array $template_mapping = []
    ) {
        $this->engine = $engine;
        $this->adapterValidator = $adapterValidator;
        $this->code = $code;
        $this->description = $description;
        $this->template_mapping = $template_mapping;
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * @inheritdoc
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @return array
     */
    public function getTemplateMapping(): array
    {
        return $this->template_mapping;
    }

    /**
     * @inheritdoc
     */
    public function sendMessage(string $message, array $configParams = [], array $params = []): bool
    {
        $message = trim($message);
        $this->validateMessage($message);
        $this->validateParams($configParams);

        return $this->engine->execute($message, $configParams, $params);
    }

    /**
     * @inheritdoc
     */
    public function validateMessage(string $message): bool
    {
        $message = trim($message);
        return $this->adapterValidator->validateMessage($message);
    }

    /**
     * @inheritdoc
     */
    public function validateParams(array $params = []): bool
    {
        return $this->adapterValidator->validateParams($params);
    }
}
