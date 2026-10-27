<?php declare(strict_types=1);

namespace Nova\Dezimal\Controller;

use Nova\Dezimal\Service\DezimalPayload;
use Nova\Dezimal\Service\DezimalSettingsReader;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Wert einer Dezimal-Position im Warenkorb ändern (z. B. 2,5 m² → 3 m²).
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class DezimalCartController extends StorefrontController
{
    public function __construct(private readonly CartService $cartService)
    {
    }

    #[Route(path: '/nova-dezimal/line-item/{id}', name: 'frontend.nova_dezimal.line_item.update', defaults: ['XmlHttpRequest' => true], methods: [Request::METHOD_POST])]
    public function update(Cart $cart, string $id, Request $request, SalesChannelContext $context): Response
    {
        $lineItem = $cart->get($id);
        $value = DezimalSettingsReader::float($request->request->get('novaDezimalWert'));

        if ($lineItem === null || $value === null || $value <= 0 || !DezimalPayload::has($lineItem)) {
            $this->addFlash(self::DANGER, $this->trans('error.message-default'));

            return $this->createActionResponse($request);
        }

        $payload = $lineItem->getPayloadValue(DezimalPayload::KEY);
        $payload = \is_array($payload) ? $payload : [];
        $payload['wert'] = $value;
        $lineItem->setPayloadValue(DezimalPayload::KEY, $payload);
        $cart->markModified();

        $cart = $this->cartService->recalculate($cart, $context);
        // Hinweise (z. B. „auf Mindestwert angehoben“) anzeigen, sonst die normale Erfolgsmeldung
        if ($cart->getErrors()->count() > 0) {
            $this->addCartErrors($cart);
        } else {
            $this->addFlash(self::SUCCESS, $this->trans('checkout.cartUpdateSuccess'));
        }

        return $this->createActionResponse($request);
    }
}
