<?php

/*
 * This file is part of the Doctrine Extensions Rateable package.
 * (c) 2011 Fabien Pennequin <fabien@pennequin.me>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DoctrineExtensions\Rateable;

use Doctrine\ORM\EntityManager;

/**
 * RatingManager.
 *
 * @author Fabien Pennequin <fabien@pennequin.me>
 * @author Bat <contact@graindeweb.fr>
 */
class RatingManager
{
    protected $em;
    protected $minRateScore = 1;
    protected $maxRateScore = 5;
    
    /**
     * Set if a user can add rating more than once
     * @var boolean 
     */
    protected $multiRating  = false;
    
    /**
     * Time before a user can re-rate. Only in case of multiRating.
     * @var integer time in seconds 
     */
    protected $timeBeforeNewRating = 86400;

    
    
    public function __construct(EntityManager $em, $class = null, $minRateScore = 1, $maxRateScore = 5)
    {
        $this->em = $em;
        $this->class = $class ?: 'DoctrineExtensions\Rateable\Entity\Rate';
        $this->minRateScore = $minRateScore;
        $this->maxRateScore = $maxRateScore;
    }
    
    /**
     * Set the permission to vote more than once
     * @param boolean $multiRating
     * @param integer $timeBeforeNewRating 
     */
    public function setMultiRating($multiRating, $timeBeforeNewRating = null)
    {
        $this->multiRating = $multiRating;
        if (!is_null($timeBeforeNewRating)) $this->timeBeforeNewRating = $timeBeforeNewRating;
    }
    
    public function getMultiRating()
    {
        return $this->multiRating;
    }
    
    public function getTimeBeforeNewRating()
    {
        return $this->timeBeforeNewRating;
    }

    /**
     * Adds a new rate.
     *
     * @param Rateable  $resource   The resource object
     * @param Reviewer  $reviewer   The reviewer object
     * @param integer   $rateScore  The rate score
     *
     * @return void
     */
    public function addRate(Rateable $resource, Reviewer $reviewer, $rateScore, $save=true)
    {
        if (!$reviewer->canAddRate($resource)) {
            throw new Exception\PermissionDeniedException('This reviewer cannot add a rate for this resource');
        }

        if (!$this->isValidRateScore($rateScore)) {
            throw new Exception\InvalidRateScoreException($this->minRateScore, $this->maxRateScore);
        }

        if ((!$this->multiRating || $this->timeBeforeNewRating > 0) && $lastRate = $this->findRate($resource, $reviewer)) {
            if (!$this->multiRating) {
              throw new Exception\ResourceAlreadyRatedException('The reviewer has already rated this resource');
            }
            elseif( $lastRate->getCreatedAt()->getTimestamp() >  time()-$this->getTimeBeforeNewRating()) {
              throw new Exception\ResourceAlreadyRatedInPeriodException('The reviewer must wait before re-rate this resource');
            }
        }

        $rate = $this->createRate();
        $rate->setResource($resource);
        $rate->setReviewer($reviewer);
        $rate->setScore($rateScore);

        $resource->setRatingVotes($resource->getRatingVotes() + 1);
        $resource->setRatingTotal($resource->getRatingTotal() + $rateScore);

        if ($save) {
            $this->saveEntity($rate);
            $this->saveEntity($resource);
        }

        return $rate;
    }

    /**
     * Changes score for an existant rate.
     * Warning : This method only works fine with single Rating
     * @todo : add method to remove All Rate, or on Rate by id
     *
     * @param Rateable  $resource   The resource object
     * @param Reviewer  $reviewer   The reviewer object
     * @param integer   $rateScore  The new rate score
     *
     * @return void
     */
    public function changeRate(Rateable $resource, Reviewer $reviewer, $rateScore)
    {
        if (!$reviewer->canChangeRate($resource)) {
            throw new Exception\PermissionDeniedException('This reviewer cannot change his rate for this resource');
        }

        if (!$this->isValidRateScore($rateScore)) {
            throw new Exception\InvalidRateScoreException($this->minRateScore, $this->maxRateScore);
        }

        if (!($rate = $this->findRate($resource, $reviewer))) {
            throw new Exception\NotFoundRateException('Unable to find rate object');
        }

        $resource->setRatingTotal($resource->getRatingTotal() - $rate->getScore());
        $resource->setRatingTotal($resource->getRatingTotal() + $rateScore);

        $rate->setScore($rateScore);
        $rate->setUpdatedAt(new \DateTime('now'));
        $this->saveEntity($rate);

        $this->saveEntity($resource);
    }

