<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Model;

use Algolia\AlgoliaSearch\Exceptions\AlgoliaException;
use App\Entity\Dependent;
use App\Entity\PhpStat;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Package;
use App\Entity\Version;
use App\Entity\Download;
use App\Entity\EmptyReferenceCache;
use Psr\Log\LoggerInterface;
use Algolia\AlgoliaSearch\SearchClient;
use Predis\Client;
use App\Service\GitHubUserMigrationWorker;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Twig\Environment;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PackageManager
{
    private ManagerRegistry $doctrine;
    private MailerInterface $mailer;
    private Environment $twig;
    private LoggerInterface $logger;
    private array $options;
    private ProviderManager $providerManager;
    private SearchClient $algoliaClient;
    private string $algoliaIndexName;
    private GitHubUserMigrationWorker $githubWorker;
    private string $metadataDir;
    private Client $redis;

    public function __construct(ManagerRegistry $doctrine, MailerInterface $mailer, Environment $twig, LoggerInterface $logger, array $options, ProviderManager $providerManager, SearchClient $algoliaClient, string $algoliaIndexName, GitHubUserMigrationWorker $githubWorker, string $metadataDir, Client $redis, private VersionIdCache $versionIdCache)
    {
        $this->doctrine = $doctrine;
        $this->mailer = $mailer;
        $this->twig = $twig;
        $this->logger = $logger;
        $this->options = $options;
        $this->providerManager = $providerManager;
        $this->algoliaClient = $algoliaClient;
        $this->algoliaIndexName  = $algoliaIndexName;
        $this->githubWorker  = $githubWorker;
        $this->metadataDir  = $metadataDir;
        $this->redis = $redis;
    }

    public function deletePackage(Package $package)
    {
        $versionRepo = $this->doctrine->getRepository(Version::class);
        foreach ($package->getVersions() as $version) {
            $versionRepo->remove($version);
        }

        if ($package->getAutoUpdated() === Package::AUTO_GITHUB_HOOK) {
            foreach ($package->getMaintainers() as $maintainer) {
                $token = $maintainer->getGithubToken();
                try {
                    if ($token && $this->githubWorker->deleteWebHook($token, $package)) {
                        break;
                    }
                } catch (TransportExceptionInterface | DecodingExceptionInterface | HttpExceptionInterface $e) {
                    // ignore
                }
            }
        }

        $em = $this->doctrine->getManager();

        $downloadRepo = $this->doctrine->getRepository(Download::class);
        $downloadRepo->deletePackageDownloads($package);

        $phpStatsRepo = $this->doctrine->getRepository(PhpStat::class);
        $phpStatsRepo->deletePackageStats($package);

        $dependentRepo = $this->doctrine->getRepository(Dependent::class);
        $dependentRepo->deletePackageDependentSuggesters($package->getId());

        $emptyRefRepo = $this->doctrine->getRepository(EmptyReferenceCache::class);
        $emptyRef = $emptyRefRepo->findOneBy(['package' => $package]);
        if ($emptyRef) {
            $em->remove($emptyRef);
            $em->flush();
        }

        $this->providerManager->deletePackage($package);
        $this->versionIdCache->deletePackage($package);
        $packageId = $package->getId();
        $packageName = $package->getName();

        $em->remove($package);
        $em->flush();

        $metadataV2 = $this->metadataDir.'/p2/'.strtolower($packageName).'.json';
        if (file_exists($metadataV2)) {
            @unlink($metadataV2);
        }
        if (file_exists($metadataV2.'.gz')) {
            @unlink($metadataV2.'.gz');
        }
        $metadataV2Dev = $this->metadataDir.'/p2/'.strtolower($packageName).'~dev.json';
        if (file_exists($metadataV2Dev)) {
            @unlink($metadataV2Dev);
        }
        if (file_exists($metadataV2Dev.'.gz')) {
            @unlink($metadataV2Dev.'.gz');
        }

        // delete redis stats
        try {
            $this->redis->del('views:'.$packageId);
        } catch (\Predis\Connection\ConnectionException $e) {
        }

        $this->redis->zadd('metadata-deletes', [strtolower($packageName) => round(microtime(true)*10000)]);

        // attempt search index cleanup
        try {
            $indexName = $this->algoliaIndexName;
            $algolia = $this->algoliaClient;
            $index = $algolia->initIndex($indexName);
            $index->deleteObject($packageName);
        } catch (AlgoliaException $e) {
        }
    }

    public function notifyUpdateFailure(Package $package, \Exception $e, $details = null)
    {
        if (!$package->isUpdateFailureNotified()) {
            $recipients = [];
            foreach ($package->getMaintainers() as $maintainer) {
                if ($maintainer->isNotifiableForFailures()) {
                    $recipients[$maintainer->getEmail()] = new Address($maintainer->getEmail(), $maintainer->getUsername());
                }
            }

            if ($recipients) {
                $body = $this->twig->render('email/update_failed.txt.twig', [
                    'package' => $package,
                    'exception' => get_class($e),
                    'exceptionMessage' => $e->getMessage(),
                    'details' => strip_tags($details),
                ]);

                $message = (new Email())
                    ->subject($package->getName().' failed to update, invalid composer.json data')
                    ->from(new Address($this->options['from'], $this->options['fromName']))
                    ->to(...array_values($recipients))
                    ->text($body)
                ;

                $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'OOF, DR, RN, NRN, AutoReply');

                try {
                    $this->mailer->send($message);
                } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
                    $this->logger->error('['.get_class($e).'] '.$e->getMessage());

                    return false;
                }
            }

            $package->setUpdateFailureNotified(true);
        }

        // make sure the package crawl time is updated so we avoid retrying failing packages more often than working ones
        if (!$package->getCrawledAt() || $package->getCrawledAt() < new \DateTime()) {
            $package->setCrawledAt(new \DateTime);
        }
        $this->doctrine->getManager()->flush();

        return true;
    }

    public function notifyNewMaintainer($user, $package)
    {
        $body = $this->twig->render('email/maintainer_added.txt.twig', [
            'package_name' => $package->getName()
        ]);

        $message = (new Email)
            ->subject('You have been added to ' . $package->getName() . ' as a maintainer')
            ->from(new Address($this->options['from'], $this->options['fromName']))
            ->to($user->getEmail())
            ->text($body)
        ;

        $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'OOF, DR, RN, NRN, AutoReply');

        try {
            $this->mailer->send($message);
        } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
            $this->logger->error('['.get_class($e).'] '.$e->getMessage());

            return false;
        }

        return true;
    }
}
