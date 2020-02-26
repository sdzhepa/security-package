<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ReCaptchaReview\Test\Integration;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Message\MessageInterface;
use Magento\Framework\Url\EncoderInterface;
use Magento\Framework\UrlInterface;
use Magento\ReCaptcha\Model\CaptchaValidator;
use Magento\ReCaptchaApi\Api\CaptchaValidatorInterface;
use Magento\ReCaptchaUi\Model\CaptchaResponseResolverInterface;
use Magento\Review\Model\Review;
use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\App\MutableScopeConfig;
use Magento\TestFramework\TestCase\AbstractController;
use PHPUnit\Framework\MockObject\MockObject;
use Zend\Stdlib\Parameters;

/**
 * Test for \Magento\ReCaptchaReview\Observer\ReviewFormObserver class.
 *
 * @magentoDataFixture Magento/Catalog/_files/product_virtual.php
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ReviewFormTest extends AbstractController
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
     * @var ManagerInterface
     */
    private $messageManager;

    /**
     * @var EncoderInterface
     */
    private $urlEncoder;

    /**
     * @var UrlInterface
     */
    private $url;

    /**
     * @var Review
     */
    private $review;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var ResponseInterface
     */
    private $response;

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
        $this->response = $this->_objectManager->get(ResponseInterface::class);
        $this->review = $this->_objectManager->get(Review::class);
        $this->productRepository = $this->_objectManager->get(ProductRepositoryInterface::class);
        $this->messageManager = $this->_objectManager->get(ManagerInterface::class);
        $this->urlEncoder = $this->_objectManager->get(EncoderInterface::class);
        $this->url = $this->_objectManager->get(UrlInterface::class);
        $this->captchaValidatorMock = $this->createMock(CaptchaValidatorInterface::class);
        $this->_objectManager->addSharedInstance($this->captchaValidatorMock, CaptchaValidator::class);
    }

    public function testGetRequestIfReCaptchaIsDisabled()
    {
        $this->initConfig(0, 'test_public_key', 'test_private_key');

        $this->checkSuccessfulGetResponse();
    }

    public function testGetRequestIfReCaptchaKeysAreNotConfigured()
    {
        $this->initConfig(1, null, null);

        $this->checkSuccessfulGetResponse();
    }

    public function testGetRequestIfReCaptchaIsEnabled()
    {
        $this->initConfig(1, 'test_public_key', 'test_private_key');

        $this->checkSuccessfulGetResponse(true);
    }

    public function testPostRequestIfReCaptchaKeysAreNotConfigured()
    {
        $this->initConfig(1, null, null);

        $this->checkSuccessfulPostResponse(true);
    }

    public function testPostRequestIfReCaptchaIsDisabled()
    {
        $this->initConfig(0, 'test_public_key', 'test_private_key');

        $this->checkSuccessfulPostResponse(true);
    }

    public function testPostRequestWithSuccessfulReCaptchaValidation()
    {
        $this->initConfig(1, 'test_public_key', 'test_private_key');
        $this->captchaValidatorMock->expects($this->once())->method('isValid')->willReturn(true);

        $this->checkSuccessfulPostResponse(
            true,
            [CaptchaResponseResolverInterface::PARAM_RECAPTCHA => 'test_response']
        );
    }

    public function testPostRequestWithFailedReCaptchaValidation()
    {
        $this->initConfig(1, 'test_public_key', 'test_private_key');
        $this->captchaValidatorMock->expects($this->once())->method('isValid')->willReturn(false);

        $this->checkSuccessfulPostResponse(
            false,
            [CaptchaResponseResolverInterface::PARAM_RECAPTCHA => 'test_response']
        );
    }

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
     * @param bool $reviewCreated
     * @param array $postValues
     * @throws LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function checkSuccessfulPostResponse(bool $reviewCreated, array $postValues = [])
    {
        $productSku = 'virtual-product';
        $product = $this->productRepository->get($productSku);
        $initialReviewsCount = $this->review->getTotalReviews($product->getId());

        $this->getRequest()->setMethod('POST');
        $this->getRequest()->setServer(
            new Parameters(['HTTP_REFERER' => $this->url->getRouteUrl($this->prepareProductUrl($product))])
        );
        $this->getRequest()->setParam('id', $product->getId());
        $this->getRequest()->setPostValue(
            array_replace_recursive(
                [
                    'nickname' => 'review_author',
                    'title' => 'review_title',
                    'detail' => 'review_detail',
                    'form_key' => $this->formKey->getFormKey()
                ],
                $postValues
            )
        );

        $this->dispatch('review/product/post');

        $this->assertRedirect(self::equalTo($this->url->getRouteUrl($this->prepareProductUrl($product))));

        $reviewsCount = $this->review->getTotalReviews($product->getId());

        if ($reviewCreated) {
            $this->assertNotEquals(
                $initialReviewsCount,
                $reviewsCount
            );
        } else {
            $this->assertEquals(
                $initialReviewsCount,
                $reviewsCount
            );
            $this->assertSessionMessages(
                self::equalTo(['You cannot proceed with such operation, your reCAPTCHA reputation is too low.']),
                MessageInterface::TYPE_ERROR
            );
        }

    }

    /**
     * @param bool $shouldContainReCaptcha
     */
    private function checkSuccessfulGetResponse($shouldContainReCaptcha = false)
    {
        $productSku = 'virtual-product';
        $product = $this->productRepository->get($productSku);

        $this->dispatch($this->prepareProductUrl($product));
        $content = $this->getResponse()->getBody();

        self::assertNotEmpty($content);

        $shouldContainReCaptcha
            ? $this->assertContains('field-recaptcha', $content)
            : $this->assertNotContains('field-recaptcha', $content);

        self::assertEmpty($this->getSessionMessages(MessageInterface::TYPE_ERROR));
    }

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
        $this->mutableScopeConfig->setValue('recaptcha/frontend/enabled_for_product_review', $enabled, ScopeInterface::SCOPE_WEBSITE);
        $this->mutableScopeConfig->setValue('recaptcha/frontend/public_key', $public, ScopeInterface::SCOPE_WEBSITE);
        $this->mutableScopeConfig->setValue('recaptcha/frontend/private_key', $private, ScopeInterface::SCOPE_WEBSITE);
    }
}
