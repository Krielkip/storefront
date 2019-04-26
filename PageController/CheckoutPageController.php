<?php declare(strict_types=1);

namespace Shopware\Storefront\PageController;

use Shopware\Core\Checkout\Cart\Exception\CartTokenNotFoundException;
use Shopware\Core\Checkout\Cart\Exception\CustomerNotLoggedInException;
use Shopware\Core\Checkout\Cart\Exception\LineItemNotFoundException;
use Shopware\Core\Checkout\Cart\Exception\OrderNotFoundException;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\SalesChannel\AddressService;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Checkout\Promotion\Cart\Builder\PromotionItemBuilder;
use Shopware\Core\Checkout\Promotion\Cart\CartPromotionsCollector;
use Shopware\Core\Framework\Routing\Exception\MissingRequestParameterException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\SalesChannel\SalesChannel\SalesChannelContextSwitcher;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Framework\Controller\StorefrontController;
use Shopware\Storefront\Framework\Page\PageLoaderInterface;
use Shopware\Storefront\Page\Checkout\AddressList\CheckoutAddressListPageLoader;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoader;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoader;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoader;
use Shopware\Storefront\Page\Checkout\Register\CheckoutRegisterPageLoader;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Exception\InvalidParameterException;
use Symfony\Contracts\Translation\TranslatorInterface;

class CheckoutPageController extends StorefrontController
{
    /**
     * @var CartService
     */
    private $cartService;

    /**
     * @var CheckoutCartPageLoader|PageLoaderInterface
     */
    private $cartPageLoader;

    /**
     * @var CheckoutConfirmPageLoader|PageLoaderInterface
     */
    private $confirmPageLoader;

    /**
     * @var CheckoutFinishPageLoader|PageLoaderInterface
     */
    private $finishPageLoader;

    /**
     * @var CheckoutRegisterPageLoader|PageLoaderInterface
     */
    private $registerPageLoader;

    /**
     * @var CheckoutAddressListPageLoader|PageLoaderInterface
     */
    private $addressListPageLoader;

    /**
     * @var PageLoaderInterface
     */
    private $addressPageLoader;

    /**
     * @var OrderService
     */
    private $orderService;

    /**
     * @var AddressService
     */
    private $addressService;

    /**
     * @var SalesChannelContextSwitcher
     */
    private $contextSwitcher;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    public function __construct(
        CartService $cartService,
        PageLoaderInterface $cartPageLoader,
        PageLoaderInterface $confirmPageLoader,
        PageLoaderInterface $finishPageLoader,
        PageLoaderInterface $registerPageLoader,
        PageLoaderInterface $addressListPageLoader,
        PageLoaderInterface $addressPageLoader,
        AddressService $addressService,
        OrderService $orderService,
        SalesChannelContextSwitcher $contextSwitcher,
        TranslatorInterface $translator
    ) {
        $this->cartService = $cartService;
        $this->cartPageLoader = $cartPageLoader;
        $this->confirmPageLoader = $confirmPageLoader;
        $this->finishPageLoader = $finishPageLoader;
        $this->registerPageLoader = $registerPageLoader;
        $this->addressListPageLoader = $addressListPageLoader;
        $this->addressPageLoader = $addressPageLoader;
        $this->orderService = $orderService;
        $this->addressService = $addressService;
        $this->contextSwitcher = $contextSwitcher;
        $this->translator = $translator;
    }

    /**
     * @Route("/checkout", name="frontend.checkout.forward", options={"seo"="false"}, methods={"GET"})
     */
    public function index(): RedirectResponse
    {
        return $this->redirectToRoute('frontend.checkout.cart.page');
    }

    /**
     * @Route("/checkout/cart", name="frontend.checkout.cart.page", options={"seo"="false"}, methods={"GET"})
     *
     * @throws CartTokenNotFoundException
     */
    public function cart(Request $request, SalesChannelContext $context): Response
    {
        $page = $this->cartPageLoader->load($request, $context);

        return $this->renderStorefront('@Storefront/page/checkout/cart/index.html.twig', ['page' => $page]);
    }

