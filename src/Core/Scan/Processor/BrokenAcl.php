<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;
use EasyAudit\Core\Scan\Util\Xml;

class BrokenAcl extends AbstractProcessor
{
    /** @var array<string, bool> */
    private array $aclResources = [];

    public function getIdentifier(): string
    {
        return 'brokenAcl';
    }

    public function getFileType(): string
    {
        return 'xml';
    }

    public function getName(): string
    {
        return 'Broken ACL Binding';
    }

    public function getMessage(): string
    {
        return 'Detects admin menu entries that reference ACL resources not defined in any acl.xml.';
    }

    public function getLongDescription(): string
    {
        return 'Flags adminhtml/menu.xml entries whose resource attribute does not correspond to any '
            . '<resource id="..."> defined in an acl.xml file across the scanned codebase.' . "\n"
            . 'Impact: A menu item bound to a non-existent ACL resource has ambiguous authorization. '
            . 'Depending on Magento version and user role, the item may be hidden for everyone, visible '
            . 'to admins who should not have access, or trigger runtime errors when the menu renders.' . "\n"
            . 'Why change: Admin authorization is silent — no test failure, no startup error, only a '
            . 'missing or mis-protected menu entry. This class of bug is routinely discovered in '
            . 'production by the wrong user stumbling into the wrong screen.' . "\n"
            . 'How to fix: Define the missing resource id in acl.xml (commonly under '
            . '<resource id="Magento_Backend::admin"> hierarchy), or correct the menu entry to reference '
            . 'an existing resource.';
    }

    public function process(array $files): void
    {
        if (empty($files['xml'])) {
            return;
        }

        $this->aclResources = [];
        $this->collectAclResources($files['xml']);
        $this->checkMenuEntries($files['xml']);

        if (!empty($this->results)) {
            echo "  \033[31m✗\033[0m Broken ACL bindings: \033[1;31m"
                . count($this->results) . "\033[0m\n";
        }
    }

    private function collectAclResources(array $xmlFiles): void
    {
        foreach ($xmlFiles as $file) {
            if (basename($file) !== 'acl.xml') {
                continue;
            }
            $xml = Xml::loadFile($file);
            if ($xml === false) {
                continue;
            }
            foreach ($xml->xpath('//resource[@id]') as $resource) {
                $id = (string)$resource['id'];
                if ($id !== '') {
                    $this->aclResources[$id] = true;
                }
            }
        }
    }

    private function checkMenuEntries(array $xmlFiles): void
    {
        foreach ($xmlFiles as $file) {
            if (!$this->isAdminMenuFile($file)) {
                continue;
            }
            $xml = Xml::loadFile($file);
            if ($xml === false) {
                continue;
            }

            $fileContent = file_get_contents($file);

            foreach ($xml->xpath('//add[@resource]') as $entry) {
                $resource = (string)$entry['resource'];
                if ($resource === '' || isset($this->aclResources[$resource])) {
                    continue;
                }

                $id = (string)$entry['id'];
                $line = Content::getLineNumber($fileContent, 'resource="' . $resource . '"');
                if ($line === 0 && $id !== '') {
                    $line = Content::getLineNumber($fileContent, 'id="' . $id . '"');
                }
                if ($line === 0) {
                    $line = 1;
                }

                $this->foundCount++;
                $this->results[] = Formater::formatError(
                    $file,
                    $line,
                    sprintf(
                        'Menu entry "%s" references ACL resource "%s", which is not defined in any '
                        . 'acl.xml in the scan. Add the resource or correct the reference.',
                        $id !== '' ? $id : '(unnamed)',
                        $resource
                    ),
                    'error',
                    0,
                    [
                        'menuId' => $id,
                        'resource' => $resource,
                    ]
                );
            }
        }
    }

    private function isAdminMenuFile(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);
        return str_ends_with($normalized, '/adminhtml/menu.xml');
    }
}
