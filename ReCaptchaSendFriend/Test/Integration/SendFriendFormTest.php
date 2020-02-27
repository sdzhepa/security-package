<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ReCaptchaSendFriend\Test\Integration;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\MessageInterface;
use Magento\Framework\UrlInterface;
use Magento\ReCaptcha\Model\CaptchaValidator;
use Magento\ReCaptchaApi\Api\CaptchaValidatorInterface;
use Magento\ReCaptchaUi\Model\CaptchaResponseResolverInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\App\MutableScopeConfig;
use Magento\TestFramework\Mail\Template\TransportBuilderMock;
use Magento\TestFramework\TestCase\AbstractController;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test for \Magento\ReCaptchaSendFriend\Observer\SendFriendObserverTest class.
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 * @magentoDataFixture Magento/Catalog/_files/products.php
 */
class SendFriendFormTest extends AbstractController
{
    /**
     * @var MutableScopeConfig
     */
    private $mutableScopeConfig;

    /**
     * @var FormKey
     */
    private $formKey;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var UrlInterface
     */
    private $url;

    /**
     * @var TransportBuilderMock
     */
    private $transportMock;

    /**
     * @var CaptchaValidatorInterface|MockObject
     */
    private $captchaValidatorMock;

    /**
     * @inheritDoc
     */
    protected function setUp()
    {
        parent::setUp();
        $this->mutableScopeConfig = $this->_objectManager->get(MutableScopeConfig::class);
        $this->formKey = $this->_objectManager->get(FormKey::class);
        $this->productRepository = $this->_objectManager->get(ProductRepositoryInterface::class);
        $this->url = $this->_objectManager->get(UrlInterface::class);
        $this->transportMock = $this->_objectManager->get(TransportBuilderMock::class);
        $this->captchaValidatorMock = $this->createMock(CaptchaValidatorInterface::class);
        $this->_objectManager->addSharedInstance($this->captchaValidatorMock, CaptchaValidator::class);
    }

    /**
     * @magentoConfigFixture default_store sendfriend/email/enabled 1
     * @magentoConfigFixture default_store sendfriend/email/allow_guest 1
     */
    public function testGetRequestIfReCaptchaIsDisabled()
    {
        $this->initConfig(0, 'test_public_key', 'test_private_key');

        $this->checkSuccessfulGetResponse();
    }

    /**
     * @magentoConfigFixture default_store sendfriend/email/enabled 1
     * @magentoConfigFixture default_store sendfriend/email/allow_guest 1
     */
    public function testGetRequestIfReCaptchaKeysAreNotConfigured()
    {
        $this->initConfig(1, null, null);

        $this->checkSuccessfulGetResponse();
    }

    /**
     * @magentoConfigFixture default_store sendfriend/email/enabled 1
     * @magentoConfigFixture default_store sendfriend/email/allow_guest 1
     */
    public function testGetRequestIfReCaptchaIsEnabled()
    {
        $this->initConfig(1, 'test_public_key', 'test_private_key');

        $this->checkSuccessfulGetResponse(true);
    }

    /**
     * @magentoConfigFixture default_store sendfriend/email/enabled 1
     * @magentoConfigFixture default_store sendfriend/email/allow_guest 1
     */
    public function testPostRequestIfReCaptchaKeysAreNotConfigured()
    {
        $this->initConfig(1, null, null);

        $this->checkSuccessfulPostResponse(true);
    }

    /**
     * @magentoConfigFixture default_store sendfriend/email/enabled 1
     * @magentoConfigFixture default_store sendfriend/email/allow_guest 1
     */
    public function testPostRequestIfReCaptchaIsDisabled()
    {
        $this->initConfig(0, 'test_public_key', 'test_private_key');

        $this->checkSuccessfulPostResponse(true);
    }

    /**
     * @magentoConfigFixture default_store sendfriend/email/enabled 1
     * @magentoConfigFixture default_store sendfriend/email/allow_guest 1
     */
    public function testPostRequestWithSuccessfulReCaptchaValidation()
    {
        $this->initConfig(1, 'test_public_key', 'test_private_key');
        $this->captchaValidatorMock->expects($this->once())->method('isValid')->willReturn(true);

        $this->checkSuccessfulPostResponse(
            true,
            [CaptchaResponseResolverInterface::PARAM_RECAPTCHA => 'test_response']
        );
    }

