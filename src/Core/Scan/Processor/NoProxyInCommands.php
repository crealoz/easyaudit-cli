<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Classes;
use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;

class NoProxyInCommands extends AbstractProcessor
{
    protected string $diFile = '';

    public function getIdentifier(): string
    {
        return 'noProxyUsedInCommands';
    }

    /**
     * @throws \ReflectionException
     */
    public function process(array $files): void
    {
        foreach ($files['di'] as $file) {
            $content = simplexml_load_file($file);
            if ($content === false) {
                continue;
            }
            $this->diFile = $file;
            $commandsListNode = $content->xpath('//type[@name=\'Magento\Framework\Console\CommandList\']//item');
            foreach ($commandsListNode as $commandNode) {
                $this->manageCommandNode($commandNode, $content);
            }
        }
    }

    private function getFilePath(string $className): ?string
    {
        $classPath = str_replace('\\', DIRECTORY_SEPARATOR, $className) . '.php';
        $fullPath = EA_SCAN_PATH . DIRECTORY_SEPARATOR . $classPath;
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
    private function manageCommandNode(\SimpleXMLElement $commandNode, \SimpleXMLElement $input): void
    {
        $commandClassName = (string)$commandNode;
        $filePath = $this->getFilePath($commandClassName);
        if ($filePath === null) {
            return ;
        }
        $proxies = $this->getCommandProxies($input, $commandClassName);
        $fileContent = file_get_contents($filePath);
        $constructorParameters = Classes::parseConstructorParameters($fileContent);
        $importedClasses = Classes::parseImportedClasses($fileContent);
        /**
         * Once we have constructor parameters and imported classes, we can get the full class names of the parameters.
         */
        $consolidatedParameters = Classes::consolidateParameters($constructorParameters, $importedClasses);
        if (empty($proxies) || count($proxies) < count($constructorParameters) - 1) {
            foreach ($consolidatedParameters as $paramName => $parameterClassName) {
                if (!str_contains($parameterClassName, 'Factory') && !in_array($parameterClassName . '\Proxy', $proxies)) {
                    $this->foundCount++;
                    $this->results[] = Formater::formatError(
                        $filePath,
                        Content::getLineNumber($fileContent, $paramName),
                        "Command $commandClassName should use a Proxy for $parameterClassName (parameter $paramName in constructor). Change it in " . $this->diFile,
                    );
                }
            }
        }
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

    public function getFileType(): string
    {
        return 'di';
    }

    public function getMessage(): string
    {
        return 'Commands should use proxies for their injections. Doing so improves performances especially for crons.';
    }

    public function getLongDescription(): string
    {
        return 'Commands should use proxies for their injections. Doing so improves performances especially for crons.
        When a command is executed, not all dependencies are always needed. Using proxies allows to delay the 
        instantiation of these dependencies until they are actually used, which can significantly reduce memory
         usage and execution time.';
    }

    public function getName(): string
    {
        return 'No Proxy in Commands';
    }
}