<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\{
    TreeBuilder,
    ArrayNodeDefinition
};
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public const string ALIAS = 'danilovl_open_telemetry';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();
        $rootChildren = $rootNode->children();

        $rootChildren
            ->arrayNode('service')
                ->addDefaultsIfNotSet()
                ->children()
                    ->scalarNode('namespace')->defaultNull()->end()
                    ->scalarNode('name')->defaultNull()->end()
                    ->scalarNode('version')->defaultNull()->end()
                    ->scalarNode('environment')->defaultNull()->end()
                ->end()
            ->end();

        $instrumentationNode = $rootChildren
            ->arrayNode('instrumentation')
            ->addDefaultsIfNotSet();

        $this->appendInstrumentationNode(
            instrumentationNode: $instrumentationNode,
            name: 'http_server',
            withDefaultTraceIgnore: true
        );

        $this->appendInstrumentationNode(
            instrumentationNode: $instrumentationNode,
            name: 'messenger',
            withLongRunningCommand: true
        );

        $this->appendInstrumentationNode(
            instrumentationNode: $instrumentationNode,
            name: 'console'
        );

        $this->appendInstrumentationNode(
            instrumentationNode: $instrumentationNode,
            name: 'traceable'
        );

        $this->appendInstrumentationNode(
            instrumentationNode: $instrumentationNode,
            name: 'twig'
        );

        $this->appendInstrumentationNode(
            instrumentationNode: $instrumentationNode,
            name: 'cache',
            withMetering: true
        );

        $this->appendInstrumentationNode(
            instrumentationNode: $instrumentationNode,
            name: 'doctrine',
            withDefaultTraceIgnore: true,
            withDefaultSpanNameHandler: true
        );

        $this->appendInstrumentationNode(
            instrumentationNode: $instrumentationNode,
            name: 'redis'
        );

        $this->appendInstrumentationNode(
            instrumentationNode: $instrumentationNode,
            name: 'mailer'
        );

        $this->appendInstrumentationNode(
            instrumentationNode: $instrumentationNode,
            name: 'events',
            withDefaultTraceIgnore: true,
            withDefaultSpanNameHandler: true
        );

        $this->appendInstrumentationNode(
            instrumentationNode: $instrumentationNode,
            name: 'async'
        );

        $this->appendInstrumentationNode(
            instrumentationNode: $instrumentationNode,
            name: 'http_client'
        );

        return $treeBuilder;
    }

    private function appendInstrumentationNode(
        ArrayNodeDefinition $instrumentationNode,
        string $name,
        bool $withMetering = false,
        bool $withDefaultTraceIgnore = false,
        bool $withDefaultSpanNameHandler = false,
        bool $withLongRunningCommand = false,
    ): void {
        $node = $instrumentationNode
            ->children()
            ->arrayNode($name)
            ->addDefaultsIfNotSet();

        $node
            ->children()
            ->booleanNode('enabled')->defaultTrue()->end();

        if ($withDefaultTraceIgnore) {
            $node
                ->children()
                ->booleanNode('default_trace_ignore_enabled')->defaultTrue()->end();
        }

        if ($withDefaultSpanNameHandler) {
            $node
                ->children()
                ->booleanNode('default_span_name_handler_enabled')->defaultTrue()->end();
        }

        if ($withLongRunningCommand) {
            $node
                ->children()
                ->booleanNode('long_running_command_enabled')->defaultTrue()->end();
        }

        $tracingNode = $node
            ->children()
            ->arrayNode('tracing')
            ->addDefaultsIfNotSet();

        $tracingChildren = $tracingNode->children();
        $tracingChildren->booleanNode('enabled')->defaultTrue()->end();

        $meteringEnabledDefault = $withMetering;

        $node
            ->children()
                ->arrayNode('metering')
                    ->addDefaultsIfNotSet()
                        ->children()
                            ->booleanNode('enabled')->defaultValue($meteringEnabledDefault)->end()
                ->end()
            ->end();

    }
}
