<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;
use EasyAudit\Core\Scan\Util\Xml;

class GetWriteAntipattern extends AbstractProcessor
{
    private const WRITE_METHOD_PREFIXES = 'save|update|delete|create|remove|set|put|post';
    private const READ_METHOD_PREFIXES = 'get|load|fetch|find|list|search|read';

    public function getIdentifier(): string
    {
        return 'getWriteAntipattern';
    }

    public function getFileType(): string
    {
        return 'xml';
    }

    public function getName(): string
    {
        return 'HTTP Method vs Service Method Mismatch';
    }

    public function getMessage(): string
    {
        return 'Detects webapi.xml routes where the HTTP verb does not match the semantics of the target service method.';
    }

    public function getLongDescription(): string
    {
        return 'Flags webapi.xml routes where the HTTP method and the service method name disagree on '
            . 'whether the endpoint is a read or a write.' . "\n"
            . 'Impact: A GET mapped to save/update/delete is a critical security and architectural '
            . 'anti-pattern. GET requests are cached, prefetched, crawled, and logged with URLs intact. '
            . 'Using GET to mutate state can trigger data changes from link previews, browser prefetching, '
            . 'or CSRF attacks. Conversely, a DELETE or PUT mapped to a getter is a correctness bug.' . "\n"
            . 'Why change: HTTP semantics are contractual. Clients, proxies, and caches rely on verbs to '
            . 'decide whether a request is safe to repeat, cache, or prefetch. Mismatches break those '
            . 'assumptions.' . "\n"
            . 'How to fix: Align the HTTP method with the operation. Use POST/PUT/DELETE for writes, GET '
            . 'for reads. If the service method name is misleading, rename it; otherwise correct the '
            . 'webapi.xml route.';
    }

    public function process(array $files): void
    {
        if (empty($files['xml'])) {
            return;
        }

        foreach ($files['xml'] as $file) {
            if (basename($file) !== 'webapi.xml') {
                continue;
            }
            $this->processWebapiFile($file);
        }

        if (!empty($this->results)) {
            echo "  \033[31m✗\033[0m HTTP method / service method mismatches: \033[1;31m"
                . count($this->results) . "\033[0m\n";
        }
    }

    private function processWebapiFile(string $file): void
    {
        $xml = Xml::loadFile($file);
        if ($xml === false) {
            return;
        }

        $fileContent = '';
        foreach ($xml->xpath('//route') as $route) {
            $httpMethod = strtoupper((string)$route['method']);
            $url = (string)$route['url'];

            if (!isset($route->service)) {
                continue;
            }
            $serviceClass = (string)$route->service['class'];
            $serviceMethod = (string)$route->service['method'];

            if ($serviceMethod === '' || $httpMethod === '') {
                continue;
            }

            $violation = $this->classifyViolation($httpMethod, $serviceMethod);
            if ($violation === null) {
                continue;
            }

            if ($fileContent === '') {
                $fileContent = file_get_contents($file);
            }

            $needle = 'url="' . $url . '"';
            $line = Content::getLineNumber($fileContent, $needle);
            if ($line === 0) {
                $line = Content::getLineNumber($fileContent, 'method="' . $serviceMethod . '"');
            }
            if ($line === 0) {
                $line = 1;
            }

            $this->foundCount++;
            $this->results[] = Formater::formatError(
                $file,
                $line,
                $violation,
                'error',
                0,
                [
                    'httpMethod' => $httpMethod,
                    'serviceClass' => $serviceClass,
                    'serviceMethod' => $serviceMethod,
                ]
            );
        }
    }

    private function classifyViolation(string $httpMethod, string $serviceMethod): ?string
    {
        if ($httpMethod === 'GET' && $this->methodIsWrite($serviceMethod)) {
            return sprintf(
                'GET route mapped to write-style service method "%s". GET must be safe and idempotent; '
                . 'use POST, PUT, or DELETE for mutations.',
                $serviceMethod
            );
        }

        if (($httpMethod === 'DELETE' || $httpMethod === 'PUT') && $this->methodIsRead($serviceMethod)) {
            return sprintf(
                '%s route mapped to read-style service method "%s". Mutating verbs should not target '
                . 'read operations.',
                $httpMethod,
                $serviceMethod
            );
        }

        return null;
    }

    private function methodIsWrite(string $method): bool
    {
        return preg_match('/^(' . self::WRITE_METHOD_PREFIXES . ')[A-Z_]/i', $method) === 1
            || in_array(strtolower($method), ['save', 'update', 'delete', 'create', 'remove'], true);
    }

    private function methodIsRead(string $method): bool
    {
        return preg_match('/^(' . self::READ_METHOD_PREFIXES . ')[A-Z_]/i', $method) === 1
            || in_array(strtolower($method), ['get', 'load', 'fetch', 'find', 'list', 'search', 'read'], true);
    }
}
