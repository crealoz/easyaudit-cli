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
     * @param array|null $selectedRules
     * @return bool
     */
    abstract protected function canFix(string $ruleId, array $fixables, ?array $selectedRules = null): bool;

    protected function isSpecificRule(string $ruleId): bool
    {
        return isset(self::SPECIFIC_RULES[$ruleId]);
    }

    protected function isRuleFixable(string $ruleId, array $fixables, ?array $selectedRules = null): bool
    {
        $rule = $ruleId;
        if (isset(self::MAPPED_RULES[$ruleId])) {
            $rule = self::MAPPED_RULES[$ruleId];
        }
        return ($selectedRules === null || in_array($ruleId, $selectedRules, true)) && isset($fixables[$rule]);
    }
}