    /**
     * @magentoConfigFixture default_store sendfriend/email/enabled 1
     * @magentoConfigFixture default_store sendfriend/email/allow_guest 1
     */
    public function testPostRequestWithFailedReCaptchaValidation()
    {
        $this->initConfig(1, 'test_public_key', 'test_private_key');
        $this->captchaValidatorMock->expects($this->once())->method('isValid')->willReturn(false);

        $this->checkSuccessfulPostResponse(
            false,
            [CaptchaResponseResolverInterface::PARAM_RECAPTCHA => 'test_response']
        );
    }

    /**
     * @magentoConfigFixture default_store sendfriend/email/enabled 1
     * @magentoConfigFixture default_store sendfriend/email/allow_guest 1
     */
    public function testPostRequestIfReCaptchaParameterIsMissed()
    {
        $this->initConfig(1, 'test_public_key', 'test_private_key');
        $this->captchaValidatorMock->expects($this->never())->method('isValid');
        $this->expectException(InputException::class);
        $this->expectExceptionMessage('Can not resolve reCAPTCHA parameter.');

        $this->checkSuccessfulPostResponse(
            false
        );
    }

    /**
     * @param bool $shouldContainReCaptcha
     */
    private function checkSuccessfulGetResponse($shouldContainReCaptcha = false)
    {
        $product = $this->productRepository->get('simple');
        $this->dispatch('sendfriend/product/send/id/' . $product->getId());
        $content = $this->getResponse()->getBody();

        self::assertNotEmpty($content);

        $shouldContainReCaptcha
            ? $this->assertContains('field-recaptcha', $content)
            : $this->assertNotContains('field-recaptcha', $content);

        self::assertEmpty($this->getSessionMessages(MessageInterface::TYPE_ERROR));
    }

    /**
     * @param bool $result
     * @param array $postValues
     * @throws LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function checkSuccessfulPostResponse(bool $result, array $postValues = [])
    {
        $product = $this->productRepository->get('simple');
        $this->getRequest()->setMethod('POST');
        $this->getRequest()->setPostValue(
            array_replace_recursive(
                [
                    'sender' => [
                        'name' => 'Sender',
                        'email' => 'sender@example.com',
                        'message' => 'Message',
                    ],
                    'recipients' => [
                        'name' => [
                            'Recipient'
                        ],
                        'email' => [
                            'recipient@example.com'
                        ]
                    ],
                    'form_key' => $this->formKey->getFormKey(),
                ],
                $postValues
            )
        );

        $this->dispatch('sendfriend/product/sendmail/id/' . $product->getId());

        if ($result) {
            $this->assertRedirect($this->stringEndsWith($this->prepareProductUrl($product)));
            $this->assertSessionMessages(
                $this->equalTo(['The link to a friend was sent.']),
                MessageInterface::TYPE_SUCCESS
            );
            self::assertEmpty($this->getSessionMessages(MessageInterface::TYPE_ERROR));
            $message = $this->transportMock->getSentMessage();
            self::assertNotEmpty($message);
            self::assertEquals((string)__('Welcome, Recipient'), $message->getSubject());
        } else {
            $this->assertRedirect(self::equalTo($this->url->getRouteUrl()));
            $this->assertSessionMessages(
                self::equalTo(['You cannot proceed with such operation, your reCAPTCHA reputation is too low.']),
                MessageInterface::TYPE_ERROR
            );
            self::assertEmpty($this->transportMock->getSentMessage());
        }
    }

    /**
     * @param ProductInterface $product
     * @return string
     */
    private function prepareProductUrl(ProductInterface $product): string
    {
        return '/' . $product->getUrlKey() . '.html';
    }

    /**
     * @param int|null $enabled
     * @param string|null $public
     * @param string|null $private
     */
    private function initConfig(?int $enabled, ?string $public, ?string $private): void
    {
        $this->mutableScopeConfig->setValue('recaptcha/frontend/type', 'invisible', ScopeInterface::SCOPE_WEBSITE);
        $this->mutableScopeConfig->setValue('recaptcha/frontend/enabled_for_newsletter', 0, ScopeInterface::SCOPE_WEBSITE);
        $this->mutableScopeConfig->setValue('recaptcha/frontend/enabled_for_sendfriend', $enabled, ScopeInterface::SCOPE_WEBSITE);
        $this->mutableScopeConfig->setValue('recaptcha/frontend/public_key', $public, ScopeInterface::SCOPE_WEBSITE);
        $this->mutableScopeConfig->setValue('recaptcha/frontend/private_key', $private, ScopeInterface::SCOPE_WEBSITE);
    }
}
