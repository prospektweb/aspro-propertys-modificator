<?php

namespace Prospektweb\PropModificator;

use Bitrix\Main\Loader;
use Prospektweb\PropModificator\Domain\DTO\CalcPriceRequest;
use Prospektweb\PropModificator\Infrastructure\Http\RequestInput;

class AjaxController
{
    public function __construct(
        private ?RequestParser $requestParser = null,
        private ?PricingService $pricingService = null,
        private ?MainPriceResolver $mainPriceResolver = null,
        private ?ResponseFactory $responseFactory = null,
    ) {
        $this->requestParser = $this->requestParser ?? new RequestParser();
        $this->pricingService = $this->pricingService ?? new PricingService();
        $this->mainPriceResolver = $this->mainPriceResolver ?? new MainPriceResolver();
        $this->responseFactory = $this->responseFactory ?? new ResponseFactory();
    }

    public static function calcPrice(): array
    {
        return (new self())->calcPriceFromInput(RequestInput::fromGlobals());
    }

    public function calcPriceFromInput(RequestInput $input): array
    {
        if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
            return $this->responseFactory->error('Required modules not loaded', 'MODULES_NOT_LOADED');
        }

        $parsed = $this->requestParser->parseDtoFromInput($input);
        if (!$parsed['ok']) {
            return $this->responseFactory->error(
                (string)($parsed['error'] ?? 'Request parse failed'),
                (string)($parsed['errorCode'] ?? 'REQUEST_PARSE_ERROR')
            );
        }

        /** @var CalcPriceRequest $request */
        $request = $parsed['dto'];
        $pricing = $this->pricingService->calculateDto($request);
        if (!$pricing->ok) {
            return $this->responseFactory->error((string)$pricing->error, 'PRICE_CALC_FAILED');
        }

        $mainPrice = $this->mainPriceResolver->resolve(
            $pricing->rawPrices,
            $pricing->rangePrices,
            $pricing->catalogGroups,
            $pricing->accessibleGroupIds,
            $request->basketQty,
            $request->visibleGroups,
            $request->activeGroupId
        );

        return $this->responseFactory->success($pricing, $mainPrice, $request);
    }
}
