<?php declare(strict_types=1);

namespace App\Entity;

use Composer\Pcre\Preg;
use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;

/**
 * @ORM\Entity(repositoryClass="App\Entity\PhpStatRepository")
 * @ORM\Table(
 *     name="php_stat",
 *     indexes={
 *         @ORM\Index(name="type_idx",columns={"type"}),
 *         @ORM\Index(name="depth_idx",columns={"depth"}),
 *         @ORM\Index(name="version_idx",columns={"version"}),
 *         @ORM\Index(name="last_updated_idx",columns={"last_updated"}),
 *         @ORM\Index(name="package_idx",columns={"package_id"})
 *     }
 * )
 */
class PhpStat
{
    const TYPE_PHP = 1;
    const TYPE_PLATFORM = 2;

    const DEPTH_PACKAGE = 0;
    const DEPTH_MAJOR = 1;
    const DEPTH_MINOR = 2;
    const DEPTH_EXACT = 3;

    /**
     * Version prefix
     *
     * - "" for the overall package stats
     * - x.y for numeric versions (grouped by minor)
     * - x for numeric versions (grouped by major)
     * - Full version for the rest (dev- & co)
     *
     * @ORM\Id
     * @ORM\Column(type="string", length=191)
     */
    public string $version;

    /**
     * @ORM\Id
     * @ORM\Column(type="smallint")
     * @var self::TYPE_*
     */
    public int $type;

    /**
     * DEPTH_MAJOR for x
     * DEPTH_MINOR for x.y
     * DEPTH_EXACT for the rest
     *
     * @ORM\Column(type="smallint")
     * @var self::DEPTH_*
     */
    public int $depth;

    /**
     * array[php-version][Ymd] = downloads
     *
     * @ORM\Column(type="json")
     * @var array<string, array<string, int>>
     */
    public array $data;

    /**
     * @ORM\Column(type="datetime", name="last_updated")
     */
    public DateTimeInterface $lastUpdated;

    /**
     * @ORM\Id
     * @ORM\ManyToOne(targetEntity="App\Entity\Package")
     * @ORM\JoinColumn(name="package_id", nullable=false)
     */
    public Package $package;

    /**
     * @param self::TYPE_* $type
     */
    public function __construct(Package $package, int $type, string $version)
    {
        $this->package = $package;
        $this->type = $type;
        $this->version = $version;

        if ('' === $version) {
            $this->depth = self::DEPTH_PACKAGE;
        } elseif (Preg::isMatch('{^\d+$}', $version)) {
            $this->depth = self::DEPTH_MAJOR;
        } elseif (Preg::isMatch('{^\d+\.\d+$}', $version)) {
            $this->depth = self::DEPTH_MINOR;
        } else {
            $this->depth = self::DEPTH_EXACT;
        }

        $this->data = [];
        $this->lastUpdated = new \DateTimeImmutable();
    }

    /**
     * @return self::TYPE_*
     */
    public function getType(): int
    {
        return $this->type;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * @return self::DEPTH_*
     */
    public function getDepth(): int
    {
        return $this->depth;
    }

    public function setData(array $data)
    {
        $this->data = $data;
    }

    public function addDataPoint($phpMinor, $date, $value)
    {
        $this->data[$phpMinor][$date] = ($this->data[$phpMinor][$date] ?? 0) + $value;
    }

    public function setDataPoint($phpMinor, $date, $value)
    {
        $this->data[$phpMinor][$date] = $value;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setLastUpdated(DateTimeInterface $lastUpdated)
    {
        $this->lastUpdated = $lastUpdated;
    }

    public function getLastUpdated(): DateTimeInterface
    {
        return $this->lastUpdated;
    }

    public function getPackage(): Package
    {
        return $this->package;
    }
}