    /**
     * @Route("/checkout/confirm", name="frontend.checkout.confirm.page", options={"seo"="false"}, methods={"GET"})
     *
     * @throws CartTokenNotFoundException
     */
    public function confirm(Request $request, SalesChannelContext $context): Response
    {
        if (!$context->getCustomer()) {
            return $this->redirectToRoute('frontend.checkout.register.page');
        }

        if ($this->cartService->getCart($context->getToken(), $context)->getLineItems()->count() === 0) {
            return $this->redirectToRoute('frontend.checkout.cart.page');
        }

        $page = $this->confirmPageLoader->load($request, $context);

        return $this->renderStorefront('@Storefront/page/checkout/confirm/index.html.twig', ['page' => $page]);
    }

    /**
     * @Route("/checkout/finish", name="frontend.checkout.finish.page", options={"seo"="false"}, methods={"GET"})
     *
     * @throws OrderNotFoundException
     * @throws InvalidParameterException
     * @throws MissingRequestParameterException
     */
    public function finish(Request $request, SalesChannelContext $context): Response
    {
        if (!$context->getCustomer()) {
            return $this->redirectToRoute('frontend.checkout.register.page');
        }

        $page = $this->finishPageLoader->load($request, $context);

        return $this->renderStorefront('@Storefront/page/checkout/finish/index.html.twig', ['page' => $page]);
    }

    /**
     * @Route("/checkout/finish", name="frontend.checkout.finish.order", options={"seo"="false"}, methods={"POST"})
     */
    public function finishOrder(RequestDataBag $data, SalesChannelContext $context): Response
    {
        if (!$context->getCustomer()) {
            return $this->redirectToRoute('frontend.checkout.register.page');
        }

        try {
            $orderId = $this->orderService->createOrder($data, $context);

            return new RedirectResponse($this->generateUrl('frontend.checkout.finish.page', [
                'orderId' => $orderId,
            ]));
        } catch (ConstraintViolationException $formViolations) {
        }

        return $this->forward('Shopware\Storefront\PageController\CheckoutPageController::confirm', ['formViolations' => $formViolations]);
    }

    /**
     * @Route("/checkout/register", name="frontend.checkout.register.page", options={"seo"="false"}, methods={"GET"})
     */
    public function register(Request $request, SalesChannelContext $context): Response
    {
        /** @var string $redirect */
        $redirect = $request->get('redirectTo', 'frontend.checkout.confirm.page');

        if ($context->getCustomer()) {
            return $this->redirectToRoute($redirect);
        }

        if ($this->cartService->getCart($context->getToken(), $context)->getLineItems()->count() === 0) {
            return $this->redirectToRoute('frontend.checkout.cart.page');
        }

        $page = $this->registerPageLoader->load($request, $context);

        return $this->renderStorefront('@Storefront/page/checkout/address/index.html.twig', ['redirectTo' => $redirect, 'page' => $page]);
    }

    /**
     * @Route("/checkout/address/create", name="frontend.checkout.address.create.page", options={"seo"="false"}, methods={"GET"})
     *
     * @throws CustomerNotLoggedInException
     */
    public function createAddress(Request $request, SalesChannelContext $context): Response
    {
        $this->denyAccessUnlessLoggedIn();

        $page = $this->addressPageLoader->load($request, $context);

        return $this->renderStorefront('@Storefront/component/address/address-editor.html.twig', ['page' => $page]);
    }

    /**
     * @Route("/checkout/address/{addressId}", name="frontend.checkout.address.edit.page", options={"seo"="false"}, methods={"GET"})
     *
     * @throws CustomerNotLoggedInException
     */
    public function editAddress(Request $request, SalesChannelContext $context): Response
    {
        $this->denyAccessUnlessLoggedIn();

        $page = $this->addressPageLoader->load($request, $context);

        return $this->renderStorefront('@Storefront/component/address/address-editor.html.twig', ['page' => $page]);
    }

