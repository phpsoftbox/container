<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Profiler;

use PhpSoftBox\Profiler\ProfilerExtensionInterface;
use PhpSoftBox\Profiler\ProfilerRegistryInterface;

final class ContainerProfilerExtension implements ProfilerExtensionInterface
{
    private ContainerProfilerCollector $collector;

    public function __construct(?ContainerProfilerCollector $collector = null)
    {
        $this->collector = $collector ?? new ContainerProfilerCollector();
    }

    public function collector(): ContainerProfilerCollector
    {
        return $this->collector;
    }

    public function register(ProfilerRegistryInterface $registry): void
    {
        $registry->addCollector($this->collector);
    }
}
