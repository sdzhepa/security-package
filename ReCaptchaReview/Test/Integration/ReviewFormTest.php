<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ReCaptchaReview\Test\Integration;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\MessageInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\Validation\ValidationResult;
use Magento\ReCaptchaUi\Model\CaptchaResponseResolverInterface;
use Magento\ReCaptchaValidation\Model\Validator;
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
     * @var ValidationResult|MockObject
     */
    private $captchaValidationResultMock;

    /**
     * @inheritDoc
     */
    protected function setUp()
    {
        parent::setUp();
        $this->mutableScopeConfig = $this->_objectManager->get(MutableScopeConfig::class);
        $this->formKey = $this->_objectManager->get(FormKey::class);
        $this->review = $this->_objectManager->get(Review::class);
        $this->productRepository = $this->_objectManager->get(ProductRepositoryInterface::class);
        $this->url = $this->_objectManager->get(UrlInterface::class);
        $this->captchaValidationResultMock = $this->createMock(ValidationResult::class);
        $captchaValidatorMock = $this->createMock(Validator::class);
        $captchaValidatorMock->expects($this->any())
            ->method('isValid')
            ->willReturn($this->captchaValidationResultMock);
        $this->_objectManager->addSharedInstance($captchaValidatorMock, Validator::class);
    }

    public function testGetRequestIfReCaptchaIsDisabled()
    {
        $this->initConfig(0, 'test_public_key', 'test_private_key');

        $this->checkSuccessfulGetResponse();
    }

    /**
     * @magentoConfigFixture default_store recaptcha_frontend/type_for/product_review invisible
     */
    public function testGetRequestIfReCaptchaKeysAreNotConfigured()
    {
        $this->initConfig(1, null, null);

        $this->checkSuccessfulGetResponse();
    }

    /**
     * @magentoConfigFixture default_store recaptcha_frontend/type_for/product_review invisible
     */
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
        $this->captchaValidationResultMock->expects($this->once())->method('isValid')->willReturn(true);

        $this->checkSuccessfulPostResponse(
            true,
            [CaptchaResponseResolverInterface::PARAM_RECAPTCHA => 'test_response']
        );
    }

    public function testPostRequestWithFailedReCaptchaValidation()
    {
        $this->initConfig(1, 'test_public_key', 'test_private_key');
        $this->captchaValidationResultMock->expects($this->once())->method('isValid')->willReturn(false);

        $this->checkSuccessfulPostResponse(
            false,
            [CaptchaResponseResolverInterface::PARAM_RECAPTCHA => 'test_response']
        );
    }

    public function testPostRequestIfReCaptchaParameterIsMissed()
    {
        $this->initConfig(1, 'test_public_key', 'test_private_key');
        $this->captchaValidationResultMock->expects($this->never())->method('isValid');
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
                self::equalTo(['reCAPTCHA verification failed']),
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
        $this->mutableScopeConfig->setValue('recaptcha_frontend/type_for/newsletter', null, ScopeInterface::SCOPE_WEBSITE);
        $this->mutableScopeConfig->setValue('recaptcha_frontend/type_for/product_review', $enabled ? 'invisible' : null, ScopeInterface::SCOPE_WEBSITE);
        $this->mutableScopeConfig->setValue('recaptcha_frontend/type_invisible/public_key', $public, ScopeInterface::SCOPE_WEBSITE);
        $this->mutableScopeConfig->setValue('recaptcha_frontend/type_invisible/private_key', $private, ScopeInterface::SCOPE_WEBSITE);
    }
}