    /**
     * @Route("/checkout/address/{addressId}", name="frontend.checkout.address.edit.save", options={"seo"="false"}, methods={"POST"})
     * @Route("/checkout/address/create", name="frontend.checkout.address.create", options={"seo"="false"}, methods={"POST"})
     */
    public function saveAddress(RequestDataBag $data, SalesChannelContext $context): Response
    {
        $this->denyAccessUnlessLoggedIn();

        /** @var RequestDataBag $address */
        $address = $data->get('address');
        try {
            $this->addressService->create($address, $context);

            return new Response();
        } catch (ConstraintViolationException $formViolations) {
        }

        $forwardAction = $address->get('id') ? 'editAddress' : 'createAddress';

        return $this->forward(
            'Shopware\Storefront\PageController\CheckoutPageController::' . $forwardAction,
            ['formViolations' => $formViolations],
            ['addressId' => $address->get('id')]
        );
    }

    /**
     * @Route("/checkout/address", name="frontend.checkout.address.page", options={"seo"="false"}, methods={"GET"})
     */
    public function address(Request $request, SalesChannelContext $context): Response
    {
        $this->denyAccessUnlessLoggedIn();

        $page = $this->addressListPageLoader->load($request, $context);

        return $this->renderStorefront('@Storefront/component/address/address-selection.html.twig', ['page' => $page]);
    }

    /**
     * @Route("/checkout/configure", name="frontend.checkout.configure", options={"seo"="false"}, methods={"POST"})
     */
    public function configure(Request $request, RequestDataBag $data, SalesChannelContext $context): Response
    {
        $route = $request->get('redirectTo', 'frontend.checkout.cart.page');
        $parameters = $request->get('redirectParameters', []);

        //since the keys "redirectTo" and "redirectParameters" are used to configure this action, the shall not be persisted
        $data->remove('redirectTo');
        $data->remove('redirectParameters');

        $this->contextSwitcher->update($data, $context);

        return $this->redirectToRoute($route, $parameters);
    }

    /**
     * @Route("/checkout/line-item/delete/{id}", name="frontend.checkout.line-item.delete", defaults={"XmlHttpRequest": true}, methods={"POST", "DELETE"})
     */
    public function removeLineItem(string $id, Request $request, SalesChannelContext $context): Response
    {
        $token = $request->request->getAlnum('token', $context->getToken());

        $cart = $this->cartService->getCart($token, $context);

        if (!$cart->has($id)) {
            throw new LineItemNotFoundException($id);
        }

        $this->cartService->remove($cart, $id, $context);

        return $this->createActionResponse($request);
    }

    /**
     * @Route("/checkout/promotion/add", name="frontend.checkout.promotion.add", defaults={"XmlHttpRequest": true}, methods={"POST"})
     */
    public function addCode(Request $request, SalesChannelContext $context): Response
    {
        try {
            /** @var string $token */
            $token = $request->request->getAlnum('token', $context->getToken());

            /** @var string|null $code */
            $code = $request->request->getAlnum('code');

            if ($code === null) {
                throw new \Exception('Code is required');
            }
            $itemBuilder = new PromotionItemBuilder(CartPromotionsCollector::LINE_ITEM_TYPE);

            $lineItem = $itemBuilder->buildPlaceholderItem(
                $code,
                $context->getContext()->getCurrencyPrecision()
            );

            $cart = $this->cartService->add($this->cartService->getCart($token, $context), $lineItem, $context);

            if (!$cart->has($lineItem->getKey())) {
                throw new \Exception('code was not added');
            }
            $this->addFlash('success', $this->translator->trans('checkout.codeAddedSuccessful'));
        } catch (\Exception $exception) {
            $this->addFlash('danger', $this->translator->trans('error.message-default'));
        }

        return $this->createActionResponse($request);
    }

    /**
     * @Route("/checkout/line-item/update/{id}", name="frontend.checkout.line-item.update", defaults={"XmlHttpRequest": true}, methods={"POST"})
     */
    public function updateLineItem(string $id, Request $request, SalesChannelContext $context): Response
    {
        $token = $request->request->getAlnum('token', $context->getToken());

        $quantity = $request->get('quantity', null);

        if ($quantity === null) {
            throw new \Exception('quantity field is required');
        }

        $cart = $this->cartService->getCart($token, $context);

        if (!$cart->has($id)) {
            throw new LineItemNotFoundException($id);
        }

        $this->cartService->changeQuantity($cart, $id, (int) $quantity, $context);

        return $this->createActionResponse($request);
    }
}
