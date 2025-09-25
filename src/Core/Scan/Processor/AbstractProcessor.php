<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\ProcessorInterface;

abstract class AbstractProcessor implements ProcessorInterface
{
    protected array $results = [];

    protected int $foundCount = 0;

    public function getFoundCount(): int
    {
        return $this->foundCount;
    }

    public function getReport(): array
    {
        return [
            'ruleId' => $this->getIdentifier(),
            'message' => $this->getMessage(),
            'files' => $this->results,
        ];
    }
}