<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Doctrine\Middleware;

enum DoctrineContextAttribute: string
{
    case OPERATION = 'db.operation';
    case SYSTEM = 'db.system';
    case NAME = 'db.name';
    case SQL = 'db.sql';
    case PARAMS = 'db.params';
    case USER = 'db.user';
}
