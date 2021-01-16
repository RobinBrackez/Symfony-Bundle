<?php
/**
 * Courtesy of Buromac.be/Tadaaz.be
 */
namespace Cron\CronBundle\Service\HeartBeatService;

use App\DBal\Type\CronStatus;
use Cron\CronBundle\Entity\CronJob;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\ORM\UnitOfWork;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Provide functionality to allow heartbeat monitoring to an import command
 */
class HeartBeatService
{
    /** @var EntityManagerInterface */
    private $em;
    private $name;
    /** @var CronJob */
    private $cronJob;

    /**
     * @var int The number of seconds to wait before a new heartbeat must be generated
     */
    private $intervalSeconds;
    /**
     * @var int The number of minutes before a command can be considered crashed
     */
    private $minutesToAssumeCrash;

    /**
     * HeartBeatService constructor.
     * @param EntityManagerInterface $em
     */
    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
        $this->intervalSeconds = 10;
        $this->minutesToAssumeCrash = 15;
    }

    /**
     * Initiate the service and set the name (of the running command)
     *
     * @param $name
     */
    public function initiate($name)
    {
        $this->name = $name;
        $this->cronJob = null;
    }

    /**
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Signal (or 'heartbeat') that the command is still running.
     *
     * This will only write a value to the database if the 'heartbeat interval time' has passed.
     */
    public function heartBeat()
    {
        if ($this->shouldHeartBeat()) {
            $this->setStatus(CronStatus::RUNNING);
        }
    }

    /**
     * Signal that the import has stopped
     */
    public function setJobStopped()
    {
        $this->setStatus(CronStatus::IDLE);
    }

    /**
     * Signal that the import has crashed
     */
    public function setJobCrashed()
    {
        $this->setStatus(CronStatus::CRASHED);
    }

    /**
     * Returns true is the command is already running
     *
     * @return bool
     */
    public function isAlreadyRunning()
    {
        return ($this->getCronJob()->getStatus() !== CronStatus::IDLE);
    }

    /**
     * Returns true is the command is running
     *
     * @return bool
     */
    public function hasStatusRunning()
    {
        return ($this->getCronJob()->getStatus() === CronStatus::RUNNING);
    }

    /**
     * Returns true if the heartbeat is old enough to be considered crashed.
     * This function should be used by the heartbeat command.
     *
     * @param DateTimeInterface $heartbeat
     *
     * @return bool
     */
    public function assumeCrashed(DateTimeInterface $heartbeat)
    {
        $heartbeatAge = $this->getHeartbeatAge($heartbeat);

        return (($heartbeatAge / 60) > $this->minutesToAssumeCrash);
    }

    /**
     * Get how old a heartbeat is (in seconds)
     *
     * @param DateTimeInterface|null $heartbeat
     *
     * @return int
     */
    private function getHeartbeatAge(?DateTimeInterface $heartbeat): int
    {
        if (null === $heartbeat) {
            return PHP_INT_MAX;
        }

        return abs($this->getNowDateTime()->getTimestamp() - $heartbeat->getTimestamp());
    }

    /**
     * Lazy load the Cronjob object.
     *  - Lookup if it already exists for this $name
     *  - Create if it doesn't exist.
     *
     * @return CronJob
     */
    private function getCronJob(): CronJob
    {
        if (null === $this->name) {
            $now = $this->getNowDateTime()->format('Ymd_His');
            $this->name = sprintf('nameless_process_%s', $now);
        }

        if ($this->cronJob instanceof CronJob) {
            // If $em->clear() happens in the import script, the CronJob becomes detached.
            // Doctrine would perform an insert instead of an update. To avoid that, we need the fetch the entity again from the manager.
            if ($this->isImportObjectDetached()) {
                $this->cronJob = null;
            };
        }

        if (null === $this->cronJob) {
            // fetch by name (name is unique)
            $this->cronJob = $this->em->getRepository(CronJob::class)
                ->findOneBy(
                    [
                        'name' => $this->name,
                    ]
                );
        }

        if (null === $this->cronJob) {
            $this->cronJob = new CronJob();
            $this->cronJob->setName($this->name);
            $this->cronJob->setStatus(CronStatus::IDLE);
        }

        return $this->cronJob;
    }

    /**
     * Update the running status and set a heartbeat timestamp
     *
     * @param int $status
     */
    private function setStatus(int $status)
    {
        $cronJob = $this->getCronJob();
        $cronJob->setStatus($status);

        $cronJob->setHeartbeat($this->getNowDateTime());

        $this->em->persist($cronJob);
        $this->em->flush();
    }


    /**
     * Decide whether or not to update the heartbeat based on the last heartbeat's age.
     *
     * @return bool
     */
    private function shouldHeartBeat(): bool
    {
        $heartBeat = null;

        if ($this->cronJob instanceof CronJob) {
            // get the heartbeat that is still saved as property
            $heartBeat = $this->cronJob->getHeartbeat();
        }

        if (null === $heartBeat) {
            // get the heartbeat through lazy loading the object (possibly from the db)
            $heartBeat = $this->getCronJob()->getHeartbeat();
        }

        return ($this->getHeartbeatAge($heartBeat) > $this->intervalSeconds);
    }

    /**
     * Returns true if the CronJob is detached from the entity manager.
     *
     * @return bool
     */
    private function isImportObjectDetached(): bool
    {
        $entityState = $this->em->getUnitOfWork()->getEntityState($this->cronJob);

        // DETACHED records must be created again. NEW records as well, they might have been added, but the reference
        // is lost due to a clear() executed in one of the scripts.
        return $entityState === UnitOfWork::STATE_DETACHED || $entityState === UnitOfWork::STATE_NEW;
    }

    /**
     * @return DateTime|false
     */
    private function getNowDateTime()
    {
        $dateTime = DateTime::createFromFormat('U.u', sprintf('%.6F', microtime(true)));
        $dateTime->setTimezone(new DateTimeZone(date_default_timezone_get()));

        return $dateTime;
    }
}
