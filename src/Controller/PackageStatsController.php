<?php declare(strict_types=1);

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\PhpStat;
use App\Statistics\PackageStatsProvider;
use App\Util\Killswitch;
use Composer\Package\Version\VersionParser;
use Composer\Pcre\Preg;
use DateTimeImmutable;
use Doctrine\ORM\NoResultException;
use App\Entity\Package;
use App\Entity\Version;
use Predis\Connection\ConnectionException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/packages/{name}', requirements: ['name' => PackageController::NAME_PATTERN])]
class PackageStatsController extends Controller
{
    public function __construct(private readonly PackageStatsProvider $statsProvider)
    {
    }

    #[Route(path: '/downloads.{_format}', name: 'package_downloads_full', requirements: ['_format' => 'json'], methods: ['GET'])]
    public function viewPackageDownloads(string $name): Response
    {
        if (!Killswitch::isEnabled(Killswitch::DOWNLOADS_ENABLED)) {
            return new Response('This page is temporarily disabled, please come back later.', Response::HTTP_BAD_GATEWAY);
        }

        $repo = $this->getEM()->getRepository(Package::class);

        try {
            $package = $repo->getPartialPackageByNameWithVersions($name);
        } catch (NoResultException) {
            return new JsonResponse(['status' => 'error', 'message' => 'Package not found'], 404);
        }

        $versions = $package->getVersions();
        $data = [
            'name' => $package->getName(),
        ];

        try {
            $data['downloads']['total'] = $this->downloadManager->getDownloads($package);
            $data['favers'] = $this->favoriteManager->getFaverCount($package);
        } catch (ConnectionException) {
            $data['downloads']['total'] = null;
            $data['favers'] = null;
        }

        foreach ($versions as $version) {
            try {
                $data['downloads']['versions'][$version->getVersion()] = $this->downloadManager->getDownloads($package, $version);
            } catch (ConnectionException) {
                $data['downloads']['versions'][$version->getVersion()] = null;
            }
        }

        return $this->cachedJson(['package' => $data], 3600);
    }

    #[Route(path: '/stats.{_format}', name: 'view_package_stats', requirements: ['_format' => 'json'], defaults: ['_format' => 'html'])]
    public function stats(Request $req, Package $package): Response
    {
        if (!Killswitch::isEnabled(Killswitch::DOWNLOADS_ENABLED)) {
            return new Response('This page is temporarily disabled, please come back later.', Response::HTTP_BAD_GATEWAY);
        }

        /** @var Version[] $versions */
        $versions = $package->getVersions()->toArray();
        usort($versions, Package::class.'::sortVersions');
        $date = $this->statsProvider->guessStatsStartDate($package);
        $data = [
            'downloads' => $this->downloadManager->getDownloads($package),
            'versions' => $versions,
            'average' => $this->statsProvider->guessStatsAverage($date),
            'date' => $date->format('Y-m-d'),
        ];

        if ($req->getRequestFormat() === 'json') {
            $data['versions'] = array_map(static function ($version) {
                /** @var Version $version */
                return $version->getVersion();
            }, $data['versions']);

            return new JsonResponse($data);
        }

        $data['package'] = $package;

        $expandedVersion = reset($versions);
        $majorVersions = [];
        $foundExpandedVersion = false;
        foreach ($versions as $v) {
            if (!$v->isDevelopment()) {
                $majorVersions[] = $v->getMajorVersion();
                if (!$foundExpandedVersion) {
                    $expandedVersion = $v;
                    $foundExpandedVersion = true;
                }
            }
        }
        $data['majorVersions'] = $majorVersions ? array_merge(['all'], array_unique($majorVersions)) : [];
        $data['expandedId'] = $majorVersions ? 'major/all' : ($expandedVersion ? $expandedVersion->getId() : false);

        return $this->render('package/stats.html.twig', $data);
    }

    #[Route(path: '/php-stats.{_format}', name: 'view_package_php_stats', requirements: ['_format' => 'json'], defaults: ['_format' => 'html'])]
    public function phpStats(Request $req, Package $package): Response
    {
        if (!Killswitch::isEnabled(Killswitch::DOWNLOADS_ENABLED)) {
            return new Response('This page is temporarily disabled, please come back later.', Response::HTTP_BAD_GATEWAY);
        }

        $phpStatRepo = $this->getEM()->getRepository(PhpStat::class);
        $versions = $phpStatRepo->getStatVersions($package);
        $defaultVersion = $this->getEM()->getConnection()->fetchOne('SELECT normalizedVersion from package_version WHERE package_id = :id AND defaultBranch = 1', ['id' => $package->getId()]);

        usort($versions, static function ($a, $b) use ($defaultVersion) {
            if ($defaultVersion === $a['version'] && $b['depth'] !== PhpStat::DEPTH_PACKAGE) {
                return -1;
            }
            if ($defaultVersion === $b['version'] && $a['depth'] !== PhpStat::DEPTH_PACKAGE) {
                return 1;
            }

            if ($a['depth'] !== $b['depth']) {
                return $a['depth'] <=> $b['depth'];
            }

            if ($a['depth'] === PhpStat::DEPTH_EXACT) {
                return $a['version'] <=> $b['version'];
            }

            return version_compare($b['version'], $a['version']);
        });

        $versionsFormatted = [];
        foreach ($versions as $index => $version) {
            if ($version['version'] === '') {
                $label = 'All';
            } elseif (str_ends_with($version['version'], '.9999999')) {
                $label = Preg::replace('{\.9999999$}', '.x-dev', $version['version']);
            } elseif (in_array($version['depth'], [PhpStat::DEPTH_MINOR, PhpStat::DEPTH_MAJOR], true)) {
                $label = $version['version'].'.*';
            } else {
                $label = $version['version'];
            }
            $versionsFormatted[] = [
                'label' => $label,
                'version' => $version['version'] === '' ? 'all' : $version['version'],
                'depth' => match ($version['depth']) {
                    PhpStat::DEPTH_PACKAGE => 'package',
                    PhpStat::DEPTH_MAJOR => 'major',
                    PhpStat::DEPTH_MINOR => 'minor',
                    PhpStat::DEPTH_EXACT => 'exact',
                },
            ];
        }
        unset($versions);

        $date = $this->statsProvider->guessPhpStatsStartDate($package);
        $data = [
            'versions' => $versionsFormatted,
            'average' => $this->statsProvider->guessStatsAverage($date),
            'date' => $date->format('Y-m-d'),
        ];

        if ($req->getRequestFormat() === 'json') {
            return new JsonResponse($data);
        }

        $data['package'] = $package;

        $data['expandedVersion'] = $versionsFormatted ? reset($versionsFormatted)['version'] : null;

        return $this->render('package/php_stats.html.twig', $data);
    }

