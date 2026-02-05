<?php

namespace EasyAudit\Service\PayloadPreparers;

use EasyAudit\Service\CliWriter;

abstract class AbstractPreparer implements PreparerInterface
{
    /**
     * Check if the rule is fixable
     *
     * @param string $ruleId
     * @param array $fixables
     * @param string|null $selectedRule
     * @return bool
     */
    abstract protected function canFix(string $ruleId, array $fixables, ?string $selectedRule = null): bool;

    protected function isSpecificRule(string $ruleId): bool
    {
        return isset(self::SPECIFIC_RULES[$ruleId]);
    }

    protected function getMappedRule(string $ruleId): string
    {
        return self::MAPPED_RULES[$ruleId];
    }

    protected function isRuleFixable(string $ruleId, array $fixables, ?string $selectedRule = null): bool
    {
        $rule = $ruleId;
        if (isset(self::MAPPED_RULES[$ruleId])) {
            $rule = self::MAPPED_RULES[$ruleId];
        }
        return ($selectedRule === null || $selectedRule === $ruleId) && isset($fixables[$rule]);
    }
}
