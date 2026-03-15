<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Twig;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Twig\Interfaces\{
    TwigAttributeProviderInterface,
    TwigSpanNameHandlerInterface,
    TwigTraceIgnoreInterface
};
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Attribute\InstrumentationTags;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Helper\SpanAttributeEnricher;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Validator\AutowireIteratorTypeValidator;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\{
    SpanInterface,
    SpanKind
};
use OpenTelemetry\Context\Context;
use SplObjectStorage;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Twig\Extension\AbstractExtension;
use Twig\NodeVisitor\NodeVisitorInterface;
use Twig\Profiler\NodeVisitor\ProfilerNodeVisitor;
use Twig\Profiler\Profile;

final class TraceableTwigExtension extends AbstractExtension
{
    /**
     * @var SplObjectStorage<Profile, SpanInterface>
     */
    private SplObjectStorage $spans;

    /**
     * @param iterable<TwigAttributeProviderInterface> $attributeProviders
     * @param iterable<TwigSpanNameHandlerInterface> $twigSpanNameHandlers
     * @param iterable<TwigTraceIgnoreInterface> $twigTraceIgnores
     */
    public function __construct(
        private readonly CachedInstrumentation $instrumentation,
        #[AutowireIterator(InstrumentationTags::TWIG_ATTRIBUTE_PROVIDER)]
        private readonly iterable $attributeProviders = [],
        #[AutowireIterator(InstrumentationTags::TWIG_SPAN_NAME_HANDLER)]
        private readonly iterable $twigSpanNameHandlers = [],
        #[AutowireIterator(InstrumentationTags::TWIG_TRACE_IGNORE)]
        private readonly iterable $twigTraceIgnores = [],
    ) {
        $this->spans = new SplObjectStorage;

        AutowireIteratorTypeValidator::validate(
            argumentName: '$attributeProviders',
            items: $this->attributeProviders,
            expectedType: TwigAttributeProviderInterface::class
        );

        AutowireIteratorTypeValidator::validate(
            argumentName: '$twigSpanNameHandlers',
            items: $this->twigSpanNameHandlers,
            expectedType: TwigSpanNameHandlerInterface::class
        );

        AutowireIteratorTypeValidator::validate(
            argumentName: '$twigTraceIgnores',
            items: $this->twigTraceIgnores,
            expectedType: TwigTraceIgnoreInterface::class
        );
    }

    public function enter(Profile $profile): void
    {
        $scope = Context::storage()->scope();

        $spanName = sprintf('twig %s', $this->getSpanName($profile));

        foreach ($this->twigSpanNameHandlers as $twigSpanNameHandler) {
            if (method_exists($twigSpanNameHandler, 'processProfile')) {
                $spanName = $twigSpanNameHandler->processProfile($spanName, $profile);
            }
        }

        foreach ($this->twigTraceIgnores as $twigTraceIgnore) {
            if (method_exists($twigTraceIgnore, 'shouldIgnoreProfile') && $twigTraceIgnore->shouldIgnoreProfile($spanName, $profile)) {
                return;
            }
        }

        /** @var non-empty-string $spanNameNonEmpty */
        $spanNameNonEmpty = (string) $spanName;

        $spanBuilder = $this->instrumentation
            ->tracer()
            ->spanBuilder($spanNameNonEmpty)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute('transaction.type', 'template')
            ->setAttribute('twig.system', 'twig')
            ->setParent($scope?->context());

        $span = $spanBuilder->startSpan();

        SpanAttributeEnricher::enrich(
            span: $span,
            providers: $this->attributeProviders,
            context: ['profile' => $profile]
        );

        $this->spans[$profile] = $span;
    }

    public function leave(Profile $profile): void
    {
        if (!isset($this->spans[$profile])) {
            return;
        }

        /** @var SpanInterface $span */
        $span = $this->spans[$profile];
        $span->end();

        unset($this->spans[$profile]);
    }

    /**
     * @return array<int, NodeVisitorInterface>
     */
    public function getNodeVisitors(): array
    {
        return [
            new ProfilerNodeVisitor(self::class)
        ];
    }

    private function getSpanName(Profile $profile): string
    {
        return match (true) {
            $profile->isRoot() => $profile->getName(),
            $profile->isTemplate() => $profile->getTemplate(),
            default => sprintf('%s::%s(%s)', $profile->getTemplate(), $profile->getType(), $profile->getName()),
        };
    }
}