    #[Route(path: '/php-stats/{type}/{version}.json', name: 'version_php_stats', requirements: ['type' => 'platform|effective', 'version' => '.+'])]
    public function versionPhpStats(Request $req, string $name, string $type, string $version): JsonResponse
    {
        if (!Killswitch::isEnabled(Killswitch::DOWNLOADS_ENABLED)) {
            return new JsonResponse(['status' => 'error', 'message' => 'This page is temporarily disabled, please come back later.'], Response::HTTP_BAD_GATEWAY);
        }

        try {
            $package = $this->getEM()
                ->getRepository(Package::class)
                ->getPackageByName($name);
        } catch (NoResultException) {
            return new JsonResponse(['status' => 'error', 'message' => 'Package not found'], 404);
        }

        if ($from = $req->query->get('from')) {
            $from = new DateTimeImmutable($from);
        } else {
            $from = $this->statsProvider->guessPhpStatsStartDate($package);
        }
        if ($to = $req->query->get('to')) {
            $to = new DateTimeImmutable($to);
        } else {
            $to = new DateTimeImmutable('today 00:00:00');
        }

        $average = $req->query->get('average', $this->statsProvider->guessStatsAverage($from, $to));

        $phpStat = $this->getEM()->getRepository(PhpStat::class)->findOneBy(['package' => $package, 'type' => $type === 'platform' ? PhpStat::TYPE_PLATFORM : PhpStat::TYPE_PHP, 'version' => $version === 'all' ? '' : $version]);
        if (!$phpStat) {
            throw new NotFoundHttpException('No stats found for the requested version');
        }

        $datePoints = $this->statsProvider->createDatePoints($from, $to, $average);
        $series = [];
        $totals = array_fill(0, count($datePoints), 0);

        $index = 0;
        foreach ($datePoints as $label => $values) {
            foreach ($phpStat->getData() as $seriesName => $seriesData) {
                $value = 0;
                foreach ($values as $valueKey) {
                    $value += $seriesData[$valueKey] ?? 0;
                }
                // average the value over the datapoints in this current label
                $value = (int) ceil($value / count($values));

                $series[$seriesName][] = $value;
                $totals[$index] += $value;
            }
            $index++;
        }

        // filter out series which have only 0 values
        foreach ($series as $seriesName => $data) {
            foreach ($data as $value) {
                if ($value !== 0) {
                    continue 2;
                }
            }
            unset($series[$seriesName]);
        }

        // delete last datapoint or two if they are still 0 as the nightly job syncing the data in mysql may not have run yet
        for ($i = 0; $i < 2; $i++) {
            if (0 === $totals[count($totals) - 1]) {
                unset($totals[count($totals) - 1]);
                end($datePoints);
                unset($datePoints[key($datePoints)]);
                foreach ($series as $seriesName => $data) {
                    unset($series[$seriesName][count($data) - 1]);
                }
            }
        }

        uksort($series, static function ($a, $b) {
            if ($a === 'hhvm') {
                return 1;
            }
            if ($b === 'hhvm') {
                return -1;
            }

            return $b <=> $a;
        });

        $datePoints = [
            'labels' => array_keys($datePoints),
            'values' => $series,
        ];

        $datePoints['average'] = $average;

        if (empty($datePoints['labels']) && empty($datePoints['values'])) {
            $datePoints['labels'][] = date('Y-m-d');
            $datePoints['values'][] = [0];
        }

        return $this->cachedJson($datePoints, 1800);
    }

    #[Route(path: '/stats/all.json', name: 'package_stats')]
    public function overallStats(Request $req, Package $package): JsonResponse
    {
        return $this->cachedJson($this->statsProvider->computeStats($req, $package), 1800);
    }

    #[Route(path: '/stats/major/{majorVersion}.json', name: 'major_version_stats', requirements: ['majorVersion' => '(all|[0-9]+?)'])]
    public function majorVersionStats(Request $req, Package $package, string $majorVersion): JsonResponse
    {
        return $this->cachedJson($this->statsProvider->computeStats($req, $package, null, $majorVersion), 1800);
    }

    #[Route(path: '/stats/{version}.json', name: 'version_stats', requirements: ['version' => '.+?'])]
    public function versionStats(Request $req, Package $package, string $version): JsonResponse
    {
        $normalizer = new VersionParser;
        try {
            $normVersion = $normalizer->normalize($version);
        } catch (\UnexpectedValueException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $version = $this->getEM()->getRepository(Version::class)->findOneBy([
            'package' => $package,
            'normalizedVersion' => $normVersion,
        ]);

        if (!$version) {
            throw new NotFoundHttpException(sprintf('Version %s not found.', $version));
        }

        return $this->cachedJson($this->statsProvider->computeStats($req, $package, $version), 1800);
    }
}
