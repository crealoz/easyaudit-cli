<?php

namespace Test\Fixtures\SpecificClassInjection;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Monolog\Logger;
use Symfony\Component\Console\Application;
use Laminas\Http\Client as LaminasClient;
use League\Flysystem\Filesystem;
use Psr\Log\LoggerInterface;
use Doctrine\ORM\EntityManager;
use Aws\S3\S3Client;
use Google\Cloud\Storage\StorageClient;
use Carbon\Carbon;
use Ramsey\Uuid\Uuid;

/**
 * Examples of non-Magento library injections that should NOT trigger the generic rule.
 * These libraries don't have Magento-style factories.
 */
class NonMagentoLibraries
{
    /**
     * All these injections should NOT be flagged by the generic "use Factory" rule
     * because these are standard PHP libraries, not Magento modules.
     */
    public function __construct(
        private Client $guzzleClient,                    // OK: Non-Magento library (GuzzleHttp)
        private ClientInterface $guzzleInterface,        // OK: Interface
        private Logger $monologLogger,                   // OK: Non-Magento library (Monolog)
        private Application $symfonyApp,                 // OK: Non-Magento library (Symfony)
        private LaminasClient $laminasClient,            // OK: Non-Magento library (Laminas)
        private Filesystem $leagueFilesystem,            // OK: Non-Magento library (League)
        private LoggerInterface $psrLogger,              // OK: Interface (PSR)
        private EntityManager $doctrineEntityManager,    // OK: Non-Magento library (Doctrine)
        private S3Client $awsS3Client,                   // OK: Non-Magento library (Aws)
        private StorageClient $googleStorage,            // OK: Non-Magento library (Google)
        private Carbon $carbonDate,                      // OK: Non-Magento library (Carbon)
        private Uuid $ramseyUuid                         // OK: Non-Magento library (Ramsey)
    ) {
    }

    public function someMethod(): void
    {
        // Using injected dependencies
        $response = $this->guzzleClient->get('https://example.com');
        $this->monologLogger->info('Fetched data');
    }
}
