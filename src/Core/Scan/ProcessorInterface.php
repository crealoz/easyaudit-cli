<?php

namespace EasyAudit\Core\Scan;

interface ProcessorInterface
{
    public function getIdentifier(): string;

    public function process(array $files): array;

    public function getFoundCount(): int;

    public function getFileType(): string;
}