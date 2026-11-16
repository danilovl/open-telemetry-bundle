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
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Instrumentation\ScopedInstrumentation;
use OpenTelemetry\API\Trace\{
    SpanInterface,
    SpanKind,
    StatusCode
};
use OpenTelemetry\Context\{
    Context,
    ContextInterface
};
use SplObjectStorage;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\NodeVisitor\NodeVisitorInterface;
use Twig\Profiler\NodeVisitor\ProfilerNodeVisitor;
use Twig\Profiler\Profile;

final class TraceableTwigExtension extends AbstractExtension implements ResetInterface
{
    public const string INSTRUMENTATION_NAME = 'danilovl.twig';

    /**
     * Object-keyed map from Twig Profile to its active OTEL Span.
     * SplObjectStorage is used instead of a plain array because Profile objects
     * are passed by reference to both enter() and leave(), making them ideal keys.
     * An array would require a string key derived from the profile, risking collisions
     * when templates are rendered recursively with the same name.
     *
     * @var SplObjectStorage<Profile, SpanInterface>
     */
    private SplObjectStorage $spans;

    /**
     * Innermost last.
     *
     * @var list<Profile>
     */
    private array $openProfiles = [];

    /**
     * @param iterable<TwigAttributeProviderInterface> $attributeProviders
     * @param iterable<TwigSpanNameHandlerInterface> $twigSpanNameHandlers
     * @param iterable<TwigTraceIgnoreInterface> $twigTraceIgnores
     */
    public function __construct(
        private readonly ScopedInstrumentation $instrumentation,
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
            ->setParent($this->resolveParentContext());

        $span = $spanBuilder->startSpan();

        SpanAttributeEnricher::enrich(
            span: $span,
            providers: $this->attributeProviders,
            context: ['profile' => $profile]
        );

        $this->spans[$profile] = $span;
        $this->openProfiles[] = $profile;
    }

    public function leave(Profile $profile): void
    {
        if (!isset($this->spans[$profile])) {
            return;
        }

        // inner profiles not left because of an exception
        while ($this->openProfiles !== []) {
            $openProfile = array_pop($this->openProfiles);
            /** @var SpanInterface $span */
            $span = $this->spans[$openProfile];
            unset($this->spans[$openProfile]);

            if ($openProfile === $profile) {
                $span->end();

                return;
            }

            $span->setStatus(StatusCode::STATUS_ERROR, 'Rendering aborted');
            $span->end();
        }
    }

    /** Twig does not call leave() when rendering throws. */
    public function reset(): void
    {
        while ($this->openProfiles !== []) {
            $openProfile = array_pop($this->openProfiles);
            /** @var SpanInterface $span */
            $span = $this->spans[$openProfile];
            unset($this->spans[$openProfile]);

            $span->setStatus(StatusCode::STATUS_ERROR, 'Rendering aborted');
            $span->end();
        }
    }

    public function getOpenSpanCount(): int
    {
        return count($this->openProfiles);
    }

    private function resolveParentContext(): ContextInterface
    {
        $current = Context::getCurrent();
        $parentProfile = $this->openProfiles[array_key_last($this->openProfiles) ?? -1] ?? null;

        if ($parentProfile === null || !isset($this->spans[$parentProfile])) {
            return $current;
        }

        /** @var SpanInterface $parentSpan */
        $parentSpan = $this->spans[$parentProfile];

        return $parentSpan->storeInContext($current);
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
