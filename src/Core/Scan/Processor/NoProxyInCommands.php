<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\ProcessorInterface;
use EasyAudit\Core\Scan\Util\Classes;

class NoProxyInCommands implements ProcessorInterface
{
    private int $foundCount = 0;

    public function getIdentifier(): string
    {
        return 'no-proxy-in-commands';
    }

    /**
     * @throws \ReflectionException
     */
    public function process(array $files): array
    {
        $findings = [];
        foreach ($files['di'] as $file) {
            $content = simplexml_load_file($file);
            $commandsListNode = $content->xpath('//type[@name=\'Magento\Framework\Console\CommandList\']//item');
            foreach ($commandsListNode as $commandNode) {
                $findings[] = $this->manageCommandNode($commandNode, $content);
            }
        }
        return $findings;
    }

    private function getFilePath(string $className): ?string
    {
        $classPath = str_replace('\\', DIRECTORY_SEPARATOR, $className) . '.php';
        $fullPath = EA_SCAN_PATH . DIRECTORY_SEPARATOR . $classPath;
        echo "Looking for file: $fullPath\n";
        if (file_exists($fullPath)) {
            return $fullPath;
        }
        return null;
    }

    /**
     * Manage each command node, check if it has proxies in its constructor
     *
     * @param \SimpleXMLElement $commandNode
     * @param \SimpleXMLElement $input
     * @return array
     * @throws \ReflectionException
     */
    private function manageCommandNode(\SimpleXMLElement $commandNode, \SimpleXMLElement $input): array
    {
        $commandClassName = (string)$commandNode;
        echo "Checking command: $commandClassName\n";
        $filePath = $this->getFilePath($commandClassName);
        if ($filePath === null) {
            return [];
        }
        $foundings = [];
        $proxies = $this->getCommandProxies($input, $commandClassName);
        $fileContent = file_get_contents($filePath);
        $constructorParameters = Classes::parseConstructorParameters($fileContent);
        $importedClasses = Classes::parseImportedClasses($fileContent);
        /**
         * Once we have constructor parameters and imported classes, we can get the full class names of the parameters.
         */
        $consolidatedParameters = Classes::consolidateParameters($constructorParameters, $importedClasses);
        print_r($proxies);
        print_r($consolidatedParameters);
        if (empty($proxies) || count($proxies) < count($constructorParameters) - 1) {
            foreach ($consolidatedParameters as $parameterClassName) {
                echo $parameterClassName . '\Proxy' . "\n";
                if (!str_contains($parameterClassName, 'Factory') && !in_array($parameterClassName . '\Proxy', $proxies)) {
                    $this->foundCount++;
                    if (isset($foundings[$commandClassName])) {
                        $foundings[$commandClassName][] = $parameterClassName;
                    } else {
                        $foundings[$commandClassName] = [$parameterClassName];
                    }
                }
            }
        }
        return $foundings;
    }

    /**
     * Get all proxies used in the command class
     *
     * @param \SimpleXMLElement $input
     * @param string $commandClass
     * @return array
     */
    private function getCommandProxies($input, $commandClass)
    {
        $commandsListNode = $input->xpath('//type[@name=\'' . $commandClass . '\']//argument');

        $proxies = [];
        foreach ($commandsListNode as $commandsNode) {
            $argumentClassName = (string)$commandsNode;
            if (str_contains($argumentClassName, 'Proxy')) {
                $proxies[] = $argumentClassName;
            }
        }
        return $proxies;
    }

    public function getFoundCount(): int
    {
        return $this->foundCount;
    }

    public function getFileType(): string
    {
        return 'di';
    }
}