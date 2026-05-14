<?php

namespace EasyAudit\Tests\Unit\Core\Glossary;

use EasyAudit\Core\Glossary\GlossaryLoader;
use EasyAudit\Core\Scan\Processor\AbstractProcessor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class GlossaryIntegrityTest extends TestCase
{
    /** @var array<string, true> */
    private array $glossarySlugs;

    protected function setUp(): void
    {
        $loader = new GlossaryLoader();
        $concepts = $loader->load('en');
        $this->glossarySlugs = array_fill_keys(array_keys($concepts), true);
    }

    public function testEveryProcessorConceptSlugExistsInGlossary(): void
    {
        $offenders = [];
        foreach ($this->discoverProcessors() as $className) {
            $reflection = new ReflectionClass($className);

            // CONCEPTS const (declared on AbstractProcessor, possibly overridden by subclass)
            if ($reflection->hasConstant('CONCEPTS')) {
                $concepts = $reflection->getConstant('CONCEPTS');
                if (is_array($concepts)) {
                    foreach ($concepts as $slug) {
                        if (!isset($this->glossarySlugs[$slug])) {
                            $offenders[] = "{$className}::CONCEPTS references unknown slug '{$slug}'";
                        }
                    }
                }
            }

            // Per-processor private RULE_CONFIGS array (e.g. SpecificClassInjection)
            $offenders = array_merge($offenders, $this->checkConstantArray($reflection, 'RULE_CONFIGS', 'concepts'));

            // Per-processor private RULE_CONCEPTS map (e.g. AroundPlugins)
            $offenders = array_merge($offenders, $this->checkConceptMap($reflection, 'RULE_CONCEPTS'));
        }

        $this->assertSame([], $offenders, "Concept slug integrity violations:\n" . implode("\n", $offenders));
    }

    public function testAtLeastOneProcessorIsAnnotated(): void
    {
        $found = false;
        foreach ($this->discoverProcessors() as $className) {
            $reflection = new ReflectionClass($className);
            if ($reflection->hasConstant('CONCEPTS')) {
                $concepts = $reflection->getConstant('CONCEPTS');
                if (is_array($concepts) && $concepts !== []) {
                    $found = true;
                    break;
                }
            }
        }
        $this->assertTrue($found, 'Expected at least one processor to declare a non-empty CONCEPTS const');
    }

    /**
     * @return array<int, class-string<AbstractProcessor>>
     */
    private function discoverProcessors(): array
    {
        $dir = __DIR__ . '/../../../../src/Core/Scan/Processor';
        $classes = [];
        foreach (scandir($dir) ?: [] as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }
            $name = pathinfo($file, PATHINFO_FILENAME);
            if ($name === 'AbstractProcessor') {
                continue;
            }
            $fqcn = 'EasyAudit\\Core\\Scan\\Processor\\' . $name;
            if (class_exists($fqcn) && is_subclass_of($fqcn, AbstractProcessor::class)) {
                /** @var class-string<AbstractProcessor> $fqcn */
                $classes[] = $fqcn;
            }
        }
        return $classes;
    }

    /**
     * Check a constant array of shape [key => ['concepts' => string[], ...]].
     *
     * @return array<int, string>
     */
    private function checkConstantArray(ReflectionClass $reflection, string $constName, string $conceptsKey): array
    {
        $offenders = [];
        if (!$reflection->hasConstant($constName)) {
            return $offenders;
        }
        $value = $reflection->getConstant($constName);
        if (!is_array($value)) {
            return $offenders;
        }
        foreach ($value as $entryKey => $entry) {
            if (!is_array($entry) || !isset($entry[$conceptsKey])) {
                continue;
            }
            if (!is_array($entry[$conceptsKey])) {
                continue;
            }
            foreach ($entry[$conceptsKey] as $slug) {
                if (!isset($this->glossarySlugs[$slug])) {
                    $offenders[] = "{$reflection->getName()}::{$constName}['{$entryKey}']['{$conceptsKey}'] references unknown slug '{$slug}'";
                }
            }
        }
        return $offenders;
    }

    /**
     * Check a constant array of shape [ruleId => string[]].
     *
     * @return array<int, string>
     */
    private function checkConceptMap(ReflectionClass $reflection, string $constName): array
    {
        $offenders = [];
        if (!$reflection->hasConstant($constName)) {
            return $offenders;
        }
        $value = $reflection->getConstant($constName);
        if (!is_array($value)) {
            return $offenders;
        }
        foreach ($value as $ruleId => $slugs) {
            if (!is_array($slugs)) {
                continue;
            }
            foreach ($slugs as $slug) {
                if (!isset($this->glossarySlugs[$slug])) {
                    $offenders[] = "{$reflection->getName()}::{$constName}['{$ruleId}'] references unknown slug '{$slug}'";
                }
            }
        }
        return $offenders;
    }
}
