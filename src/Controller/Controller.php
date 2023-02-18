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

use App\Util\DoctrineTrait;
use Doctrine\Persistence\ManagerRegistry;
use Predis\Connection\ConnectionException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Entity\Package;
use App\Model\DownloadManager;
use App\Model\FavoriteManager;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
abstract class Controller extends AbstractController
{
    use DoctrineTrait;

    protected ManagerRegistry $doctrine;
    protected FavoriteManager $favoriteManager;
    protected DownloadManager $downloadManager;

    #[Required]
    public function setDeps(ManagerRegistry $doctrine, FavoriteManager $favoriteManager, DownloadManager $downloadManager): void
    {
        $this->doctrine = $doctrine;
        $this->favoriteManager = $favoriteManager;
        $this->downloadManager = $downloadManager;
    }

    /**
     * @param array<Package|array{id: int}> $packages
     * @return array{downloads: array<int, int>, favers: array<int, int>}
     */
    protected function getPackagesMetadata(iterable $packages): array
    {
        $downloads = [];
        $favorites = [];

        try {
            $ids = [];

            $search = false;
            foreach ($packages as $package) {
                if ($package instanceof Package) {
                    $ids[] = $package->getId();
                    // fetch one by one to avoid re-fetching the github stars as we already have them on the package object
                    $favorites[$package->getId()] = $this->favoriteManager->getFaverCount($package);
                } elseif (is_array($package)) {
                    $ids[] = $package['id'];
                    // fetch all in one query if we do not have objects
                    $search = true;
                } else {
                    throw new \LogicException('Got invalid package entity');
                }
            }

            $downloads = $this->downloadManager->getPackagesDownloads($ids);
            if ($search) {
                $favorites = $this->favoriteManager->getFaverCounts($ids);
            }
        } catch (ConnectionException) {
        }

        return ['downloads' => $downloads, 'favers' => $favorites];
    }
}
