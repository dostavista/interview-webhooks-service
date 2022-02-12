<?php

namespace Borzo;

use Borzo\Dostawizard\Mvc\ControllerAbstract;
use Borzo\Dostawizard\Mvc\Views\Simple404NotFoundView;

abstract class WebhooksControllerAbstract extends ControllerAbstract {
    /**
     * Порт в контейнере 1990, devtools.borzo.com
     * Это хост, куда ходят наши сотрудники браузерами.
     * Запросы на этот хост завёрнуты через SAML аутентификацию.
     */
    public const HOST_FOR_BROWSERS = 1;

    /**
     * Порт в контейнере 1991, externalwebhooks.borzo.net
     * Это хост для вебхуков от чужих сервисов,
     * например гитхаб шлёт сюда уведомления о коммитах и пулл-реквестах.
     * Запросы сюда приходят из интернета!
     */
    public const HOST_FOR_EXTERNAL_API = 2;

    /**
     * Порт в контейнере 1992, internalwebhooks.borzo.net
     * Это хост для запросов во внутренней сети Борзо (только через VPN).
     * Здесь нет SAML аутентификации.
     * Сюда должны приходить запросы не из браузеров, а из других наших сервисов.
     */
    public const HOST_FOR_INTERNAL_API = 3;

    /**
     * У нас тут необычная конструкция: контейнер обслуживает три разных хоста.
     * На некоторых хостах недоступны некоторые контроллеры.
     * Поэтому каждый контроллер должен объявить, на каких хостах он доступен.
     * @return int[]
     */
    abstract protected function getAllowedHostTypes(): array;
}