    /**
     * Removes an existant rate.
     * Warning : This method only works fine with single Rating
     * @todo : add method to remove All Rate, or on Rate by id
     *
     * @param Rateable  $resource   The resource object
     * @param Reviewer  $reviewer   The reviewer object
     *
     * @return void
     */
    public function removeRate(Rateable $resource, Reviewer $reviewer)
    {
        if (!$reviewer->canRemoveRate($resource)) {
            throw new Exception\PermissionDeniedException('This reviewer cannot remove his rate for this resource');
        }

        if (!($rate = $this->findRate($resource, $reviewer))) {
            throw new Exception\NotFoundRateException('Unable to find rate object');
        }

        $resource->setRatingVotes($resource->getRatingVotes() - 1);
        $resource->setRatingTotal($resource->getRatingTotal() - $rate->getScore());

        $this->em->remove($rate);
        $this->em->flush();

        $this->saveEntity($resource);
    }

    /**
     * Computes rating fields for an resource.
     *
     * @param Rateable  $resource   The resource object
     *
     * @return void
     */
    public function computeRating(Rateable $resource)
    {
        $votes = 0;
        $total = 0;

        foreach ($this->findRatesForResource($resource) as $rate) {
            $total += $rate->getScore();
            $votes++;
        }

        $resource->setRatingVotes($votes);
        $resource->setRatingTotal($total);
        $this->saveEntity($resource);
    }

    /**
     * Gets the rating score for a resource
     *
     * @param Rateable  $resource   The resource object
     * @param integer   $precision  Score precision
     *
     * @return void
     */
    public function getRatingScore(Rateable $resource, $precision=0)
    {
        if (!($resource->getRatingVotes() > 0)) {
            return 0;
        }

        return round($resource->getRatingTotal() / $resource->getRatingVotes(), $precision);
    }

    /**
     * Finds the last rate object for a couple resource/reviewer.
     *
     * @param Rateable  $resource   The resource object
     * @param Reviewer  $reviewer   The reviewer object
     *
     * @return null|Rate The rate object if exist, null otherwise
     */
    public function findRate(Rateable $resource, Reviewer $reviewer)
    {
        return $this->em
            ->getRepository($this->class)
            ->findOneBy(array(
                'resourceId'    => $resource->getRateableId(),
                'resourceType'  => $resource->getRateableType(),
                'reviewerId'    => $reviewer->getReviewerId(),
            ), array('createdAt' => 'DESC'))
        ;
    }
    
    /**
     * Finds rates collection for a couple resource/reviewer
     * 
     * @param Rateable $resource
     * @param Reviewer $reviewer
     * @return DoctrineCollection 
     */
    public function findRates(Rateable $resource, Reviewer $reviewer)
    {
        return $this->em
                ->getRepository($this->class)
                ->findBy(array(
                  'resourceId'    => $resource->getRateableId(),
                  'resourceType'  => $resource->getRateableType(),
                  'reviewerId'    => $reviewer->getReviewerId(),
                ), array('createdAt' => 'DESC'))
            ;
    }

    /**
     * Finds all rate objects for a resource.
     *
     * @param Rateable  $resource   The resource object
     *
     * @return Doctrine\Common\Collection
     */
    public function findRatesForResource(Rateable $resource)
    {
        return $this->em
            ->getRepository($this->class)
            ->findBy(array(
                'resourceId'    => $resource->getRateableId(),
                'resourceType'  => $resource->getRateableType(),
            ))
        ;
    }

    /**
     * Checks if a value is a valid rate score
     *
     * @param integer   $rateScore  The rate score
     *
     * @return Boolean TRUE if value is valid, FALSE otherwise
     */
    public function isValidRateScore($score)
    {
        return ($score >= $this->minRateScore
            and $score <= $this->maxRateScore);
    }

    /**
     * Returns the class name for Rate entity
     *
     * @return string
     */
    public function getRateClass()
    {
        return $this->class;
    }

    /**
     * Creates a new Rate object
     *
     * @return Rate
     */
    protected function createRate()
    {
        return new $this->class();
    }

    /**
     * Saves a Doctrine Entity
     *
     * @param mixed  $entity   The entity object
     *
     * @return mixed Entity object
     */
    protected function saveEntity($entity)
    {
        $this->em->persist($entity);
        $this->em->flush();

        return $entity;
    }
}
