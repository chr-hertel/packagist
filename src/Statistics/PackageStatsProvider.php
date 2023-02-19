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

namespace App\Statistics;

use App\Util\DoctrineTrait;
use DateTimeImmutable;
use App\Entity\Download;
use App\Entity\Package;
use App\Entity\Version;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class PackageStatsProvider
{
    use DoctrineTrait;

    public function __construct(private readonly ManagerRegistry $doctrine)
    {
    }

    /**
     * @return array{labels: array<int, string>, values: array<int|string, array<int, float>>, average: string}
     */
    public function computeStats(Request $req, Package $package, ?Version $version = null, ?string $majorVersion = null): array
    {
        if ($from = $req->query->get('from')) {
            $from = new DateTimeImmutable($from);
        } else {
            $from = $this->guessStatsStartDate($version ?: $package);
        }
        if ($to = $req->query->get('to')) {
            $to = new DateTimeImmutable($to);
        } else {
            $to = new DateTimeImmutable('-2days 00:00:00');
        }
        $average = $req->query->get('average', $this->guessStatsAverage($from, $to));

        $dlData = [];
        if (null !== $majorVersion) {
            if ($majorVersion === 'all') {
                $dlData = $this->getEM()->getRepository(Download::class)->findDataByMajorVersions($package);
            } else {
                if (!is_numeric($majorVersion)) {
                    throw new BadRequestHttpException('Major version should be an int or "all"');
                }
                $dlData = $this->getEM()->getRepository(Download::class)->findDataByMajorVersion($package, (int) $majorVersion);
            }
        } elseif (null !== $version) {
            $downloads = $this->getEM()->getRepository(Download::class)->findOneBy(['id' => $version->getId(), 'type' => Download::TYPE_VERSION]);
            $dlData[$version->getVersion()] = [$downloads ? $downloads->getData() : []];
        } else {
            $downloads = $this->getEM()->getRepository(Download::class)->findOneBy(['id' => $package->getId(), 'type' => Download::TYPE_PACKAGE]);
            $dlData[$package->getName()] = [$downloads ? $downloads->getData() : []];
        }

        $datePoints = $this->createDatePoints($from, $to, $average);
        $series = [];

        foreach ($datePoints as $values) {
            foreach ($dlData as $seriesName => $seriesData) {
                $value = 0;
                foreach ($values as $valueKey) {
                    foreach ($seriesData as $data) {
                        $value += $data[$valueKey] ?? 0;
                    }
                }
                $series[$seriesName][] = ceil($value / count($values));
            }
        }

        $datePoints = [
            'labels' => array_keys($datePoints),
            'values' => $series,
        ];

        $datePoints['average'] = $average;

        if (empty($datePoints['labels']) && empty($datePoints['values'])) {
            $datePoints['labels'][] = date('Y-m-d');
            $datePoints['values'][] = [0];
        }

        return $datePoints;
    }

    /**
     * @return array<string, string[]>
     */
    public function createDatePoints(DateTimeImmutable $from, DateTimeImmutable $to, string $average): array
    {
        $interval = $this->getStatsInterval($average);

        $dateKey = 'Ymd';
        $dateFormat = $average === 'monthly' ? 'Y-m' : 'Y-m-d';
        $dateJump = '+1day';

        $nextDataPointLabel = $from->format($dateFormat);

        if ($average === 'monthly') {
            $nextDataPoint = new DateTimeImmutable('first day of ' . $from->format('Y-m'));
            $nextDataPoint = $nextDataPoint->modify($interval);
        } else {
            $nextDataPoint = $from->modify($interval);
        }

        $datePoints = [];
        while ($from <= $to) {
            $datePoints[$nextDataPointLabel][] = $from->format($dateKey);

            $from = $from->modify($dateJump);
            if ($from >= $nextDataPoint) {
                $nextDataPointLabel = $from->format($dateFormat);
                $nextDataPoint = $from->modify($interval);
            }
        }

        return $datePoints;
    }

    public function guessStatsStartDate(Package|Version $packageOrVersion): DateTimeImmutable
    {
        if ($packageOrVersion instanceof Package) {
            $date = DateTimeImmutable::createFromInterface($packageOrVersion->getCreatedAt());
        } elseif ($packageOrVersion->getReleasedAt()) {
            $date = DateTimeImmutable::createFromInterface($packageOrVersion->getReleasedAt());
        } else {
            throw new \LogicException('Version with release date expected');
        }

        $statsRecordDate = new DateTimeImmutable('2012-04-13 00:00:00');
        if ($date < $statsRecordDate) {
            $date = $statsRecordDate;
        }

        return $date->setTime(0, 0, 0);
    }

    public function guessPhpStatsStartDate(Package $package): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromInterface($package->getCreatedAt());

        $statsRecordDate = new DateTimeImmutable('2021-05-18 00:00:00');
        if ($date < $statsRecordDate) {
            $date = $statsRecordDate;
        }

        return $date->setTime(0, 0, 0);
    }

    public function guessStatsAverage(DateTimeImmutable $from, ?DateTimeImmutable $to = null): string
    {
        if ($to === null) {
            $to = new DateTimeImmutable('-2 days');
        }
        if ($from < $to->modify('-48months')) {
            $average = 'monthly';
        } elseif ($from < $to->modify('-7months')) {
            $average = 'weekly';
        } else {
            $average = 'daily';
        }

        return $average;
    }

    private function getStatsInterval(string $average): string
    {
        $intervals = [
            'monthly' => '+1month',
            'weekly' => '+7days',
            'daily' => '+1day',
        ];

        if (!isset($intervals[$average])) {
            throw new BadRequestHttpException();
        }

        return $intervals[$average];
    }
}
