<?php

namespace EasyAudit\Console;

interface CommandInterface
{
    public function run(array $argv): int;
}