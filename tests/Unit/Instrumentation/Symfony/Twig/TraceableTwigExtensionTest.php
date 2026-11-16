<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Unit\Instrumentation\Symfony\Twig;

use Danilovl\OpenTelemetryBundle\Instrumentation\Symfony\Twig\TraceableTwigExtension;
use Danilovl\OpenTelemetryBundle\OpenTelemetry\Instrumentation\ScopedInstrumentation;
use Danilovl\OpenTelemetryBundle\Tests\Mock\Telemetry\InMemoryTelemetryTrait;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Twig\{
    Environment,
    TwigFunction
};
use Twig\Error\RuntimeError;
use Twig\Loader\ArrayLoader;
use Twig\Profiler\Profile;

class TraceableTwigExtensionTest extends TestCase
{
    use InMemoryTelemetryTrait;

    private function extension(): TraceableTwigExtension
    {
        return new TraceableTwigExtension(ScopedInstrumentation::create(TraceableTwigExtension::INSTRUMENTATION_NAME));
    }

    public function testRenderingProducesNestedSpans(): void
    {
        $loader = new ArrayLoader([
            'page.html.twig' => '{% extends "base.html.twig" %}{% block body %}{{ include("item.html.twig") }}{% endblock %}',
            'base.html.twig' => '<body>{% block body %}{% endblock %}</body>',
            'item.html.twig' => 'item',
        ]);
        $twig = new Environment($loader);
        $extension = $this->extension();
        $twig->addExtension($extension);

        $this->assertSame('<body>item</body>', $twig->render('page.html.twig'));

        $page = $this->telemetry->span('twig page.html.twig');
        $item = $this->telemetry->span('twig item.html.twig');

        $this->assertSame($page->getTraceId(), $item->getTraceId());
        $this->assertNotSame('0000000000000000', $item->getParentSpanId(), 'Included template must be nested');
        $this->assertSame(0, $extension->getOpenSpanCount());
    }

    public function testRenderingExceptionClosesSpans(): void
    {
        $loader = new ArrayLoader([
            'page.html.twig' => 'before {{ include("broken.html.twig") }} after',
            'broken.html.twig' => '{{ fail() }}',
        ]);
        $twig = new Environment($loader);

        $failFunction = new TwigFunction('fail', static fn (): never => throw new RuntimeException('boom'));
        $twig->addFunction($failFunction);
        $extension = $this->extension();
        $twig->addExtension($extension);

        try {
            $twig->render('page.html.twig');
            $this->fail('Exception expected');
        } catch (RuntimeError) {
        }

        $this->assertSame(2, $extension->getOpenSpanCount(), 'Twig does not call leave() on exceptions');

        $extension->reset();

        $this->assertSame(0, $extension->getOpenSpanCount());
        $broken = $this->telemetry->span('twig broken.html.twig');
        $page = $this->telemetry->span('twig page.html.twig');
        $this->assertSame(StatusCode::STATUS_ERROR, $broken->getStatus()->getCode());
        $this->assertSame($page->getSpanId(), $broken->getParentSpanId());
    }

    public function testLeaveClosesInnerProfilesLeftOpen(): void
    {
        $extension = $this->extension();
        $outer = new Profile('outer.html.twig', Profile::TEMPLATE, 'outer.html.twig');
        $inner = new Profile('inner.html.twig', Profile::TEMPLATE, 'inner.html.twig');

        $extension->enter($outer);
        $extension->enter($inner);
        // inner not left because of an exception
        $extension->leave($outer);

        $innerSpan = $this->telemetry->span('twig inner.html.twig');
        $outerSpan = $this->telemetry->span('twig outer.html.twig');

        $this->assertSame(StatusCode::STATUS_ERROR, $innerSpan->getStatus()->getCode());
        $this->assertSame($outerSpan->getSpanId(), $innerSpan->getParentSpanId());
        $this->assertSame(0, $extension->getOpenSpanCount());
    }

    public function testLeaveOfUnknownProfileIsIgnored(): void
    {
        $extension = $this->extension();
        $extension->leave(new Profile);

        $this->assertCount(0, $this->telemetry->spans());
    }
}
