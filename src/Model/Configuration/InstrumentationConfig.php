<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Model\Configuration;

use InvalidArgumentException;

/**
 * @phpstan-import-type InstrumentationConfigArray from BaseInstrumentationConfig as BaseInstrumentationConfigArray
 * @phpstan-import-type HttpServerInstrumentationConfigArray from HttpServerInstrumentationConfig
 * @phpstan-import-type MessengerInstrumentationConfigArray from MessengerInstrumentationConfig
 * @phpstan-import-type DoctrineInstrumentationConfigArray from DoctrineInstrumentationConfig
 * @phpstan-import-type EventsInstrumentationConfigArray from EventsInstrumentationConfig
 * @phpstan-type InstrumentationConfigArray array{
 *     http_server: HttpServerInstrumentationConfigArray,
 *     messenger: MessengerInstrumentationConfigArray,
 *     console: BaseInstrumentationConfigArray,
 *     traceable: BaseInstrumentationConfigArray,
 *     twig: BaseInstrumentationConfigArray,
 *     cache: BaseInstrumentationConfigArray,
 *     doctrine: DoctrineInstrumentationConfigArray,
 *     redis: BaseInstrumentationConfigArray,
 *     mailer: BaseInstrumentationConfigArray,
 *     events: EventsInstrumentationConfigArray,
 *     async: BaseInstrumentationConfigArray,
 *     http_client: BaseInstrumentationConfigArray
 * }
 */

final readonly class InstrumentationConfig
{
    public function __construct(
        public HttpServerInstrumentationConfig $httpServer,
        public MessengerInstrumentationConfig $messenger,
        public BaseInstrumentationConfig $console,
        public BaseInstrumentationConfig $traceable,
        public BaseInstrumentationConfig $twig,
        public BaseInstrumentationConfig $cache,
        public DoctrineInstrumentationConfig $doctrine,
        public BaseInstrumentationConfig $redis,
        public BaseInstrumentationConfig $mailer,
        public EventsInstrumentationConfig $events,
        public BaseInstrumentationConfig $async,
        public BaseInstrumentationConfig $httpClient
    ) {}

    /**
     * @phpstan-param InstrumentationConfigArray $config
     */
    public static function fromConfig(array $config): self
    {
        $httpServer = $config['http_server'];
        $messenger = $config['messenger'];
        $console = $config['console'];
        $traceable = $config['traceable'];
        $twig = $config['twig'];
        $cache = $config['cache'];
        $doctrine = $config['doctrine'];
        $redis = $config['redis'];
        $mailer = $config['mailer'];
        $events = $config['events'];
        $async = $config['async'];
        $httpClient = $config['http_client'];

        return new self(
            httpServer: HttpServerInstrumentationConfig::fromConfig($httpServer),
            messenger: MessengerInstrumentationConfig::fromConfig($messenger),
            console: BaseInstrumentationConfig::fromConfig($console),
            traceable: BaseInstrumentationConfig::fromConfig($traceable),
            twig: BaseInstrumentationConfig::fromConfig($twig),
            cache: BaseInstrumentationConfig::fromConfig($cache),
            doctrine: DoctrineInstrumentationConfig::fromConfig($doctrine),
            redis: BaseInstrumentationConfig::fromConfig($redis),
            mailer: BaseInstrumentationConfig::fromConfig($mailer),
            events: EventsInstrumentationConfig::fromConfig($events),
            async: BaseInstrumentationConfig::fromConfig($async),
            httpClient: BaseInstrumentationConfig::fromConfig($httpClient),
        );
    }

    public function getByKey(string $key): BaseInstrumentationConfig
    {
        return match ($key) {
            'http_server' => $this->httpServer,
            'messenger' => $this->messenger,
            'console' => $this->console,
            'traceable' => $this->traceable,
            'twig' => $this->twig,
            'cache' => $this->cache,
            'doctrine' => $this->doctrine,
            'redis' => $this->redis,
            'mailer' => $this->mailer,
            'events' => $this->events,
            'async' => $this->async,
            'http_client' => $this->httpClient,
            default => throw new InvalidArgumentException(sprintf('Unknown instrumentation key: "%s".', $key))
        };
    }
}
