<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ReCaptchaReview\Test\Integration\Observer;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\ReCaptcha\Model\CaptchaValidator;
use Magento\ReCaptchaApi\Api\CaptchaValidatorInterface;
use Magento\Review\Model\Review;
use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\App\MutableScopeConfig;
use Magento\TestFramework\TestCase\AbstractController;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test for \Magento\ReCaptchaReview\Observer\ReviewFormObserver class.
 *
 * @magentoDataFixture Magento/Catalog/_files/product_virtual.php
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ReviewFormObserverTest extends AbstractController
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
        $this->captchaValidatorMock = $this->createMock(CaptchaValidatorInterface::class);
        $this->_objectManager->addSharedInstance($this->captchaValidatorMock, CaptchaValidator::class);
    }

    public function testReCaptchaNotConfigured()
    {
        $this->initConfig(1, null, null);
        $this->sendReviewProductPostRequest(true);
    }

    public function testReCaptchaDisabled()
    {
        $this->initConfig(0, 'test_public_key', 'test_private_key');
        $this->sendReviewProductPostRequest(true);
    }

    public function testCorrectRecaptcha()
    {
        $this->initConfig(1, 'test_public_key', 'test_private_key');
        $this->captchaValidatorMock->expects($this->once())->method('validate')->willReturn(true);
        $this->sendReviewProductPostRequest(true);
    }

    public function testIncorrectRecaptcha()
    {
        $this->initConfig(1, 'test_public_key', 'test_private_key');
        $this->captchaValidatorMock->expects($this->once())->method('validate')->willReturn(false);
        $this->sendReviewProductPostRequest(false);
    }

    public function testErrorValidatingRecaptcha()
    {
        $this->initConfig(1, 'test_public_key', 'test_private_key');
        $exception = new LocalizedException(__('error_message'));
        $this->captchaValidatorMock->expects($this->once())->method('validate')->willThrowException($exception);
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('error_message');
        $this->sendReviewProductPostRequest(false);
    }

    /**
     * @param bool $reviewCreated
     * @throws LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function sendReviewProductPostRequest(bool $reviewCreated)
    {
        $productSku = 'virtual-product';
        $product = $this->productRepository->get($productSku);
        $initialReviewsCount = $this->review->getTotalReviews($product->getId());

        $this->getRequest()->setMethod('POST');
        $this->getRequest()->setParam('id', $product->getId());
        $this->getRequest()->setPostValue(
            [
                'nickname' => 'review_author',
                'title' => 'review_title',
                'detail' => 'review_detail',
                'form_key' => $this->formKey->getFormKey(),
                'g-recaptcha-response' => 'test_response'
            ]
        );

        $this->dispatch('review/product/post');

        $code = $this->response->getHttpResponseCode();
        $this->assertEquals(
            302,
            $code,
            'Incorrect response code'
        );

        $reviewsCount = $this->review->getTotalReviews($product->getId());
        $this->assertEquals(
            $reviewCreated,
            $initialReviewsCount !== $reviewsCount
        );
    }

    /**
     * @param int|null $enabled
     * @param string|null $public
     * @param string|null $private
     */
    private function initConfig(?int $enabled, ?string $public, ?string $private): void
    {
        $this->mutableScopeConfig->setValue('recaptcha/frontend/enabled_for_product_review', $enabled, ScopeInterface::SCOPE_WEBSITE);
        $this->mutableScopeConfig->setValue('recaptcha/frontend/public_key', $public, ScopeInterface::SCOPE_WEBSITE);
        $this->mutableScopeConfig->setValue('recaptcha/frontend/private_key', $private, ScopeInterface::SCOPE_WEBSITE);
    }
}
